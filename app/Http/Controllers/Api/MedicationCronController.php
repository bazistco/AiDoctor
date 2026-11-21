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
     * ارسال یادآوری برای داروهایی که امروز هستند و ۱ ساعت به موعد مصرفشان باقی مانده
     */
    public function sendReminders(Request $request)
    {
        // ۱. بررسی کلید امنیتی کران‌جاب
        if ($request->query('secret') !== config('app.cron_secret', 'sosmost666')) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        // ۲. مشخص کردن روز جاری هفته (سه حرفی کوچک: sun, mon, tue, ...)
        $currentDayKey = strtolower(now()->format('D'));

        // ۳. محاسبه زمان هدف: دقیقاً ۶۰ دقیقه بعد (کمتر از ۱ ساعت آینده)
        // برای مثال اگر الان ۱۲:۳۰ باشد، داروهای ساعت ۱۳:۳۰ هدف قرار می‌گیرند.
        $targetCarbon = now()->addHour();
        $englishTargetTime = $targetCarbon->format('H:i');
        $persianTargetTime = $this->convertNumbersToPersian($englishTargetTime);

        // ۴. کوئری واکشی داروهای امروز که مصرف نشده‌اند و زمان مصرفشان ۱ ساعت دیگر است
        $medications = DB::table('user_medications')
            ->join('users', 'user_medications.user_id', '=', 'users.id')
            ->where('user_medications.day_key', $currentDayKey)
            ->where(function ($query) use ($englishTargetTime, $persianTargetTime) {
                // پوشش هر دو فرمت فارسی و انگلیسی دیتابیس
                $query->where('user_medications.time', $persianTargetTime)
                    ->orWhere('user_medications.time', $englishTargetTime);
            })
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

        if ($medications->isEmpty()) {
            return response()->json([
                'status'         => true,
                'message'        => 'No medications found requiring reminder for ' . $englishTargetTime . ' (' . $persianTargetTime . ').',
                'target_time'    => $englishTargetTime
            ]);
        }

        // ۵. تجمیع داروها به ازای هر کاربر
        $medicationsByUser = $medications->groupBy('user_id');
        $sentCount = 0;

        foreach ($medicationsByUser as $userId => $userMedications) {
            $firstRecord = $userMedications->first();
            $medicationNames = $userMedications->pluck('medication_name')->implode('، ');

            $this->triggerNovuEvent(
                subscriberId: (string) $firstRecord->novu_subscriber_id,
                userName: $firstRecord->user_name ?? 'کاربر',
                medicationNames: $medicationNames,
                reminderTime: $firstRecord->time
            );

            $sentCount++;
        }

        return response()->json([
            'status'         => true,
            'message'        => 'Upcoming reminders processed successfully.',
            'target_time'    => $englishTargetTime,
            'users_notified' => $sentCount
        ]);
    }

    /**
     * ارسال Event به سرور Novu با متن اختصاصی یادآوری پیش از موعد
     */
    private function triggerNovuEvent(string $subscriberId, string $userName, string $medicationNames, string $reminderTime): void
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
                    'msg'   => "{$userName} عزیز، حدود ۱ ساعت دیگر (ساعت {$reminderTime}) زمان مصرف این داروهاست: {$medicationNames}",
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
     * تبدیل ارقام انگلیسی به فارسی
     */
    private function convertNumbersToPersian(string $string): string
    {
        $englishNumbers = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
        $persianNumbers = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];

        return str_replace($englishNumbers, $persianNumbers, $string);
    }
}
