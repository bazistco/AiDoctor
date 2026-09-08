<?php

declare(strict_types=1);

namespace App\Services\Payment\Gateways;

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

    public function __construct()
    {
        $this->terminalId = (string) config('payment.saman.terminal_id');
        $this->tokenUrl   = (string) config('payment.saman.token_url');
        $this->paymentUrl = (string) config('payment.saman.payment_url');
        $this->verifyUrl  = (string) config('payment.saman.verify_url');
        $this->reverseUrl = (string) config('payment.saman.reverse_url');
        $this->timeout    = (int)    config('payment.saman.timeout', 30);
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
        $payload = array_filter([
            'TerminalId'  => $this->terminalId,
            'ResNum'      => $resNum,
            'Amount'      => $amount,
            'RedirectURL' => $callbackUrl,
            'CellNumber'  => $cellNumber,
        ]);
        dump($payload);

        $response = $this->post($this->tokenUrl, $payload);
        dump($response);
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
            'RefNum'     => $refNum,
            'TerminalId' => $this->terminalId,
        ];

        $response = $this->post($this->verifyUrl, $payload);

        $detail = $response['TransactionDetail'] ?? $response;

        // عدد مثبت = مبلغ تأییدشده؛ عدد منفی = خطا (صفحه 15 مستند)
        $verifiedAmount = (int) ($detail['AffectiveAmount'] ?? $response['AffectiveAmount'] ?? -1);

        if ($verifiedAmount <= 0) {
            $code = $response['ResultCode'] ?? $response['errorCode'] ?? $verifiedAmount;
            throw new RuntimeException("Verify failed. ResultCode: {$code}");
        }

        return [
            'ref_id'          => (string) ($detail['RefNum']          ?? $refNum),
            'trace_no'        => (string) ($detail['TraceNo']         ?? ''),
            'rrn'             => (string) ($detail['RRN']             ?? ''),
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
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $body  = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno || $body === false) {
            throw new RuntimeException("cURL error ({$errno}): {$error}");
        }

        if ($httpCode >= 500) {
            throw new RuntimeException("Saman server error. HTTP {$httpCode}");
        }

        $decoded = json_decode((string) $body, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new RuntimeException("Invalid JSON from Saman: {$body}");
        }

        return $decoded;
    }
}
