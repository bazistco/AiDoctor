<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Payment\PaymentService;
use Carbon\Carbon;
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
        if ((int) $order->reason_id === 6 && !empty($order->reason_ref)) {
            $pharReqId = $order->reason_ref;
            $pharReq = DB::table('users_pharmacy_requests')->where('id', $pharReqId)->first();

            // بررسی منقضی شدن درخواست (گذشت بیش از ۳۰ دقیقه از زمان ایجاد)
            $isExpired = false;
            if ($pharReq && $pharReq->created_at) {
                // آیا زمان ایجاد درخواست بیش از ۳۰ دقیقه با زمان فعلی فاصله دارد؟
                $isExpired = Carbon::parse($pharReq->created_at)->addMinutes(30)->isPast();
                // یا به شکل معادل: Carbon::parse($pharReq->created_at)->diffInMinutes(now()) >= 30;
            }

            // اگر درخواست وجود نداشت، وضعیت آن ۱ نبود، یا ۳۰ دقیقه گذشته بود
            if (!$pharReq || (int)$pharReq->status !== 1 || $isExpired) {

                // اگر سفارش هنوز در انتظار پرداخت (1) است، آن را لغو می‌کنیم
                if ((int) $order->status === 1) {
                    DB::table('orders')->where('id', $orderId)->update([
                        'status'       => 3, // Cancelled
                        'cancelled_at' => now(),
                        'updated_at'   => now()
                    ]);
                }

                // اگر درخواست داروخانه پیدا شده بود ولی منقضی شده، وضعیت خود درخواست داروخانه را هم لغو/منقضی می‌کنیم
                if ($pharReq && (int)$pharReq->status === 1 && $isExpired) {
                    DB::table('users_pharmacy_requests')->where('id', $pharReqId)->update([
                        'status'     => 7, // فرض: وضعیت ۴ یا وضعیت متناظر با Cancelled/Expired در سیستم شما
                        'updated_at' => now()
                    ]);
                }

                $message = $isExpired
                    ? 'مهلت ۳۰ دقیقه‌ای پرداخت این درخواست به پایان رسیده است.'
                    : 'این درخواست نامعتبر است یا وضعیت آن تغییر کرده است.';

                return response()->json([
                    'success' => false,
                    'message' => $message
                ], 410);
            }
        }
        if ((int) $order->reason_id === 4 && !empty($order->reason_ref)) {
            $medRequestId = $order->reason_ref;
            $medRequest = DB::table('user_medical_center_requests')->where('id', $medRequestId)->first();

            // بررسی منقضی شدن درخواست (گذشت بیش از ۳۰ دقیقه از زمان ایجاد)
            $isExpired = false;
            if ($medRequest && $medRequest->created_at) {
                $isExpired = Carbon::parse($medRequest->created_at)->addMinutes(30)->isPast();
            }

            // اگر درخواست وجود نداشت، وضعیت ۱ نبود، یا ۳۰ دقیقه منقضی شده بود
            if (!$medRequest || (int)$medRequest->status !== 0 || $isExpired) {

                // اگر سفارش هنوز در انتظار پرداخت (Pending = 1) است، آن را لغو می‌کنیم
                if ((int) $order->status === 1) {
                    DB::table('orders')->where('id', $orderId)->update([
                        'status'       => 3, // Cancelled
                        'cancelled_at' => now(),
                        'updated_at'   => now()
                    ]);
                }

                // در صورت انقضای زمان، خود رکورد درخواست مرکز درمانی را هم لغو/منقضی می‌کنیم
                if ($medRequest && (int)$medRequest->status === 0 && $isExpired) {
                    DB::table('user_medical_center_requests')->where('id', $medRequestId)->update([
                        'status'     => 5, // وضعیت معادل لغو شده / منقضی شده در سیستم شما
                        'updated_at' => now()
                    ]);
                }

                $message = $isExpired
                    ? 'مهلت ۳۰ دقیقه‌ای پرداخت این درخواست به پایان رسیده است.'
                    : 'زمان پرداخت این درخواست منقضی شده یا وضعیت آن تغییر کرده است.';

                return response()->json([
                    'success' => false,
                    'message' => $message
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

