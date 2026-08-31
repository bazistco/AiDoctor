<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class UserController extends Controller
{

      public function updateProfile(Request $request)
    {
        try {
            $userId = $request->user()->id;

            // ۱. اعتبارسنجی بر اساس فیلدهای ارسالی فرانت
            $validator = Validator::make($request->all(), [
                'first_name' => 'required|string|max:100',
                'last_name' => 'nullable|string|max:100',
                'gender' => 'required|in:0,1', // 0: male, 1: female
                'age' => 'nullable|integer|min:1|max:120',
                'weight' => 'nullable|numeric|min:10|max:500',
                'height' => 'nullable|numeric|min:50|max:250',
                 'province'   => 'nullable|integer|min:1|max:31',   // اضافه شد
                'city'       => 'nullable|integer|min:1',   
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'داده‌های ورودی معتبر نیستند',
                    'errors' => $validator->errors()
                ], 422);
            }

            DB::beginTransaction();

            // ۲. ترکیب نام و نام خانوادگی برای ذخیره در فیلد name جدول users
            // در کد شما نام به صورت "نام-نام خانوادگی" در فرانت هند استفاده شده است
            $fullName = trim($request->first_name . ' ' . $request->last_name);

            DB::table('users')->where('id', $userId)->update([
                'province_id'   => $request->province ?? 1,  // اضافه شد
                'city_id'       => $request->city ?? 1,       // اضافه شد
                'name' => $fullName,
                'gender' => $request->gender, // در دیتابیس شما احتمالا gender در یوزر است
                'updated_at' => now()
            ]);

            // ۳. بروزرسانی اطلاعات فیزیکی در جدول user_profiles
            DB::table('user_profiles')->updateOrInsert(
                ['user_id' => $userId],
                [
                    'age' => $request->age?? rand(18, 65),
                    'weight' => $request->weight??rand(50, 120),
                    'height' => $request->height??rand(150, 195),
                    'updated_at' => now()
                ]
            );

            DB::commit();
            // ==========================================
            // 🔸 ارسال پیام خوش‌آمدگویی در Novu
            // ==========================================
            $dbUser = DB::table('users')->where('id', $userId)->first(['novu_subscriber_id']);

            if ($dbUser && !empty($dbUser->novu_subscriber_id)) {
                try {
                    $novuUrl = 'http://185.222.163.113:3000/v1' . '/events/trigger';
                    $novuApiKey = '9bf460e9cafb98ca32e7da42e36a5217';

                    Http::withHeaders([
                        'Authorization' => 'ApiKey ' . $novuApiKey,
                        'Content-Type' => 'application/json',
                    ])->post($novuUrl, [
                        'name' => 'welcome-msg',
                        'to' => [
                            'subscriberId' => $dbUser->novu_subscriber_id
                        ],
                        'payload' => [
                            'title' => 'به مدیران خوش آمدید',
                            'msg'   => "کاربر گرامی {$fullName} عزیز، اطلاعات پروفایل شما با موفقیت تکمیل شد.",
                            'link'  => 'http://mediraai.com' // لینک دلخواه برای هدایت کاربر
                        ]
                    ]);
                } catch (\Exception $e) {
                    // ثبت در لاگ تا اگر Novu قطع بود، کاربر ارور پروفایل نبیند
                    Log::error('Novu Trigger Error (welcome-msg): ' . $e->getMessage());
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'پروفایل با موفقیت ذخیره شد'
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'خطا در ذخیره اطلاعات',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getProfile(Request $request)
    {
        try {
            $userId = $request->user()->id;

            $user = DB::table('users')
                ->leftJoin('user_plans', 'users.id', '=', 'user_plans.user_id')
                ->leftJoin('user_profiles', 'users.id', '=', 'user_profiles.user_id')
                ->select(
                    'users.id',
                    'users.status',
                    'users.name',
                    'users.role',
                    'users.email',
                    'users.phone',
                    'users.gender',
                    'users.avatar',
                    'users.novu_subscriber_id',
                    'users.created_at',
                    'user_plans.plan_type',
                    'user_plans.start_date',
                    'user_plans.end_date',
                    'user_plans.is_active as plan_is_active',
                    'user_plans.auto_renew',
                    'user_plans.next_billing_date',
                    'user_profiles.height',
                    'user_profiles.weight',
                    'user_profiles.age',
                    'user_profiles.birth_date',
                    'users.city_id',
                    'users.province_id',
                    'user_profiles.address',
                    'user_profiles.postal_code',
                    'user_profiles.blood_type',
                    'user_profiles.emergency_contact',
                    'user_profiles.medical_conditions',
                    'user_profiles.allergies',
                    'user_profiles.medications'
                )
                ->where('users.id', $userId)
                ->first();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'کاربر یافت نشد'
                ], 404);
            }
               if ($user->status == 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'شما مجاز نیستید'
                ], 403);
            }

            // اگر پلن نداشت، پلن پایه بساز
            if (!$user->plan_type) {
                DB::table('user_plans')->insert([
                    'user_id' => $userId,
                    'plan_type' => 'basic',
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
                $user->plan_type = 'basic';
                $user->plan_is_active = true;
            }

            // اگر پروفایل نداشت، پروفایل پایه بساز
            if (!$user->height && !$user->weight && !$user->age) {
                 $genders = ['male', 'female'];
                DB::table('user_profiles')->insert([
                    'user_id' => $userId,
                    'created_at' => now(),
                    'updated_at' => now(),
                    'age' => rand(18, 65),
                'weight' => rand(50, 120),
                'height' => rand(150, 195),
                'gender' => $genders[rand(0, 1)],
                'birth_date' => now()
                ]);
            }

            // محاسبه BMI
            $bmi = null;
            if ($user->height && $user->weight) {
                $heightInMeters = $user->height / 100;
                $bmi = round($user->weight / ($heightInMeters * $heightInMeters), 2);
            }
            $isVerify = !empty($user->name) && in_array($user->gender, [0, 1], true);
   
            return response()->json([
                'success' => true,
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'role'=>$user->role,
                        'phone' => $user->phone,
                        'gender' => $user->gender,
                        'avatar' => $user->avatar,
                        'novu_subscriber_id' => $user->novu_subscriber_id,
                          'is_verify' => $isVerify,
                        'created_at' => $user->created_at,
                        'height' => $user->height,
                        'weight' => $user->weight,
                        'age' => $user->age,
                        'birth_date' => $user->birth_date,
                        'city' => $user->city_id,
                        'province' => $user->province_id,
                        'address' => $user->address,
                        'postal_code' => $user->postal_code,
                        'blood_type' => $user->blood_type,
                        'emergency_contact' => $user->emergency_contact,
                        'medical_conditions' => $user->medical_conditions,
                        'allergies' => $user->allergies,
                        'medications' => $user->medications,
                        'bmi' => $bmi
                    ],
                    'plan' => [
                        'type' => $user->plan_type,
                        'is_active' => (bool)$user->plan_is_active,
                        'start_date' => $user->start_date,
                        'end_date' => $user->end_date,
                        'auto_renew' => (bool)$user->auto_renew,
                        'next_billing_date' => $user->next_billing_date
                    ]
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'خطا در دریافت اطلاعات کاربر',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    /**
     * @route POST /api/user/change-plan
     * @description تغییر پلن کاربر
     * @body {
     *   "plan_type": "pro|premium",
     *   "payment_method": "online|wallet",
     *   "auto_renew": true|false
     * }
     */
    public function changePlan(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'plan_type' => 'required|in:pro,premium',
                'payment_method' => 'nullable|string|max:50',
                'auto_renew' => 'nullable|boolean'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'داده‌های ورودی نامعتبر',
                    'errors' => $validator->errors()
                ], 422);
            }

            $userId = $request->user()->id;
            $newPlanType = $request->plan_type;
            $paymentMethod = $request->payment_method ?? 'online';
            $autoRenew = $request->auto_renew ?? false;

            // دریافت پلن فعلی
            $currentPlan = DB::table('user_plans')
                ->where('user_id', $userId)
                ->first();

            $oldPlanType = $currentPlan ? $currentPlan->plan_type : 'basic';

            // محاسبه قیمت
            $prices = [
                'pro' => 299000,
                'premium' => 599000
            ];
            $amount = $prices[$newPlanType] ?? 0;

            // شروع تراکنش
            DB::beginTransaction();

            try {
                // ثبت تراکنش
                $transactionId = 'TXN_' . time() . '_' . $userId;
                DB::table('plan_transactions')->insert([
                    'user_id' => $userId,
                    'plan_type' => $newPlanType,
                    'amount' => $amount,
                    'currency' => 'IRR',
                    'status' => 'completed',
                    'transaction_id' => $transactionId,
                    'payment_gateway' => $paymentMethod,
                    'payment_date' => now(),
                    'created_at' => now()
                ]);

                // محاسبه تاریخ پایان (30 روز)
                $endDate = date('Y-m-d H:i:s', strtotime('+30 days'));
                $nextBillingDate = $autoRenew ? $endDate : null;

                // به‌روزرسانی یا ایجاد پلن
                if ($currentPlan) {
                    DB::table('user_plans')
                        ->where('user_id', $userId)
                        ->update([
                            'plan_type' => $newPlanType,
                            'end_date' => $endDate,
                            'is_active' => true,
                            'auto_renew' => $autoRenew,
                            'payment_method' => $paymentMethod,
                            'last_payment_date' => now(),
                            'next_billing_date' => $nextBillingDate,
                            'updated_at' => now()
                        ]);
                } else {
                    DB::table('user_plans')->insert([
                        'user_id' => $userId,
                        'plan_type' => $newPlanType,
                        'start_date' => now(),
                        'end_date' => $endDate,
                        'is_active' => true,
                        'auto_renew' => $autoRenew,
                        'payment_method' => $paymentMethod,
                        'last_payment_date' => now(),
                        'next_billing_date' => $nextBillingDate,
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);
                }

                // ثبت تاریخچه
                DB::table('plan_history')->insert([
                    'user_id' => $userId,
                    'old_plan' => $oldPlanType,
                    'new_plan' => $newPlanType,
                    'change_reason' => 'ارتقا پلن توسط کاربر',
                    'changed_by' => $userId,
                    'changed_at' => now()
                ]);

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => 'پلن شما با موفقیت تغییر یافت',
                    'data' => [
                        'plan_type' => $newPlanType,
                        'end_date' => $endDate,
                        'transaction_id' => $transactionId,
                        'amount' => $amount
                    ]
                ], 200);

            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'خطا در تغییر پلن',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * @route GET /api/user/plan-history
     * @description دریافت تاریخچه تغییرات پلن
     */
    public function getPlanHistory(Request $request)
    {
        try {
            $userId = $request->user()->id;

            $history = DB::table('plan_history')
                ->where('user_id', $userId)
                ->orderBy('changed_at', 'desc')
                ->limit(20)
                ->get();

            return response()->json([
                'success' => true,
                'data' => $history
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'خطا در دریافت تاریخچه',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * @route POST /api/user/cancel-plan
     * @description لغو پلن و بازگشت به پلن پایه
     */
    public function cancelPlan(Request $request)
    {
        try {
            $userId = $request->user()->id;

            $currentPlan = DB::table('user_plans')
                ->where('user_id', $userId)
                ->first();

            if (!$currentPlan || $currentPlan->plan_type === 'basic') {
                return response()->json([
                    'success' => false,
                    'message' => 'شما در حال حاضر از پلن پایه استفاده می‌کنید'
                ], 400);
            }

            DB::beginTransaction();

            try {
                // به‌روزرسانی به پلن پایه
                DB::table('user_plans')
                    ->where('user_id', $userId)
                    ->update([
                        'plan_type' => 'basic',
                        'auto_renew' => false,
                        'next_billing_date' => null,
                        'updated_at' => now()
                    ]);

                // ثبت تاریخچه
                DB::table('plan_history')->insert([
                    'user_id' => $userId,
                    'old_plan' => $currentPlan->plan_type,
                    'new_plan' => 'basic',
                    'change_reason' => 'لغو پلن توسط کاربر',
                    'changed_by' => $userId,
                    'changed_at' => now()
                ]);

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => 'پلن شما با موفقیت لغو شد'
                ], 200);

            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'خطا در لغو پلن',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
