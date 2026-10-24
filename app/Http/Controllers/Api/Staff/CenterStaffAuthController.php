<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class CenterStaffAuthController extends Controller
{
    public function check(Request $request): JsonResponse
    {
        $user = $request->user();

        // ۱. بررسی وضعیت کلی حساب خود کاربر
        if (!$user || (int) $user->status !== 1) {
            return response()->json([
                'success' => false,
                'message' => 'حساب کاربری شما غیرفعال شده است.',
                'code'    => 'USER_INACTIVE'
            ], Response::HTTP_UNAUTHORIZED);
        }

        $extraData = [
            'centerId'   => null,
            'centerType' => null,
            'doctorUserId' => null,
            'jobTitle'   => null,
        ];

        // ۲. حالت اول: اگر نقش کاربر منشی پزشک باشد (doctor_secretary)
        if ($user->role === 'doctor_secretary') {
            $secretaryRelation = DB::table('doctor_secretaries')
                ->where('secretary_user_id', $user->id)
                ->first();

            if (!$secretaryRelation) {
                return response()->json([
                    'success' => false,
                    'message' => 'شما به عنوان منشی به هیچ پزشکی متصل نیستید.',
                    'code'    => 'NO_DOCTOR_ASSIGNED'
                ], Response::HTTP_FORBIDDEN);
            }

            // در صورتی که ستون is_active در جدول doctor_secretaries وجود داشته باشد
            if (isset($secretaryRelation->is_active) && (int) $secretaryRelation->is_active !== 1) {
                return response()->json([
                    'success' => false,
                    'message' => 'دسترسی شما توسط پزشک غیرفعال شده است.',
                    'code'    => 'SECRETARY_DISABLED'
                ], Response::HTTP_FORBIDDEN);
            }

            // بررسی فعال بودن وضعیت خود پزشک متصل‌شده
            $doctorUser = DB::table('users')
                ->where('id', $secretaryRelation->doctor_user_id)
                ->first();

            if (!$doctorUser || (int) $doctorUser->status !== 1) {
                return response()->json([
                    'success' => false,
                    'message' => 'حساب کاربری پزشک مرتبط با شما غیرفعال است.',
                    'code'    => 'DOCTOR_INACTIVE'
                ], Response::HTTP_FORBIDDEN);
            }

            $extraData['doctorUserId'] = (string) $secretaryRelation->doctor_user_id;
            $extraData['jobTitle']     = 'منشی پزشک';
        }
        // ۳. حالت دوم: کارمندان آزمایشگاه یا داروخانه یا مراکز درمانی
        else if (in_array($user->role, ['lab_staff', 'pharmacy_staff'])) {
            $staffRelation = DB::table('medical_centers_staffs')
                ->where('staff_id', $user->id)
                ->first();

            if (!$staffRelation) {
                return response()->json([
                    'success' => false,
                    'message' => 'شما به هیچ مرکزی انتساب داده نشده‌اید.',
                    'code'    => 'NO_CENTER_ASSIGNED'
                ], Response::HTTP_FORBIDDEN);
            }

            if ((int) $staffRelation->is_active !== 1) {
                return response()->json([
                    'success' => false,
                    'message' => 'دسترسی شما توسط مدیریت مرکز غیرفعال گردیده است.',
                    'code'    => 'STAFF_DISABLED'
                ], Response::HTTP_FORBIDDEN);
            }

            $extraData['centerId']   = (string) $staffRelation->center_id;
            $extraData['centerType'] = $staffRelation->center_type;
            $extraData['jobTitle']   = $staffRelation->job_title ?? null;
        }

        return response()->json([
            'success' => true,
            'data'    => array_merge([
                'id'       => (string) $user->id,
                'name'     => $user->name,
                'phone'    => $user->phone,
                'role'     => $user->role,
                'isActive' => true,
            ], $extraData)
        ]);
    }
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
            ['mobile' => $request->phone],
            [
                'code'       => 1111,
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
            ->where('mobile', $request->phone)
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
        DB::table('otp_codes')->where('mobile', $request->phone)->delete();

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
