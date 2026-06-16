<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class FinancialService
{
    // ── Payment Reason IDs (از جدول payment_reasons) ──────────────────────
    private function getReasonId(string $slug): int
    {
        $row = DB::table('payment_reasons')->where('slug', $slug)->value('id');
        if (!$row) throw new RuntimeException("payment_reason not found: {$slug}");
        return (int) $row;
    }

    // ── Transaction Subject IDs (از جدول transaction_subjects) ────────────
    private function getSubjectId(string $name): int
    {
        $row = DB::table('transaction_subjects')->where('name', $name)->value('id');
        if (!$row) throw new RuntimeException("transaction_subject not found: {$name}");
        return (int) $row;
    }

    // ━━━━━━━━━━━━━
    // ORDER
    // ━━━━━━━━━━━━━

    /**
     * @param  string  $reasonSlug  'appointment' | 'wallet_charge' | 'chat'
     * @param  int|null $reasonRef   e.g. appointment_slots.id
     */
    public function createOrder(int $userId, string $reasonSlug, int $amount, ?int $reasonRef = null): int
    {
        return DB::table('orders')->insertGetId([
            'user_id'    => $userId,
            'reason_id'  => $this->getReasonId($reasonSlug),
            'reason_ref' => $reasonRef,
            'amount'     => $amount,
            'status'     => 1, // pending
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ━━━━━━━━━━━━━━━━━━━━━
    // PAYMENT
    // ━━━━━━━━━━━━━

    public function createPayment(
        int $userId,
        int $orderId,
        string $reasonSlug,
        int $amount,
        string $authority,
        ?int $reasonRef = null,
        string $gateway = 'zarinpal',
        ?string $userIp = null,
        ?string $userAgent = null
    ): int {
        $paymentId = DB::table('payments')->insertGetId([
            'user_id'    => $userId,
            'order_id'   => $orderId,
            'reason_id'  => $this->getReasonId($reasonSlug),
            'reason_ref' => $reasonRef,
            'amount'     => $amount,
            'authority'  => $authority,
            'status'     => 1, // pending
            'gateway'    => $gateway,
            'user_ip'    => $userIp,
            'user_agent' => $userAgent,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // لاگ اولیه درگاه
        DB::table('payment_gateway_logs')->insert([
            'id'           => $paymentId,
            'payment_id'   => $paymentId,
            'gateway_name' => $gateway,
            'created_at'   => now(),'updated_at'   => now(),
        ]);

        return $paymentId;
    }

    // ━━━━━━━━━━━━━
    // COMPLETE PAYMENT (callback موفق)
    // ━━━━━━━━━━━━━

    /**
     * ثبت callback، verify درگاه و تکمیل پرداخت — idempotent
     */
    public function completePayment(
        string $authority,
        string $refId,
        array $callbackPayload = [],
        array $verifyResponse = []
    ): array {
        // ── Idempotency: اگر قبلاً پردازش شده، نتیجه همان را برگردان ──────
        $existing = DB::table('payment_callbacks')
            ->where('authority', $authority)
            ->first();
        if ($existing) {
            return ['idempotent' => true, 'payment_id' => $existing->payment_id];
        }

        $payment = DB::table('payments')->where('authority', $authority)->first();
        if (!$payment) throw new RuntimeException("Payment not found for authority: {$authority}");
        if ($payment->status == 2) return ['idempotent' => true, 'payment_id' => $payment->id];

        DB::beginTransaction();
        try {
            $now = now();

            // ثبت callback (idempotency key)
            DB::table('payment_callbacks')->insert([
                'payment_id'  => $payment->id,
                'authority'   => $authority,
                'raw_payload' => json_encode($callbackPayload),
                'created_at'  => $now,
            ]);

            // آپدیت پرداخت
            DB::table('payments')->where('id', $payment->id)->update([
                'status'     => 2, // paid
                'ref_id'     => $refId,
                'updated_at' => $now,
            ]);

            // آپدیت سفارش
            DB::table('orders')->where('id', $payment->order_id)->update([
                'status'     => 2, // confirmed
                'updated_at' => $now,
            ]);

            // آپدیت لاگ درگاه
            DB::table('payment_gateway_logs')
                ->where('payment_id', $payment->id)
                ->update([
                    'callback_payload'     => json_encode($callbackPayload),
                    'callback_received_at' => $now,
                    'verify_response'      => json_encode($verifyResponse),
                    'verified_at'          => $now,
                    'gateway_trace_id'     => $refId,
                    'updated_at'           => $now,
                ]);

            // تراکنش‌های کیف پول
            $this->recordWalletTransactions(
                userId: $payment->user_id,
                paymentId: $payment->id,
                orderId: $payment->order_id,
                amount: $payment->amount
            );

            DB::commit();

            return ['idempotent' => false, 'payment_id' => $payment->id];

        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('completePayment failed', [
                'authority' => $authority,
                'error'     => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    // ━━━━━━━━━━━━━
    // WALLET TRANSACTIONS
    // ━━━━━━━━━━━━━

    private function recordWalletTransactions(int $userId, int $paymentId, int $orderId, int $amount): void
    {
        $wallet = DB::table('wallets')
            ->where('user_id', $userId)
            ->lockForUpdate()
            ->first();

        if (!$wallet) {
            // ساخت کیف پول اگر وجود ندارد
            $walletId = DB::table('wallets')->insertGetId([
                'user_id'    => $userId,
                'balance'    => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $currentBalance = 0;
        } else {
            $walletId = $wallet->id;
            $currentBalance = (int) $wallet->balance;
        }

        $subjectPayment = $this->getSubjectId('payment');
        $subjectOrder   = $this->getSubjectId('order');
        $now = now();

        // واریز بابت پرداخت
        $balanceAfterDeposit = $currentBalance + $amount;
        DB::table('wallet_transactions')->insert([
            'wallet_id'    => $walletId,
            'type'         => 1, // واریز
            'amount'       => $amount,
            'balance_after'=> $balanceAfterDeposit,
            'subject_type' => $subjectPayment,
            'subject_id'   => $paymentId,
            'description'  => 'واریز بابت پرداخت #' . $paymentId,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);

        // برداشت بابت سفارش
        $balanceAfterWithdraw = $balanceAfterDeposit - $amount;
        DB::table('wallet_transactions')->insert([
            'wallet_id'    => $walletId,
            'type'         => 2, // برداشت
            'amount'       => $amount,
            'balance_after'=> $balanceAfterWithdraw,
            'subject_type' => $subjectOrder,
            'subject_id'   => $orderId,
            'description'  => 'برداشت بابت سفارش #' . $orderId,
            'created_at'   => $now,
            'updated_at'   => $now,
        ]);

        DB::table('wallets')->where('id', $walletId)->update([
            'balance'    => $balanceAfterWithdraw,
            'updated_at' => $now,
        ]);
    }

    // ━━━━━━━━━━━━━━━━━━━━━
    // WALLET BALANCE
    // ━━━━━━━━━━━━━━━━━━━━━

    public function getWalletBalance(int $userId): int
    {
        return (int) DB::table('wallets')
            ->where('user_id', $userId)
            ->whereNull('deleted_at')
            ->value('balance') ?? 0;
    }

    public function calculateWalletBalance(int $userId): int
    {
        $walletId = DB::table('wallets')->where('user_id', $userId)->value('id');
        if (!$walletId) return 0;

        $deposits = (int) DB::table('wallet_transactions')
            ->where('wallet_id', $walletId)
            ->where('type', 1)
            ->whereNull('deleted_at')
            ->sum('amount');

        $withdrawals = (int) DB::table('wallet_transactions')
            ->where('wallet_id', $walletId)
            ->where('type', 2)
            ->whereNull('deleted_at')
            ->sum('amount');

        return $deposits - $withdrawals;
    }

    /**
     * مقایسه موجودی ذخیره‌شده با محاسبه‌شده و اصلاح در صورت اختلاف
     */
    public function reconcileWalletBalance(int $userId): array
    {
        $wallet = DB::table('wallets')
            ->where('user_id', $userId)
            ->lockForUpdate()
            ->first();

        if (!$wallet) throw new RuntimeException("Wallet not found for user: {$userId}");

        $storedBalance     = (int) $wallet->balance;
        $calculatedBalance = $this->calculateWalletBalance($userId);
        $diff              = $calculatedBalance - $storedBalance;

        if ($diff !== 0) {
            DB::table('wallets')->where('id', $wallet->id)->update([
                'balance'    => $calculatedBalance,
                'updated_at' => now(),
            ]);
            Log::warning('Wallet balance mismatch corrected', [
                'user_id'    => $userId,
                'stored'     => $storedBalance,
                'calculated' => $calculatedBalance,
                'diff'       => $diff,
            ]);
        }

        return [
            'stored'     => $storedBalance,
            'calculated' => $calculatedBalance,
            'diff'       => $diff,
            'corrected'  => $diff !== 0,
        ];
    }

    // ━━━━━━━━━━━━━━━━━━━━━
    // CANCEL
    // ━━━━━━━━━━━━━

    public function cancelPayment(int $paymentId): bool
    {
        $payment = DB::table('payments')->where('id', $paymentId)->first();
        if (!$payment) throw new RuntimeException("Payment not found: {$paymentId}");
        if ($payment->status != 1) throw new RuntimeException("Only pending payments can be cancelled.");

        DB::beginTransaction();
        try {
            $now = now();
            DB::table('payments')->where('id', $paymentId)->update([
                'status'     => 4, // cancelled
                'updated_at' => $now,
            ]);
            DB::table('orders')->where('id', $payment->order_id)->update([
                'status'     => 4, // cancelled
                'updated_at' => $now,
            ]);
            DB::commit();
            return true;
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('cancelPayment failed', ['payment_id' => $paymentId, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    // ━━━━━━━━━━━━━
    // REFUND
    // ━━━━━━━━━━━━━

    public function refundPayment(int $paymentId, ?string $reason = null): bool
    {
        $payment = DB::table('payments')->where('id', $paymentId)->first();
        if (!$payment) throw new RuntimeException("Payment not found: {$paymentId}");
        if ($payment->status != 2) throw new RuntimeException("Only paid payments can be refunded.");

        DB::beginTransaction();
        try {
            $now     = now();
            $wallet  = DB::table('wallets')
                ->where('user_id', $payment->user_id)
                ->lockForUpdate()
                ->first();

            if (!$wallet) throw new RuntimeException("Wallet not found.");

            $subjectRefund  = $this->getSubjectId('refund');
            $newBalance     = (int) $wallet->balance + (int) $payment->amount;

            // واریز مجدد به کیف پول
            DB::table('wallet_transactions')->insert([
                'wallet_id'    => $wallet->id,
                'type'         => 1, // واریز
                'amount'       => $payment->amount,
                'balance_after'=> $newBalance,
                'subject_type' => $subjectRefund,
                'subject_id'   => $paymentId,
                'description'  => $reason ?? 'بازگشت وجه بابت پرداخت #' . $paymentId,
                'created_at'   => $now,
                'updated_at'   => $now,
            ]);

            DB::table('wallets')->where('id', $wallet->id)->update([
                'balance'    => $newBalance,
                'updated_at' => $now,
            ]);

            DB::table('payments')->where('id', $paymentId)->update([
                'status'     => 3, // failed → در صورت داشتن status=5 برای refund، عدد را عوض کن
                'updated_at' => $now,
            ]);

            DB::table('orders')->where('id', $payment->order_id)->update([
                'status'     => 5, // refunded
                'updated_at' => $now,
            ]);

            DB::commit();
            return true;
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('refundPayment failed', ['payment_id' => $paymentId, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    // ━━━━━━━━━━━━━
    // HISTORY
    // ━━━━━━━━━━━━━

    public function getWalletTransactionHistory(int $userId, int $perPage = 20): object
    {
        $walletId = DB::table('wallets')->where('user_id', $userId)->value('id');
        if (!$walletId) return (object)['data' => [], 'total' => 0];

        return DB::table('wallet_transactions')
            ->where('wallet_id', $walletId)
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }
    // در FinancialService

    private const PAYMENT_STATUS = [
        1 => 'در انتظار',
        2 => 'پرداخت شده',
        3 => 'ناموفق',
        4 => 'لغو شده',
    ];

    // در FinancialService


    public function getPaymentReport(int $perPage = 15): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return DB::table('payments as p')
            ->join('users as u', 'u.id', '=', 'p.user_id')
            ->join('payment_reasons as pr', 'pr.id', '=', 'p.reason_id')
            ->leftJoin('orders as o', function ($join) {
                $join->on('o.id', '=', 'p.order_id')
                    ->where('pr.slug', '=', 'appointment');
            })
            ->leftJoin('appointment_slots as asl', function ($join) {
                $join->on('asl.id', '=', 'o.reason_ref')
                    ->where('pr.slug', '=', 'appointment');
            })
            ->leftJoin('doctor_info as di', 'di.user_id', '=', 'asl.doctor_id')
            ->leftJoin('specialties as sp', 'sp.id', '=', 'di.specialty_id')
            ->where('p.status', 2)
            ->orderByDesc('p.created_at')
            ->select([
                'p.ref_id as tracking_code',
                'u.name as payer_name',
                'u.phone as payer_phone',
                'p.gateway as payment_method',
                'p.amount',
                'p.status',
                'p.updated_at as paid_at',
                'pr.slug as reason_slug',
                'pr.label as reason_label',
                'di.name as doctor_name',
                'sp.name as doctor_specialty',
                'asl.start_time',
                'asl.end_time',
            ])
            ->paginate($perPage)
            ->through(function ($row) {
                $row->status_label = self::PAYMENT_STATUS[$row->status] ?? 'نامشخص';

                $row->service_type = match ($row->reason_slug) {
                    'appointment'   => 'رزرو نوبت - دکتر ' . $row->doctor_name,
                    'wallet_charge' => 'شارژ کیف پول',
                    'chat'          => 'مشاوره چت',
                    default         => $row->reason_label,
                };

                unset($row->status, $row->reason_slug, $row->reason_label);

                return $row;
            });
    }



}

