<?php

declare(strict_types=1);

namespace App\Services\Payment;

use Illuminate\Support\Facades\DB;
use RuntimeException;

class OrderService
{
    // ─── وضعیت‌های order ───────────────────────────────────────────
    public const STATUS_PENDING   = 1;
    public const STATUS_PAID      = 2;
    public const STATUS_FAILED    = 3;
    public const STATUS_CANCELLED = 4;
    public const STATUS_REFUNDED  = 5;

    /** تراکنش‌های مجاز State Machine */
    private const TRANSITIONS = [
        self::STATUS_PENDING   => [self::STATUS_PAID, self::STATUS_FAILED, self::STATUS_CANCELLED],
        self::STATUS_PAID      => [self::STATUS_REFUNDED],
        self::STATUS_FAILED    => [],
        self::STATUS_CANCELLED => [],
        self::STATUS_REFUNDED  => [],
    ];

    /**
     * ایجاد سفارش جدید — Idempotent
     * اگر سفارش pending برای همین ترکیب وجود داشته باشد، همان برگردانده می‌شود.
     *
     * @return array{order_id: int, amount: int, is_new: bool}
     */
    public function createOrReuse(
        int     $userId,
        int     $reasonId,
        int     $reasonRef,
        int     $amount,
        ?string $description = null,
    ): array {
        // بررسی سفارش فعال قبلی
        $existing = DB::table('orders')
            ->where('user_id',   $userId)
            ->where('reason_id', $reasonId)
            ->where('reason_ref', $reasonRef)
            ->where('status',    self::STATUS_PENDING)
            ->orderByDesc('created_at')
            ->first();

        if ($existing) {
            return [
                'order_id' => (int) $existing->id,
                'amount'   => (int) $existing->amount,
                'is_new'   => false,
            ];
        }

        $orderId = DB::table('orders')->insertGetId([
            'user_id'     => $userId,
            'reason_id'   => $reasonId,
            'reason_ref'  => $reasonRef,
            'amount'      => $amount,
            'status'      => self::STATUS_PENDING,
            'description' => $description,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        return [
            'order_id' => $orderId,
            'amount'   => $amount,
            'is_new'   => true,
        ];
    }

    /**
     * انتقال وضعیت با اعمال State Machine
     * از lockForUpdate استفاده می‌کند — باید داخل DB::transaction فراخوانی شود
     *
     * @throws RuntimeException
     */
    public function transition(int $orderId, int $toStatus): void
    {
        $order = DB::table('orders')
            ->where('id', $orderId)
            ->lockForUpdate()
            ->first();

        if (! $order) {
            throw new RuntimeException("سفارش #{$orderId} یافت نشد.");
        }

        $fromStatus = (int) $order->status;

        if (! in_array($toStatus, self::TRANSITIONS[$fromStatus] ?? [], true)) {
            throw new RuntimeException(
                "انتقال وضعیت از {$fromStatus} به {$toStatus} برای سفارش #{$orderId} مجاز نیست."
            );
        }

        $extra = match ($toStatus) {
            self::STATUS_PAID => ['paid_at' => now()],
            default           => [],
        };

        DB::table('orders')->where('id', $orderId)->update(array_merge(
            ['status' => $toStatus, 'updated_at' => now()],
            $extra,
        ));
    }

    /**
     * دریافت سفارش با قفل — باید داخل تراکنش استفاده شود
     *
     * @throws RuntimeException
     */
    public function findLocked(int $orderId): object
    {
        $order = DB::table('orders')
            ->where('id', $orderId)
            ->lockForUpdate()
            ->first();

        if (! $order) {
            throw new RuntimeException("سفارش #{$orderId} یافت نشد.");
        }

        return $order;
    }
}
