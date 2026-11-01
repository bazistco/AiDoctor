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
    // در صورت استفاده از پروداکشن از api.finnotech.ir استفاده کنید:
    protected static string $baseUrl = 'https://api.finnotech.ir';

    /**
     * دریافت توکن دسترسی فینوتک با کش هوشمند
     */
    public static function token(): ?string
    {
        // کش کردن توکن به مدت زمان اعتبار آن (پیش‌فرض ۴ ساعت منهای چند دقیقه حاشیه امنیت)
        return Cache::remember('finnotech_token_' . static::$clientId, 14000, function () {
            try {
                $response = Http::withBasicAuth(static::$clientId, static::$clientSecret)
                    ->asJson()
                    ->post(static::$baseUrl . '/dev/v2/oauth2/token', [
                        'grant_type' => 'client_credentials',
                        'nid'        => '0922521700', // در صورت نیاز فینوتک به NID سازمان/حقیقی
                        'scopes'     => 'facility:shahkar:get',
                    ]);

                if ($response->successful()) {
                    $data = $response->json();
                    return $data['result']['value'] ?? null;
                }

                Log::error('خطا در دریافت توکن فینوتک: ' . $response->body());
                return null;
            } catch (\Exception $e) {
                Log::error('استثنا در توکن فینوتک: ' . $e->getMessage());
                return null;
            }
        });
    }

    /**
     * استعلام تطبیق شماره موبایل و کد ملی از سامانه شاهکار
     *
     * @param string $mobile شماره موبایل (مثال: 09123456789)
     * @param string $nationalCode کد ملی ۱۰ رقمی
     * @return array ['success' => bool, 'isValid' => bool, 'message' => string, 'data' => mixed]
     */
    public static function verifyShahkar(string $mobile, string $nationalCode): array
    {
        $token = static::token();

        if (!$token) {
            // یک بار پاک‌سازی کش در صورت نامعتبر بودن توکن
            Cache::forget('finnotech_token_' . static::$clientId);
            return [
                'success' => false,
                'isValid' => false,
                'message' => 'امکان دریافت توکن احراز هویت از فینوتک وجود ندارد.'
            ];
        }

        // تمیزسازی ورودی‌ها
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
                ->get($endpoint, [
                    'mobile'       => $mobile,
                    'nationalCode' => $nationalCode,
                    'trackId'      => $trackId,
                ]);

            $json = $response->json();

            // در صورت منقضی شدن توکن (کد 401)، کش حذف شده و یک بار دیگر مجدد تلاش می‌شود
            if ($response->status() === 401) {
                Cache::forget('finnotech_token_' . static::$clientId);
            }

            if ($response->successful() && isset($json['status']) && $json['status'] === 'DONE') {
                $isValid = (bool) ($json['result']['isValid'] ?? false);

                return [
                    'success'  => true,
                    'isValid'  => $isValid,
                    'trackId'  => $json['trackId'] ?? $trackId,
                    'message'  => $isValid ? 'شماره موبایل و کد ملی تطابق دارند.' : 'شماره موبایل به نام این کد ملی ثبت نشده است.',
                    'response' => $json
                ];
            }

            // در صورت بروز خطا در پاسخ وب‌سرویس
            $errorMessage = $json['error']['message'] ?? 'خطا در استعلام از سامانه شاهکار';
            Log::warning('خطای سامانه شاهکار فینوتک: ' . json_encode($json, JSON_UNESCAPED_UNICODE));

            return [
                'success' => false,
                'isValid' => false,
                'message' => $errorMessage,
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
