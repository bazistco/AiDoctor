<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class FinancialService
{
    /**
     * ایجاد سفارش جدید
     */
    public function createOrder(int $userId, int $reasonId, int $reasonRef, int $amount, ?string $description = null): int
    {
        $orderId = DB::table('orders')->insertGetId([
            'user_id' => $userId,
            'reason_id' => $reasonId,
            'reason_ref' => $reasonRef,
            'amount' => $amount,
            'status' => 1, // در انتظار پرداخت
            'description' => $description,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $orderId;
    }

    /**
     * ایجاد پرداخت جدید
     */
    public function createPayment(int $userId, int $orderId, int $reasonId, int $reasonRef, int $amount, string $gateway): int
    {
        $paymentId = DB::table('payments')->insertGetId([
            'user_id' => $userId,
            'order_id' => $orderId,
            'reason_id' => $reasonId,
            'reason_ref' => $reasonRef,
            'amount' => $amount,
            'status' => 1, // در انتظار پرداخت
            'gateway' => $gateway,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $paymentId;
    }

    /**
     * تکمیل پرداخت موفق با رعایت Idempotency
     */
    public function completePayment(
        int $paymentId,
        string $authority,
        string $refId,
        ?int $providerId = null,
        ?string $providerId_payment_description = null
    ): bool
    {
        DB::beginTransaction();

        try {
            // ════════════════════════════════════════════════════════════
            // مرحله 1: Idempotency Check با Insert + Unique Constraint
            // ════════════════════════════════════════════════════════════
            try {
                DB::table('payment_callbacks')->insert([
                    'payment_id' => $paymentId,
                    'authority' => $authority,
                    'ref_id' => $refId,
                    'raw_payload' => json_encode(request()->all()),
                    'ip_address' => request()->ip(),
                    'user_agent' => request()->userAgent(),
                    'created_at' => now(),
                ]);
            } catch (\Illuminate\Database\QueryException $e) {
                if ($e->getCode() == 23000) {
                    Log::warning('Duplicate callback (idempotency)', [
                        'payment_id' => $paymentId,
                        'authority' => $authority,
                    ]);
                    DB::rollBack();
                    return true;
                }
                throw $e;
            }

            // ════════════════════════════════════════════════════════════
            // مرحله 2: قفل و بررسی وضعیت پرداخت
            // ════════════════════════════════════════════════════════════
            $payment = DB::table('payments')
                ->where('id', $paymentId)
                ->lockForUpdate()
                ->first();

            if (!$payment) {
                throw new Exception('Payment not found');
            }

            // اگر قبلاً موفق شده، دیگه کاری نکن
            if ($payment->status == 2) {
                Log::info('Payment already completed', ['payment_id' => $paymentId]);
                DB::rollBack();
                return true;
            }

            if ($payment->status != 1) {
                throw new Exception("Invalid payment status: {$payment->status}");
            }

            // ════════════════════════════════════════════════════════════
            // مرحله 2.1: بررسی سرویس‌دهنده (در صورت وجود)
            // ════════════════════════════════════════════════════════════
            $provider = null;

            if (!is_null($providerId)) {
                $provider = DB::table('users')
                    ->where('id', $providerId)
                    ->lockForUpdate()
                    ->first();

                if (!$provider) {
                    throw new Exception("Provider not found: {$providerId}");
                }
            }

            // ════════════════════════════════════════════════════════════
            // مرحله 3: به‌روزرسانی پرداخت
            // ════════════════════════════════════════════════════════════
            DB::table('payments')
                ->where('id', $paymentId)
                ->update([
                    'status' => 2,
                    'authority' => $authority,
                    'ref_id' => $refId,
                    'paid_at' => now(),
                    'updated_at' => now(),
                ]);

            Log::info('Payment status updated to successful', [
                'payment_id' => $paymentId,
                'ref_id' => $refId,
            ]);

            // ════════════════════════════════════════════════════════════
            // مرحله 4: به‌روزرسانی سفارش
            // ════════════════════════════════════════════════════════════
            DB::table('orders')
                ->where('id', $payment->order_id)
                ->update([
                    'status' => 2,
                    'paid_at' => now(),
                    'updated_at' => now(),
                ]);

            Log::info('Order status updated to paid', [
                'order_id' => $payment->order_id,
            ]);

            // ════════════════════════════════════════════════════════════
            // مرحله 5: تراکنش‌های کیف پول
            // ════════════════════════════════════════════════════════════

            // 5.1: واریز از درگاه به کیف پول کاربر
            $this->recordWalletTransaction(
                userId: $payment->user_id,
                type: 1, // deposit
                subjectId: 1, // payment
                subjectRef: $paymentId,
                amount: $payment->amount,
                description: "واریز از درگاه {$payment->gateway} - RefID: {$refId}"
            );


            if ($payment->reason_id != 2) {

                // 5.2: برداشت بابت سفارش از کیف پول کاربر
                $this->recordWalletTransaction(
                    userId: $payment->user_id,
                    type: 2, // withdrawal
                    subjectId: 2, // order
                    subjectRef: $payment->order_id,
                    amount: $payment->amount,
                    description: "پرداخت سفارش #{$payment->order_id}"
                );

                // 5.3: واریز به کیف پول سرویس‌دهنده
                if (!is_null($providerId)) {
                    $this->recordWalletTransaction(
                        userId: $providerId,
                        type: 1, // deposit
                        subjectId: 2, // order
                        subjectRef: $payment->order_id,
                        amount: $payment->amount,
                        description: $providerId_payment_description ?? "دریافت مبلغ بابت سفارش #{$payment->order_id}"
                    );

                    Log::info('Provider wallet credited', [
                        'provider_id' => $providerId,
                        'order_id' => $payment->order_id,
                        'amount' => $payment->amount,
                    ]);
                }
            }

            Log::info('Wallet transactions recorded', [
                'user_id' => $payment->user_id,
                'provider_id' => $providerId,
                'amount' => $payment->amount,
            ]);

            // ════════════════════════════════════════════════════════════
            // مرحله 6: ثبت لاگ کامل درگاه
            // ════════════════════════════════════════════════════════════
            DB::table('payment_gateway_logs')->insert([
                'payment_id' => $paymentId,
                'gateway' => $payment->gateway,
                'step' => 'complete',
                'request_data' => json_encode([
                    'payment_id' => $paymentId,
                    'authority' => $authority,
                    'amount' => $payment->amount,
                    'provider_id' => $providerId,
                ]),
                'response_data' => json_encode([
                    'authority' => $authority,
                    'ref_id' => $refId,
                    'status' => 'success',
                    'completed_at' => now()->toDateTimeString(),
                ]),
                'ip_address' => request()->ip(),
                'created_at' => now(),
            ]);

            DB::commit();

            Log::info('Payment completed successfully', [
                'payment_id' => $paymentId,
                'order_id' => $payment->order_id,
                'provider_id' => $providerId,
                'ref_id' => $refId,
                'amount' => $payment->amount,
            ]);

            return true;

        } catch (Exception $e) {
            DB::rollBack();

            Log::error('Payment completion failed', [
                'payment_id' => $paymentId,
                'authority' => $authority,
                'provider_id' => $providerId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }


    /**
     * بازگشت وجه (Refund)
     */
    public function refundPayment(int $orderId, ?string $reason = null): bool
    {
        DB::beginTransaction();

        try {
            // قفل کردن سفارش
            $order = DB::table('orders')
                ->where('id', $orderId)
                ->lockForUpdate()
                ->first();

            if (!$order || $order->status != 2) {
                throw new Exception('Order cannot be refunded');
            }

            // قفل کردن پرداخت
            $payment = DB::table('payments')
                ->where('order_id', $orderId)
                ->where('status', 2)
                ->lockForUpdate()
                ->first();

            if (!$payment) {
                throw new Exception('No successful payment found for this order');
            }

            // به‌روزرسانی وضعیت سفارش
            DB::table('orders')
                ->where('id', $orderId)
                ->update([
                    'status' => 4, // refunded
                    'updated_at' => now(),
                ]);

            // به‌روزرسانی وضعیت پرداخت
            DB::table('payments')
                ->where('id', $payment->id)
                ->update([
                    'status' => 4, // refunded
                    'updated_at' => now(),
                ]);

            // آزادسازی نوبت (اگر نوع سفارش نوبت باشد)
            if ($order->reason_id == 1) {
                DB::table('appointment_slots')
                    ->where('id', $order->reason_ref)
                    ->update([
                        'status' => 'cancelled',
                        'cancelled_at' => now(),
                        'updated_at' => now(),
                    ]);
            }

            // واریز برگشتی به کیف پول
            $this->recordWalletTransaction(
                $order->user_id,
                1, // واریز
                3, // refund subject
                $orderId,
                $order->amount,
                $reason ?? 'بازگشت وجه کنسلی سفارش #' . $orderId
            );

            DB::commit();
            return true;

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Refund failed', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * ثبت تراکنش کیف پول با قفل امن
     */
    private function recordWalletTransaction(
        int $userId,
        int $type,
        int $subjectId,
        int $subjectRef,
        int $amount,
        string $description
    ): void {
        // قفل کردن کیف پول کاربر
        $wallet = DB::table('wallets')
            ->where('user_id', $userId)
            ->lockForUpdate()
            ->first();

        if (!$wallet) {
            // ایجاد کیف پول در صورت عدم وجود
            $walletId = DB::table('wallets')->insertGetId([
                'user_id' => $userId,
                'balance' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $currentBalance = 0;
        } else {
            $walletId = $wallet->id;
            $currentBalance = $wallet->balance;
        }

        // محاسبه موجودی جدید
        $newBalance = match($type) {
            1 => $currentBalance + $amount, // واریز
            2 => $currentBalance - $amount, // برداشت
            default => throw new Exception('Invalid transaction type'),
        };

        // بررسی موجودی کافی برای برداشت
        if ($type == 2 && $newBalance < 0) {
            throw new Exception('Insufficient wallet balance');
        }

        // ثبت تراکنش
        DB::table('wallet_transactions')->insert([
            'wallet_id' => $walletId,
            'type' => $type,
            'subject_type' => $subjectId,
            'subject_id' => $subjectRef,
            'amount' => $amount,
            'balance_after' => $newBalance,
            'description' => $description,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'created_at' => now(),
        ]);

        // به‌روزرسانی موجودی کیف پول
        DB::table('wallets')
            ->where('id', $walletId)
            ->update([
                'balance' => $newBalance,
                'updated_at' => now(),
                'version' => DB::raw('version + 1'), // Optimistic locking
            ]);
    }

    /**
     * تطبیق موجودی کیف پول (Reconciliation)
     */
    public function reconcileWalletBalance(int $userId): array
    {
        DB::beginTransaction();

        try {
            $wallet = DB::table('wallets')
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->first();

            if (!$wallet) {
                throw new Exception('Wallet not found');
            }

            // محاسبه موجودی واقعی از تراکنش‌ها
            $calculatedBalance = DB::table('wallet_transactions')
                ->where('wallet_id', $wallet->id)
                ->selectRaw('
                    SUM(CASE WHEN type = 1 THEN amount ELSE 0 END) -
                    SUM(CASE WHEN type = 2 THEN amount ELSE 0 END) as balance
                ')
                ->value('balance') ?? 0;

            $difference = $calculatedBalance - $wallet->balance;

            if ($difference != 0) {
                // اصلاح موجودی
                DB::table('wallets')
                    ->where('id', $wallet->id)
                    ->update([
                        'balance' => $calculatedBalance,
                        'updated_at' => now(),
                    ]);

                Log::warning('Wallet balance reconciled', [
                    'user_id' => $userId,
                    'wallet_id' => $wallet->id,
                    'old_balance' => $wallet->balance,
                    'new_balance' => $calculatedBalance,
                    'difference' => $difference,
                ]);
            }

            DB::commit();

            return [
                'old_balance' => $wallet->balance,
                'new_balance' => $calculatedBalance,
                'difference' => $difference,
                'reconciled' => $difference != 0,
            ];

        } catch (Exception $e) {
            DB::rollBack();
            Log::error('Wallet reconciliation failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * دریافت موجودی کیف پول
     */
    public function getWalletBalance(int $userId): int
    {
        $wallet = DB::table('wallets')
            ->where('user_id', $userId)
            ->first();

        return $wallet ? $wallet->balance : 0;
    }

    /**
     * دریافت تاریخچه تراکنش‌های کیف پول
     */
    public function getWalletTransactions(int $userId, int $perPage = 20)
    {
        $wallet = DB::table('wallets')
            ->where('user_id', $userId)
            ->first();

        if (!$wallet) {
            return [];
        }

        return DB::table('wallet_transactions as wt')
            ->join('transaction_subjects as ts', 'wt.subject_id', '=', 'ts.id')
            ->where('wt.wallet_id', $wallet->id)
            ->select([
                'wt.id',
                'wt.type',
                'ts.title as subject_title',
                'wt.amount',
                'wt.balance_after',
                'wt.description',
                'wt.created_at',
            ])
            ->orderByDesc('wt.created_at')
            ->paginate($perPage);
    }

    /**
     * گزارش پرداخت‌ها
     */
    public function getPaymentReport(array $filters = [], int $perPage = 50)
    {
        $query = DB::table('payments as p')
            ->join('orders as o', 'p.order_id', '=', 'o.id')
            ->join('payment_reasons as pr', 'o.reason_id', '=', 'pr.id')
            ->join('users as u', 'p.user_id', '=', 'u.id')
            ->leftJoin('provinces as prov', 'u.province_id', '=', 'prov.id')
            ->leftJoin('cities as c', 'u.city_id', '=', 'c.id')
            ->select([
                'p.id',
                'p.user_id',
                'pr.label',
                'u.name as user_name',
                'u.phone as user_phone',
                'prov.name as province_name',
                'c.name as city_name',
                'p.order_id',
                'p.amount',
                'p.status',
                'p.gateway',
                'p.ref_id',
                'p.created_at',
                'p.paid_at',
            ]);

        // فیلتر بر اساس وضعیت
        if (isset($filters['status'])) {
            $query->where('p.status', $filters['status']);
        }

        // فیلتر بر اساس کاربر
        if (isset($filters['user_id'])) {
            $query->where('p.user_id', $filters['user_id']);
        }

        // فیلتر بر اساس تاریخ
        if (isset($filters['from_date'])) {
            $query->where('p.created_at', '>=', $filters['from_date']);
        }

        if (isset($filters['to_date'])) {
            $query->where('p.created_at', '<=', $filters['to_date']);
        }

        // فیلتر بر اساس درگاه
        if (isset($filters['gateway'])) {
            $query->where('p.gateway', $filters['gateway']);
        }

        return $query->orderByDesc('p.created_at')
            ->paginate($perPage)
            ->through(function ($payment) {
                if ($payment->gateway == 'zarinpal') {
                    $payment->gateway='درگاه زرین پال'  ;
                }
                elseif ($payment->gateway == 'wallet') {
                    $payment->gateway = 'کیف پول';
                }
                else{
                    $payment->gateway='درگاه'  ;
                }
                $payment->status_text = match($payment->status) {
                    1 => 'در انتظار پرداخت',
                    2 => 'موفق',
                    3 => 'ناموفق',
                    4 => 'بازگشت داده شده',
                    default => 'نامشخص',
                };
                return $payment;
            });
    }
}
