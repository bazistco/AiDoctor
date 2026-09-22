<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Payment\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class PaymentGatewayController extends Controller
{
    public function __construct(
        protected PaymentService $paymentService
    ) {}

    /**
     * وب‌سرویس ۲: دریافت یا ایجاد اطلاعات درگاه بانکی برای سفارش
     */
    public function initiatePayment(Request $request)
    {
        $validated = $request->validate([
            'order_id' => 'required|integer|exists:orders,id',
            'gateway'  => 'nullable|string|in:saman,zarinpal',
        ]);

        $orderId = $validated['order_id'];
        $userId  = $request->user()->id;

        // ۱. بررسی مالکیت و وضعیت سفارش
        $order = DB::table('orders')
            ->where('id', $orderId)
            ->where('user_id', $userId)
            ->first();

        if (!$order) {
            return response()->json(['success' => false, 'message' => 'سفارش یافت نشد یا متعلق به شما نیست.'], 404);
        }

        if ((int) $order->status !== 1) { // 1 = Pending
            $statusText = match((int)$order->status) {
                2 => 'این سفارش قبلاً پرداخت شده است.',
                3 => 'این سفارش منقضی یا لغو شده است.',
                4 => 'این سفارش مسترد شده است.',
                default => 'وضعیت سفارش نامعتبر است.'
            };
            return response()->json(['success' => false, 'message' => $statusText], 422);
        }

        // ۲. بررسی مهلت زمانی نوبت پزشک (reason_id == 1)
        if ((int) $order->reason_id === 1 && !empty($order->reason_ref)) {
            $slotId = $order->reason_ref;
            $reservationKey = "slot:reservation:{$slotId}";

            if (!Redis::exists($reservationKey)) {
                return response()->json([
                    'success' => false,
                    'message' => 'مهلت زمانی رزرو موقت این نوبت (۱۵ دقیقه) به پایان رسیده است. لطفاً مجدداً نوبت را رزرو کنید.'
                ], 410); // 410 Gone
            }
        }

        // ۳. بررسی اعتبار درخواست آزمایشگاه (reason_id == 5)
        if ((int) $order->reason_id === 5 && !empty($order->reason_ref)) {
            $labRequestId = $order->reason_ref;
            $labRequest = DB::table('users_labs_requests')->where('id', $labRequestId)->first();

            // اگر درخواست پیدا نشد یا از وضعیت در انتظار پرداخت (1) خارج شده بود
            if (!$labRequest || (int)$labRequest->status !== 1) {

                // اگر سفارش هنوز Pending (1) است، آن را لغو می‌کنیم
                if ((int) $order->status === 1) {
                    DB::table('orders')->where('id', $orderId)->update([
                        'status' => 3, // Cancelled
                        'cancelled_at' => now(),
                        'updated_at' => now()
                    ]);
                }

                return response()->json([
                    'success' => false,
                    'message' => 'زمان پرداخت این درخواست منقضی شده یا وضعیت آن تغییر کرده است.'
                ], 410);
            }
        }

        try {
            // ۴. فراخوانی PaymentService
            $callbackUrl = 'https://mediraai.com/api/pg/call_back';
            $cellNumber  = $request->user()->phone ?? null;

            $paymentData = $this->paymentService->initiate(
                orderId:     $orderId,
                userId:      $userId,
                callbackUrl: $callbackUrl,
                cellNumber:  $cellNumber
            );

            // ۵. ذخیره اطلاعات پرداخت در قفل ردیس نوبت (اختیاری جهت تطبیق سریع)
            if ((int) $order->reason_id === 1) {
                $existingLock = Redis::get("slot:reservation:{$order->reason_ref}");
                if ($existingLock) {
                    $decoded = json_decode($existingLock, true);
                    $decoded['payment_id'] = $paymentData['payment_id'];
                    $decoded['authority']  = $paymentData['res_num'];
                    $ttl = Redis::ttl("slot:reservation:{$order->reason_ref}");
                    if ($ttl > 0) {
                        Redis::setex("slot:reservation:{$order->reason_ref}", $ttl, json_encode($decoded));
                    }
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'اطلاعات درگاه پرداخت با موفقیت آماده شد.',
                'data'    => [
                    'order_id'    => $orderId,
                    'payment_id'  => $paymentData['payment_id'],
                    'token'       => $paymentData['token'] ?? null,
                    'res_num'     => $paymentData['res_num'],
                    'amount'      => $order->amount,
                    'gateway'     => 'saman',
                    'payment_url' => $paymentData['payment_url'],
                ]
            ], 200);

        } catch (\Throwable $e) {
            Log::error('[PaymentGatewayController] Initiate failed', [
                'order_id' => $orderId,
                'user_id'  => $userId,
                'error'    => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'خطا در برقراری ارتباط با درگاه بانکی: ' . $e->getMessage()
            ], 500);
        }
    }
}

