<?php

namespace App\Http\Controllers\Api\Owner\MedicalCenters;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Validator;
use App\Models\User;
use Carbon\Carbon;
use Kavenegar\KavenegarApi;


class MedicalCenterAuthController extends Controller
{
    /**
     * ارسال OTP با محدودیت زمانی (Rate Limit)
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

        // 🔸 بررسی وجود کاربر با نقش medical_center
        $user = DB::table('users')->where('phone', $phone)->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'شماره موبایل یافت نشد.'
            ], 404);
        }

        if ($user->role !== 'medical_center') {
            return response()->json([
                'success' => false,
                'message' => 'شما دسترسی مرکز درمانی ندارید.'
            ], 403);
        }

        if ((int)$user->status !== 1) {
            return response()->json([
                'success' => false,
                'message' => 'حساب کاربری شما غیرفعال است.'
            ], 403);
        }

        $redisKey = "otp:rate_limit:medical:{$phone}";
        $ttlSeconds = 180; // 3 دقیقه

        $lockAcquired = Redis::set($redisKey, 1, 'EX', $ttlSeconds, 'NX');

        if (!$lockAcquired) {
            $remainingTtl = Redis::ttl($redisKey);
            return response()->json([
                'success' => false,
                'message' => 'محدودیت در ارسال کد؛ لطفا کمی صبر کنید.',
                'next_allowed_in_seconds' => $remainingTtl,
            ], 429);
        }

        // تولید کد 4 رقمی
        $code = rand(1000, 9999);

        // ارسال پیامک با کاوه‌نگار
        try {
            $k = new KavenegarApi(env('KAVENEGAR_API_KEY'));
            $k->VerifyLookup($phone, $code, "", "", 'medira-verify', 'sms');
        } catch (\Exception $e) {
            // آزاد کردن Redis در صورت خطای پیامک برای تلاش مجدد
            Redis::del($redisKey);
            return response()->json([
                'success' => false,
                'message' => 'خطا در ارتباط با سرویس پیامک.',
            ], 500);
        }


        DB::table('otp_codes')->insert([
            'code' => $code, // TODO: در پروداکشن به $code تغییر دهید
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
     * تأیید OTP با بررسی انقضا
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

        // 🔸 بررسی وجود کاربر با نقش medical_center
        $user = DB::table('users')->where('phone', $phone)->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'شماره موبایل یافت نشد.'
            ], 404);
        }

        if ($user->role !== 'medical_center') {
            return response()->json([
                'success' => false,
                'message' => 'شما دسترسی مرکز درمانی ندارید.'
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

        // 🔸 ساخت توکن با ability مخصوص medical_center
        $userModel = User::find($user->id);
        $token = $userModel->createToken('medical-center-token', ['medical_center'])->plainTextToken;

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
