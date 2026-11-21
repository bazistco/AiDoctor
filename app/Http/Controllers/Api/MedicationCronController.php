<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MedicationCronController extends Controller
{
    /**
     * وب‌سرویس اجرایی توسط کران‌جاب لینوکس
     * واکشی داروهای امروز که زمان مصرفشان کمتر از ۱ ساعت آینده است (بین اکنون تا ۱ ساعت بعد)
     */
    public function sendReminders(Request $request)
    {
        // ۱. بررسی کلید امنیتی کران‌جاب
        if ($request->query('secret') !== config('app.cron_secret', 'sosmost666')) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        // ۲. استخراج روز جاری هفته (sun, mon, tue, ...)
        $currentDayKey = strtolower(now()->format('D'));

        // ۳. تعیین بازه زمانی: از همین لحظه تا حداکثر ۶۰ دقیقه آینده
        $currentTime = now()->format('H:i');           // مثلا 14:15
        $oneHourLater = now()->addHour()->format('H:i'); // مثلا 15:15

        // ۴. واکشی داروهای امروز که هنوز مصرف نشده‌اند و کاربر دارای اشتراک Novu است
        $rawMedications = DB::table('user_medications')
            ->join('users', 'user_medications.user_id', '=', 'users.id')
            ->where('user_medications.day_key', $currentDayKey)
            ->where('user_medications.is_taken', 0)
            ->whereNotNull('users.novu_subscriber_id')
            ->where('users.novu_subscriber_id', '!=', '')
            ->select([
                'user_medications.id',
                'user_medications.user_id',
                'user_medications.name as medication_name',
                'user_medications.time',
                'user_medications.instruction',
                'users.name as user_name',
                'users.novu_subscriber_id',
            ])
            ->get();

        // ۵. فیلتر کردن رکوردهایی که زمان مصرفشان بین اکنون تا کمتر از ۱ ساعت دیگر است
        // (پشتیبانی کامل از ارقام فارسی و انگلیسی ذخیره شده در ستون time)
        $medications = $rawMedications->filter(function ($item) use ($currentTime, $oneHourLater) {
            if (empty($item->time)) {
                return false;
            }

            // نرمال‌سازی زمان دیتابیس به ارقام انگلیسی و فرمت استاندارد H:i
            $normalizedTime = $this->convertNumbersToEnglish(trim($item->time));

            // اگر به صورت تک رقمی ذخیره شده باشد (مثلاً 9:05)، پد صفر اضافه می‌شود
            if (strlen($normalizedTime) === 4 && str_contains($normalizedTime, ':')) {
                $normalizedTime = '0' . $normalizedTime;
            }

            // شرط: زمان مصرف بزرگتر یا مساوی اکنون و کوچکتر یا مساوی ۱ ساعت بعد باشد
            if ($oneHourLater >= $currentTime) {
                // حالت عادی روز (مثلا 14:00 تا 15:00)
                return $normalizedTime >= $currentTime && $normalizedTime <= $oneHourLater;
            } else {
                // حالت نیمه‌شب و تغییر روز (مثلا 23:30 تا 00:30)
                return $normalizedTime >= $currentTime || $normalizedTime <= $oneHourLater;
            }
        });

        if ($medications->isEmpty()) {
            return response()->json([
                'status'       => true,
                'message'      => "No medications found between {$currentTime} and {$oneHourLater}.",
                'current_time' => $currentTime,
                'until_time'   => $oneHourLater
            ]);
        }

        // ۶. تجمیع داروها به ازای هر کاربر جهت ارسال نوتیفیکیشن واحد
        $medicationsByUser = $medications->groupBy('user_id');
        $sentCount = 0;

        foreach ($medicationsByUser as $userId => $userMedications) {
            $firstRecord = $userMedications->first();
            $medicationNames = $userMedications->pluck('medication_name')->implode('، ');

            $this->triggerNovuEvent(
                subscriberId: (string) $firstRecord->novu_subscriber_id,
                userName: $firstRecord->user_name ?? 'کاربر',
                medicationNames: $medicationNames
            );

            $sentCount++;
        }

        return response()->json([
            'status'         => true,
            'message'        => 'Reminders processed successfully.',
            'from_time'      => $currentTime,
            'to_time'        => $oneHourLater,
            'users_notified' => $sentCount
        ]);
    }

    /**
     * ارسال Event به سرور Novu
     */
    private function triggerNovuEvent(string $subscriberId, string $userName, string $medicationNames): void
    {
        try {
            $novuUrl = 'http://185.222.163.113:3000/v1/events/trigger';
            $novuApiKey = config('services.novu.api_key');

            Http::withHeaders([
                'Authorization' => 'ApiKey ' . $novuApiKey,
                'Content-Type'  => 'application/json',
            ])->timeout(5)->post($novuUrl, [
                'name' => 'medication-reminder',
                'to'   => [
                    'subscriberId' => $subscriberId
                ],
                'payload' => [
                    'title' => '⏳ یادآوری: کمتر از ۱ ساعت تا مصرف دارو',
                    'msg'   => "{$userName} عزیز، کمتر از یک ساعت دیگر زمان مصرف این داروهاست: {$medicationNames}",
                    'link'  => 'https://app.mediraai.com/medications'
                ]
            ]);
        } catch (\Throwable $e) {
            Log::error('Cron Novu 1-Hour Reminder Error: ' . $e->getMessage(), [
                'subscriber_id' => $subscriberId
            ]);
        }
    }

    /**
     * متد کمکی برای تبدیل ارقام فارسی و عربی به انگلیسی جهت مقایسه‌ی دقیق زمان
     */
    private function convertNumbersToEnglish(string $string): string
    {
        $persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
        $arabic  = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
        $english = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

        $string = str_replace($persian, $english, $string);
        return str_replace($arabic, $english, $string);
    }
}
