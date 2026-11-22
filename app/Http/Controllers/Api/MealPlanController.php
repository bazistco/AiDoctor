<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class MealPlanController extends Controller
{
    private function getHealthRedisKey(int $userId): string
    {
        return "health_data:user_{$userId}";
    }

    /**
     * دریافت پلن فعال کاربر (و در صورت نبودن، پلن پایه)
     */
    private function resolveUserPlan(int $userId): object
    {
        $now = now();

        $activePlan = DB::table('user_plans')
            ->join('subscription_plans', 'user_plans.plan_id', '=', 'subscription_plans.id')
            ->where('user_plans.user_id', $userId)
            ->where('user_plans.is_active', 1)
            ->where(function ($q) use ($now) {
                $q->whereNull('user_plans.end_date')
                    ->orWhere('user_plans.end_date', '>', $now);
            })
            ->orderByDesc('user_plans.id')
            ->select(
                'subscription_plans.id',
                'subscription_plans.name',
                'subscription_plans.slug',
                'user_plans.start_date',
                'user_plans.end_date'
            )
            ->first();

        if ($activePlan) {
            $isBasic = strtolower((string) $activePlan->slug) === 'basic';
            return (object) [
                'id'         => (int) $activePlan->id,
                'name'       => $activePlan->name,
                'slug'       => $activePlan->slug,
                'is_basic'   => $isBasic,
                'max_plans'  => $isBasic ? 1 : null,
                'can_delete' => !$isBasic,
                'start_date' => $activePlan->start_date,
                'end_date'   => $activePlan->end_date,
            ];
        }

        $basicPlan = DB::table('subscription_plans')
            ->where('slug', 'basic')
            ->where('status', 1)
            ->first();

        return (object) [
            'id'         => (int) ($basicPlan->id ?? 1),
            'name'       => $basicPlan->name ?? 'پلن پایه',
            'slug'       => $basicPlan->slug ?? 'basic',
            'is_basic'   => true,
            'max_plans'  => 1,
            'can_delete' => false,
            'start_date' => null,
            'end_date'   => null,
        ];
    }

    /**
     * محاسبه کالری هدف بر اساس پروفایل کاربر و وزن ایده‌آل
     */
    private function calculateCalorieGoal($user, ?float $idealWeight = null): int
    {
        $weight = $user->weight ?? 70;
        $height = $user->height ?? 170;
        $age = $user->age ?? 30;
        $gender = $user->gender ?? 0;

        $isFemale = in_array((string) $gender, ['1', 'female', 'زن', 'f'], true);

        if ($isFemale) {
            $bmr = (10 * $weight) + (6.25 * $height) - (5 * $age) - 161;
        } else {
            $bmr = (10 * $weight) + (6.25 * $height) - (5 * $age) + 5;
        }

        $tdee = $bmr * 1.2;

        if ($idealWeight !== null) {
            if ($idealWeight < $weight) {
                return (int) max(round($tdee - 500), 1200);
            }
            if ($idealWeight > $weight) {
                return (int) round($tdee + 500);
            }
        }

        return (int) round($tdee);
    }

    /**
     * تولید یک برنامه غذایی پیشنهادی خودکار بر اساس بودجه کالری هر وعده
     */
    private function buildSuggestedPlanMeals(int $dailyGoal): array
    {
        $breakfastBudget = (int) round($dailyGoal * 0.25);
        $lunchBudget = (int) round($dailyGoal * 0.40);
        $dinnerBudget = (int) round($dailyGoal * 0.35);

        $catalog = DB::table('meal_catalog_items')
            ->where('is_active', 1)
            ->orderBy('sort_order')
            ->get();

        $pickForBudget = function (string $type, int $budget) use ($catalog): array {
            $items = $catalog->where('meal_type', $type);
            $picked = [];
            $sum = 0;

            foreach ($items as $item) {
                $cal = (int) $item->calories;
                if ($cal <= 0) {
                    continue;
                }
                if ($sum + $cal <= $budget || empty($picked)) {
                    $picked[] = [
                        'type'         => $type,
                        'name'         => $item->name,
                        'calories'     => $cal,
                        'is_suggested' => 1,
                    ];
                    $sum += $cal;
                }
            }

            return $picked;
        };

        return array_merge(
            $pickForBudget('breakfast', $breakfastBudget),
            $pickForBudget('lunch', $lunchBudget),
            $pickForBudget('dinner', $dinnerBudget)
        );
    }

    /**
     * GET /api/user/meal-plans
     * دریافت برنامه‌های غذایی کاربر، وضعیت پلن، محدودیت‌ها و بانک غذاهای پیشنهادی
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $userId = (int) $user->id;
        $plan = $this->resolveUserPlan($userId);

        $redisRaw = Redis::get($this->getHealthRedisKey($userId));
        $redisData = $redisRaw ? json_decode($redisRaw, true) : [];
        $dailyGoal = (int) (
            $redisData['dailyGoal']
            ?? $this->calculateCalorieGoal($user, $redisData['idealWeight'] ?? null)
        );
        $idealWeight = $redisData['idealWeight'] ?? null;

        $plans = DB::table('meal_plans')
            ->where('user_id', $userId)
            ->orderByDesc('plan_date')
            ->orderByDesc('id')
            ->get();

        $planIds = $plans->pluck('id')->all();
        $itemsByPlan = !empty($planIds)
            ? DB::table('meal_plan_items')
                ->whereIn('meal_plan_id', $planIds)
                ->orderBy('id')
                ->get()
                ->groupBy('meal_plan_id')
            : collect();

        $formattedPlans = $plans->map(function ($p) use ($itemsByPlan) {
            $items = ($itemsByPlan->get($p->id) ?? collect())->map(function ($it) {
                return [
                    'id'           => (int) $it->id,
                    'type'         => $it->meal_type,
                    'name'         => $it->food_name,
                    'calories'     => (int) $it->calories,
                    'is_suggested' => (bool) $it->is_suggested,
                ];
            })->values();

            return [
                'id'                     => (int) $p->id,
                'title'                  => $p->title,
                'plan_date'              => $p->plan_date,
                'daily_calorie_goal'     => (int) $p->daily_calorie_goal,
                'ideal_weight'           => $p->ideal_weight !== null ? (float) $p->ideal_weight : null,
                'total_calories'         => (int) $items->sum('calories'),
                'is_auto_generated'      => (bool) $p->is_auto_generated,
                'allow_delete_on_basic'  => (bool) ($p->allow_delete_on_basic ?? false),
                'meals'                  => $items,
            ];
        })->values();

        $catalog = DB::table('meal_catalog_items')
            ->where('is_active', 1)
            ->orderBy('sort_order')
            ->get();

        $usedCount = $formattedPlans->count();
        $canCreateNew = !$plan->is_basic || $usedCount < 1;

        return response()->json([
            'success' => true,
            'data'    => [
                'subscription' => [
                    'plan_id'        => $plan->id,
                    'plan_name'      => $plan->name,
                    'slug'           => $plan->slug,
                    'is_basic'       => $plan->is_basic,
                    'max_plans'      => $plan->max_plans,
                    'used_plans'     => $usedCount,
                    'can_create_new' => $canCreateNew,
                    'can_delete'     => $plan->can_delete,
                ],
                'daily_calorie_goal' => $dailyGoal,
                'ideal_weight'       => $idealWeight,
                'plans'              => $formattedPlans,
                'catalog'            => [
                    'breakfast_suggestions' => $catalog->where('meal_type', 'breakfast')->where('is_suggestion', 1)->values(),
                    'breakfast_options'     => $catalog->where('meal_type', 'breakfast')->where('is_suggestion', 0)->values(),
                    'lunch_options'         => $catalog->where('meal_type', 'lunch')->where('is_suggestion', 0)->values(),
                    'dinner_options'        => $catalog->where('meal_type', 'dinner')->where('is_suggestion', 0)->values(),
                    'snack_options'         => $catalog->where('meal_type', 'snack')->where('is_suggestion', 0)->values(),
                ],
            ],
        ]);
    }

    /**
     * POST /api/user/meal-plans
     * ایجاد برنامه غذایی جدید (با کنترل محدودیت پلن پایه = فقط ۱ برنامه) یا تولید خودکار برنامه پیشنهادی
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'title'              => 'nullable|string|max:191',
            'plan_date'          => 'nullable|date_format:Y-m-d',
            'daily_calorie_goal' => 'nullable|integer|min:800|max:6000',
            'ideal_weight'       => 'nullable|numeric|min:30|max:250',
            'auto_generate'      => 'nullable|boolean',
            'meals'              => 'nullable|array',
            'meals.*.type'       => 'required_with:meals|in:breakfast,lunch,dinner,snack',
            'meals.*.name'       => 'required_with:meals|string|max:191',
            'meals.*.calories'   => 'required_with:meals|integer|min:0|max:5000',
        ]);

        $user = $request->user();
        $userId = (int) $user->id;
        $plan = $this->resolveUserPlan($userId);

        $existingCount = DB::table('meal_plans')->where('user_id', $userId)->count();

        if ($plan->is_basic && $existingCount >= 1) {
            return response()->json([
                'success' => false,
                'code'    => 'BASIC_PLAN_LIMIT_REACHED',
                'message' => 'در پلن پایه فقط امکان ساخت ۱ برنامه غذایی وجود دارد. برای ساخت برنامه‌های بیشتر اشتراک خود را ارتقا دهید.',
                'data'    => [
                    'plan_slug'  => $plan->slug,
                    'max_plans'  => 1,
                    'used_plans' => $existingCount,
                ],
            ], 403);
        }

        $redisRaw = Redis::get($this->getHealthRedisKey($userId));
        $redisData = $redisRaw ? json_decode($redisRaw, true) : [];

        $idealWeight = array_key_exists('ideal_weight', $validated)
            ? $validated['ideal_weight']
            : ($redisData['idealWeight'] ?? null);

        $goal = (int) (
            $validated['daily_calorie_goal']
            ?? $redisData['dailyGoal']
            ?? $this->calculateCalorieGoal($user, $idealWeight !== null ? (float) $idealWeight : null)
        );

        $planDate = $validated['plan_date'] ?? Carbon::now()->format('Y-m-d');
        $autoGenerate = (bool) ($validated['auto_generate'] ?? empty($validated['meals']));
        $meals = !empty($validated['meals'])
            ? $validated['meals']
            : ($autoGenerate ? $this->buildSuggestedPlanMeals($goal) : []);

        $planId = DB::transaction(function () use ($userId, $validated, $planDate, $goal, $idealWeight, $autoGenerate, $meals) {
            $id = DB::table('meal_plans')->insertGetId([
                'user_id'            => $userId,
                'title'              => $validated['title'] ?? 'برنامه غذایی شخصی',
                'plan_date'          => $planDate,
                'daily_calorie_goal' => $goal,
                'ideal_weight'       => $idealWeight,
                'is_auto_generated'  => $autoGenerate ? 1 : 0,
                'created_at'         => now(),
                'updated_at'         => now(),
            ]);

            foreach ($meals as $m) {
                DB::table('meal_plan_items')->insert([
                    'meal_plan_id' => $id,
                    'meal_type'    => $m['type'],
                    'food_name'    => $m['name'],
                    'calories'     => (int) $m['calories'],
                    'is_suggested' => !empty($m['is_suggested']) ? 1 : 0,
                    'created_at'   => now(),
                    'updated_at'   => now(),
                ]);
            }

            return $id;
        });

        $redisData['dailyGoal'] = $goal;
        if ($idealWeight !== null) {
            $redisData['idealWeight'] = (float) $idealWeight;
        }
        Redis::set($this->getHealthRedisKey($userId), json_encode($redisData));

        return response()->json([
            'success' => true,
            'message' => 'برنامه غذایی با موفقیت ایجاد شد.',
            'data'    => [
                'id'           => (int) $planId,
                'subscription' => [
                    'plan_id'        => $plan->id,
                    'plan_name'      => $plan->name,
                    'slug'           => $plan->slug,
                    'is_basic'       => $plan->is_basic,
                    'max_plans'      => $plan->max_plans,
                    'used_plans'     => $existingCount + 1,
                    'can_create_new' => !$plan->is_basic || ($existingCount + 1) < 1,
                    'can_delete'     => $plan->can_delete,
                ],
            ],
        ], 201);
    }

    /**
     * PUT /api/user/meal-plans/{id}
     * ویرایش آیتم‌های یک برنامه غذایی موجود
     */
    public function update(Request $request, int $id)
    {
        $validated = $request->validate([
            'title'              => 'nullable|string|max:191',
            'daily_calorie_goal' => 'nullable|integer|min:800|max:6000',
            'meals'              => 'required|array',
            'meals.*.type'       => 'required|in:breakfast,lunch,dinner,snack',
            'meals.*.name'       => 'required|string|max:191',
            'meals.*.calories'   => 'required|integer|min:0|max:5000',
        ]);

        $userId = (int) $request->user()->id;
        $mealPlan = DB::table('meal_plans')
            ->where('id', $id)
            ->where('user_id', $userId)
            ->first();

        if (!$mealPlan) {
            return response()->json([
                'success' => false,
                'message' => 'برنامه غذایی یافت نشد.',
            ], 404);
        }

        DB::transaction(function () use ($id, $validated, $mealPlan) {
            DB::table('meal_plans')->where('id', $id)->update([
                'title'              => $validated['title'] ?? $mealPlan->title,
                'daily_calorie_goal' => $validated['daily_calorie_goal'] ?? $mealPlan->daily_calorie_goal,
                'updated_at'         => now(),
            ]);

            DB::table('meal_plan_items')->where('meal_plan_id', $id)->delete();

            foreach ($validated['meals'] as $m) {
                DB::table('meal_plan_items')->insert([
                    'meal_plan_id' => $id,
                    'meal_type'    => $m['type'],
                    'food_name'    => $m['name'],
                    'calories'     => (int) $m['calories'],
                    'is_suggested' => !empty($m['is_suggested']) ? 1 : 0,
                    'created_at'   => now(),
                    'updated_at'   => now(),
                ]);
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'برنامه غذایی به‌روزرسانی شد.',
        ]);
    }

    /**
     * DELETE /api/user/meal-plans/{id}
     */
    public function destroy(Request $request, int $id)
    {
        $userId = (int) $request->user()->id;
        $plan = $this->resolveUserPlan($userId);

        $mealPlan = DB::table('meal_plans')
            ->where('id', $id)
            ->where('user_id', $userId)
            ->first();

        if (!$mealPlan) {
            return response()->json([
                'success' => false,
                'message' => 'برنامه غذایی یافت نشد.',
            ], 404);
        }

        $allowBasicDelete = (bool) ($mealPlan->allow_delete_on_basic ?? false);
        if ($plan->is_basic && !$allowBasicDelete) {
            return response()->json([
                'success' => false,
                'code'    => 'BASIC_PLAN_DELETE_FORBIDDEN',
                'message' => 'در پلن پایه امکان حذف برنامه غذایی وجود ندارد. می‌توانید برنامه خود را ویرایش کنید یا اشتراک را ارتقا دهید.',
            ], 403);
        }

        DB::table('meal_plans')
            ->where('id', $id)
            ->where('user_id', $userId)
            ->delete();

        return response()->json([
            'success' => true,
            'message' => 'برنامه غذایی حذف شد.',
        ]);
    }

    /**
     * POST /api/user/meal-plans/biometrics
     * به‌روزرسانی قد، وزن فعلی، وزن ایده‌آل و هدف کالری
     */
    public function updateBiometrics(Request $request)
    {
        $validated = $request->validate([
            'height'       => 'nullable|numeric|min:80|max:260',
            'weight'       => 'nullable|numeric|min:25|max:350',
            'ideal_weight' => 'nullable|numeric|min:30|max:250',
            'daily_goal'   => 'nullable|integer|min:800|max:6000',
        ]);

        $user = $request->user();
        $userId = (int) $user->id;

        $updateUser = [];
        if (array_key_exists('height', $validated)) {
            $updateUser['height'] = $validated['height'];
        }
        if (array_key_exists('weight', $validated)) {
            $updateUser['weight'] = $validated['weight'];
        }

        if (!empty($updateUser)) {
            DB::table('users')->where('id', $userId)->update($updateUser);
            foreach ($updateUser as $k => $v) {
                $user->{$k} = $v;
            }
        }

        $redisRaw = Redis::get($this->getHealthRedisKey($userId));
        $redisData = $redisRaw ? json_decode($redisRaw, true) : [];

        $idealWeight = array_key_exists('ideal_weight', $validated)
            ? $validated['ideal_weight']
            : ($redisData['idealWeight'] ?? null);

        $newGoal = isset($validated['daily_goal'])
            ? (int) $validated['daily_goal']
            : $this->calculateCalorieGoal($user, $idealWeight !== null ? (float) $idealWeight : null);

        $redisData['idealWeight'] = $idealWeight !== null ? (float) $idealWeight : null;
        $redisData['dailyGoal'] = $newGoal;
        Redis::set($this->getHealthRedisKey($userId), json_encode($redisData));

        return response()->json([
            'success' => true,
            'message' => 'اطلاعات قد، وزن و هدف کالری به‌روزرسانی شد.',
            'data'    => [
                'height'             => $user->height,
                'weight'             => $user->weight,
                'ideal_weight'       => $redisData['idealWeight'],
                'daily_calorie_goal' => $newGoal,
            ],
        ]);
    }

    /**
     * GET /api/user/meal-plans/daily-meals?date=YYYY-MM-DD
     */
    public function getDailyMeals(Request $request)
    {
        $date = $request->query('date', Carbon::now()->format('Y-m-d'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return response()->json([
                'success' => false,
                'message' => 'فرمت تاریخ نامعتبر است.',
            ], 422);
        }

        $userId = (int) $request->user()->id;

        $rows = DB::table('daily_meal_logs')
            ->where('user_id', $userId)
            ->where('log_date', $date)
            ->orderBy('id')
            ->get()
            ->map(function ($r) {
                return [
                    'id'        => (int) $r->id,
                    'type'      => $r->meal_type,
                    'name'      => $r->food_name,
                    'calories'  => (int) $r->calories,
                    'createdAt' => $r->created_at,
                ];
            })
            ->values();

        return response()->json([
            'success' => true,
            'data'    => [
                'date'           => $date,
                'meals'          => $rows,
                'total_calories' => (int) $rows->sum('calories'),
            ],
        ]);
    }

    /**
     * POST /api/user/meal-plans/daily-meals
     */
    public function storeDailyMeal(Request $request)
    {
        $validated = $request->validate([
            'log_date' => 'nullable|date_format:Y-m-d',
            'type'     => 'required|in:breakfast,lunch,dinner,snack',
            'name'     => 'nullable|string|max:191',
            'calories' => 'required|integer|min:1|max:5000',
        ]);

        $userId = (int) $request->user()->id;
        $date = $validated['log_date'] ?? Carbon::now()->format('Y-m-d');

        $labels = [
            'breakfast' => 'صبحانه',
            'lunch'     => 'ناهار',
            'dinner'    => 'شام',
            'snack'     => 'میان‌وعده',
        ];

        $foodName = trim((string) ($validated['name'] ?? '')) ?: ($labels[$validated['type']] ?? 'وعده غذایی');

        $id = DB::table('daily_meal_logs')->insertGetId([
            'user_id'    => $userId,
            'log_date'   => $date,
            'meal_type'  => $validated['type'],
            'food_name'  => $foodName,
            'calories'   => (int) $validated['calories'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'وعده روزانه ثبت شد.',
            'data'    => [
                'id'        => (int) $id,
                'type'      => $validated['type'],
                'name'      => $foodName,
                'calories'  => (int) $validated['calories'],
                'createdAt' => now()->toDateTimeString(),
            ],
        ], 201);
    }

    /**
     * DELETE /api/user/meal-plans/daily-meals/{id}
     */
    public function destroyDailyMeal(Request $request, int $id)
    {
        $userId = (int) $request->user()->id;
        $deleted = DB::table('daily_meal_logs')
            ->where('id', $id)
            ->where('user_id', $userId)
            ->delete();

        if (!$deleted) {
            return response()->json([
                'success' => false,
                'message' => 'وعده یافت نشد.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'وعده حذف شد.',
        ]);
    }
}
