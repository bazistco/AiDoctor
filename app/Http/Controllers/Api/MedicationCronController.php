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
     * و ارسال نوتیفیکیشن برای خود کاربر و مشاهده‌کنندگان تقویم اشتراک‌گذاری‌شده او
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
        $currentTime  = now()->format('H:i');           // مثلا 14:15
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
        $medications = $rawMedications->filter(function ($item) use ($currentTime, $oneHourLater) {
            if (empty($item->time)) {
                return false;
            }

            $normalizedTime = $this->convertNumbersToEnglish(trim($item->time));

            if (strlen($normalizedTime) === 4 && str_contains($normalizedTime, ':')) {
                $normalizedTime = '0' . $normalizedTime;
            }

            if ($oneHourLater >= $currentTime) {
                return $normalizedTime >= $currentTime && $normalizedTime <= $oneHourLater;
            }

            // حالت نیمه‌شب و تغییر روز (مثلا 23:30 تا 00:30)
            return $normalizedTime >= $currentTime || $normalizedTime <= $oneHourLater;
        });

        if ($medications->isEmpty()) {
            return response()->json([
                'status'       => true,
                'message'      => "No medications found between {$currentTime} and {$oneHourLater}.",
                'current_time' => $currentTime,
                'until_time'   => $oneHourLater,
            ]);
        }

        // ۶. واکشی همه مشاهده‌کنندگان تقویم‌های اشتراک‌گذاری‌شده به ازای صاحبان دارو
        $ownerIds = $medications->pluck('user_id')->unique()->values()->all();

        $sharesByOwner = DB::table('pill_organizer_shares as s')
            ->join('users as v', 'v.id', '=', 's.viewer_user_id')
            ->whereIn('s.owner_user_id', $ownerIds)
            ->whereNotNull('v.novu_subscriber_id')
            ->where('v.novu_subscriber_id', '!=', '')
            ->select([
                's.owner_user_id',
                's.viewer_user_id',
                'v.name as viewer_name',
                'v.novu_subscriber_id as viewer_novu_subscriber_id',
            ])
            ->get()
            ->groupBy('owner_user_id');

        // ۷. تجمیع داروها به ازای هر صاحب تقویم و ارسال نوتیفیکیشن برای خود فرد و مشاهده‌کنندگان
        $medicationsByUser = $medications->groupBy('user_id');
        $ownersNotified    = 0;
        $viewersNotified   = 0;

        foreach ($medicationsByUser as $ownerId => $userMedications) {
            $firstRecord     = $userMedications->first();
            $ownerName       = $firstRecord->user_name ?? 'کاربر';
            $medicationNames = $userMedications->pluck('medication_name')->implode('، ');

            // ارسال برای خود صاحب تقویم
            $this->triggerNovuEvent(
                subscriberId: (string) $firstRecord->novu_subscriber_id,
                title: '⏳ یادآوری: کمتر از ۱ ساعت تا مصرف دارو',
                message: "{$ownerName} عزیز، کمتر از یک ساعت دیگر زمان مصرف این داروهاست: {$medicationNames}",
                link: 'https://app.mediraai.com/medications'
            );
            $ownersNotified++;

            // ارسال برای کاربرانی که تقویم این فرد را دنبال می‌کنند
            $viewers = $sharesByOwner->get((int) $ownerId, collect());

            foreach ($viewers as $viewer) {
                // جلوگیری از ارسال تکراری در صورت وجود رکورد هم‌نام یا خود کاربر
                if ((int) $viewer->viewer_user_id === (int) $ownerId) {
                    continue;
                }

                $this->triggerNovuEvent(
                    subscriberId: (string) $viewer->viewer_novu_subscriber_id,
                    title: "👥 یادآوری داروی {$ownerName}",
                    message: "کمتر از یک ساعت دیگر زمان مصرف داروهای {$ownerName} است: {$medicationNames}",
                    link: "https://app.mediraai.com/pill-organizer/shared/{$ownerId}"
                );
                $viewersNotified++;
            }
        }

        return response()->json([
            'status'           => true,
            'message'          => 'Reminders processed successfully.',
            'from_time'        => $currentTime,
            'to_time'          => $oneHourLater,
            'owners_notified'  => $ownersNotified,
            'viewers_notified' => $viewersNotified,
            'total_notified'   => $ownersNotified + $viewersNotified,
        ]);
    }

    /**
     * ارسال Event به سرور Novu
     */
    private function triggerNovuEvent(string $subscriberId, string $title, string $message, string $link): void
    {
        try {
            $novuUrl    = 'http://185.222.163.113:3000/v1/events/trigger';
            $novuApiKey = config('services.novu.api_key');

            Http::withHeaders([
                'Authorization' => 'ApiKey ' . $novuApiKey,
                'Content-Type'  => 'application/json',
            ])->timeout(5)->post($novuUrl, [
                'name' => 'medication-reminder',
                'to'   => [
                    'subscriberId' => $subscriberId,
                ],
                'payload' => [
                    'title' => $title,
                    'msg'   => $message,
                    'link'  => $link,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('Cron Novu 1-Hour Reminder Error: ' . $e->getMessage(), [
                'subscriber_id' => $subscriberId,
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
