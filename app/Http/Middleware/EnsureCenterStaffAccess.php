<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class EnsureCenterStaffAccess
{
    /**
     * نگاشت نوع مرکز به نقش متناظر در جدول users
     */
    protected array $typeRoleMap = [
        'lab'     => 'lab_staff',
        'pharmacy'       => 'pharmacy_staff',
        'medical_center' => 'medical_center_staff',
    ];

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  string|null  $type  نوع مرکز (laboratory, pharmacy, medical_center)
     */
    public function handle(Request $request, Closure $next, ?string $type = null): Response
    {
        $user = $request->user();

        // ۱. بررسی احراز هویت
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'احراز هویت انجام نشده است.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        // ۲. تشخیص type (از پارامتر میدلور یا پارامتر روت)
        $centerType = $type ?: $request->route('type');

        if (!$centerType || !array_key_exists($centerType, $this->typeRoleMap)) {
            return response()->json([
                'success' => false,
                'message' => 'نوع مرکز درمانی نامعتبر است.',
            ], Response::HTTP_BAD_REQUEST);
        }

        // ۳. بررسی نقش کاربر
        $expectedRole = $this->typeRoleMap[$centerType];
        if ($user->role !== $expectedRole) {
            return response()->json([
                'success' => false,
                'message' => 'شما دسترسی لازم برای این بخش را ندارید.',
            ], Response::HTTP_FORBIDDEN);
        }

        // ۴. بررسی فعال بودن حساب کاربر در جدول users
        if ((int) $user->status !== 1) {
            return response()->json([
                'success' => false,
                'message' => 'حساب کاربری شما غیرفعال شده است.',
            ], Response::HTTP_FORBIDDEN);
        }

        // ۵. بررسی وضعیت انتساب و فعال بودن در مرکز درمانی (جدول واسط)
        $staffRecord = DB::table('medical_centers_staffs')
            ->where('staff_id', $user->id)
            ->where('center_type', $centerType)
            ->where('is_active', 1)
            ->first();

        if (!$staffRecord) {
            return response()->json([
                'success' => false,
                'message' => 'دسترسی شما به این مرکز غیرفعال یا حذف شده است.',
            ], Response::HTTP_FORBIDDEN);
        }

        // ۶. تزریق اطلاعات مفید به Request جهت دسترسی آسان در کنترلرها
        $request->attributes->set('center_staff', $staffRecord);
        $request->attributes->set('center_id', $staffRecord->center_id);
        $request->attributes->set('center_type', $centerType);

        return $next($request);
    }
}
