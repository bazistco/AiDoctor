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
        $plans = DB::table('doctor_plans')->where('status', 1)->orderBy('tier_level', 'asc')->get();
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

        DB::beginTransaction();
        try {
            // ۲. بررسی وجود اشتراک قبلی و مدیریت آن
            $existingSub = DB::table('doctor_subscriptions')->where('doctor_id', $doctorId)->first();

            if ($existingSub) {
                $subId = $existingSub->id;
                $transactionType = 'renewal'; // از نوع تمدید یا ارتقا
            } else {
                // اگر اشتراکی ندارد، یک اشتراک جدید ولی "غیرفعال (0)" می‌سازیم
                $subId = DB::table('doctor_subscriptions')->insertGetId([
                    'doctor_id'  => $doctorId,
                    'plan_id'    => $plan->id,
                    'status'     => 0, // در انتظار پرداخت
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $transactionType = 'purchase'; // از نوع خرید جدید
            }

            // ۳. ایجاد سفارش مالی (reason_id = 7) و رفرنس (شناسه اشتراک)
            $orderResult = $orderService->createOrReuse(
                userId: $doctorId,
                reasonId: 7,
                reasonRef: $subId,
                amount: $plan->price,
                description: "خرید/ارتقای پلن VIP - {$plan->name}"
            );

            $orderId = $orderResult['order_id'];

            // ۴. ثبت تاریخچه خرید با وضعیت در انتظار (0)
            // برای جلوگیری از رکورد تکراری، اگر سفارشی از قبل مانده پاک می‌کنیم
            DB::table('doctor_plan_purchase_histories')
                ->where('transaction_id', (string)$orderId)
                ->delete();

            DB::table('doctor_plan_purchase_histories')->insert([
                'doctor_id'        => $doctorId,
                'plan_id'          => $plan->id,
                'subscription_id'  => $subId,
                'plan_name'        => $plan->name,
                'plan_slug'        => $plan->slug ?? null,
                'original_price'   => $plan->price,
                'paid_price'       => $plan->price,
                'duration_days'    => $plan->duration_days ?? 30,
                'payment_status'   => 0, // وضعیت: در انتظار پرداخت
                'transaction_type' => $transactionType,
                'transaction_id'   => (string)$orderId, // اتصال تاریخچه به سفارش
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);

            // ۵. دریافت لینک از درگاه پرداخت
            $callbackUrl = config('payment.saman.callback_url', 'https://api.mediraai.com/api/pg/call_back');

            $paymentResult = $paymentService->initiate(
                orderId:     $orderId,
                userId:      $doctorId,
                callbackUrl: $callbackUrl
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
            ], 200);

        } catch (Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'خطا در ایجاد سفارش پلن: ' . $e->getMessage()
            ], 500);
        }
    }


}
