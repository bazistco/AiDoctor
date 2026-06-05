<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class CheckApiRateLimit
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['success' => false, 'message' => 'احراز هویت نشده'], 401);
        }

        // دریافت نوع پلن
        $userPlan = DB::table('user_plans')
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->first();

        $planType = $userPlan ? $userPlan->plan_type : 'basic';
        $dailyLimit = $planType === 'basic' ? 10 : 15;

        // شمارش درخواست‌های امروز
        $today = Carbon::today();
        $requestCount = DB::table('api_request_logs')
            ->where('user_id', $user->id)
            ->whereDate('created_at', $today)
            ->count();

        if ($requestCount >= $dailyLimit) {
            return response()->json([
                'success' => false,
                'message' => 'محدودیت درخواست روزانه به پایان رسید. برای دسترسی بیشتر پلن خود را ارتقا دهید.',
                'data' => [
                    'daily_limit' => $dailyLimit,
                    'used_requests' => $requestCount,
                    'plan_type' => $planType
                ]
            ], 400);
        }

        // ثبت لاگ
        DB::table('api_request_logs')->insert([
            'user_id' => $user->id,
            'endpoint' => $request->path(),
            'created_at' => now(),
            'updated_at' => now()
        ]);

        // اضافه کردن اطلاعات به response
        $request->attributes->add([
            'remaining_requests' => $dailyLimit - $requestCount - 1
        ]);

        return $next($request);
    }
}
