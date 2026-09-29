<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;

class HealthInsightController extends Controller
{
    /**
     * دریافت اطلاعات تناسب و تغذیه کاربر از Redis
     */
    public function index(Request $request)
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
                    'dailyGoal' => 2000,
                    'days' => (object)[] // آبجکت خالی برای جلوگیری از خطای سمت ریکت
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
        // 1. اعتبارسنجی ساختار داده ارسالی
        $validated = $request->validate([
            'dailyGoal' => 'required|numeric',
            'today_date' => 'required|date_format:Y-m-d',
            'meals' => 'present|array',
            'meals.*.id' => 'required|string',
            'meals.*.type' => 'required|string|in:breakfast,lunch,dinner,snack',
            'meals.*.name' => 'required|string',
            'meals.*.calories' => 'required|numeric',
            'meals.*.createdAt' => 'required|string',
        ]);

        $userId = $request->user()->id;
        $redisKey = "health_insights:user_{$userId}";
        $today = $validated['today_date'];

        // 2. دریافت دیتای قبلی برای از دست نرفتن روزهای گذشته
        $existingData = Redis::get($redisKey);
        $insights = $existingData ? json_decode($existingData, true) : ['days' => []];

        // 3. بروزرسانی هدف روزانه
        $insights['dailyGoal'] = $validated['dailyGoal'];

        // 4. قرار دادن وعده‌های امروز (و بازنویسی در صورت وجود) در آرایه روزها
        $insights['days'][$today] = [
            'date' => $today,
            'meals' => $validated['meals']
        ];

        // 5. ذخیره مجدد کل ساختار در ردیس به صورت JSON
        Redis::set($redisKey, json_encode($insights));

        // (اختیاری) اگر می‌خواهید دیتا بعد از مثلاً ۳ ماه از ردیس پاک شود:
        // Redis::expire($redisKey, 60 * 60 * 24 * 90);

        return response()->json([
            'success' => true,
            'message' => 'برنامه غذایی با موفقیت ذخیره شد.',
            'data' =>  $insights
        ]);
    }
}
