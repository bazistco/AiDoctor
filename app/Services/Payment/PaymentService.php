<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Services\FinancialService;
use App\Services\Payment\Gateways\SamanGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use RuntimeException;
use staabm\SideEffectsDetector\SideEffect;

/**
 * لایه پرداخت — اورکستراتور
 *
 * مسئولیت: هماهنگی بین OrderService، SamanGateway، FinancialService
 * هیچ call مستقیمی به API سامان ندارد.
 */
class PaymentService
{
    // وضعیت‌های payments
    public const PAY_PENDING  = 1;
    public const PAY_PAID     = 2;
    public const PAY_FAILED   = 3;
    public const PAY_REFUNDED = 4;

    // حداکثر تعداد کالبک مجاز (flood protection)
    private const MAX_CALLBACKS = 5;

    public function __construct(
        private readonly OrderService    $orderService,
        private readonly SamanGateway   $gateway,
        private readonly FinancialService $financialService,
    ) {}

    // ═══════════════════════════════════════════════════════════════
    // مرحله ۱ — ثبت رکورد payment و دریافت توکن
    // ═══════════════════════════════════════════════════════════════

    /**
     * @return array{payment_id: int, payment_url: string, res_num: string}
     * @throws RuntimeException
     */
    public function initiate(
        int     $orderId,
        int     $userId,
        string  $callbackUrl,
        ?string $cellNumber = null,
    ): array {
        $order = DB::table('orders')
            ->where('id',      $orderId)
            ->where('user_id', $userId)
            ->first();

        if (! $order) {
            throw new RuntimeException("سفارش #{$orderId} یافت نشد.");
        }

        if ((int) $order->status !== OrderService::STATUS_PENDING) {
            throw new RuntimeException("سفارش #{$orderId} قابل پرداخت نیست.");
        }

        // بررسی payment pending قبلی برای همین order (idempotency)
        $existing = DB::table('payments')
            ->where('order_id', $orderId)
            ->where('status',   self::PAY_PENDING)
            ->whereNotNull('authority')
            ->where('token_expires_at', '>', now())
            ->first();

        if ($existing) {
            return [
                'payment_id'  => (int) $existing->id,
                'payment_url' => config('payment.saman.payment_url') . $existing->token,
                'res_num'     => (string) $existing->authority,
            ];
        }

        $resNum = $this->generateResNum($orderId);

        // ثبت رکورد payment
        $paymentId = DB::table('payments')->insertGetId([
            'user_id'    => $userId,
            'order_id'   => $orderId,
            'reason_id'  => $order->reason_id,
            'reason_ref' => $order->reason_ref,
            'amount'     => $order->amount,
            'gateway'    => 'saman',
            'status'     => self::PAY_PENDING,
            'authority'  => $resNum,   // authority = res_num در سامان
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $result = $this->gateway->requestToken(
                amount:      (int) $order->amount,
                resNum:      $resNum,
                callbackUrl: $callbackUrl,
                cellNumber:  $cellNumber,
            );

            DB::table('payments')->where('id', $paymentId)->update([
                'token'           => $result['token'],
                'token_expires_at' => now()->addMinutes(
                    (int) config('payment.saman.token_expiry_min', 20)
                ),
                'updated_at' => now(),
            ]);

            $this->logGateway($paymentId, 'token_request', compact('resNum'), $result['raw']);

            return [
                'token' => $result['token'],
                'payment_id'  => $paymentId,
                'payment_url' => $result['payment_url'],
                'res_num'     => $resNum,
            ];

        } catch (\Throwable $e) {
            $this->markFailed($paymentId, $e->getMessage());
            Log::error('[PaymentService] Token failed', [
                'payment_id' => $paymentId,
                'order_id'   => $orderId,
                'error'      => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // مرحله ۲ — پردازش کالبک
    // ═══════════════════════════════════════════════════════════════

    /**
     * @param  array<string, mixed> $payload   پارامترهای POST از سامان
     * @return array{success: bool, ref_num?: string, payment_id?: int, error?: string}
     */
    public function handleCallback(array $payload): array
    {
        $resNum = (string) ($payload['ResNum'] ?? '');
        $state  = (string) ($payload['State']  ?? '');
        $refNum = (string) ($payload['RefNum'] ?? '');

        if ($resNum === '') {
            Log::warning('[PaymentService][CB] Missing ResNum', ['payload' => $payload]);
            return ['success' => false, 'error' => 'missing_res_num'];
        }

        $payment = DB::table('payments')
            ->where('authority', $resNum)
            ->first();

        if (! $payment) {
            Log::error('[PaymentService][CB] Not found', ['res_num' => $resNum]);
            return ['success' => false, 'error' => 'payment_not_found'];
        }

        $paymentId = (int) $payment->id;

        // ۱. Flood protection
        if ((int) $payment->callback_count >= self::MAX_CALLBACKS) {
            Log::warning('[PaymentService][CB] Flood detected', ['payment_id' => $paymentId]);
            return ['success' => false, 'error' => 'too_many_callbacks'];
        }

        DB::table('payments')
            ->where('id', $paymentId)
            ->increment('callback_count');

        // ثبت کالبک خام
        $this->logCallback($paymentId, $resNum, $refNum, $payload);

        // ۲. Idempotency — اگر قبلاً موفق شده باشد
        if ((int) $payment->status === self::PAY_PAID) {
            return ['success' => true, 'ref_num' => $payment->ref_num, 'payment_id' => $paymentId];
        }

        // ۳. پرداخت ناموفق در درگاه
        if (! $this->gateway->isCallbackSuccessful($state)) {
            $reason = $this->gateway->describeState($state);
            $this->markFailed($paymentId, $reason);

            // تغییر وضعیت سفارش به FAILED
            DB::table('orders')
                ->where('id', $payment->order_id)
                ->where('status', OrderService::STATUS_PENDING)
                ->update(['status' => OrderService::STATUS_FAILED, 'updated_at' => now(),'canceled_at' => now()]);

            // آزادسازی قفل ردیس نوبت در صورت پرداخت ناموفق
            $order = DB::table('orders')->where('id', $payment->order_id)->first();
            if ($order && (int) $order->reason_id === 1 && !empty($order->reason_ref)) {
                Redis::del("slot:reservation:{$order->reason_ref}");
                DB::table('appointment_slots')
                    ->where('id', $order->reason_ref)
                    ->update([
                        'patient_id'   => null,
                        'updated_at'   => now()
                    ]);
            }

            $this->logGateway($paymentId, 'callback_failed', $payload, ['state' => $state]);
            return ['success' => false, 'error' => $reason, 'payment_id' => $paymentId];
        }

        // ۴. Verify + Finalize در یک تراکنش اتمیک
        return DB::transaction(function () use ($payment, $refNum, $payload): array {
            $paymentId = (int) $payment->id;

            // قفل ردیف payment برای جلوگیری از Race Condition
            $locked = DB::table('payments')
                ->where('id', $paymentId)
                ->lockForUpdate()
                ->first();

            // بررسی مجدد بعد از قفل دیتابیس
            if ((int) $locked->status === self::PAY_PAID) {
                return ['success' => true, 'ref_num' => $locked->ref_num, 'payment_id' => $paymentId];
            }

            try {
                $verified = $this->gateway->verify($refNum);

                // بررسی مبلغ برای جلوگیری از Partial Payment
                if ($verified['verified_amount'] !== (int) $locked->amount) {
                    throw new RuntimeException(
                        sprintf(
                            'مبلغ تأیید (%d) با سفارش (%d) مطابقت ندارد.',
                            $verified['verified_amount'],
                            $locked->amount
                        )
                    );
                }

                // الف) به‌روزرسانی رکورد پرداخت
                DB::table('payments')->where('id', $paymentId)->update([
                    'status'      => self::PAY_PAID,
                    'ref_num'     => $verified['ref_id'],
                    'trace_no'    => $verified['trace_no'],
                    'rrn'         => $verified['rrn'],
                    'terminal_id' => config('payment.saman.terminal_id'),
                    'paid_at'     => now(),
                    'verified_at' => now(),
                    'last_error'  => null,
                    'updated_at'  => now(),
                ]);

                // ب) انتقال وضعیت Order
                $this->orderService->transition(
                    (int) $payment->order_id,
                    OrderService::STATUS_PAID
                );

                // ج) ثبت تراکنش‌های مالی و کیف پول
                $this->financialService->completePayment(
                    paymentId: $paymentId,
                    authority: (string) $locked->authority,
                    refId:     $verified['ref_id'],
                );

                // د) رزرو قطعی نوبت در جدول نوبت‌ها (در صورت reason_id == 1)
                $order = DB::table('orders')->where('id', $payment->order_id)->first();

                if ($order && (int) $order->reason_id === 1 && !empty($order->reason_ref)) {
                    $slotId = (int) $order->reason_ref;

                    DB::table('appointment_slots')
                        ->where('id', $slotId)
                        ->update([
                            'status'       => 'booked',
                            'patient_id'   => $order->user_id,
                            'order_id'     => $order->id,
                            'booking_time' => now(),
                            'updated_at'   => now(),
                        ]);

                    // حذف قفل موقت از Redis چون نوبت رسماً در دیتابیس ثبت قطعی شد
                    Redis::del("slot:reservation:{$slotId}");

                    Log::info('[PaymentService] Appointment booked successfully', [
                        'slot_id'    => $slotId,
                        'patient_id' => $order->user_id,
                        'order_id'   => $order->id,
                    ]);
                }

                $this->logGateway($paymentId, 'verify_success', [
                    'ref_num' => $refNum,
                    'amount'  => $locked->amount,
                ], $verified['raw']);

                Log::info('[PaymentService] Verified', [
                    'payment_id' => $paymentId,
                    'order_id'   => $payment->order_id,
                    'ref_num'    => $verified['ref_id'],
                    'amount'     => $locked->amount,
                ]);

                return [
                    'success'    => true,
                    'ref_num'    => $verified['ref_id'],
                    'payment_id' => $paymentId,
                ];

            } catch (\Throwable $e) {
                $this->markFailed($paymentId, $e->getMessage());

                if ($refNum !== '') {
                    Log::critical('[PaymentService] VERIFY FAILED AFTER DEBIT — MANUAL REVIEW NEEDED', [
                        'payment_id' => $paymentId,
                        'ref_num'    => $refNum,
                        'amount'     => $locked->amount,
                        'error'      => $e->getMessage(),
                    ]);
                }
                DB::table('appointment_slots')
                    ->where('id', $slotId)
                    ->update([
                        'patient_id'   => null,
                        'updated_at'   => now(),
                    ]);
                Redis::del("slot:reservation:{$slotId}");
                $this->logGateway($paymentId, 'verify_failed', $payload, ['error' => $e->getMessage()]);

                return ['success' => false, 'error' => 'verify_failed', 'payment_id' => $paymentId];
            }
        });
    }

    // ═══════════════════════════════════════════════════════════════
    // استرداد
    // ═══════════════════════════════════════════════════════════════

    /**
     * @throws RuntimeException
     */
    public function refund(int $paymentId, ?string $reason = null): bool
    {
        return DB::transaction(function () use ($paymentId, $reason): bool {
            $payment = DB::table('payments')
                ->where('id', $paymentId)
                ->where('status', self::PAY_PAID)
                ->lockForUpdate()
                ->first();

            if (! $payment) {
                throw new RuntimeException("پرداخت #{$paymentId} قابل استرداد نیست.");
            }

            if (empty($payment->rrn)) {
                throw new RuntimeException("RRN برای پرداخت #{$paymentId} موجود نیست.");
            }

            $this->gateway->reverse(
                refNum: (string) $payment->ref_num,
                rrn:    (string) $payment->rrn,
                amount: (int)    $payment->amount,
            );

            DB::table('payments')->where('id', $paymentId)->update([
                'status'     => self::PAY_REFUNDED,
                'updated_at' => now(),
            ]);

            $this->orderService->transition(
                (int) $payment->order_id,
                OrderService::STATUS_REFUNDED
            );

            $this->financialService->refundPayment(
                orderId: (int) $payment->order_id,
                reason:  $reason,
            );

            $this->logGateway($paymentId, 'reverse', [
                'ref_num' => $payment->ref_num,
                'rrn'     => $payment->rrn,
                'reason'  => $reason,
            ], ['result' => 'reversed']);

            return true;
        });
    }

    // ─── متدهای کمکی ───────────────────────────────────────────────

    private function generateResNum(int $orderId): string
    {
        return strtoupper(
            substr(hash('sha256', $orderId . microtime(true) . random_bytes(4)), 0, 16)
        );
    }


    private function markFailed(int $paymentId, string $reason): void
    {
        DB::table('payments')->where('id', $paymentId)->update([
            'status'     => self::PAY_FAILED,
            'last_error' => mb_substr($reason, 0, 255),
            'updated_at' => now(),
        ]);
    }

    private function logCallback(
        int    $paymentId,
        string $authority,
        string $refId,
        array  $payload
    ): void {
        try {
            DB::table('payment_callbacks')->insertOrIgnore([
                'payment_id'  => $paymentId,
                'authority'   => $authority,
                'ref_id'      => $refId ?: null,
                'raw_payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'ip_address'  => request()->ip(),
                'created_at'  => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[PaymentService] Callback log failed', ['error' => $e->getMessage()]);
        }
    }

    private function logGateway(
        int    $paymentId,
        string $step,
        array  $request,
        array  $response
    ): void {
        try {
            DB::table('payment_gateway_logs')->insert([
                'payment_id'    => $paymentId,
                'gateway'       => 'saman',
                'step'          => $step,
                'request_data'  => json_encode($request,  JSON_UNESCAPED_UNICODE),
                'response_data' => json_encode($response, JSON_UNESCAPED_UNICODE),
                'ip_address'    => request()->ip(),
                'created_at'    => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[PaymentService] Gateway log failed', ['error' => $e->getMessage()]);
        }
    }
}
