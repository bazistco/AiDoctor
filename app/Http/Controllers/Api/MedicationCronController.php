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
    public function sendReminders(Request $request)
    {
        // ۱. بررسی کلید امنیتی کران‌جاب
        if ($request->query('secret') !== config('app.cron_secret', 'sosmost666')) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $now = Carbon::now();
        $currentDayKey = strtolower($now->format('D')); // sun, mon, tue, ...

        // ۲. واکشی داروهای مصرف‌نشده امروز برای کاربران دارای Novu Subscriber ID
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

        // ۳. دسته‌بندی داروها در دو بازه: ۳۰ دقیقه مانده (30m) و سرِ موقع (on_time)
        // فرض: کران‌جاب هر ۱ تا ۵ دقیقه اجرا می‌شود.
        // برای 30m: بین 28 تا 32 دقیقه آینده
        // برای on_time: بین 2 دقیقه قبل تا 2 دقیقه بعد از زمان مقرر
        $dueMedications = collect();

        foreach ($rawMedications as $item) {
            $medTime = $this->parseMedicationTimeToday((string) $item->time, $now);
            if (!$medTime) {
                continue;
            }

            // تفاوت دقیق به دقیقه نسبت به الان (مثبت یعنی در آینده است، منفی یعنی گذشته است)
            $diffInMinutes = $now->diffInMinutes($medTime, false);

            $reminderType = null;

            if ($diffInMinutes >= 28 && $diffInMinutes <= 32) {
                $reminderType = '30m';
            } elseif ($diffInMinutes >= -2 && $diffInMinutes <= 2) {
                $reminderType = 'on_time';
            }

            if ($reminderType !== null) {
                $clone = clone $item;
                $clone->reminder_type = $reminderType;
                $clone->normalized_time = $medTime->format('H:i');
                $dueMedications->push($clone);
            }
        }

        if ($dueMedications->isEmpty()) {
            return response()->json([
                'status'       => true,
                'message'      => 'No medications due for 30-min or on-time reminders.',
                'current_time' => $now->format('H:i'),
            ]);
        }

        // ۴. واکشی مشاهده‌کنندگان تقویم اشتراک‌گذاری‌شده
        $ownerIds = $dueMedications->pluck('user_id')->unique()->values()->all();

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

        // ۵. گروه‌بندی بر اساس صاحب تقویم و نوع یادآور (30m یا on_time)
        $groupedByOwnerAndType = $dueMedications->groupBy(function ($item) {
            return $item->user_id . '_' . $item->reminder_type;
        });

        $ownersNotified  = 0;
        $viewersNotified = 0;

        foreach ($groupedByOwnerAndType as $group) {
            $firstRecord     = $group->first();
            $ownerId         = (int) $firstRecord->user_id;
            $reminderType    = $firstRecord->reminder_type;
            $ownerName       = $firstRecord->user_name ?? 'کاربر';
            $medicationNames = $group->pluck('medication_name')->unique()->implode('، ');

            if ($reminderType === '30m') {
                $ownerTitle   = "⏳ ۳۰ دقیقه تا مصرف دارو: {$medicationNames}";
                $ownerMessage = "{$ownerName} عزیز، حدود ۳۰ دقیقه دیگر زمان مصرف داروهای فوق است.";
                $viewerTitle  = "⏳ یادآوری ۳۰ دقیقه‌ای داروی {$ownerName}";
                $viewerMsg    = "حدود ۳۰ دقیقه دیگر زمان مصرف داروهای {$ownerName} است: {$medicationNames}";
            } else {
                $ownerTitle   = "💊 زمان مصرف دارو رسید: {$medicationNames}";
                $ownerMessage = "{$ownerName} عزیز، اکنون زمان مصرف داروهای زیر است: {$medicationNames}";
                $viewerTitle  = "💊 زمان مصرف داروی {$ownerName}";
                $viewerMsg    = "اکنون زمان مصرف داروهای {$ownerName} است: {$medicationNames}";
            }

            // ارسال برای صاحب تقویم
            $this->triggerNovuEvent(
                subscriberId: (string) $firstRecord->novu_subscriber_id,
                title: $ownerTitle,
                message: $ownerMessage,
                link: 'https://app.mediraai.com/pill-organizer'
            );
            $ownersNotified++;

            // ارسال برای مشاهده‌کنندگان
            $viewers = $sharesByOwner->get($ownerId, collect());

            foreach ($viewers as $viewer) {
                if ((int) $viewer->viewer_user_id === $ownerId) {
                    continue;
                }

                $this->triggerNovuEvent(
                    subscriberId: (string) $viewer->viewer_novu_subscriber_id,
                    title: $viewerTitle,
                    message: $viewerMsg,
                    link: "https://app.mediraai.com/pill-organizer/shared/{$ownerId}"
                );
                $viewersNotified++;
            }
        }

        return response()->json([
            'status'           => true,
            'message'          => 'Reminders processed successfully.',
            'current_time'     => $now->format('H:i'),
            'owners_notified'  => $ownersNotified,
            'viewers_notified' => $viewersNotified,
            'total_notified'   => $ownersNotified + $viewersNotified,
        ]);
    }

    private function parseMedicationTimeToday(string $rawTime, Carbon $now): ?Carbon
    {
        $clean = trim($this->convertNumbersToEnglish($rawTime));
        if ($clean === '') {
            return null;
        }

        // پشتیبانی از فرمت‌های H:i یا HH:MM یا H:i:s
        foreach (['H:i:s', 'H:i', 'G:i'] as $format) {
            try {
                $parsed = Carbon::createFromFormat($format, $clean, $now->getTimezone());
                if ($parsed !== false) {
                    return $now->copy()->setTime(
                        $parsed->hour,
                        $parsed->minute,
                        0
                    );
                }
            } catch (\Throwable) {
                // تلاش با فرمت بعدی
            }
        }

        return null;
    }

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
            Log::error('Cron Novu Reminder Error: ' . $e->getMessage(), [
                'subscriber_id' => $subscriberId,
            ]);
        }
    }

    private function convertNumbersToEnglish(string $string): string
    {
        $persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
        $arabic  = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
        $english = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

        $string = str_replace($persian, $english, $string);
        return str_replace($arabic, $english, $string);
    }
}
