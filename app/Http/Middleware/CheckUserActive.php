<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class CheckUserActive
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // بررسی اینکه آیا کاربر لاگین کرده است یا خیر
        $user = Auth::guard('sanctum')->user() ?? Auth::user();

        if (!$user) {
            return response()->json([
                'message' => 'Unauthenticated.'
            ], 401);
        }

        // بررسی اینکه آیا کاربر حذف شده است یا غیرفعال است
        // (فرض بر این است که ستون‌های is_active برای وضعیت فعال/غیرفعال
        // و Trait سافت دیلیت برای بررسی حذف شدن وجود دارد)
        $isDeleted = method_exists($user, 'trashed') && $user->trashed();
        $isInactive = isset($user->status) && $user->status === 0;

        if ($isDeleted || $isInactive) {
            // اختیاری: باطل کردن توکن فعلی در صورت استفاده از Sanctum
            if (method_exists($user, 'currentAccessToken') && $user->currentAccessToken()) {
                $user->currentAccessToken()->delete();
            }

            return response()->json([
                'error' => 'Unauthorized',
                'message' => 'حساب کاربری شما غیرفعال یا حذف شده است.'
            ], 401);
        }

        return $next($request);
    }
}
