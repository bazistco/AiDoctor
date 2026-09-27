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

        $userPlan = DB::table('user_plans')
            ->join('subscription_plans', 'user_plans.plan_id', '=', 'subscription_plans.id')
            ->where('user_plans.user_id', $user->id)
            ->where('user_plans.is_active', 1)
            ->where(function($query) {
                $query->whereNull('user_plans.end_date')
                    ->orWhere('user_plans.end_date', '>', now());
            })
            ->select('subscription_plans.slug')
            ->first();

// اگر کاربر پلن نداشت (یا منقضی شده بود)، مقدار 'none' در نظر گرفته می‌شود
        $planSlug = $userPlan ? $userPlan->slug : 'none';

// تعیین محدودیت روزانه: بدون پلن = 3، پایه = 10، حرفه‌ای = 20، پریمیوم = 30
        $dailyLimit = match ($planSlug) {
            'basic'   => 1,
            'pro'     => 5,
            'premium' => 8,
            default   => 1, // کاربرانی که هیچ پلن فعالی ندارند
        };

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
                    'plan_type' => $planSlug
                ]
            ], 400);
        }

//        // ثبت لاگ
//        DB::table('api_request_logs')->insert([
//            'user_id' => $user->id,
//            'endpoint' => $request->path(),
//            'created_at' => now(),
//            'updated_at' => now()
//        ]);

        // اضافه کردن اطلاعات به response
        $request->attributes->add([
            'remaining_requests' => $dailyLimit - $requestCount - 1
        ]);

        return $next($request);
    }
}
