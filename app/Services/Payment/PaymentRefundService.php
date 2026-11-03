<?php

namespace App\Services\Payment;

use App\Services\FinancialService;
use App\Services\Payment\OrderService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class PaymentRefundService
{
    public const PAY_PENDING  = 1;
    public const PAY_PAID     = 2;
    public const PAY_FAILED   = 3;
    public const PAY_REFUNDED = 5;

    public function __construct(
        protected OrderService $orderService,
        protected FinancialService $financialService
    ) {}

    /**
     * استرداد کامل وجه پرداخت به کیف پول کاربر در سامانه
     *
     * @param int $paymentId شناسه پرداخت در جدول payments
     * @param string|null $reason دلیل لغو یا استرداد
     * @return bool
     * @throws RuntimeException
     */
    public function refund(int $paymentId, ?string $reason = null): bool
    {
        return DB::transaction(function () use ($paymentId, $reason): bool {

            // ۱. قفل بدبینانه روی پرداخت برای جلوگیری از درخواست هم‌زمان استرداد
            $payment = DB::table('payments')
                ->where('id', $paymentId)
                ->lockForUpdate()
                ->first();

            if (! $payment) {
                throw new RuntimeException("پرداخت با شناسه #{$paymentId} یافت نشد.");
            }

            if ((int) $payment->status !== self::PAY_PAID) {
                throw new RuntimeException("فقط پرداخت‌های موفق قابل استرداد هستند. وضعیت فعلی: {$payment->status}");
            }

            // ۲. بررسی وجود و دریافت اطلاعات سفارش
            $order = DB::table('orders')
                ->where('id', $payment->order_id)
                ->lockForUpdate()
                ->first();

            if (! $order) {
                throw new RuntimeException("سفارش مرتبط با پرداخت #{$paymentId} یافت نشد.");
            }

            $orderId    = (int) $order->id;
            $userId     = (int) $payment->user_id;
            $amount     = (int) $payment->amount;
            $providerId = $order->provider_id ? (int) $order->provider_id : null;

            // ۳. به‌روزرسانی ردیف پرداخت
            DB::table('payments')->where('id', $paymentId)->update([
                'status'     => self::PAY_REFUNDED,
                'updated_at' => now(),
            ]);

            // ۴. تغییر وضعیت سفارش از طریق ماشین وضعیت OrderService (STATUS_REFUNDED = 5)
            $this->orderService->transition(
                orderId: $orderId,
                toStatus: OrderService::STATUS_REFUNDED
            );

            // ۵. واریز وجه برگشتی به کیف پول بیمار/کاربر
            $this->financialService->recordWalletTransaction(
                userId: $userId,
                type: 1, // 1: واریز (Deposit)
                subjectId: 3, // 3: مرجع برگشت وجه (Refund)
                subjectRef: $orderId,
                amount: $amount,
                description: $reason ?? "بازگشت وجه به کیف پول بابت استرداد سفارش #{$orderId}"
            );

            // ۶. در صورت وجود پزشک/ارائه‌دهنده، کسر مبلغی که قبلاً به حساب او واریز شده بود
            if (! is_null($providerId)) {
                $this->financialService->recordWalletTransaction(
                    userId: $providerId,
                    type: 2, // 2: برداشت (Withdrawal / Debit)
                    subjectId: 2, // 2: سفارش (Order)
                    subjectRef: $orderId,
                    amount: $amount,
                    description: "کسر وجه بابت لغو و استرداد سفارش #{$orderId}"
                );

                Log::info("Provider wallet debited for refund", [
                    'provider_id' => $providerId,
                    'order_id'    => $orderId,
                    'amount'      => $amount,
                ]);
            }

            // ۷. آزادسازی اسلات نوبت در صورت رزرو بودن
            if ((int) $order->reason_id === 1 && ! empty($order->reason_ref)) {
                DB::table('appointment_slots')
                    ->where('id', $order->reason_ref)
                    ->update([
                        'status'       => 'cancelled',
                        'cancelled_at' => now(),
                        'updated_at'   => now(),
                    ]);
            }

            // ۸. ثبت رخداد در جدول لاگ‌های سیستم
            DB::table('payment_gateway_logs')->insert([
                'payment_id' => $paymentId,
                'action'     => 'wallet_refund',
                'payload'    => json_encode([
                    'order_id'    => $orderId,
                    'user_id'     => $userId,
                    'amount'      => $amount,
                    'provider_id' => $providerId,
                    'reason'      => $reason,
                ], JSON_UNESCAPED_UNICODE),
                'response'   => json_encode([
                    'status'  => 'success',
                    'message' => 'وجه با موفقیت به کیف پول کاربر بازگردانده شد.',
                ], JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
            ]);

            return true;
        });
    }
}
