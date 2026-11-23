<?php

namespace App\Http\Controllers\Api\Owner\Beauty;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Validator;

class BeautyClinicScheduleController extends Controller
{
    private function rulesRedisKey(int $clinicId): string
    {
        return "beauty_clinic:schedule_rules:{$clinicId}";
    }

    /**
     * ۱. دریافت قوانین شیفت‌بندی ذخیره‌شده در Redis
     * GET /api/owner/beauty-clinic/schedule/rules
     */
    public function getRules(Request $request)
    {
        $clinicId = (int) $request->user()->id;
        $raw = Redis::get($this->rulesRedisKey($clinicId));

        return response()->json([
            'status' => true,
            'data'   => $raw ? json_decode($raw, true) : null,
        ]);
    }

    /**
     * ۲. ذخیره قوانین هفتگی در Redis
     * POST /api/owner/beauty-clinic/schedule/rules
     */
    public function saveRules(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'rules' => 'required|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => false,
                'message' => 'ساختار قوانین نامعتبر است.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $clinicId = (int) $request->user()->id;
        $rules = $request->input('rules');

        // اعتبارسنجی منطقی شیفت‌ها (شروع کمتر از پایان و عدم تداخل)
        foreach ($rules as $dayKey => $dayRule) {
            if (!isset($dayRule['shifts']) || !is_array($dayRule['shifts'])) {
                continue;
            }

            $intervals = [];
            foreach ($dayRule['shifts'] as $shift) {
                $start = $shift['start'] ?? null;
                $end   = $shift['end'] ?? null;

                if (!$start || !$end || $start >= $end) {
                    return response()->json([
                        'status'  => false,
                        'message' => 'ساعت پایان هر شیفت باید بزرگ‌تر از ساعت شروع آن باشد.',
                    ], 422);
                }

                $intervals[] = [$start, $end];
            }

            usort($intervals, fn ($a, $b) => strcmp($a[0], $b[0]));
            for ($i = 1; $i < count($intervals); $i++) {
                if ($intervals[$i][0] < $intervals[$i - 1][1]) {
                    return response()->json([
                        'status'  => false,
                        'message' => 'شیفت‌های یک روز نباید با یکدیگر هم‌پوشانی داشته باشند.',
                    ], 422);
                }
            }
        }

        Redis::set($this->rulesRedisKey($clinicId), json_encode($rules, JSON_UNESCAPED_UNICODE));

        return response()->json([
            'status'  => true,
            'message' => 'قوانین کاری کلینیک با موفقیت در سیستم ذخیره شد.',
        ]);
    }

    /**
     * ۳. تولید گروهی اسلات‌ها برای تاریخ‌های انتخاب‌شده (با حفظ اسلات‌های رزروشده)
     * POST /api/owner/beauty-clinic/schedule/generate-slots-batch
     */
    public function generateSlotsBatch(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'dates'          => 'required|array|min:1',
            'dates.*'        => 'required|date_format:Y-m-d',
            'slot_minutes'   => 'required|integer|min:5|max:180',
            'capacity'       => 'required|integer|min:1|max:50',
            'shifts'         => 'required|array|min:1|max:4',
            'shifts.*.start' => 'required|date_format:H:i',
            'shifts.*.end'   => 'required|date_format:H:i',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => false,
                'message' => $validator->errors()->first(),
                'errors'  => $validator->errors(),
            ], 422);
        }

        $clinicId    = (int) $request->user()->id;
        $dates       = array_values(array_unique($request->input('dates')));
        $slotMinutes = (int) $request->input('slot_minutes');
        $capacity    = (int) $request->input('capacity');
        $shifts      = $request->input('shifts');

        // بررسی ترتیب ساعات شیفت
        foreach ($shifts as $shift) {
            if ($shift['start'] >= $shift['end']) {
                return response()->json([
                    'status'  => false,
                    'message' => 'ساعت پایان شیفت باید بزرگ‌تر از ساعت شروع آن باشد.',
                ], 422);
            }
        }

        $insertedCount = 0;

        DB::transaction(function () use ($clinicId, $dates, $slotMinutes, $capacity, $shifts, &$insertedCount) {
            $now = now();

            // ۱. حذف اسلات‌های بدون رزرو در تاریخ‌های هدف تا قوانین جدید جایگزین شوند
            DB::table('beauty_clinic_slots')
                ->where('clinic_id', $clinicId)
                ->whereIn('slot_date', $dates)
                ->where('booked_count', 0)
                ->delete();

            // ۲. دریافت اسلات‌های رزروشده باقی‌مانده برای جلوگیری از تداخل زمانی
            $bookedSlots = DB::table('beauty_clinic_slots')
                ->where('clinic_id', $clinicId)
                ->whereIn('slot_date', $dates)
                ->where('booked_count', '>', 0)
                ->select('slot_date', 'start_time', 'end_time')
                ->get()
                ->groupBy('slot_date');

            $rowsToInsert = [];

            foreach ($dates as $date) {
                $existingForDay = $bookedSlots->get($date, collect());

                foreach ($shifts as $shift) {
                    $cursor = Carbon::createFromFormat('Y-m-d H:i', "{$date} {$shift['start']}");
                    $end    = Carbon::createFromFormat('Y-m-d H:i', "{$date} {$shift['end']}");

                    while ($cursor->copy()->addMinutes($slotMinutes)->lte($end)) {
                        $slotStart = $cursor->format('H:i:s');
                        $slotEnd   = $cursor->copy()->addMinutes($slotMinutes)->format('H:i:s');

                        // عدم درج در صورت هم‌پوشانی با اسلات رزروشده
                        $hasOverlap = $existingForDay->contains(function ($booked) use ($slotStart, $slotEnd) {
                            return $slotStart < $booked->end_time && $slotEnd > $booked->start_time;
                        });

                        if (!$hasOverlap) {
                            $rowsToInsert[] = [
                                'clinic_id'    => $clinicId,
                                'slot_date'    => $date,
                                'start_time'   => $slotStart,
                                'end_time'     => $slotEnd,
                                'capacity'     => $capacity,
                                'booked_count' => 0,
                                'status'       => 1,
                                'created_at'   => $now,
                                'updated_at'   => $now,
                            ];
                        }

                        $cursor->addMinutes($slotMinutes);
                    }
                }
            }

            if (!empty($rowsToInsert)) {
                foreach (array_chunk($rowsToInsert, 200) as $chunk) {
                    DB::table('beauty_clinic_slots')->insert($chunk);
                }
                $insertedCount = count($rowsToInsert);
            }
        });

        return response()->json([
            'status'  => true,
            'message' => 'اسلات‌های زمانی با موفقیت تولید شدند.',
            'data'    => ['inserted_count' => $insertedCount],
        ]);
    }

    /**
     * ۴. خلاصه وضعیت تقویم برای نمایش روی روزهای تقویم شمسی
     * GET /api/owner/beauty-clinic/schedule/calendar-summary
     */
    public function calendarSummary(Request $request)
    {
        $clinicId = (int) $request->user()->id;

        $summary = DB::table('beauty_clinic_slots')
            ->where('clinic_id', $clinicId)
            ->where('slot_date', '>=', Carbon::today()->subDays(31)->toDateString())
            ->groupBy('slot_date')
            ->orderBy('slot_date')
            ->select(
                'slot_date',
                DB::raw('COUNT(*) as total_slots'),
                DB::raw('SUM(CASE WHEN status = 1 AND booked_count < capacity THEN 1 ELSE 0 END) as available_slots'),
                DB::raw('SUM(booked_count) as booked_slots'),
                DB::raw('SUM(CASE WHEN status = 0 THEN 1 ELSE 0 END) as blocked_slots')
            )
            ->get()
            ->map(function ($row) {
                return [
                    'slot_date'       => $row->slot_date,
                    'total_slots'     => (int) $row->total_slots,
                    'available_slots' => (int) $row->available_slots,
                    'booked_slots'    => (int) $row->booked_slots,
                    'blocked_slots'   => (int) $row->blocked_slots,
                ];
            });

        return response()->json([
            'status' => true,
            'data'   => $summary,
        ]);
    }

    /**
     * ۵. دریافت اسلات‌ها و رزروهای یک تاریخ مشخص
     * GET /api/owner/beauty-clinic/schedule/slots?date=YYYY-MM-DD
     */
    public function getSlotsByDate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'date' => 'required|date_format:Y-m-d',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'message' => 'تاریخ معتبر نیست.'], 422);
        }

        $clinicId = (int) $request->user()->id;
        $date     = $request->query('date');

        $slots = DB::table('beauty_clinic_slots')
            ->where('clinic_id', $clinicId)
            ->where('slot_date', $date)
            ->orderBy('start_time')
            ->get();

        if ($slots->isEmpty()) {
            return response()->json([
                'status' => true,
                'data'   => ['slots' => []],
            ]);
        }

        $slotIds = $slots->pluck('id')->toArray();

        // دریافت رزروهای فعال هر اسلات در یک کوئری
        $bookings = DB::table('users_beauty_requests as ubr')
            ->join('users as u', 'u.id', '=', 'ubr.user_id')
            ->whereIn('ubr.slot_id', $slotIds)
            ->where('ubr.status', '!=', 6) // به جز لغوشده‌ها
            ->select(
                'ubr.slot_id',
                'ubr.id as request_id',
                'u.name as patient_name',
                'u.phone as patient_phone',
                'ubr.status'
            )
            ->get()
            ->groupBy('slot_id');

        $formattedSlots = $slots->map(function ($slot) use ($bookings) {
            return [
                'id'           => (int) $slot->id,
                'slot_date'    => $slot->slot_date,
                'start_time'   => $slot->start_time,
                'end_time'     => $slot->end_time,
                'capacity'     => (int) $slot->capacity,
                'booked_count' => (int) $slot->booked_count,
                'status'       => (int) $slot->status,
                'bookings'     => $bookings->get($slot->id, collect())->map(fn ($b) => [
                    'request_id'    => (int) $b->request_id,
                    'patient_name'  => $b->patient_name,
                    'patient_phone' => $b->patient_phone,
                    'status'        => (int) $b->status,
                ])->values(),
            ];
        });

        return response()->json([
            'status' => true,
            'data'   => ['slots' => $formattedSlots],
        ]);
    }

    /**
     * ۶. مسدود یا آزاد کردن یک اسلات (فقط در صورت نداشتن رزرو فعال)
     * PATCH /api/owner/beauty-clinic/schedule/slots/{id}/toggle-status
     */
    public function toggleSlotStatus(Request $request, $id)
    {
        $clinicId = (int) $request->user()->id;

        $slot = DB::table('beauty_clinic_slots')
            ->where('id', $id)
            ->where('clinic_id', $clinicId)
            ->first();

        if (!$slot) {
            return response()->json([
                'status'  => false,
                'message' => 'اسلات مورد نظر یافت نشد.',
            ], 404);
        }

        if ((int) $slot->booked_count > 0) {
            return response()->json([
                'status'  => false,
                'message' => 'این بازه دارای رزرو فعال است و امکان مسدود کردن آن وجود ندارد.',
            ], 422);
        }

        $newStatus = ((int) $slot->status === 1) ? 0 : 1;

        DB::table('beauty_clinic_slots')
            ->where('id', $slot->id)
            ->update([
                'status'     => $newStatus,
                'updated_at' => now(),
            ]);

        return response()->json([
            'status'  => true,
            'message' => $newStatus === 1 ? 'بازه زمانی فعال شد.' : 'بازه زمانی مسدود شد.',
            'data'    => ['status' => $newStatus],
        ]);
    }
}
