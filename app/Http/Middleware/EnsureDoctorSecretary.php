<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureDoctorSecretary
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // کاربر لاگین نکرده یا توکن معتبر ندارد
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'احراز هویت الزامی است.',
            ], 401);
        }

//        // بررسی ability توکن Sanctum
//        if (!$user->tokenCan('doctor_secretary')) {
//            return response()->json([
//                'success' => false,
//                'message' => 'توکن دسترسی معتبر نیست.',
//            ], 403);
//        }

        // بررسی نقش در دیتابیس (double-check در صورت تغییر role)
        if ($user->role !== 'doctor_secretary') {
            return response()->json([
                'success' => false,
                'message' => 'شما دسترسی منشی مطب ندارید.',
            ], 403);
        }

        // بررسی فعال‌بودن حساب
        if ((int) $user->status !== 1) {
            return response()->json([
                'success' => false,
                'message' => 'حساب شما غیرفعال شده است.',
            ], 403);
        }

        return $next($request);
    }
}
