<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CenterStaffAuthController extends Controller
{
    // انواع مجاز مراکز درمانی/آزمایشگاهی/داروخانه‌ای
    private array $allowedTypes = ['lab', 'pharmacy', 'medical_center'];

    /**
     * ارسال کد OTP به کارمند مرکز
     */
    public function login(Request $request, string $type)
    {
        if (!in_array($type, $this->allowedTypes)) {
            return response()->json([
                'success' => false,
                'message' => 'نوع مرکز نامعتبر است.'
            ], 400);
        }

        $request->validate([
            'phone' => ['required', 'string', 'regex:/^09[0-9]{9}$/']
        ], [
            'phone.required' => 'شماره موبایل الزامی است.',
            'phone.regex'    => 'فرمت شماره موبایل نامعتبر است.'
        ]);

        // یافتن شناسه کارمند با DB::table
        $user = DB::table('users')
            ->where('phone', $request->phone)
            ->select('id', 'name', 'phone')
            ->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'کاربری با این شماره موبایل یافت نشد.'
            ], 404);
        }

        // بررسی انتساب به عنوان کارمند فعال مرکز مربوطه با DB::table
        $isStaff = DB::table('medical_centers_staffs')
            ->where('staff_id', $user->id)
            ->where('center_type', $type)
            ->where('is_active', 1)
            ->exists();

        if (!$isStaff) {
            return response()->json([
                'success' => false,
                'message' => 'شما به عنوان پرسنل فعال در این مرکز ثبت نشده‌اید.'
            ], 403);
        }

        // تولید کد تایید ۴ رقمی
        $code = rand(1000, 9999);

        DB::table('otp_codes')->updateOrInsert(
            ['phone' => $request->phone],
            [
                'code'       => $code,
                'created_at' => now(),
            ]
        );

        // لاگ برای تست و دیباگ
        Log::info("OTP Code for {$type} staff ({$request->phone}): {$code}");

        return response()->json([
            'success'    => true,
            'message'    => 'کد تایید با موفقیت ارسال شد.',
            'debug_code' => config('app.debug') ? $code : null
        ]);
    }

    /**
     * اعتبارسنجی OTP و صدور توکن کارمند
     */
    public function verify(Request $request, string $type)
    {
        if (!in_array($type, $this->allowedTypes)) {
            return response()->json([
                'success' => false,
                'message' => 'نوع مرکز نامعتبر است.'
            ], 400);
        }

        $request->validate([
            'phone' => ['required', 'string', 'regex:/^09[0-9]{9}$/'],
            'code'  => ['required', 'string']
        ], [
            'phone.required' => 'شماره موبایل الزامی است.',
            'code.required'  => 'کد تایید الزامی است.'
        ]);

        // تطبیق کد با DB::table
        $otp = DB::table('otp_codes')
            ->where('phone', $request->phone)
            ->where('code', $request->code)
            ->first();

        if (!$otp) {
            return response()->json([
                'success' => false,
                'message' => 'کد تایید وارد شده نادرست یا منقضی شده است.'
            ], 400);
        }

        // مدل User فقط برای تولید توکن استاندارد Sanctum
        $user = User::where('phone', $request->phone)->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'کاربر یافت نشد.'
            ], 404);
        }

        // دریافت اطلاعات مرکز و عنوان شغلی با Join مستقیم در DB::table
        $staffRecord = DB::table('medical_centers_staffs as mcs')
            ->join('users as center', 'mcs.center_id', '=', 'center.id')
            ->where('mcs.staff_id', $user->id)
            ->where('mcs.center_type', $type)
            ->where('mcs.is_active', 1)
            ->select(
                'mcs.job_title',
                'mcs.center_id',
                'center.name as owner_name'
            )
            ->first();

        if (!$staffRecord) {
            return response()->json([
                'success' => false,
                'message' => 'دسترسی شما به این مرکز غیرفعال شده است.'
            ], 403);
        }

        // حذف کد مصرف‌شده
        DB::table('otp_codes')->where('phone', $request->phone)->delete();

        // ایجاد توکن Sanctum مخصوص نقش مربوطه
        $tokenName = "{$type}_staff_token";
        $token = $user->createToken($tokenName, ["{$type}_staff"])->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'ورود با موفقیت انجام شد.',
            'data'    => [
                'access_token' => $token,
                'user' => [
                    'id'         => (string) $user->id,
                    'name'       => $user->name ?? 'پرسنل مرکز',
                    'phone'      => $user->phone,
                    'center_id'  => (string) $staffRecord->center_id,
                    'owner_name' => $staffRecord->owner_name ?? 'مرکز نامشخص',
                    'job_title'  => $staffRecord->job_title ?? 'پرسنل',
                    'type'       => $type,
                ]
            ],
        ]);
    }
}
