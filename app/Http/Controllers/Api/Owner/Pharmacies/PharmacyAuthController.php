<?php

namespace App\Http\Controllers\Api\Owner\Pharmacies;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use App\Models\User; // فقط برای تولید توکن Sanctum نیاز است
use Carbon\Carbon;

class PharmacyAuthController extends Controller
{
    /**
     * ارسال OTP با محدودیت زمانی (Rate Limit) برای داروخانه
     */
    public function login(Request $request)
    {
        $phone = $this->convertPersianToEnglish($request->phone);

        $validator = Validator::make(['phone' => $phone], [
            'phone' => [
                'required',
                'string',
                'min:10',
                'max:15',
                'regex:/^[0-9]+$/'
            ]
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'اطلاعات ورودی نامعتبر است.',
                'errors' => $validator->errors()
            ], 422);
        }

        // 🔸 بررسی وجود کاربر با نقش pharmacy
        $user = DB::table('users')->where('phone', $phone)->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'شماره موبایل یافت نشد.'
            ], 404);
        }

        if ($user->role !== 'pharmacy') {
            return response()->json([
                'success' => false,
                'message' => 'شما دسترسی داروخانه ندارید.'
            ], 403);
        }

        if ((int)$user->status !== 1) {
            return response()->json([
                'success' => false,
                'message' => 'حساب کاربری شما غیرفعال است.'
            ], 403);
        }

        // 🔸 چک محدودیت زمانی OTP
        $recentOtp = DB::table('otp_codes')
            ->where('mobile', $phone)
            ->where('created_at', '>=', Carbon::now()->subMinutes(2))
            ->first();

        if ($recentOtp) {
            return response()->json([
                'success' => false,
                'message' => 'لطفاً قبل از درخواست مجدد صبر کنید.',
                'next_allowed_in_seconds' => Carbon::parse($recentOtp->created_at)
                    ->diffInSeconds(Carbon::now()->addMinutes(2))
            ], 429);
        }

        // 🔸 ایجاد کد OTP
        $code = rand(100000, 999999);

        DB::table('otp_codes')->insert([
            'code' => 1111, // TODO: در پروداکشن به $code تغییر دهید
            'mobile' => $phone,
            'created_at' => now()
        ]);

        // 🔸 اینجا سرویس SMS فراخوانی می‌شود

        return response()->json([
            'success' => true,
            'message' => 'کد تأیید ارسال شد.',
            'otp' => $code, // فقط برای تست، در نسخه نهایی حذف کن
        ]);
    }

    /**
     * تأیید OTP با بررسی انقضا برای داروخانه
     */
    public function verify(Request $request)
    {
        $phone = $this->convertPersianToEnglish($request->phone);
        $code = $this->convertPersianToEnglish($request->code);

        $validator = Validator::make(
            ['phone' => $phone, 'code' => $code],
            [
                'phone' => [
                    'required',
                    'string',
                    'min:10',
                    'max:15',
                    'regex:/^[0-9]+$/'
                ],
                'code' => [
                    'required',
                    'numeric',
                    'digits:4',
                    'regex:/^[0-9]+$/'
                ]
            ]
        );

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'اطلاعات ورودی نامعتبر است.',
                'errors' => $validator->errors()
            ], 422);
        }

        // 🔸 بررسی وجود کاربر با نقش pharmacy
        $user = DB::table('users')->where('phone', $phone)->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'شماره موبایل یافت نشد.'
            ], 404);
        }

        if ($user->role !== 'pharmacy') {
            return response()->json([
                'success' => false,
                'message' => 'شما دسترسی داروخانه ندارید.'
            ], 403);
        }

        if ((int)$user->status !== 1) {
            return response()->json([
                'success' => false,
                'message' => 'حساب کاربری شما غیرفعال است.'
            ], 403);
        }

        // 🔸 بررسی OTP
        $otp = DB::table('otp_codes')
            ->where('mobile', $phone)
            ->where('code', $code)
            ->orderByDesc('created_at')
            ->first();

        if (!$otp) {
            return response()->json([
                'success' => false,
                'message' => 'کد تأیید نامعتبر است.'
            ], 401);
        }

        // 🔸 بررسی انقضا (۲ دقیقه)
        $expiresAt = Carbon::parse($otp->created_at)->addMinutes(2);
        if (Carbon::now()->greaterThan($expiresAt)) {
            DB::table('otp_codes')->where('id', $otp->id)->delete();

            return response()->json([
                'success' => false,
                'message' => 'کد تأیید منقضی شده است، مجدداً درخواست دهید.'
            ], 401);
        }

        // 🔸 ساخت توکن با ability مخصوص pharmacy
        // از مدل User فقط برای تولید توکن Sanctum استفاده می‌کنیم
        $userModel = User::find($user->id);
        $token = $userModel->createToken('pharmacy-token', ['pharmacy'])->plainTextToken;

        // 🔸 حذف OTP بعد از استفاده
        DB::table('otp_codes')->where('id', $otp->id)->delete();

        return response()->json([
            'success' => true,
            'message' => 'ورود موفقیت‌آمیز بود.',
            'data' => ['access_token' => $token],
        ]);
    }

    private function convertPersianToEnglish($string)
    {
        if (is_null($string)) {
            return null;
        }

        $persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
        $arabic  = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
        $english = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

        $string = str_replace($persian, $english, $string);
        $string = str_replace($arabic, $english, $string);

        return $string;
    }
}
