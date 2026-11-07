<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class FinnotechService
{
    protected static string $clientId = 'medira';
    protected static string $clientSecret = '2BgcjAkbvwCR9is8qWyY';
    protected static string $nid = '0922521700';
    protected static string $baseUrl = 'https://api.finnotech.ir';

    /**
     * کلید کش توکن
     */
    protected static function getCacheKey(): string
    {
        return 'finnotech_token_' . static::$clientId;
    }

    /**
     * دریافت توکن دسترسی فینوتک با مدیریت هوشمند کش و زمان انقضا
     */
    public static function token(bool $forceRefresh = false): ?string
    {
        $cacheKey = static::getCacheKey();

        if ($forceRefresh) {
            Cache::forget($cacheKey);
        }

        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        try {
            $response = Http::withBasicAuth(static::$clientId, static::$clientSecret)
                ->asJson()
                ->timeout(15)
                ->post(static::$baseUrl . '/dev/v2/oauth2/token', [
                    'grant_type' => 'client_credentials',
                    'nid'        => static::$nid,
                    'scopes'     => 'facility:shahkar:get',
                ]);

            if ($response->successful()) {
                $data = $response->json();
                $token = $data['result']['value'] ?? null;

                if ($token) {
                    // فینوتک lifeTime را به میلی‌ثانیه برمی‌گرداند (در صورت نبودن، پیش‌فرض 3 ساعت)
                    $lifeTimeMs = $data['result']['lifeTime'] ?? 10800000;
                    $ttlSeconds = max((int) ($lifeTimeMs / 1000) - 300, 60); // ۵ دقیقه حاشیه امنیت

                    Cache::put($cacheKey, $token, $ttlSeconds);
                    return $token;
                }
            }

            Log::error('خطا در دریافت توکن فینوتک: ' . $response->body());
            return null;
        } catch (\Exception $e) {
            Log::error('استثنا در دریافت توکن فینوتک: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * استعلام تطبیق شماره موبایل و کد ملی از سامانه شاهکار
     *
     * @param string $mobile شماره موبایل (مثال: 09123456789)
     * @param string $nationalCode کد ملی ۱۰ رقمی
     * @param bool $isRetry آیا درخواست در حال تلاش مجدد پس از رفرش توکن است؟
     * @return array
     */
    public static function verifyShahkar(string $mobile, string $nationalCode, bool $isRetry = false): array
    {
        $token = static::token($isRetry);

        if (!$token) {
            return [
                'success' => false,
                'isValid' => false,
                'message' => 'امکان دریافت توکن احراز هویت از فینوتک وجود ندارد.'
            ];
        }

        $mobile = trim($mobile);
        $nationalCode = trim($nationalCode);
        $trackId = (string) Str::uuid();

        try {
            $endpoint = sprintf(
                '%s/facility/v2/clients/%s/shahkar/verify',
                static::$baseUrl,
                static::$clientId
            );

            $response = Http::withToken($token)
                ->acceptJson()
                ->timeout(15)
                ->get($endpoint, [
                    'mobile'       => $mobile,
                    'nationalCode' => $nationalCode,
                    'trackId'      => $trackId,
                ]);

            $json = $response->json();

            // بررسی خطای انقضای توکن (فینوتک برای انقضا گاهی 403 و کد UNAUTHORIZED برمی‌گرداند)
            $errorCode = $json['error']['code'] ?? '';
            $errorMessage = $json['error']['message'] ?? '';

            $isTokenExpired = in_array($response->status(), [401, 403])
                && ($errorCode === 'UNAUTHORIZED' || str_contains(strtolower($errorMessage), 'expiration'));

            if ($isTokenExpired) {
                Cache::forget(static::getCacheKey());

                // یک بار تلاش مجدد خودکار با توکن جدید
                if (!$isRetry) {
                    Log::info('توکن فینوتک منقضی شده بود؛ در حال دریافت توکن جدید و تلاش مجدد...');
                    return static::verifyShahkar($mobile, $nationalCode, true);
                }

                // اگر بعد از دریافت توکن جدید باز هم خطای انقضا داد، یعنی قرارداد اسکوپ در پنل فینوتک منقضی شده است
                Log::critical('تاریخ اعتبار دسترسی (Scope) شاهکار در پنل فینوتک به پایان رسیده است: ' . json_encode($json, JSON_UNESCAPED_UNICODE));
                return [
                    'success'  => false,
                    'isValid'  => false,
                    'message'  => 'اعتبار سرویس استعلام در پنل فینوتک به پایان رسیده است.',
                    'response' => $json
                ];
            }

            if ($response->successful() && ($json['status'] ?? '') === 'DONE') {
                $isValid = (bool) ($json['result']['isValid'] ?? false);

                return [
                    'success'  => true,
                    'isValid'  => $isValid,
                    'trackId'  => $json['trackId'] ?? $trackId,
                    'message'  => $isValid ? 'شماره موبایل و کد ملی تطابق دارند.' : 'شماره موبایل به نام این کد ملی ثبت نشده است.',
                    'response' => $json
                ];
            }

            // سایر خطاهای وب‌سرویس (مانند فرمت نامعتبر کد ملی یا قطعی شاهکار)
            $userMessage = $json['error']['message'] ?? 'خطا در استعلام از سامانه شاهکار';
            Log::warning('خطای سامانه شاهکار فینوتک: ' . json_encode($json, JSON_UNESCAPED_UNICODE));

            return [
                'success'  => false,
                'isValid'  => false,
                'message'  => $userMessage,
                'response' => $json
            ];

        } catch (\Exception $e) {
            Log::error('خطا در ارتباط با شاهکار: ' . $e->getMessage());
            return [
                'success' => false,
                'isValid' => false,
                'message' => 'خطای فنی در اتصال به سامانه استعلام.',
            ];
        }
    }
}
