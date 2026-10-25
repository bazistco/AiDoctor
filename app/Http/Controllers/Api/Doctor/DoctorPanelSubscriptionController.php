<?php

namespace App\Http\Controllers\Api\Doctor;

use App\Http\Controllers\Controller;
use App\Services\Payment\OrderService;
use App\Services\Payment\PaymentService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DoctorPanelSubscriptionController extends Controller
{
    public function getPlans()
    {
        $plans = DB::table('doctor_plans')->where('status', 1)->orderBy('tier_level', 'asc')->get()
            ->map(function ($plan) {
                $plan->price = $plan->price / 10;
                return $plan;
            });
        return response()->json(['data' => $plans]);
    }

    public function getMyPlan()
    {
        $doctorId = auth()->id();

        // دریافت آخرین پلن فعال پزشک به همراه ضریب (multiplier)
        $subscription = DB::table('doctor_subscriptions')
            ->join('doctor_plans', 'doctor_subscriptions.plan_id', '=', 'doctor_plans.id')
            ->where('doctor_subscriptions.doctor_id', $doctorId)
            ->where('doctor_subscriptions.status', 1)
            ->where('doctor_subscriptions.expires_at', '>', Carbon::now())
            ->select(
                'doctor_subscriptions.*',
                'doctor_plans.name as plan_name',
                'doctor_plans.tier_level',
                'doctor_plans.description',
                'doctor_plans.multiplier' // <- این فیلد اضافه شد
            )
            ->orderBy('doctor_subscriptions.id', 'desc')
            ->first();

        return response()->json(['data' => $subscription]);
    }

    public function subscribeToPlan(Request $request, OrderService $orderService, PaymentService $paymentService)
    {
        $request->validate(['plan_id' => 'required|integer']);
        $doctorId = $request->user()->id;

        // ۱. دریافت اطلاعات پلن درخواستی
        $plan = DB::table('doctor_plans')->where('id', $request->plan_id)->where('status', 1)->first();
        if (!$plan) {
            return response()->json(['success' => false, 'message' => 'پلن نامعتبر است.'], 404);
        }

        $amount = (int) $plan->price;
        $duration = (int) ($plan->duration_days ?? 360);

        // گارد ضد کلیک دوباره (۱ درخواست در هر ۳ ثانیه)
        $lock = \Illuminate\Support\Facades\Cache::add("sub:lock:{$doctorId}", 1, 5);
        if (! $lock) {
            return response()->json(['success' => false, 'message' => 'درخواست شما در حال پردازش است.'], 429);
        }

        DB::beginTransaction();
        try {
            // ═══════════════════════════════════════════════════════
            // ۲. کنسل کردن همه سفارش‌ها و پرداخت‌های معلق قبلی برای reason_id=7
            // ═══════════════════════════════════════════════════════
            $this->cancelPendingPlanAttempts($doctorId, $orderService);

            // ═══════════════════════════════════════════════════════
            // ۳. ایجاد/بروزرسانی اشتراک (اگر نبود ساخته می‌شود، اگر بود دست نمی‌زند)
            // ═══════════════════════════════════════════════════════
            $existingSub = DB::table('doctor_subscriptions')->where('doctor_id', $doctorId)->first();

            if ($existingSub) {
                $subId = (int) $existingSub->id;
                $transactionType = 'renewal';
            } else {
                $subId = DB::table('doctor_subscriptions')->insertGetId([
                    'doctor_id'     => $doctorId,
                    'plan_id'       => $plan->id,      // هرچند بعداً در کالبک بازنویسی می‌شود
                    'duration_days' => $duration,
                    'status'        => 0,
                    'expires_at'    => now()->addDays($duration),
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ]);
                $transactionType = 'purchase';
            }

            // ═══════════════════════════════════════════════════════
            // ۴. ساخت history قبل از ساخت Order (کلید یکتا = history_id)
            // ═══════════════════════════════════════════════════════
            $historyId = DB::table('doctor_plan_purchase_histories')->insertGetId([
                'doctor_id'        => $doctorId,
                'plan_id'          => $plan->id,
                'subscription_id'  => $subId,
                'plan_name'        => $plan->name,
                'plan_slug'        => $plan->slug ?? null,
                'original_price'   => $plan->price,
                'paid_price'       => $plan->price,
                'duration_days'    => $duration,
                'payment_status'   => 0, // در انتظار پرداخت
                'transaction_type' => $transactionType,
                'transaction_id'   => null, // بعد از ساخت order پر می‌شود
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);

            // ═══════════════════════════════════════════════════════
            // ۵. ساخت سفارش با reason_ref = history_id (نه subscription_id)
            // ═══════════════════════════════════════════════════════
            $orderResult = $orderService->createOrReuse(
                userId:    $doctorId,
                reasonId:  7,
                reasonRef: $historyId,      // ← مهم‌ترین تغییر
                amount:    $amount,
                description: "خرید/ارتقای پلن VIP - {$plan->name}"
            );

            $orderId = (int) $orderResult['order_id'];

            DB::table('doctor_plan_purchase_histories')
                ->where('id', $historyId)
                ->update([
                    'transaction_id' => (string) $orderId,
                    'updated_at'     => now(),
                ]);

            // ═══════════════════════════════════════════════════════
            // ۶. دریافت لینک پرداخت
            // ═══════════════════════════════════════════════════════
            $paymentResult = $paymentService->initiate(
                orderId:     $orderId,
                userId:      $doctorId,
                callbackUrl: 'https://mediraai.com/api/pg/call_back'
            );

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'در حال انتقال به درگاه پرداخت...',
                'data' => [
                    'order_id'    => $orderId,
                    'payment_id'  => $paymentResult['payment_id'],
                    'payment_url' => $paymentResult['payment_url'],
                ]
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            \Illuminate\Support\Facades\Cache::forget("sub:lock:{$doctorId}");
            return response()->json([
                'success' => false,
                'message' => 'خطا در ایجاد سفارش پلن: ' . $e->getMessage()
            ], 500);
        }
    }

    private function cancelPendingPlanAttempts(int $doctorId, OrderService $orderService): void
    {
        // ۱. همه سفارش‌های معلق reason_id=7 این پزشک را پیدا می‌کنیم
        $pendingOrders = DB::table('orders')
            ->where('user_id', $doctorId)
            ->where('reason_id', 7)
            ->where('status', OrderService::STATUS_PENDING)
            ->get(['id', 'reason_ref']);

        if ($pendingOrders->isEmpty()) {
            return; // چیزی برای کنسل کردن نیست
        }

        foreach ($pendingOrders as $order) {
            // ۲. پرداخت‌های معلق مربوط به این سفارش را FAIL می‌کنیم
            DB::table('payments')
                ->where('order_id', $order->id)
                ->where('status', PaymentService::PAY_PENDING)
                ->update([
                    'status'     => PaymentService::PAY_FAILED,
                    'last_error' => 'سفارش قبلی توسط درخواست جدید لغو شد.',
                    'updated_at' => now(),
                ]);

            // ۳. تاریخچه‌ی معلق متصل به این سفارش راکنسل می‌کنیم تا در کالبک دوباره فعال نشود
            DB::table('doctor_plan_purchase_histories')
                ->where('transaction_id', (string) $order->id)
                ->where('payment_status', 0)
                ->update([
                    'payment_status' => 3, // 3 = لغو شده
                    'updated_at'     => now(),
                ]);

            // ۴. خود سفارش را از طریق State Machine به CANCELLED می‌بریم
            // (transition خودش تراکنش لازم ندارد، اما اینجا داخل تراکنش subscribe هستیم)
            try {
                $orderService->transition(
                    (int) $order->id,
                    OrderService::STATUS_CANCELLED
                );
            } catch (\Throwable $e) {
                // اگر State Machine اجازه نداد، حداقل مستقیم ست کنیم
                DB::table('orders')
                    ->where('id', $order->id)
                    ->where('status', OrderService::STATUS_PENDING)
                    ->update([
                        'status'      => OrderService::STATUS_CANCELLED,
                        'updated_at'  => now(),
                    ]);
            }
        }
    }

}
