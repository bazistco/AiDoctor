<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Notifications\UpcomingPeriodReminder;
use Carbon\Carbon;

class PeriodCronController extends Controller
{
    /**
     * وب‌سرویس بررسی و ارسال اعلان‌های نزدیک شدن به پریود
     */
    public function sendUpcomingReminders(Request $request)
    {
        // بررسی توکن امنیتی برای جلوگیری از فراخوانی غیرمجاز
        $cronSecret = config('services.cron.secret', env('CRON_SECRET_KEY'));
        $providedSecret = $request->header('X-CRON-KEY') ?? $request->query('key');

        if (!$cronSecret || $providedSecret !== $cronSecret) {
            return response()->json([
                'status'  => false,
                'message' => 'دسترسی غیرمجاز است.',
            ], 403);
        }

        // تعداد روز هدف مانده به پریود (پیش‌فرض: ۲ روز)
        $targetDaysLeft = (int) $request->input('days', 2);
        $today = now()->startOfDay();

        // واکشی ردیاب‌ها به همراه اطلاعات کاربر و پارتنر
        $trackers = DB::table('period_trackers')
            ->leftJoin('period_partner_links', 'period_trackers.id', '=', 'period_partner_links.period_tracker_id')
            ->select(
                'period_trackers.id as tracker_id',
                'period_trackers.user_id',
                'period_trackers.last_period_start_date',
                'period_trackers.cycle_length',
                'period_partner_links.partner_user_id'
            )
            ->whereNotNull('period_trackers.last_period_start_date')
            ->get();

        $userNotified = 0;
        $partnerNotified = 0;

        foreach ($trackers as $item) {
            $lastStart = Carbon::parse($item->last_period_start_date)->startOfDay();
            $cycleLength = (int) ($item->cycle_length ?? 28);

            // محاسبه نزدیک‌ترین پریود بعدی
            $nextPeriod = $lastStart->copy()->addDays($cycleLength);
            while ($nextPeriod->lt($today)) {
                $nextPeriod->addDays($cycleLength);
            }

            $daysRemaining = (int) $today->diffInDays($nextPeriod, false);

            if ($daysRemaining === $targetDaysLeft) {
                // ارسال اعلان به کاربر اصلی
                $user = User::find($item->user_id);
                if ($user) {
                    $user->notify(new UpcomingPeriodReminder($daysRemaining, false));
                    $userNotified++;

                    // ارسال اعلان به پارتنر در صورت وجود ارتباط فعال
                    if ($item->partner_user_id) {
                        $partner = User::find($item->partner_user_id);
                        if ($partner) {
                            $partner->notify(new UpcomingPeriodReminder($daysRemaining, true, $user->name));
                            $partnerNotified++;
                        }
                    }
                }
            }
        }

        return response()->json([
            'status'  => true,
            'message' => 'عملیات ارسال نوتیفیکیشن با موفقیت انجام شد.',
            'data'    => [
                'target_days_left'  => $targetDaysLeft,
                'user_reminders'    => $userNotified,
                'partner_reminders' => $partnerNotified,
                'total_sent'        => $userNotified + $partnerNotified,
                'executed_at'       => now()->toDateTimeString(),
            ],
        ]);
    }
}
