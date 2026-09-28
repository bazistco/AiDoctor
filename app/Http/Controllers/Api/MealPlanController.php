<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;

class MealPlanController extends Controller
{
    /**
     * دریافت اطلاعات تناسب و تغذیه کاربر از Redis
     */
    public function show(Request $request)
    {
        $userId = $request->user()->id;
        $redisKey = "health_insights:user_{$userId}";

        // خواندن داده‌ها از ردیس
        $data = Redis::get($redisKey);

        if (!$data) {
            // مقادیر پیش‌فرض در صورتی که کاربر هنوز دیتایی در ردیس ندارد
            return response()->json([
                'success' => true,
                'data' => [
                    'dailyGoal' => 2000, // معادل DEFAULT_DAILY_GOAL در فرانت
                    'days' => (object)[] // آبجکت خالی برای روزها
                ]
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => json_decode($data, true)
        ]);
    }

    /**
     * ذخیره/بروزرسانی اطلاعات برنامه غذایی در Redis
     */
    public function store(Request $request)
    {
        // اعتبارسنجی ساختار داده ارسالی از سمت ری‌اکت
        $validated = $request->validate([
            'dailyGoal' => 'required|numeric',
            'today_date' => 'required|date_format:Y-m-d',
            'meals' => 'required|array',
            'meals.*.id' => 'required|string',
            'meals.*.type' => 'required|string|in:breakfast,lunch,dinner,snack',
            'meals.*.name' => 'required|string',
            'meals.*.calories' => 'required|numeric',
            'meals.*.createdAt' => 'required|string',
        ]);

        $userId = $request->user()->id;
        $redisKey = "health_insights:user_{$userId}";
        $today = $validated['today_date'];

        // ۱. دریافت دیتای قبلی برای از دست نرفتن روزهای گذشته
        $existingData = Redis::get($redisKey);
        $insights = $existingData ? json_decode($existingData, true) : ['days' => []];

        // ۲. بروزرسانی هدف روزانه
        $insights['dailyGoal'] = $validated['dailyGoal'];

        // ۳. قرار دادن وعده‌های امروز در آرایه روزها
        $insights['days'][$today] = [
            'date' => $today,
            'meals' => $validated['meals']
        ];

        // ۴. ذخیره مجدد کل ساختار در ردیس (شما می‌توانید یک زمان انقضا مثلا 30 روزه هم با setex تنظیم کنید)
        Redis::set($redisKey, json_encode($insights));

        // اگر بخواهید بعد از مدتی منقضی شود (مثلا ۳ ماه):
        // Redis::setex($redisKey, 60 * 60 * 24 * 90, json_encode($insights));

        return response()->json([
            'success' => true,
            'message' => 'برنامه غذایی با موفقیت ذخیره شد.',
            'data' => $insights
        ]);
    }
}
