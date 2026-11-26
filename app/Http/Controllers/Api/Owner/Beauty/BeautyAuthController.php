<?php

namespace App\Http\Controllers\Api\Owner\Beauty;

use App\Http\Controllers\Controller;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Kavenegar\KavenegarApi;

class BeautyAuthController extends Controller
{
    /**
     * ارسال OTP با قابلیت ثبت‌نام خودکار کلینیک جدید (پیش‌فرض غیرفعال: status = 0)
     */
    public function login(Request $request)
    {
        $phone = $this->convertPersianToEnglish($request->phone);

        $validator = Validator::make(
            [
                'phone'       => $phone,
                'name'        => $request->input('name'),
                'province_id' => $request->input('province_id'),
                'city_id'     => $request->input('city_id'),
                'address'     => $request->input('address'),
                'work_hours'  => $request->input('work_hours'),
                'description' => $request->input('description'),
            ],
            [
                'phone'       => ['required', 'string', 'min:10', 'max:15', 'regex:/^[0-9]+$/'],
                'name'        => ['nullable', 'string', 'max:255'],
                'province_id' => ['nullable', 'integer'],
                'city_id'     => ['nullable', 'integer'],
                'address'     => ['nullable', 'string', 'max:1000'],
                'work_hours'  => ['nullable', 'string', 'max:255'],
                'description' => ['nullable', 'string', 'max:2000'],
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

        // اگر کاربر وجود دارد و نقش آن beauty_clinic نیست، جلوی ادامه کار را بگیر
        if ($user && $user->role !== 'beauty_clinic') {
            return response()->json([
                'success' => false,
                'message' => 'این شماره موبایل با نقش دیگری در سیستم ثبت شده است و امکان ثبت‌نام یا ورود به‌عنوان کلینیک زیبایی وجود ندارد.',
            ], 409);
        }

        if (!$user) {
            $clinicName    = trim((string) $request->input('name', ''));
            $clinicAddress = trim((string) $request->input('address', ''));

            if ($clinicName === '' || $clinicAddress === '') {
                return response()->json([
                    'success'               => false,
                    'requires_registration' => true,
                    'message'               => 'شماره موبایل ثبت نشده است؛ برای ثبت‌نام کلینیک، نام و آدرس را وارد کنید.',
                ], 404);
            }

            try {
                $user = $this->createInactiveBeautyClinic(
                    $phone,
                    $clinicName,
                    $clinicAddress,
                    $request->input('province_id') ? (int) $request->input('province_id') : null,
                    $request->input('city_id') ? (int) $request->input('city_id') : null,
                    $request->input('work_hours'),
                    $request->input('description')
                );
            } catch (\RuntimeException $e) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], 409);
            }
        }

        if ($user->role !== 'beauty_clinic') {
            return response()->json([
                'success' => false,
                'message' => 'شما دسترسی کلینیک زیبایی ندارید.',
            ], 403);
        }

        // وضعیت 2 = مسدود شده
        if ((int) $user->status === 0) {
            return response()->json([
                'success' => false,
                'message' => 'حساب کاربری شما مسدود است.',
            ], 403);
        }

        $redisKey   = "otp:rate_limit:beauty:{$phone}";
        $ttlSeconds = 180; // 3 دقیقه

        $lockAcquired = Redis::set($redisKey, 1, 'EX', $ttlSeconds, 'NX');

        if (!$lockAcquired) {
            $remainingTtl = Redis::ttl($redisKey);
            return response()->json([
                'success'                 => false,
                'message'                 => 'محدودیت در ارسال کد؛ لطفا کمی صبر کنید.',
                'next_allowed_in_seconds' => $remainingTtl,
            ], 429);
        }

        $code = rand(1000, 9999);

        try {
            $k = new KavenegarApi(env('KAVENEGAR_API_KEY'));
            $k->VerifyLookup($phone, $code, "", "", 'medira-verify', 'sms');
        } catch (\Exception $e) {
            Redis::del($redisKey);
            return response()->json([
                'success' => false,
                'message' => 'خطا در ارتباط با سرویس پیامک.',
            ], 500);
        }

        DB::table('otp_codes')->insert([
            'code'       => $code,
            'mobile'     => $phone,
            'created_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'کد تأیید ارسال شد.',
            'otp'     => $code, // فقط برای تست، در نسخه نهایی حذف کن
        ]);
    }

    /**
     * تأیید OTP با بررسی انقضا و صدور توکن کلینیک زیبایی
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
            return response()->json([
                'success' => false,
                'message' => 'شماره موبایل یافت نشد.',
            ], 404);
        }

        if ($user->role !== 'beauty_clinic') {
            return response()->json([
                'success' => false,
                'message' => 'شما دسترسی کلینیک زیبایی ندارید.',
            ], 403);
        }

        if ((int) $user->status === 2) {
            return response()->json([
                'success' => false,
                'message' => 'حساب کاربری شما مسدود است.',
            ], 403);
        }

        $otp = DB::table('otp_codes')
            ->where('mobile', $phone)
            ->where('code', $code)
            ->orderByDesc('created_at')
            ->first();

        if (!$otp) {
            return response()->json([
                'success' => false,
                'message' => 'کد تأیید نامعتبر است.',
            ], 401);
        }

        // بررسی انقضا (۲ دقیقه)
        $expiresAt = Carbon::parse($otp->created_at)->addMinutes(2);
        if (Carbon::now()->greaterThan($expiresAt)) {
            DB::table('otp_codes')->where('id', $otp->id)->delete();

            return response()->json([
                'success' => false,
                'message' => 'کد تأیید منقضی شده است، مجدداً درخواست دهید.',
            ], 401);
        }

        $userModel = User::find($user->id);
        $token     = $userModel->createToken('beauty-clinic-token', ['beauty_clinic'])->plainTextToken;

        DB::table('otp_codes')->where('id', $otp->id)->delete();

        return response()->json([
            'success' => true,
            'message' => 'ورود موفقیت‌آمیز بود.',
            'data'    => [
                'access_token' => $token,
                'status'       => (int) $user->status,
                'user'         => [
                    'id'     => $user->id,
                    'name'   => $user->name,
                    'phone'  => $user->phone,
                    'role'   => $user->role,
                    'status' => (int) $user->status,
                ],
            ],
        ]);
    }

    /**
     * ایجاد کاربر کلینیک زیبایی و کیف‌پول با وضعیت پیش‌فرض غیرفعال (status = 0)
     * با قفل و بررسی مجدد داخل تراکنش برای جلوگیری از تداخل با نقش‌های دیگر
     */
    private function createInactiveBeautyClinic(
        string $phone,
        string $clinicName,
        string $address,
        ?int $provinceId = null,
        ?int $cityId = null,
        ?string $workHours = null,
        ?string $description = null
    ): object {
        $now = now();

        $provinceRow = $provinceId
            ? DB::table('provinces')->where('id', $provinceId)->first()
            : null;

        $cityRow = ($provinceRow && $cityId)
            ? DB::table('cities')
                ->where('id', $cityId)
                ->where('province_id', $provinceRow->id)
                ->first()
            : null;

        $resolvedProvinceId = $provinceRow->id ?? 1;
        $resolvedCityId     = $cityRow->id ?? 1;

        $userId = DB::transaction(function () use (
            $phone,
            $clinicName,
            $address,
            $resolvedProvinceId,
            $resolvedCityId,
            $workHours,
            $description,
            $now
        ) {
            $existing = DB::table('users')
                ->where('phone', $phone)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                throw new \RuntimeException(
                    'این شماره موبایل قبلاً در سیستم ثبت شده است و امکان ایجاد حساب جدید با آن وجود ندارد.'
                );
            }

            $id = DB::table('users')->insertGetId([
                'name'        => $clinicName,
                'phone'       => $phone,
                'password'    => Hash::make($phone),
                'role'        => 'beauty_clinic',
                'status'      => 0, // پیش‌فرض غیرفعال
                'is_verify'   => 0,
                'province_id' => $resolvedProvinceId,
                'city_id'     => $resolvedCityId,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);

            DB::table('wallets')->insert([
                'user_id'    => $id,
                'balance'    => 0,
                'version'    => 0,
                'deleted_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $slugBase = Str::slug($clinicName) ?: 'beauty-clinic';

            DB::table('beauty_clinic_info')->insert([
                'user_id'            => $id,
                'slug'               => "{$slugBase}-{$id}",
                'phone'              => $phone,
                'address'            => $address,
                'work_hours'         => $workHours,
                'description'        => $description,
                'image'              => null,
                'is_avatar_verified' => 0,
                'status'             => 0, // پیش‌فرض غیرفعال
                'created_at'         => $now,
                'updated_at'         => $now,
            ]);

            return $id;
        });

        return DB::table('users')->where('id', $userId)->first();
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
