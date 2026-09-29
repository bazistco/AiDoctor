<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;

class HealthController extends Controller
{
    /**
     * کلید اختصاصی هر کاربر در ردیس
     */
    private function getRedisKey($userId)
    {
        return "health_data:user_{$userId}";
    }

    /**
     * خواندن کل دیتای کاربر از ردیس (و مقداردهی اولیه در صورت نبود دیتا)
     */
    private function getUserData($userId)
    {
        $data = Redis::get($this->getRedisKey($userId));

        if (!$data) {
            return [
                'dailyGoal' => 2000,
                'idealWeight' => null,
                'measurements' => [
                    'neck' => '', 'waist' => '', 'arm' => '', 'thigh' => '', 'chest' => ''
                ],
                'days' => (object)[] // آبجکت روزها برای ذخیره وعده‌ها
            ];
        }

        return json_decode($data, true);
    }

    /**
     * ذخیره دیتای آپدیت‌شده در ردیس
     */
    private function saveUserData($userId, $data)
    {
        // ذخیره در ردیس (می‌توانید زمان انقضا هم بدهید، مثلا ۳۰ روز: Redis::setex(..., 2592000, ...))
        Redis::set($this->getRedisKey($userId), json_encode($data));
    }

    /**
     * فرمول محاسبه کالری هدف (Mifflin-St Jeor)
     */
    private function calculateCalorieGoal($user, $idealWeight = null)
    {
        // فرض می‌کنیم قد، وزن، سن و جنسیت در جدول users ذخیره شده است
        $weight = $user->weight ?? 70;
        $height = $user->height ?? 170;
        $age = $user->age ?? 30;
        $gender = $user->gender ?? 'مرد';

        // محاسبه BMR
        if (in_array($gender, ['male', 'مرد', 'm'])) {
            $bmr = (10 * $weight) + (6.25 * $height) - (5 * $age) + 5;
        } else {
            $bmr = (10 * $weight) + (6.25 * $height) - (5 * $age) - 161;
        }

        // TDEE (سبک زندگی کم‌تحرک)
        $tdee = $bmr * 1.2;

        if ($idealWeight) {
            if ($idealWeight < $weight) {
                return max(round($tdee - 500), 1200); // کاهش وزن
            } elseif ($idealWeight > $weight) {
                return round($tdee + 500); // افزایش وزن
            }
        }

        return round($tdee); // تثبیت وزن
    }

    // ==========================================
    // متدهای API (پاسخ‌دهنده به ریکوئست‌های React)
    // ==========================================

    /**
     * 1. دریافت تمامی اطلاعات سلامت کاربر (داشبورد و فرم‌ها)
     * GET /api/user/health-insights
     */
    public function getInsights(Request $request)
    {
        $userData = $this->getUserData($request->user()->id);

        return response()->json([
            'success' => true,
            'data' => $userData
        ]);
    }

    /**
     * 2. ذخیره/آپدیت وعده‌های غذایی یک روز خاص
     * POST /api/user/health-insights
     */
    public function syncMealPlan(Request $request)
    {
        $validated = $request->validate([
            'dailyGoal' => 'sometimes|numeric',
            'today_date' => 'required|date_format:Y-m-d',
            'meals' => 'present|array',
        ]);

        $userId = $request->user()->id;
        $userData = $this->getUserData($userId);

        // آپدیت هدف روزانه اگر از فرانت ارسال شده باشد
        if (isset($validated['dailyGoal'])) {
            $userData['dailyGoal'] = $validated['dailyGoal'];
        }

        $date = $validated['today_date'];

        // اگر آرایه days از قبل object خالی بود، آن را به آرایه تبدیل می‌کنیم تا در PHP راحت آپدیت شود
        if (is_object($userData['days'])) {
            $userData['days'] = [];
        }

        // جایگزینی کل وعده‌های آن روز (این روش برای افزودن و حذف وعده همزمان کار می‌کند)
        if (empty($validated['meals'])) {
            unset($userData['days'][$date]); // اگر همه وعده‌ها حذف شدند، روز را پاک کن
        } else {
            $userData['days'][$date] = [
                'date' => $date,
                'meals' => $validated['meals']
            ];
        }

        $this->saveUserData($userId, $userData);

        return response()->json([
            'success' => true,
            'message' => 'برنامه غذایی بروزرسانی شد.',
            'data' => $userData
        ]);
    }

    /**
     * 3. ثبت اندازه‌گیری‌های بدن و محاسبه هدف کالری
     * POST /api/user/body-measurements
     */
    public function saveMeasurements(Request $request)
    {
        $validated = $request->validate([
            'neck' => 'nullable|numeric',
            'waist' => 'nullable|numeric',
            'arm' => 'nullable|numeric',
            'thigh' => 'nullable|numeric',
            'chest' => 'nullable|numeric',
        ]);

        $userId = $request->user()->id;
        $userData = $this->getUserData($userId);

        // ذخیره اندازه‌ها
        $userData['measurements'] = array_merge($userData['measurements'], $validated);

        // محاسبه هدف جدید
        $suggestedGoal = $this->calculateCalorieGoal($request->user(), $userData['idealWeight']);
        $userData['dailyGoal'] = $suggestedGoal;

        $this->saveUserData($userId, $userData);

        return response()->json([
            'success' => true,
            'message' => 'اندازه‌گیری‌ها در ردیس ثبت شد.',
            'data' => [
                'measurements' => $userData['measurements'],
                'suggestedGoal' => $suggestedGoal
            ]
        ]);
    }

    /**
     * 4. ثبت وزن ایده‌آل از مودال فرانت‌اند
     * POST /api/user/ideal-weight
     */
    public function saveIdealWeight(Request $request)
    {
        $request->validate([
            'idealWeight' => 'required|numeric|min:30|max:250'
        ]);

        $userId = $request->user()->id;
        $userData = $this->getUserData($userId);

        $userData['idealWeight'] = $request->idealWeight;

        // آپدیت کالری هدف با وزن جدید
        $newGoal = $this->calculateCalorieGoal($request->user(), $request->idealWeight);
        $userData['dailyGoal'] = $newGoal;

        $this->saveUserData($userId, $userData);

        return response()->json([
            'success' => true,
            'dailyCalorieGoal' => $newGoal,
            'idealWeight' => $request->idealWeight
        ]);
    }
}
