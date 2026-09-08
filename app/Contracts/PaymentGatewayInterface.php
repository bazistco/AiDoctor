<?php
// app/Contracts/PaymentGatewayInterface.php

namespace App\Contracts;

interface PaymentGatewayInterface
{
    /**
     * درخواست توکن پرداخت
     *
     * @return array{token: string, payment_url: string, expires_at: \Carbon\Carbon, raw: array}
     */
    public function request(int $amount, string $resNum, string $callbackUrl, array $options = []): array;

    /**
     * تایید تراکنش
     *
     * @return array{ref_id: string, rrn: string, amount: int, code: int, raw: array}
     */
    public function verify(string $refNum, int $expectedAmount): array;

    /**
     * برگشت وجه / اصلاح تراکنش
     *
     * @return array{code: int, raw: array}
     */
    public function reverse(string $refNum): array;
}
