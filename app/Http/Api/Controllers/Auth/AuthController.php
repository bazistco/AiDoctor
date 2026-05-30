<?php

namespace App\Http\Api\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Validator;
use App\Models\User;
use Carbon\Carbon;

class AuthController extends Controller
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
                'regex:/^[0-9]+$/' // فقط اعداد انگلیسی
            ]
        ]);

        // 🔸 اعتبارسنجی ورودی
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid parameters',
                'errors' => $validator->errors()
            ], 422);
        }

        $phone = $request->phone;

        // 🔸 چک کنیم آیا در دو دقیقه گذشته OTP ارسال شده؟
        $recentOtp = DB::table('otp_codes')
            ->where('mobile', $phone)
            ->where('created_at', '>=', Carbon::now()->subMinutes(2))
            ->first();

        if ($recentOtp) {
            return response()->json([
                'success' => false,
                'message' => 'Please wait before requesting another OTP.',
                'next_allowed_in_seconds' => Carbon::parse($recentOtp->created_at)
                    ->diffInSeconds(Carbon::now()->addMinutes(2))
            ], 429); // 429 Too Many Requests
        }

        // 🔸 ایجاد کد OTP جدید
        $code = rand(100000, 999999);

        DB::table('otp_codes')->insert([
            'code' => 1111,
            'mobile' => $phone,
            'created_at' => now()
        ]);

        // 🔸 در اینجا می‌توانی سرویس SMS را فراخوانی کنی
        return response()->json([
            'success' => true,
            'message' => 'OTP sent successfully',
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
                    'regex:/^[0-9]+$/' // فقط اعداد انگلیسی
                ],
                'code' => [
                    'required',
                    'numeric',
                    'digits:4', // دقیقاً ۶ رقم
                    'regex:/^[0-9]+$/' // فقط اعداد انگلیسی
                ]
            ]
        );

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid parameters',
                'errors' => $validator->errors()
            ], 422);
        }

        $otp = DB::table('otp_codes')
            ->where('mobile', $request->phone)
            ->where('code', $request->code)
            ->orderByDesc('created_at')
            ->first();

        // 🔸 بررسی وجود OTP
        if (!$otp) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid OTP'
            ], 401);
        }

        // 🔸 بررسی انقضای کد (۲ دقیقه)
        $expiresAt = Carbon::parse($otp->created_at)->addMinutes(2);
        if (Carbon::now()->greaterThan($expiresAt)) {
            DB::table('otp_codes')->where('id', $otp->id)->delete();

            return response()->json([
                'success' => false,
                'message' => 'OTP expired, please request a new one.'
            ], 401);
        }

        // 🔸 یافتن کاربر
        $user = User::query()->firstOrCreate(
            ['phone' => $request->phone], // شرط جستجو
            [
                'phone' => $request->phone,
                //'name' => 'user-'.$request->phone,
                'password' => Hash::make($request->phone),
                'status' => 1,
            ]
        );
        if (!$user->wasRecentlyCreated && (int)$user->status !== 1) {
            return response()->json([
                'success' => false,
                'message' => 'شما مجاز به ورود نیستید'
            ], 403);
        }

// اگر کاربر تازه ساخته شد، پروفایل و پلن پیش‌فرض ایجاد کن
        if ($user->wasRecentlyCreated) {
            $genders = ['male', 'female'];
            // ایجاد پروفایل خالی
            DB::table('user_profiles')->insert([
                'user_id' => $user->id,
                'created_at' => now(),
                'updated_at' => now(),
                'age' => rand(18, 65),
                'weight' => rand(50, 120),
                'height' => rand(150, 195),
                //'gender' => $genders[rand(0, 1)],
                'birth_date' => now(),
            ]);
            DB::table('room_participants')->insert([
                'user_id' => $user->id,
                'room_id'=>1,
                'joined_at' => now()
            ]);
            // ایجاد پلن رایگان
            DB::table('user_plans')->insert([
                'user_id' => $user->id,
                'plan_type' => 'basic',
                'is_active' => 1,
                'start_date' => now(),
                'end_date' => null, // پلن رایگان بدون تاریخ انقضا
                'created_at' => now(),
                'updated_at' => now()
            ]);

            // ثبت در تاریخچه
            DB::table('plan_history')->insert([
                'user_id' => $user->id,
                'old_plan' => 'basic',
                'new_plan' => 'basic',
                'changed_at' => now()
            ]);
        }

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found'
            ], 404);
        }

        // 🔸 ساخت توکن
        $token = $user->createToken('api-login')->plainTextToken;

        // 🔸 حذف OTP بعد از استفاده
        DB::table('otp_codes')->where('id', $otp->id)->delete();

        return response()->json([
            'success' => true,
            'data' => ['access_token' => $token],
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
        $arabic = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
        $english = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

        $string = str_replace($persian, $english, $string);
        $string = str_replace($arabic, $english, $string);

        return $string;
    }

}
