<?php

namespace App\Http\Controllers\Api\Doctor;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\FinnotechService; // کلاس فرضی شما برای استعلام شاهکار
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;
use Kavenegar\KavenegarApi;

class DoctorAuthController extends Controller
{
    /**
     * مرحله اول: بررسی موبایل پزشک و ارسال OTP (همراه با محدودیت زمانی)
     */
    public function checkMobile(Request $request)
    {
        $phone = $this->convertPersianToEnglish($request->mobile);

        $validator = Validator::make(['mobile' => $phone], [
            'mobile' => [
                'required',
                'string',
                'regex:/^09[0-9]{9}$/',
            ]
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'شماره موبایل وارد شده نامعتبر است.',
                'errors'  => $validator->errors()
            ], 422);
        }

        // بررسی وضعیت کاربر (پزشک بودن)
        $user = User::where('phone', $phone)->first();
        $isNew = true;

        if ($user) {
            if ($user->role !== 'doctor') {
                return response()->json([
                    'success' => false,
                    'message' => 'این شماره موبایل متعلق به پزشک نیست.'
                ], 403);
            }

            if ((int)$user->status !== 1) {
                return response()->json([
                    'success' => false,
                    'message' => 'حساب کاربری شما غیرفعال است.'
                ], 403);
            }

            $isNew = false;
        }

        // بررسی Rate Limit در Redis
        $redisKey = "otp:rate_limit:doctor:{$phone}";
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

        // ذخیره در جدول otp_codes
        DB::table('otp_codes')->insert([
            'code'       => $code,
            'mobile'     => $phone,
            'created_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'کد تایید با موفقیت ارسال شد.',
            'data'    => [
                'is_new' => $isNew,
                // برای تست در محیط لوکال، در حالت پروداکشن حذف شود:
                'otp'    => app()->environment('local') ? $code : null
            ]
        ]);
    }

    /**
     * مرحله دوم و سوم: بررسی کد OTP، استعلام شاهکار، ثبت نام و لاگین
     */
    public function verifyAndLogin(Request $request)
    {
        $phone = $this->convertPersianToEnglish($request->mobile);
        $code = $this->convertPersianToEnglish($request->otp);

        $validator = Validator::make(
            array_merge($request->all(), ['mobile' => $phone, 'otp' => $code]),
            [
                'mobile'        => ['required', 'string', 'regex:/^09[0-9]{9}$/'],
                'otp'           => ['required', 'numeric', 'digits:4'],
                'is_new'        => ['required', 'boolean'],
                // فیلدهای ثبت‌نام فقط زمانی اجباری‌اند که کاربر جدید باشد
                'national_code' => ['required_if:is_new,true', 'nullable', 'digits:10'],
                'firstName'     => ['required_if:is_new,true', 'nullable', 'string', 'max:255'],
                'lastName'      => ['required_if:is_new,true', 'nullable', 'string', 'max:255'],
                'province'      => ['required_if:is_new,true', 'nullable', 'string', 'max:100'],
                'city'          => ['required_if:is_new,true', 'nullable', 'string', 'max:100'],
                'specialty_id'  => ['required_if:is_new,true', 'nullable', 'integer'],
            ]
        );

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'اطلاعات ارسالی نامعتبر است.',
                'errors'  => $validator->errors()
            ], 422);
        }

        // بررسی کد OTP
        $otpRecord = DB::table('otp_codes')
            ->where('mobile', $phone)
            ->where('code', $code)
            ->orderByDesc('created_at')
            ->first();

        if (!$otpRecord) {
            return response()->json([
                'success' => false,
                'message' => 'کد تایید نامعتبر است.'
            ], 401);
        }

        // بررسی انقضای کد (۲ دقیقه)
        $expiresAt = Carbon::parse($otpRecord->created_at)->addMinutes(5);
        if (Carbon::now()->greaterThan($expiresAt)) {
            DB::table('otp_codes')->where('id', $otpRecord->id)->delete();
            return response()->json([
                'success' => false,
                'message' => 'کد تایید منقضی شده است، لطفا مجددا درخواست دهید.'
            ], 401);
        }

        $isNew = $request->boolean('is_new');

        if ($isNew) {
            // -- روال ثبت‌نام پزشک جدید --

            // ۱. استعلام شاهکار
            $shahkarResult = FinnotechService::verifyShahkar($phone, $request->national_code);

                if (false ||empty($shahkarResult['success']) || empty($shahkarResult['isValid'])) {
                return response()->json([
                    'success' => false,
                    'message' =>  'عدم انطباق شماره موبایل و کد ملی در سامانه شاهکار.',
                ], 400);
            }

            // ۲. اعتبارسنجی استان و شهر (محدود به خراسان رضوی و مشهد)
            $province = DB::table('provinces')->where('name', $request->province)->first();
            $city = DB::table('cities')
                ->where('name', $request->city)
                ->where('province_id', $province->id ?? null)
                ->first();

            if (!$province || !$city) {
                return response()->json([
                    'success' => false,
                    'message' => 'استان یا شهر وارد شده معتبر نیست (در حال حاضر فقط خراسان رضوی و مشهد مجاز است).'
                ], 400);
            }

            DB::beginTransaction();
            try {
                $fullName = trim($request->firstName . ' ' . $request->lastName);

                // ایجاد کاربر
                $userId = DB::table('users')->insertGetId([

                    'name'        => $fullName,
                    'phone'       => $phone,
                    'national_code'=> $request->national_code,
                    'password'    => Hash::make($phone), // پسورد پیش‌فرض
                    'role'        => 'doctor',
                    'status'      => 1,
                    'is_verify'   => 0, // تایید موبایل انجام شده
                    'province_id' => $province->id,
                    'city_id'     => $city->id,
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ]);

                // ایجاد پروفایل پایه
                DB::table('user_profiles')->insert([
                    'user_id'    => $userId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // ایجاد کیف پول
                DB::table('wallets')->insert([
                    'user_id'    => $userId,
                    'balance'    => 0,
                    'version'    => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // ثبت اطلاعات تخصصی پزشک همراه با کد ملی تایید شده شاهکار
                DB::table('doctor_info')->insert([
                    'user_id'                   => $userId,
                    'name'                      => $fullName,
                    'specialty_id'              => $request->specialty_id,
                    'national_code'             => $request->national_code,
                    'is_national_code_verified' => 1,
                    'visit_price'               => 500000,
                    'province'                  => $request->province,
                    'city'                      => $request->city,
                    'is_vip'                    => 0,
                    'rating'                    => 0.0,
                    'image_url'                 => 'https://www.tarhdokan.com/wp-content/uploads/2020/07/doctor-1-1.jpg', // تصویر دیفالت
                    'created_at'                => now(),
                    'updated_at'                => now(),
                ]);

                DB::commit();

                $user = User::find($userId);

            } catch (\Exception $e) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'خطا در ثبت نام پزشک: ' . $e->getMessage()
                ], 500);
            }
        } else {
            // -- روال ورود پزشک فعلی --
            $user = User::where('phone', $phone)->first();

            if (!$user || $user->role !== 'doctor' || (int)$user->status !== 1) {
                return response()->json([
                    'success' => false,
                    'message' => 'شما مجاز به ورود نیستید.'
                ], 403);
            }
        }

        // حذف OTP پس از استفاده موفق
        DB::table('otp_codes')->where('id', $otpRecord->id)->delete();

        // ایجاد توکن برای پزشک
        $token = $user->createToken('doctor-token', ['doctor'])->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => $isNew ? 'ثبت‌نام و ورود با موفقیت انجام شد.' : 'ورود موفقیت‌آمیز بود.',
            'data'    => [
                'user'         => $user,
                'access_token' => $token, // مطابق نامی که در AuthController اصلی دادید
            ],
        ]);
    }

    /**
     * تبدیل اعداد فارسی و عربی به انگلیسی
     */
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
