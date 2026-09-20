<?php

namespace App\Http\Controllers\Api\Doctor;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AppointmentController
{
    public function getCalendarSummary(Request $request): JsonResponse
    {
        $doctorInfoId = $this->getDoctorUserId($request);

        if (!$doctorInfoId) {
            return response()->json([
                'status' => false,
                'message' => 'اطلاعات پزشک یافت نشد',
            ], 404);
        }

        $from = $request->query('from');
        $to   = $request->query('to');

        $query = DB::table('appointment_slots')
            ->select(
                'slot_date',
                DB::raw('COUNT(*) as total_slots'),
                DB::raw("SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END) as available_slots"),
                DB::raw("SUM(CASE WHEN status = 'booked' THEN 1 ELSE 0 END) as booked_slots"),
                DB::raw("SUM(CASE WHEN status = 'blocked' THEN 1 ELSE 0 END) as blocked_slots"),
                DB::raw("SUM(CASE WHEN status = 'done' THEN 1 ELSE 0 END) as done_slots")
            )
            ->where('doctor_id', $doctorInfoId);

        if ($from) {
            $query->whereDate('slot_date', '>=', $from);
        }

        if ($to) {
            $query->whereDate('slot_date', '<=', $to);
        }

        $rows = $query
            ->groupBy('slot_date')
            ->orderBy('slot_date', 'asc')
            ->get();

        return response()->json([
            'status' => true,
            'data' => $rows,
        ]);
    }
    private function getDoctorUserId(Request $request): ?int
    {
        $userId = $request->user()->id;

        $exists = DB::table('doctor_info')
            ->where('user_id', $userId)
            ->exists();

        return $exists ? $userId : null;
    }

    public function getSlotsByDate(Request $request): JsonResponse
    {
        $doctorInfoId = $this->getDoctorUserId($request);

        if (!$doctorInfoId) {
            return response()->json([
                'status' => false,
                'message' => 'اطلاعات پزشک یافت نشد',
            ], 404);
        }

        $request->validate([
            'date' => 'required|date',
        ]);

        $date = $request->query('date');

        $slots = DB::table('appointment_slots as ap')
            ->leftJoin('users as u', 'u.id', '=', 'ap.patient_id')
            ->select(
                'ap.id',
                'ap.slot_date',
                'ap.start_time',
                'ap.end_time',
                'ap.status',
                'ap.price',
                'ap.patient_id',
                'u.name as patient_name',
                'u.phone as patient_phone',
                'ap.notes'
            )
            ->where('ap.doctor_id', $doctorInfoId)
            ->whereDate('ap.slot_date', $date)
            ->orderBy('ap.start_time', 'asc')
            ->get();

        return response()->json([
            'status' => true,
            'data' => [
                'date' => $date,
                'slots' => $slots,
            ],
        ]);
    }

    public function generateSlotsForDate(Request $request): JsonResponse
    {
        // ۱. شناسه پزشک بر اساس کاربر احراز شده
        $doctorInfoId = $this->getDoctorUserId($request);

        if (!$doctorInfoId) {
            return response()->json([
                'status'  => false,
                'message' => 'اطلاعات پزشک یافت نشد',
            ], 404);
        }

        // ۲. اعتبارسنجی ورودی‌ها
        $validated = $request->validate([
            'date'         => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'price'        => ['nullable', 'integer', 'min:0'],
            'slot_minutes' => ['nullable', 'integer', 'in:15,20,30,45,60'],

            // شیفت‌ها: آرایه‌ای از بازه‌ها، مثال:
            // "shifts": [{"start": "08:00", "end": "12:00"}, {"start": "16:00", "end": "20:00"}]
            'shifts'                 => ['nullable', 'array', 'min:1', 'max:4'],
            'shifts.*.start'         => ['required_with:shifts', 'date_format:H:i'],
            'shifts.*.end'           => ['required_with:shifts', 'date_format:H:i', 'different:shifts.*.start'],
        ]);

        $date        = $validated['date'];
        $slotMinutes = $validated['slot_minutes'] ?? 15;

        // ۳. قیمت از doctor_info (ستون visit_price)
        $doctorInfo = DB::table('doctor_info')
            ->where('user_id', $doctorInfoId)
            ->first(['visit_price']);

        if (!$doctorInfo || $doctorInfo->visit_price === null) {
            return response()->json([
                'status'  => false,
                'message' => 'قیمت ویزیت پزشک تنظیم نشده است',
            ], 422);
        }

        $price = $validated['price'] ?? (int) $doctorInfo->visit_price;

        // ۴. تعیین شیفت‌ها (پیش‌فرض: صبح و عصر)
        $shifts = $validated['shifts'] ?? [
            ['start' => '08:00', 'end' => '12:00'],
            ['start' => '16:00', 'end' => '20:00'],
        ];

        //یین شیفت‌ها (پیش‌فرض: صبح و عصر)
        $shifts = $validated['shifts'] ?? [
            ['start' => '08:00', 'end' => '12:00'],
            ['start' => '16:00', 'end' => '20:00'],
        ];

        // تداخل شیفت‌ها با هم
        for ($i = 1; $i < count($shifts); $i++) {
            if ($shifts[$i]['start'] < $shifts[$i - 1]['end']) {
                return response()->json([
                    'status'  => false,
                    'message' => 'بازه‌های زمانی شیفت‌ها با هم تداخل دارند',
                ], 422);
            }
        }

        $created = 0;
        $skipped = 0;
        $details = [];

        DB::beginTransaction();

        try {
            foreach ($shifts as $shift) {
                $cursor = Carbon::createFromFormat('Y-m-d H:i', "$date {$shift['start']}");
                $end    = Carbon::createFromFormat('Y-m-d H:i', "$date {$shift['end']}");

                $shiftCreated = 0;
                $shiftSkipped = 0;

                while ($cursor->lt($end)) {
                    $slotEnd = $cursor->copy()->addMinutes($slotMinutes);

                    // اسلات ناقص آخر بازه ساخته نشود
                    if ($slotEnd->gt($end)) {
                        break;
                    }

                    $inserted = DB::table('appointment_slots')->insertOrIgnore([
                        'doctor_id'      => $doctorInfoId,
                        'slot_date'      => $date,
                        'start_time'     => $cursor->format('H:i:s'),
                        'end_time'       => $slotEnd->format('H:i:s'),
                        'price'          => $price,
                        'status'         => 'available',
                        'patient_id'     => null,
                        'order_id'       => null,
                        'booking_time'   => null,
                        'notes'          => null,
                        'reserved_until' => null,
                        'created_at'     => now(),
                        'updated_at'     => now(),
                    ]);

                    $inserted ? ($created++ && $shiftCreated++) : ($skipped++ && $shiftSkipped++);

                    $cursor->addMinutes($slotMinutes);
                }

                $details[] = [
                    'shift'         => "{$shift['start']} - {$shift['end']}",
                    'created_count' => $shiftCreated,
                    'skipped_count' => $shiftSkipped,
                ];
            }

            DB::commit();

            return response()->json([
                'status'  => true,
                'message' => 'اسلات‌های روز با موفقیت ایجاد شدند',
                'data'    => [
                    'date'          => $date,
                    'slot_minutes'  => $slotMinutes,
                    'price'         => $price,
                    'total_created' => $created,
                    'total_skipped' => $skipped,
                    'shifts'        => $details,
                ],
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('Slot generation failed', [
                'doctor_info_id' => $doctorInfoId,
                'date'           => $date,
                'error'          => $e->getMessage(),
            ]);

            return response()->json([
                'status'  => false,
                'message' => 'خطا در ایجاد اسلات‌ها. لطفاً دوباره تلاش کنید',
            ], 500);
        }
    }
    public function generateBatchSlots(Request $request): JsonResponse
    {
        $doctorInfoId = $this->getDoctorUserId($request);

        if (!$doctorInfoId) {
            return response()->json([
                'status'  => false,
                'message' => 'اطلاعات پزشک یافت نشد',
            ], 404);
        }

        $validated = $request->validate([
            'dates'                  => ['required', 'array', 'min:1'],
            'dates.*'                => ['required', 'date_format:Y-m-d'],
            'price'                  => ['nullable', 'integer', 'min:0'],
            'slot_minutes'           => ['nullable', 'integer', 'in:15,20,30,45,60'],
            'shifts'                 => ['nullable', 'array', 'min:1', 'max:4'],
            'shifts.*.start'         => ['required_with:shifts', 'date_format:H:i'],
            'shifts.*.end'           => ['required_with:shifts', 'date_format:H:i', 'different:shifts.*.start'],
        ]);

        $slotMinutes = $validated['slot_minutes'] ?? 15;

        $doctorInfo = DB::table('doctor_info')
            ->where('user_id', $doctorInfoId)
            ->first(['visit_price']);

        if (!$doctorInfo || $doctorInfo->visit_price === null) {
            return response()->json([
                'status'  => false,
                'message' => 'قیمت ویزیت پزشک تنظیم نشده است',
            ], 422);
        }

        $price = $validated['price'] ?? (int) $doctorInfo->visit_price;

        $shifts = $validated['shifts'] ?? [
            ['start' => '08:00', 'end' => '12:00'],
            ['start' => '16:00', 'end' => '20:00'],
        ];

        // بررسی تداخل شیفت‌ها
        for ($i = 1; $i < count($shifts); $i++) {
            if ($shifts[$i]['start'] < $shifts[$i - 1]['end']) {
                return response()->json([
                    'status'  => false,
                    'message' => 'بازه‌های زمانی شیفت‌ها با هم تداخل دارند',
                ], 422);
            }
        }

        $totalCreated = 0;
        $totalSkipped = 0;

        DB::beginTransaction();

        try {
            // ایجاد اسلات‌ها برای تمام تاریخ‌های ارسال شده
            foreach ($validated['dates'] as $date) {
                foreach ($shifts as $shift) {
                    $cursor = Carbon::createFromFormat('Y-m-d H:i', "$date {$shift['start']}");
                    $end    = Carbon::createFromFormat('Y-m-d H:i', "$date {$shift['end']}");

                    while ($cursor->lt($end)) {
                        $slotEnd = $cursor->copy()->addMinutes($slotMinutes);

                        if ($slotEnd->gt($end)) break;

                        $inserted = DB::table('appointment_slots')->insertOrIgnore([
                            'doctor_id'      => $doctorInfoId,
                            'slot_date'      => $date,
                            'start_time'     => $cursor->format('H:i:s'),
                            'end_time'       => $slotEnd->format('H:i:s'),
                            'price'          => $price,
                            'status'         => 'available',
                            'created_at'     => now(),
                            'updated_at'     => now(),
                        ]);

                        $inserted ? $totalCreated++ : $totalSkipped++;
                        $cursor->addMinutes($slotMinutes);
                    }
                }
            }

            DB::commit();

            return response()->json([
                'status'  => true,
                'message' => 'اسلات‌های درخواستی با موفقیت ایجاد شدند',
                'data'    => [
                    'total_created' => $totalCreated,
                    'total_skipped' => $totalSkipped,
                ],
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Batch slot generation failed', [
                'doctor_id' => $doctorInfoId,
                'error'     => $e->getMessage(),
            ]);

            return response()->json([
                'status'  => false,
                'message' => 'خطا در ایجاد گروهی اسلات‌ها',
            ], 500);
        }
    }
    public function toggleSlotStatus(Request $request, int $slotId): JsonResponse
    {
        $doctorInfoId = $this->getDoctorUserId($request);

        if (!$doctorInfoId) {
            return response()->json([
                'status' => false,
                'message' => 'اطلاعات پزشک یافت نشد',
            ], 404);
        }

        $slot = DB::table('appointment_slots')
            ->where('id', $slotId)
            ->where('doctor_id', $doctorInfoId)
            ->first();

        if (!$slot) {
            return response()->json([
                'status' => false,
                'message' => 'اسلات یافت نشد',
            ], 404);
        }

        if (in_array($slot->status, ['booked', 'done'])) {
            return response()->json([
                'status' => false,
                'message' => 'این اسلات قابل تغییر نیست',
            ], 400);
        }

        $newStatus = $slot->status === 'blocked' ? 'available' : 'blocked';

        DB::table('appointment_slots')
            ->where('id', $slotId)
            ->update([
                'status' => $newStatus,
                'updated_at' => now(),
            ]);

        return response()->json([
            'status' => true,
            'message' => 'وضعیت اسلات تغییر کرد',
            'data' => [
                'id' => $slotId,
                'status' => $newStatus,
            ],
        ]);
    }
    public function getDoctorAppointments(Request $request): JsonResponse
    {
        $doctorId = $request->user()->id;

        $appointments = DB::table('appointment_slots as ap')
            ->join('users as u', 'u.id', '=', 'ap.patient_id')
            ->select(
                'ap.id',
                'u.name',
                'u.phone',
                'ap.status',
                'ap.slot_date',
                'ap.start_time'
            )
            ->whereIn('ap.status', ['booked', 'done'])
            ->where('ap.doctor_id', $doctorId)
            ->orderBy('ap.slot_date', 'desc')
            ->orderBy('ap.start_time', 'desc')
            ->get();

        return response()->json([
            'status' => true,
            'data' => $appointments,
        ]);
    }
    public function getAppointmentDetail(Request $request, int $appointmentId): JsonResponse
    {
        $doctorId = $request->user()->id;

        $appointment = DB::table('appointment_slots as ap')
            ->join('users as u', 'u.id', '=', 'ap.patient_id')
            ->select(
                'ap.id',
                'ap.ai_session_token',
                'ap.patient_id',
                'u.name as patient_name',
                'u.phone as patient_phone',
                'ap.slot_date',
                'ap.start_time',
                'ap.end_time',
                'ap.status',
                'ap.notes',
                'u.birth_date'
            )
            ->where('ap.id', $appointmentId)
            ->where('ap.doctor_id', $doctorId)
            ->first();

        if (!$appointment) {
            return response()->json([
                'status' => false,
                'message' => 'نوبت یافت نشد',
            ], 404);
        }

        $age = null;
        if ($appointment->birth_date) {
            $age = Carbon::parse($appointment->birth_date)->age;
        }

        $visitCount = DB::table('appointment_slots')
            ->where('patient_id', $appointment->patient_id)
            ->where('status', 'done')
            ->count();

        $currentDoctorSpecialty = DB::table('doctor_info')
            ->where('user_id', $doctorId)
            ->value('specialty_id');

        $history = [];
        if ($currentDoctorSpecialty) {
            $sameSpecialtyDoctorIds = DB::table('doctor_info')
                ->where('specialty_id', $currentDoctorSpecialty)
                ->pluck('user_id');

            $history = DB::table('appointment_slots')
                ->select('slot_date', 'start_time', 'status', 'notes')
                ->where('patient_id', $appointment->patient_id)
                ->whereIn('doctor_id', $sameSpecialtyDoctorIds)
                ->where('status', 'done')
                ->where('id', '!=', $appointmentId)
                ->orderBy('slot_date', 'desc')
                ->orderBy('start_time', 'desc')
                ->get();
        }

        // دریافت همه سوابق تشخیص AI بیمار
        $aiDiagnosisRows = DB::table('ai_messages')
            ->where('user_id', $appointment->patient_id)
            ->where('role', 'assistant')
            ->where('status', 'complete')
            ->whereNotNull('diagnosis_data')
            ->orderBy('created_at', 'desc')
            ->get([
                'session_id',
                'diagnosis_data',
                'created_at',
            ]);

        // یکتا کردن سشن‌ها بر اساس session_id
        $uniqueAiSessions = $aiDiagnosisRows
            ->unique('session_id')
            ->values();

        $aiDiagnoses = [];

        if ($uniqueAiSessions->isNotEmpty()) {
           // $latestSessionId = $uniqueAiSessions->first()->session_id;
            $latestSessionId =  $appointment->ai_session_token;

            // فقط پیام‌های آخرین سشن را کامل می‌گیریم
            $latestSessionMessages = DB::table('ai_messages')
                ->where('session_id', $latestSessionId)
                ->orderBy('created_at', 'asc')
                ->get([
                    'role',
                    'content',
                    'created_at',
                ]);

            foreach ($uniqueAiSessions as $session) {
                $diagnosisData = json_decode($session->diagnosis_data, true);
                $diagnosis = $diagnosisData['diagnosis'] ?? null;
                $isLatest = $session->session_id === $latestSessionId;

                $aiDiagnoses[] = [
                    'session_id'         => $session->session_id,
                    'created_at'         => $session->created_at,
                    'diagnosis'          => $diagnosis,
                    'urgency_level'      => $diagnosis['urgency_level'] ?? null,
                    'specialty'          => $diagnosis['specialty'] ?? null,
                    'recommended_tests'  => $diagnosis['recommended_tests'] ?? [],
                    'notes'              => $diagnosis['notes'] ?? null,
                    'is_latest'          => $isLatest,
                    'messages'           => $isLatest ? $latestSessionMessages : [],
                ];
            }
        }

        return response()->json([
            'status' => true,
            'data' => [
                'appointment' => [
                    'id'             => $appointment->id,
                    'patient_id'     => $appointment->patient_id,
                    'patient_name'   => $appointment->patient_name,
                    'patient_phone'  => $appointment->patient_phone,
                    'slot_date'      => $appointment->slot_date,
                    'start_time'     => $appointment->start_time,
                    'end_time'       => $appointment->end_time,
                    'status'         => $appointment->status,
                    'notes'          => $appointment->notes,
                ],
                'patient' => [
                    'id'          => $appointment->patient_id,
                    'name'        => $appointment->patient_name,
                    'phone'       => $appointment->patient_phone,
                    'age'         => $age,
                    'visit_count' => $visitCount,
                ],
                'history'       => $history,
                'ai_diagnoses'  => $aiDiagnoses,
            ],
        ]);
    }



    public function updateAppointmentNotes(Request $request, int $appointmentId): JsonResponse
    {
        $doctorId = $request->user()->id;

        $request->validate([
            'notes' => 'required|string|max:2000',
        ]);

        $updated = DB::table('appointment_slots')
            ->where('id', $appointmentId)
            ->where('doctor_id', $doctorId)
            ->update(['notes' => $request->notes]);

        if (!$updated) {
            return response()->json([
                'status' => false,
                'message' => 'نوبت یافت نشد یا به‌روزرسانی انجام نشد',
            ], 404);
        }

        return response()->json([
            'status' => true,
            'message' => 'نتیجه ویزیت با موفقیت ذخیره شد',
        ]);
    }
    public function markAppointmentAsDone(Request $request, int $appointmentId): JsonResponse
{
    $doctorId = $request->user()->id;

    // بررسی وجود نوبت و تعلق آن به دکتر
    $appointment = DB::table('appointment_slots')
        ->where('id', $appointmentId)
        ->where('doctor_id', $doctorId)
        ->first();

    if (!$appointment) {
        return response()->json([
            'status' => false,
            'message' => 'نوبت یافت نشد',
        ], 404);
    }

    // فقط نوبت‌های booked قابل تغییر به done هستند
    if ($appointment->status !== 'booked') {
        return response()->json([
            'status' => false,
            'message' => 'فقط نوبت‌های رزرو شده قابل تکمیل هستند',
        ], 400);
    }

    DB::table('appointment_slots')
        ->where('id', $appointmentId)
        ->update([
            'status' => 'done',
            'updated_at' => now(),
        ]);

    return response()->json([
        'status' => true,
        'message' => 'وضعیت نوبت به ویزیت شده تغییر یافت',
    ]);
}

}
