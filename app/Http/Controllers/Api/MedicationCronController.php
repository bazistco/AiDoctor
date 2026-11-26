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
        if ($request->query('secret') !== config('app.cron_secret', 'sosmost666')) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $now = Carbon::now();
        $todayDate = $now->toDateString(); // YYYY-MM-DD
        $currentDayKey = strtolower($now->format('D')); // sun, mon, tue, ...

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

        // برای کران‌جاب هر ۱ دقیقه:
        // 30m: بین 29 تا 30 دقیقه مانده (یا 28..30 برای اطمینان از تیک کران)
        // on_time: بین 0 تا 1 دقیقه گذشته/فعلی (یا -1..1)
        $candidates = collect();

        foreach ($rawMedications as $item) {
            $medTime = $this->parseMedicationTimeToday((string) $item->time, $now);
            if (!$medTime) {
                continue;
            }

            $diffInMinutes = $now->diffInMinutes($medTime, false);

            $reminderType = null;
            if ($diffInMinutes >= 29 && $diffInMinutes <= 30) {
                $reminderType = '30m';
            } elseif ($diffInMinutes >= -1 && $diffInMinutes <= 0) {
                $reminderType = 'on_time';
            }

            if ($reminderType !== null) {
                $clone = clone $item;
                $clone->reminder_type = $reminderType;
                $candidates->push($clone);
            }
        }

        if ($candidates->isEmpty()) {
            return response()->json([
                'status'       => true,
                'message'      => 'No candidate medications at this minute.',
                'current_time' => $now->format('H:i'),
            ]);
        }

        // واکشی لاگ‌های ثبت‌شده امروز برای داروهای کاندید
        $candidateMedIds = $candidates->pluck('id')->unique()->values()->all();

        $existingLogs = DB::table('medication_reminder_logs')
            ->whereIn('user_medication_id', $candidateMedIds)
            ->where('remind_date', $todayDate)
            ->get()
            ->mapWithKeys(function ($row) {
                return ["{$row->user_medication_id}_{$row->reminder_type}" => true];
            });

        // ثبت اتمیک لاگ و انتخاب فقط داروهایی که قبلاً ارسال نشده‌اند
        $unsentMedications = collect();

        foreach ($candidates as $med) {
            $key = "{$med->id}_{$med->reminder_type}";
            if ($existingLogs->has($key)) {
                continue;
            }

            $inserted = DB::table('medication_reminder_logs')->insertOrIgnore([
                'user_medication_id' => $med->id,
                'user_id'            => $med->user_id,
                'reminder_type'      => $med->reminder_type,
                'remind_date'        => $todayDate,
                'sent_at'            => $now->toDateTimeString(),
                'created_at'         => $now->toDateTimeString(),
                'updated_at'         => $now->toDateTimeString(),
            ]);

            // اگر درج انجام شد یعنی این کران‌جاب مالک ارسال این یادآور است
            if ($inserted > 0) {
                $unsentMedications->push($med);
            }
        }

        if ($unsentMedications->isEmpty()) {
            return response()->json([
                'status'       => true,
                'message'      => 'All due reminders for this minute were already sent.',
                'current_time' => $now->format('H:i'),
            ]);
        }

        $ownerIds = $unsentMedications->pluck('user_id')->unique()->values()->all();

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

        $groupedByOwnerAndType = $unsentMedications->groupBy(function ($item) {
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

            $this->triggerNovuEvent(
                subscriberId: (string) $firstRecord->novu_subscriber_id,
                title: $ownerTitle,
                message: $ownerMessage,
                link: 'https://app.mediraai.com/pill-organizer'
            );
            $ownersNotified++;

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
