<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Carbon\Carbon;

class PeriodTrackerController extends Controller
{
    /**
     * تولید کد پارتنر یکتا و رندوم
     */
    private function generatePartnerCode()
    {
        do {
            $code = strtoupper(Str::random(8));
        } while (DB::table('period_trackers')->where('partner_code', $code)->exists());
        return $code;
    }

    public function show(Request $request)
    {
        $userId = auth()->id();

        $tracker = DB::table('period_trackers')
            ->where('user_id', $userId)
            ->first();

        if (!$tracker) {
            return response()->json([
                'status' => false,
                'message' => 'اطلاعات پریود ثبت نشده است.',
                'data' => null,
            ], 404);
        }

        return response()->json([
            'status' => true,
            'message' => 'اطلاعات با موفقیت دریافت شد.',
            'data' => $tracker,
        ]);
    }

    public function storeOrUpdate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'last_period_start_date' => ['required', 'date'],
            'last_period_end_date'   => ['nullable', 'date', 'after_or_equal:last_period_start_date'],
            'cycle_length'           => ['nullable', 'integer', 'min:20', 'max:45'],
            'period_length'          => ['nullable', 'integer', 'min:2', 'max:10'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'داده‌های ورودی نامعتبر است.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $userId = auth()->id();

        $payload = [
            'last_period_start_date' => $request->last_period_start_date,
            'last_period_end_date'   => $request->last_period_end_date,
            'cycle_length'           => $request->cycle_length ?? 28,
            'period_length'          => $request->period_length ?? 5,
            'updated_at'             => now(),
        ];

        $tracker = DB::table('period_trackers')
            ->where('user_id', $userId)
            ->first();

        if ($tracker) {
            // برای رکوردهای قدیمی که فاقد کد پارتنر هستند
            if (empty($tracker->partner_code)) {
                $payload['partner_code'] = $this->generatePartnerCode();
            }

            DB::table('period_trackers')
                ->where('user_id', $userId)
                ->update($payload);

            $updated = DB::table('period_trackers')
                ->where('user_id', $userId)
                ->first();

            return response()->json([
                'status' => true,
                'message' => 'اطلاعات پریود بروزرسانی شد.',
                'data' => $updated,
            ]);
        }

        $payload['user_id'] = $userId;
        $payload['partner_code'] = $this->generatePartnerCode();
        $payload['created_at'] = now();

        $id = DB::table('period_trackers')->insertGetId($payload);
        $created = DB::table('period_trackers')->where('id', $id)->first();

        return response()->json([
            'status' => true,
            'message' => 'اطلاعات پریود ثبت شد.',
            'data' => $created,
        ], 201);
    }

    public function storePeriodLog(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'start_date' => ['required', 'date'],
            'end_date'   => ['nullable', 'date', 'after_or_equal:start_date'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'داده‌های ورودی نامعتبر است.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $userId = auth()->id();

        $tracker = DB::table('period_trackers')
            ->where('user_id', $userId)
            ->first();

        if (!$tracker) {
            return response()->json([
                'status' => false,
                'message' => 'ابتدا اطلاعات اولیه پریود را ثبت کنید.',
            ], 404);
        }

        $id = DB::table('period_logs')->insertGetId([
            'user_id' => $userId,
            'period_tracker_id' => $tracker->id,
            'start_date' => $request->start_date,
            'end_date' => $request->end_date,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('period_trackers')
            ->where('id', $tracker->id)
            ->update([
                'last_period_start_date' => $request->start_date,
                'last_period_end_date' => $request->end_date,
                'updated_at' => now(),
            ]);

        $log = DB::table('period_logs')->where('id', $id)->first();

        return response()->json([
            'status' => true,
            'message' => 'رکورد پریود با موفقیت ثبت شد.',
            'data' => $log,
        ], 201);
    }

    public function logs(Request $request)
    {
        $userId = auth()->id();

        $logs = DB::table('period_logs')
            ->where('user_id', $userId)
            ->orderByDesc('start_date')
            ->get();

        return response()->json([
            'status' => true,
            'message' => 'لیست رکوردهای پریود دریافت شد.',
            'data' => $logs,
        ]);
    }

    public function storeDailyLog(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'log_date' => ['required', 'date'],
            'period_log_id' => ['nullable', 'integer'],
            'mood' => ['nullable', 'string', 'max:50'],
            'pain_level' => ['nullable', 'integer', 'min:0', 'max:10'],
            'flow_level' => ['nullable', 'integer', 'min:1', 'max:5'],
            'discharge_type' => ['nullable', 'string', 'max:50'],
            'symptoms' => ['nullable', 'array'],
            'water' => ['nullable', 'integer', 'min:0'],              // ← اضافه شد
            'calorie' => ['nullable', 'integer', 'min:0'],            // ← اضافه شد
            'physical_symptoms' => ['nullable', 'array'],             // ← اضافه شد
            'took_medicine' => ['nullable', 'boolean'],
            'medicine_note' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'داده‌های ورودی نامعتبر است.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $userId = auth()->id();

        $exists = DB::table('period_daily_logs')
            ->where('user_id', $userId)
            ->where('log_date', $request->log_date)
            ->first();

        $payload = [
            'period_log_id' => $request->period_log_id,
            'log_date' => $request->log_date,
            'mood' => $request->mood,
            'pain_level' => $request->pain_level,
            'flow_level' => $request->flow_level,
            'discharge_type' => $request->discharge_type,
            'symptoms' => $request->symptoms ? json_encode($request->symptoms, JSON_UNESCAPED_UNICODE) : null,
            'water' => $request->water ?? 0,                                                        // ← اضافه شد
            'calorie' => $request->calorie ?? 0,                                                    // ← اضافه شد
            'physical_symptoms' => $request->physical_symptoms ? json_encode($request->physical_symptoms, JSON_UNESCAPED_UNICODE) : null, // ← اضافه شد
            'took_medicine' => $request->has('took_medicine') ? (int)$request->took_medicine : 0,
            'medicine_note' => $request->medicine_note,
            'notes' => $request->notes,
            'updated_at' => now(),
        ];

        if ($exists) {
            DB::table('period_daily_logs')
                ->where('id', $exists->id)
                ->update($payload);

            $dailyLog = DB::table('period_daily_logs')->where('id', $exists->id)->first();
        } else {
            $payload['user_id'] = $userId;
            $payload['created_at'] = now();

            $id = DB::table('period_daily_logs')->insertGetId($payload);
            $dailyLog = DB::table('period_daily_logs')->where('id', $id)->first();
        }

        if ($dailyLog) {
            $dailyLog->symptoms = $dailyLog->symptoms ? json_decode($dailyLog->symptoms, true) : [];
            $dailyLog->physical_symptoms = $dailyLog->physical_symptoms ? json_decode($dailyLog->physical_symptoms, true) : []; // ← اضافه شد
            $dailyLog->water = (int) $dailyLog->water;     // ← اطمینان از عدد بودن
            $dailyLog->calorie = (int) $dailyLog->calorie; // ← اطمینان از عدد بودن
        }

        return response()->json([
            'status' => true,
            'message' => 'اطلاعات روزانه با موفقیت ذخیره شد.',
            'data' => $dailyLog,
        ]);
    }

    public function dailyLogsByMonth(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'year' => ['required', 'integer'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'پارامترهای ورودی نامعتبر است.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $userId = auth()->id();
        $year = $request->year;
        $month = str_pad($request->month, 2, '0', STR_PAD_LEFT);

        $logs = DB::table('period_daily_logs')
            ->where('user_id', $userId)
            ->whereYear('log_date', $year)
            ->whereMonth('log_date', $month)
            ->orderBy('log_date')
            ->get();

        $logs->transform(function ($item) {
            $item->symptoms = $item->symptoms ? json_decode($item->symptoms, true) : [];
            $item->physical_symptoms = $item->physical_symptoms ? json_decode($item->physical_symptoms, true) : []; // ← اضافه شد
            $item->took_medicine = (bool) $item->took_medicine;
            $item->water = (int) $item->water;      // ← اضافه شد
            $item->calorie = (int) $item->calorie;  // ← اضافه شد
            return $item;
        });

        return response()->json([
            'status' => true,
            'message' => 'اطلاعات روزانه ماه دریافت شد.',
            'data' => $logs,
        ]);
    }

    public function dailyLogByDate($date)
    {
        $userId = auth()->id();

        $log = DB::table('period_daily_logs')
            ->where('user_id', $userId)
            ->where('log_date', $date)
            ->first();

        if (!$log) {
            return response()->json([
                'status' => false,
                'message' => 'برای این تاریخ اطلاعاتی ثبت نشده است.',
                'data' => null,
            ], 404);
        }

        $log->symptoms = $log->symptoms ? json_decode($log->symptoms, true) : [];
        $log->physical_symptoms = $log->physical_symptoms ? json_decode($log->physical_symptoms, true) : []; // ← اضافه شد
        $log->took_medicine = (bool) $log->took_medicine;
        $log->water = (int) $log->water;      // ← اضافه شد
        $log->calorie = (int) $log->calorie;  // ← اضافه شد

        return response()->json([
            'status' => true,
            'message' => 'اطلاعات روزانه دریافت شد.',
            'data' => $log,
        ]);
    }

    // ==========================================
    //   بخش جدید: پیوند با پارتنر (Partner Sync)
    // ==========================================

    /**
     * ثبت اتصال پارتنر با استفاده از کد
     */
    public function connectPartner(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'partner_code' => ['required', 'string', 'exists:period_trackers,partner_code'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'کد پارتنر نامعتبر است.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $partnerUserId = auth()->id();

        $tracker = DB::table('period_trackers')
            ->where('partner_code', $request->partner_code)
            ->first();

        // کاربر نمی‌تواند پارتنر ردیاب خودش شود
        if ($tracker->user_id == $partnerUserId) {
            return response()->json([
                'status' => false,
                'message' => 'شما نمی‌توانید به ردیاب قاعدگی خودتان متصل شوید.',
            ], 400);
        }

        // ایجاد یا به‌روزرسانی اتصال پارتنر (هر کاربر فقط می‌تواند به یک ردیاب متصل باشد)
        DB::table('period_partner_links')->updateOrInsert(
            ['partner_user_id' => $partnerUserId],
            [
                'period_tracker_id' => $tracker->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        return response()->json([
            'status' => true,
            'message' => 'اتصال با پارتنر با موفقیت برقرار شد.',
        ]);
    }

    /**
     * قطع اتصال از پارتنر
     */
    public function disconnectPartner(Request $request)
    {
        $partnerUserId = auth()->id();

        $deleted = DB::table('period_partner_links')
            ->where('partner_user_id', $partnerUserId)
            ->delete();

        return response()->json([
            'status' => true,
            'message' => $deleted ? 'ارتباط با پارتنر قطع شد.' : 'ارتباط فعالی پیدا نشد.',
        ]);
    }

    /**
     * دریافت آخرین وضعیت ردیاب برای پارتنر متصل شده
     */
    public function partnerDashboard(Request $request)
    {
        $partnerUserId = auth()->id();

        // دریافت اطلاعات ردیاب پارتنر متصل
        $link = DB::table('period_partner_links')
            ->join('period_trackers', 'period_partner_links.period_tracker_id', '=', 'period_trackers.id')
            ->join('users', 'period_trackers.user_id', '=', 'users.id')
            ->where('period_partner_links.partner_user_id', $partnerUserId)
            ->select('period_trackers.*', 'users.name as partner_name')
            ->first();

        if (!$link) {
            return response()->json([
                'status' => false,
                'message' => 'شما به هیچ ردیابی متصل نیستید.',
                'data' => null,
            ], 404);
        }

        // محاسبه وضعیت زمانی قاعدگی بعدی
        $lastStart = Carbon::parse($link->last_period_start_date);
        $nextPeriod = $lastStart->copy()->addDays($link->cycle_length);
        $daysRemaining = now()->startOfDay()->diffInDays($nextPeriod->startOfDay(), false);

        // بررسی اینکه آیا پارتنر در روزهای قاعدگی قرار دارد یا خیر
        $isPeriodDays = ($daysRemaining <= 0 && abs($daysRemaining) < $link->period_length);

        // دریافت آخرین لاگ ثبت شده در جدول روزانه (به همراه علائم و مود)
        $latestLog = DB::table('period_daily_logs')
            ->where('user_id', $link->user_id)
            ->orderByDesc('log_date')
            ->first();

        if ($latestLog) {
            $latestLog->symptoms = $latestLog->symptoms ? json_decode($latestLog->symptoms, true) : [];
            $latestLog->took_medicine = (bool) $latestLog->took_medicine;
        }

        return response()->json([
            'status' => true,
            'message' => 'اطلاعات داشبورد پارتنر با موفقیت دریافت شد.',
            'data' => [
                'partner_name' => $link->partner_name,
                'cycle_info' => [
                    'last_period_start' => $link->last_period_start_date,
                    'next_period_estimate' => $nextPeriod->toDateString(),
                    'days_remaining' => max(0, (int)$daysRemaining),
                    'is_period_days' => $isPeriodDays,
                ],
                'latest_log' => $latestLog ? [
                    'mood' => $latestLog->mood,
                    'log_date' => $latestLog->log_date,
                    'symptoms' => $latestLog->symptoms,
                    'notes' => $latestLog->notes
                ] : null
            ]
        ]);
    }
}
