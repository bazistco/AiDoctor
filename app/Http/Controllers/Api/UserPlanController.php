<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Payment\OrderService;
use App\Services\Payment\PaymentService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserPlanController extends Controller
{
    /**
     * ۱. دریافت لیست پلن‌های قابل خرید
     */
    public function getPlans()
    {
        $plans = DB::table('subscription_plans')->where('status', 1)->get();
        return response()->json(['success' => true, 'data' => $plans]);
    }

    /**
     * ۲. وضعیت پلن فعلی کاربر
     */
    public function currentPlan(Request $request)
    {
        $userId = $request->user()->id;

        $userPlan = DB::table('user_plans')
            ->join('subscription_plans', 'user_plans.plan_id', '=', 'subscription_plans.id')
            ->where('user_plans.user_id', $userId)
            ->where('user_plans.is_active', 1)
            ->where(function($query) {
                $query->whereNull('user_plans.end_date')
                    ->orWhere('user_plans.end_date', '>', now());
            })
            ->select('user_plans.*', 'subscription_plans.name as plan_name', 'subscription_plans.slug')
            ->first();

        return response()->json([
            'success' => true,
            'data' => $userPlan ? $userPlan : null,
            'message' => $userPlan ? 'پلن فعال یافت شد' : 'شما پلن فعالی ندارید'
        ]);
    }

    /**
     * ۳. ایجاد درخواست خرید پلن و انتقال به درگاه
     */
    public function purchasePlan(Request $request, OrderService $orderService, PaymentService $paymentService)
    {
        $request->validate([
            'plan_id' => 'required|integer|exists:subscription_plans,id',
            'gateway' => 'nullable|string|in:saman,zarinpal'
        ]);

        $userId = $request->user()->id;
        $gateway = $request->input('gateway', 'saman');

        // اطلاعات پلن
        $plan = DB::table('subscription_plans')->where('id', $request->plan_id)->where('status', 1)->first();
        if (!$plan) {
            return response()->json(['success' => false, 'message' => 'پلن انتخابی نامعتبر یا غیرفعال است.'], 404);
        }

        DB::beginTransaction();
        try {
            // ایجاد یک رکورد معلق در تاریخچه به عنوان پیش‌فاکتور
            $historyId = DB::table('plan_history')->insertGetId([
                'user_id'        => $userId,
                'plan_id'        => $plan->id,
                'paid_price'     => $plan->price,
                'payment_status' => 0, // در انتظار پرداخت
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);

            // ایجاد سفارش در سیستم مالی (reason_id = 9 برای خرید پلن کاربر)
            $orderResult = $orderService->createOrReuse(
                userId: $userId,
                reasonId: 9,
                reasonRef: $historyId, // متصل به شناسه تاریخچه
                amount: 15000,
                description: "خرید اشتراک - پلن {$plan->name}"
            );

            // اتصال سفارش به تاریخچه
            DB::table('plan_history')->where('id', $historyId)->update([
                'transaction_id' => $orderResult['order_id']
            ]);

            // تولید لینک درگاه
            $callbackUrl = 'https://mediraai.com/api/pg/call_back';

            $paymentResult = $paymentService->initiate(
                orderId: $orderResult['order_id'],
                userId: $userId,
                callbackUrl: $callbackUrl
            );

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'در حال انتقال به درگاه پرداخت...',
                'data' => [
                    'order_id'    => $orderResult['order_id'],
                    'payment_url' => $paymentResult['payment_url'],
                ]
            ]);

        } catch (Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'خطا در ایجاد فاکتور: ' . $e->getMessage()], 500);
        }
    }

    /**
     * ۴. تاریخچه خریدهای موفق
     */
    public function getHistory(Request $request)
    {
        $userId = $request->user()->id;

        $history = DB::table('plan_history')
            ->join('subscription_plans', 'plan_history.plan_id', '=', 'subscription_plans.id')
            ->where('plan_history.user_id', $userId)
            ->select(
                'plan_history.id',
                'plan_history.paid_price',
                'plan_history.payment_status',
                'plan_history.created_at',
                'subscription_plans.name as plan_name',
                'subscription_plans.duration_days'
            )
            ->orderBy('plan_history.created_at', 'desc')
            ->get();

        return response()->json(['success' => true, 'data' => $history]);
    }
}
