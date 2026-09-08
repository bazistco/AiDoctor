<?php

declare(strict_types=1);

namespace App\Services\Payment\Gateways;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * ارتباط مستقیم با API درگاه سامان
 * هیچ منطق تجاری، DB call، یا State ندارد.
 * Reference: مستند فنی نسخه ۳.۶
 */
class SamanGateway
{
    private string $terminalId;
    private string $tokenUrl;
    private string $paymentUrl;
    private string $verifyUrl;
    private string $reverseUrl;
    private int    $timeout;
    private int $token_expire_min;

    public function __construct()
    {
        $this->terminalId = (string) config('payment.saman.terminal_id');
        $this->tokenUrl   = (string) config('payment.saman.token_url');
        $this->paymentUrl = (string) config('payment.saman.payment_url');
        $this->verifyUrl  = (string) config('payment.saman.verify_url');
        $this->reverseUrl = (string) config('payment.saman.reverse_url');
        $this->timeout    = (int)    config('payment.saman.timeout', 30);
        $this->token_expire_min   = (int)    config('payment.saman.token_expiry_min', 20);
    }

    /**
     * دریافت توکن پرداخت (صفحه 8-10 مستند)
     *
     * @return array{token: string, payment_url: string}
     * @throws RuntimeException
     */
    public function requestToken(
        int     $amount,
        string  $resNum,
        string  $callbackUrl,
        ?string $cellNumber = null,
    ): array {
        $payload = [
            'Action'           => 'token',
            'TerminalId'       => $this->terminalId,
            'ResNum'           => $resNum,
            'Amount'           => $amount,
            'RedirectURL'      => $callbackUrl,
            'TokenExpiryInMin' => $this->token_expire_min,
        ];

        if ($cellNumber !== null && $cellNumber !== '') {
            $payload['CellNumber'] = $cellNumber;
        }

        $response = $this->post($this->tokenUrl, $payload);
        // status == 1 = موفق (صفحه 10 مستند)
        if ((int) ($response['status'] ?? 0) !== 1) {
            $code = $response['errorCode'] ?? $response['status'] ?? 'unknown';
            throw new RuntimeException("Token request failed. errorCode: {$code}");
        }

        $token = (string) ($response['token'] ?? '');

        if ($token === '') {
            throw new RuntimeException('توکن خالی از سامان دریافت شد.');
        }

        return [
            'token'       => $token,
            'payment_url' => $this->paymentUrl . $token,
            'raw'         => $response,
        ];
    }

    /**
     * تأیید تراکنش (صفحه 13-15 مستند)
     *
     * @return array{ref_id: string, trace_no: string, rrn: string, verified_amount: int, raw: array}
     * @throws RuntimeException
     */
    public function verify(string $refNum): array
    {
        $payload = [
            'RefNum'         => $refNum,
            'TerminalNumber' => (int) $this->terminalId,
        ];

        $response = $this->post($this->verifyUrl, $payload);

        // بررسی ResultCode == 0 و Success == true (صفحه 17)
        $resultCode = (int) ($response['ResultCode'] ?? -1);
        $success    = (bool) ($response['Success']    ?? false);

        if ($resultCode !== 0 || ! $success) {
            $desc = $response['ResultDescription'] ?? 'unknown';
            throw new RuntimeException("Verify failed. ResultCode: {$resultCode} — {$desc}");
        }

        $detail = $response['TransactionDetail'] ?? [];

        $verifiedAmount = (int) ($detail['AffectiveAmount'] ?? -1);

        // حالت B مستند: مبلغ نابرابر → باید برگشت بخورد
        if ($verifiedAmount <= 0) {
            throw new RuntimeException("Verify: AffectiveAmount invalid ({$verifiedAmount})");
        }

        return [
            'ref_id'          => (string) ($detail['RefNum']    ?? $refNum),
            'trace_no'        => (string) ($detail['StraceNo']  ?? ''),   // ← StraceNo نه TraceNo
            'rrn'             => (string) ($detail['RRN']       ?? ''),
            'verified_amount' => $verifiedAmount,
            'raw'             => $response,
        ];
    }


    /**
     * استرداد وجه (صفحه 30 مستند)
     *
     * @throws RuntimeException
     */
    public function reverse(string $refNum, string $rrn, int $amount): array
    {
        $payload = [
            'RefNum'     => $refNum,
            'RRN'        => $rrn,
            'Amount'     => $amount,
            'TerminalId' => $this->terminalId,
        ];

        $response = $this->post($this->reverseUrl, $payload);

        // ResultCode == 0 = موفق
        if ((int) ($response['ResultCode'] ?? -1) !== 0) {
            $code = $response['ResultCode'] ?? 'unknown';
            throw new RuntimeException("Reverse failed. ResultCode: {$code}");
        }

        return $response;
    }

    /**
     * تفسیر State کالبک (صفحه 26 مستند)
     * برگرداندن true یعنی پرداخت موفق
     */
    public function isCallbackSuccessful(string $state): bool
    {
        return strtoupper($state) === 'OK';
    }

    /**
     * ترجمه State به پیام فارسی
     */
    public function describeState(string $state): string
    {
        return match (strtoupper($state)) {
            'OK'              => 'پرداخت موفق',
            'FAILED'          => 'پرداخت ناموفق',
            'CANCELED'        => 'لغو توسط کاربر',
            'SUSPENDED'       => 'حساب فروشنده معلق',
            'CALL_SUPPORT'    => 'تماس با پشتیبانی',
            'DUPLICATE_ORDER' => 'سفارش تکراری',
            default           => "خطا: {$state}",
        };
    }

    // ─── HTTP ──────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     * @throws RuntimeException
     */
    private function post(string $url, array $payload): array
    {
        $response = Http::timeout($this->timeout)
            ->withHeaders(['Accept' => 'application/json'])
            ->asJson()
            ->post($url, $payload);

        if ($response->serverError()) {
            throw new RuntimeException("Saman server error. HTTP {$response->status()}");
        }

        $decoded = $response->json();

        if (! is_array($decoded)) {
            throw new RuntimeException("Invalid JSON from Saman: {$response->body()}");
        }

        return $decoded;
    }

}
