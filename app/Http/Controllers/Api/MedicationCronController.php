<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MedicationCronController extends Controller
{
    /**
     * وب‌سرویس اجرایی توسط کران‌جاب لینوکس با استفاده از DB::table
     */
    public function sendReminders(Request $request)
    {
        // ۱. بررسی کلید امنیتی جهت جلوگیری از دسترسی مستقیم و غیرمجاز
        if ($request->query('secret') !== config('app.cron_secret', 'sosmost666')) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        // ۲. محاسبه روز جاری هفته (sun, mon, tue, ...)
        $currentDayKey = strtolower(now()->format('D'));

        // ۳. استخراج ساعت فعلی به دو فرمت انگلیسی و فارسی برای تطبیق دقیق با دیتابیس
        $englishTime = now()->format('H:i');
        $persianTime = $this->convertNumbersToPersian($englishTime);

        // ۴. کوئری با DB::table به همراه Join با جدول users جهت استخراج subscriber_id و نام کاربر
        $medications = DB::table('user_medications')
            ->join('users', 'user_medications.user_id', '=', 'users.id')
            ->where('user_medications.day_key', $currentDayKey)
            ->where(function ($query) use ($englishTime, $persianTime) {
                $query->where('user_medications.time', $persianTime)
                    ->orWhere('user_medications.time', $englishTime);
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
                'status'  => true,
                'message' => 'No medications found for current minute (' . $englishTime . ' / ' . $persianTime . ').'
            ]);
        }

        // ۵. گروه‌بندی داروها بر اساس user_id جهت ارسال یک نوتیفیکیشن تجمیعی به هر کاربر
        $medicationsByUser = $medications->groupBy('user_id');

        $sentCount = 0;

        foreach ($medicationsByUser as $userId => $userMedications) {
            $firstRecord = $userMedications->first();

            // استخراج اسامی همه داروهای این کاربر برای این ساعت
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
                    'title' => '⏰ یادآوری مصرف دارو',
                    'msg'   => "{$userName} عزیز، زمان مصرف این داروها فرا رسیده است: {$medicationNames}",
                    'link'  => 'https://app.mediraai.com/medications'
                ]
            ]);
        } catch (\Throwable $e) {
            Log::error('Cron Novu Medication Reminder Error: ' . $e->getMessage(), [
                'subscriber_id' => $subscriberId
            ]);
        }
    }

    /**
     * متد تبدیل ارقام انگلیسی به فارسی
     */
    private function convertNumbersToPersian(string $string): string
    {
        $englishNumbers = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
        $persianNumbers = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];

        return str_replace($englishNumbers, $persianNumbers, $string);
    }
}
