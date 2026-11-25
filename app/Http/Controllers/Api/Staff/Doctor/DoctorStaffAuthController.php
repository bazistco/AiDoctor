<?php

namespace App\Http\Controllers\Api\Staff\Doctor;

use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Validator;
use Kavenegar\KavenegarApi;


class DoctorStaffAuthController extends Controller
{
    /**
     * ارسال OTP برای منشی مطب
     */
    public function login(Request $request)
    {
        $phone = $this->convertPersianToEnglish($request->phone);

        $validator = Validator::make(['phone' => $phone], [
            'phone' => ['required', 'string', 'min:10', 'max:15', 'regex:/^[0-9]+$/'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'اطلاعات ورودی نامعتبر است.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $user = DB::table('users')->where('phone', $phone)->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'شماره موبایل یافت نشد.',
            ], 404);
        }

        if ($user->role !== 'doctor_secretary') {
            return response()->json([
                'success' => false,
                'message' => 'این شماره به‌عنوان منشی مطب ثبت نشده است.',
            ], 403);
        }

        if ((int) $user->status !== 1) {
            return response()->json([
                'success' => false,
                'message' => 'حساب شما توسط پزشک غیرفعال شده است.',
            ], 403);
        }

        $redisKey = "otp:rate_limit:doctor-staff:{$phone}";
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
            'code'       => $code, // TODO: در پروداکشن به $code تغییر دهید
            'mobile'     => $phone,
            'created_at' => now(),
        ]);

        // TODO: ارسال SMS

        return response()->json([
            'success' => true,
            'message' => 'کد تأیید ارسال شد.',
            'otp'     => $code, // فقط در محیط تست
        ]);
    }

    /**
     * تأیید OTP و صدور توکن
     */
    public function verify(Request $request)
    {
        $phone = $this->convertPersianToEnglish($request->phone);
        $code  = $this->convertPersianToEnglish($request->code);

        $validator = Validator::make(
            ['phone' => $phone, 'code' => $code],
            [
                'phone' => ['required', 'string', 'min:10', 'max:15', 'regex:/^[0-9]+$/'],
                'code'  => ['required', 'numeric', 'digits:4', 'regex:/^[0-9]+$/'],
            ]
        );

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'اطلاعات ورودی نامعتبر است.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $user = DB::table('users')->where('phone', $phone)->first();

        if (!$user) {
            return response()->json(['success' => false, 'message' => 'شماره موبایل یافت نشد.'], 404);
        }

        if ($user->role !== 'doctor_secretary') {
            return response()->json(['success' => false, 'message' => 'این شماره به‌عنوان منشی مطب ثبت نشده است.'], 403);
        }

        if ((int) $user->status !== 1) {
            return response()->json(['success' => false, 'message' => 'حساب شما توسط پزشک غیرفعال شده است.'], 403);
        }

        $otp = DB::table('otp_codes')
            ->where('mobile', $phone)
            ->where('code', $code)
            ->orderByDesc('created_at')
            ->first();

        if (!$otp) {
            return response()->json(['success' => false, 'message' => 'کد تأیید نامعتبر است.'], 401);
        }

        $expiresAt = Carbon::parse($otp->created_at)->addMinutes(2);
        if (Carbon::now()->greaterThan($expiresAt)) {
            DB::table('otp_codes')->where('id', $otp->id)->delete();
            return response()->json([
                'success' => false,
                'message' => 'کد تأیید منقضی شده است، مجدداً درخواست دهید.',
            ], 401);
        }

        $userModel = User::find($user->id);
        $doctorId = DB::table('doctor_secretaries')->where('secretary_user_id',$userModel->id)->first()->doctor_user_id;
        $doctor = DB::table('users')->where('id', $doctorId)->first();
        $token = $userModel->createToken('doctor-secretary-token', ['doctor_secretary'])->plainTextToken;

        DB::table('otp_codes')->where('id', $otp->id)->delete();

        return response()->json([
            'success' => true,
            'message' => 'ورود موفقیت‌آمیز بود.',
            'data'    => ['access_token' => $token,'user' => [
                'id'         => (string) $user->id,
                'name'       => $userModer->name ?? 'منشی مطب',
                'phone'      => $userModel->phone,
                'owner_name' => $doctor ? ($doctor->name ?? 'پزشک') : '',
            ]],
        ]);
    }

    private function convertPersianToEnglish($string): ?string
    {
        if (is_null($string)) return null;

        $persian = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
        $arabic  = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
        $english = ['0','1','2','3','4','5','6','7','8','9'];

        return str_replace($arabic, $english, str_replace($persian, $english, $string));
    }
}
