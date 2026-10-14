<?php

namespace App\Http\Controllers\Api\Staff\Doctor;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class DoctorStaffAppointmentController extends Controller
{
    /**
     * پیدا کردن شناسه پزشک مربوط به این منشی
     */
    protected function getDoctorId(int $secretaryUserId): ?int
    {
        return DB::table('doctor_secretaries')
            ->where('secretary_user_id', $secretaryUserId)
            ->value('doctor_user_id');
    }

    /**
     * تبدیل تاریخ خروجی به فرمت جلالی / میلادی متناسب با فرانت
     */
    protected function formatDate($dateString): string
    {
        if (function_exists('verta')) {
            return verta($dateString)->format('Y/m/d');
        }
        return Carbon::parse($dateString)->format('Y-m-d');
    }

    /**
     * لیست نوبت‌ها همراه با فیلتر بازه، وضعیت و سرچ
     */
    public function index(Request $request): JsonResponse
    {
        $doctorId = $this->getDoctorId($request->user()->id);
        if (!$doctorId) {
            return response()->json(['success' => false, 'message' => 'پزشک مرتبط یافت نشد.'], 404);
        }

        $range  = $request->query('range', 'today'); // today, upcoming, all
        $status = $request->query('status', 'all');
        $search = trim($request->query('search', ''));
        $today  = Carbon::today()->format('Y-m-d');

        $query = DB::table('appointment_slots as ap')
            ->leftJoin('users as u', 'u.id', '=', 'ap.patient_id')
            ->select(
                'ap.id',
                'ap.slot_date',
                'ap.start_time',
                'ap.end_time',
                'ap.status',
                'ap.source',
                'ap.patient_id',
                'u.name as patient_name',
                'u.phone as patient_phone'
            )
            ->where('ap.doctor_id', $doctorId);

        // اعمال فیلتر بازه زمانی
        if ($range === 'today') {
            $query->whereDate('ap.slot_date', '=', $today);
        } elseif ($range === 'upcoming') {
            $query->whereDate('ap.slot_date', '>=', $today);
        }

        // فیلتر وضعیت
        if ($status !== 'all') {
            $query->where('ap.status', $status);
        }

        // فیلتر جستجوی نام و موبایل
        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('u.name', 'like', "%{$search}%")
                    ->orWhere('u.phone', 'like', "%{$search}%");
            });
        }

        $slots = $query->orderBy('ap.slot_date', 'asc')
            ->orderBy('ap.start_time', 'asc')
            ->get();

        // تطبیق با ساختار StaffAppointment در تایپ‌اسکریپت فرانت
        $data = $slots->map(function ($slot) {
            return [
                'id'           => $slot->id,
                'patientName'  => $slot->patient_name ?? 'بیمار بدون نام',
                'patientPhone' => $slot->patient_phone ?? '—',
                'date'         => $this->formatDate($slot->slot_date),
                'raw_date'     => $slot->slot_date, // برای استفاده در فرم‌ها در صورت نیاز
                'time'         => $slot->start_time ? substr($slot->start_time, 0, 5) : '—',
                'type'         => 'in_person',
                'status'       => $slot->status,
                'source'       => $slot->source ?? 'patient', // secretary یا patient
            ];
        });

        return response()->json([
            'success' => true,
            'data'    => $data,
        ]);
    }

    /**
     * ثبت نوبت حضوری جدید توسط منشی
     */
    public function store(Request $request): JsonResponse
    {
        $doctorId = $this->getDoctorId($request->user()->id);
        if (!$doctorId) {
            return response()->json(['success' => false, 'message' => 'پزشک مرتبط یافت نشد.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'patientName'  => 'required|string|max:190',
            'patientPhone' => 'required|string|regex:/^09[0-9]{9}$/',
            'date'         => 'required|string', // رشته تاریخ فرانت (شمسی یا میلادی)
            'time'         => 'required|date_format:H:i',
        ], [
            'patientName.required'  => 'نام بیمار الزامی است.',
            'patientPhone.required' => 'شماره موبایل الزامی است.',
            'patientPhone.regex'    => 'شماره موبایل معتبر نیست.',
            'time.required'         => 'ساعت نوبت الزامی است.',
            'time.date_format'      => 'فرمت ساعت نامعتبر است.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors'  => $validator->errors()
            ], 422);
        }

        // تبدیل تاریخ ارسالی فرانت (اگر شمسی باشد به میلادی تبدیل می‌شود)
        $slotDate = $request->date;
        if (function_exists('verta')) {
            try {
                $slotDate = verta()->parse($request->date)->toCarbon()->format('Y-m-d');
            } catch (\Exception $e) {
                // اگر قبلا Y-m-d میلادی باشد خطا ندهد
                $slotDate = Carbon::parse($request->date)->format('Y-m-d');
            }
        }

        $startTime = $request->time . ':00';
        $endTime   = Carbon::createFromTimeString($startTime)->addMinutes(30)->format('H:i:s');

        // بررسی تداخل ساعت رزرو برای این پزشک
        $conflict = DB::table('appointment_slots')
            ->where('doctor_id', $doctorId)
            ->whereDate('slot_date', $slotDate)
            ->where('start_time', $startTime)
            ->where('status', '!=', 'canceled')
            ->exists();

        if ($conflict) {
            return response()->json([
                'success' => false,
                'message' => 'این ساعت قبلاً رزرو شده است.',
            ], 409);
        }

        return DB::transaction(function () use ($doctorId, $request, $slotDate, $startTime, $endTime) {
            // ایجاد یا یافتن کاربر بر اساس شماره تماس
            $patient = DB::table('users')->where('phone', $request->patientPhone)->first();
            if (!$patient) {
                $patientId = DB::table('users')->insertGetId([
                    'name'       => $request->patientName,
                    'phone'      => $request->patientPhone,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                $patientId = $patient->id;
            }

            // ثبت اسلات نوبت
            $slotId = DB::table('appointment_slots')->insertGetId([
                'doctor_id'   => $doctorId,
                'patient_id'  => $patientId,
                'slot_date'   => $slotDate,
                'start_time'  => $startTime,
                'end_time'    => $endTime,
                'status'      => 'booked',
                'source'      => 'secretary',
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'نوبت با موفقیت ثبت شد.',
                'data'    => [
                    'id'           => $slotId,
                    'patientName'  => $request->patientName,
                    'patientPhone' => $request->patientPhone,
                    'date'         => $request->date,
                    'time'         => $request->time,
                    'type'         => 'in_person',
                    'status'       => 'booked',
                    'source'       => 'secretary',
                ]
            ], 201);
        });
    }

    /**
     * ثبت حضور بیمار در مطب
     */
    public function checkIn(Request $request, int $id): JsonResponse
    {
        $doctorId = $this->getDoctorId($request->user()->id);
        if (!$doctorId) {
            return response()->json(['success' => false, 'message' => 'پزشک مرتبط یافت نشد.'], 404);
        }

        $slot = DB::table('appointment_slots')
            ->where('id', $id)
            ->where('doctor_id', $doctorId)
            ->first();

        if (!$slot) {
            return response()->json(['success' => false, 'message' => 'نوبت یافت نشد.'], 404);
        }

        if ($slot->status !== 'booked') {
            return response()->json(['success' => false, 'message' => 'فقط نوبت‌های رزرو شده امکان ثبت حضور دارند.'], 422);
        }

        DB::table('appointment_slots')
            ->where('id', $id)
            ->update([
                'status'     => 'checked_in',
                'updated_at' => now(),
            ]);

        return response()->json([
            'success' => true,
            'message' => 'حضور بیمار با موفقیت ثبت شد.',
        ]);
    }

    /**
     * جابه‌جایی تاریخ و ساعت نوبت
     */
    public function reschedule(Request $request, int $id): JsonResponse
    {
        $doctorId = $this->getDoctorId($request->user()->id);
        if (!$doctorId) {
            return response()->json(['success' => false, 'message' => 'پزشک مرتبط یافت نشد.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'date' => 'required|string',
            'time' => 'required|date_format:H:i',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'اطلاعات تاریخ و زمان را به درستی وارد کنید.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $slot = DB::table('appointment_slots')
            ->where('id', $id)
            ->where('doctor_id', $doctorId)
            ->first();

        if (!$slot) {
            return response()->json(['success' => false, 'message' => 'نوبت یافت نشد.'], 404);
        }

        $newDate = $request->date;
        if (function_exists('verta')) {
            try {
                $newDate = verta()->parse($request->date)->toCarbon()->format('Y-m-d');
            } catch (\Exception $e) {
                $newDate = Carbon::parse($request->date)->format('Y-m-d');
            }
        }

        $newStartTime = $request->time . ':00';
        $newEndTime   = Carbon::createFromTimeString($newStartTime)->addMinutes(30)->format('H:i:s');

        // چک کردن تداخل اسلات دیگر
        $conflict = DB::table('appointment_slots')
            ->where('doctor_id', $doctorId)
            ->where('id', '!=', $id)
            ->whereDate('slot_date', $newDate)
            ->where('start_time', $newStartTime)
            ->where('status', '!=', 'canceled')
            ->exists();

        if ($conflict) {
            return response()->json(['success' => false, 'message' => 'این ساعت قبلاً رزرو شده است.'], 409);
        }

        DB::table('appointment_slots')
            ->where('id', $id)
            ->update([
                'slot_date'  => $newDate,
                'start_time' => $newStartTime,
                'end_time'   => $newEndTime,
                'updated_at' => now(),
            ]);

        return response()->json([
            'success' => true,
            'message' => 'زمان نوبت با موفقیت تغییر کرد.',
        ]);
    }

    /**
     * لغو نوبت
     */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $doctorId = $this->getDoctorId($request->user()->id);
        if (!$doctorId) {
            return response()->json(['success' => false, 'message' => 'پزشک مرتبط یافت نشد.'], 404);
        }

        $slot = DB::table('appointment_slots')
            ->where('id', $id)
            ->where('doctor_id', $doctorId)
            ->first();

        if (!$slot) {
            return response()->json(['success' => false, 'message' => 'نوبت یافت نشد.'], 404);
        }

        DB::table('appointment_slots')
            ->where('id', $id)
            ->update([
                'status'     => 'canceled',
                'updated_at' => now(),
            ]);

        // در صورت نیاز به ارسال پیامک لغو به بیمار:
        // $patient = DB::table('users')->find($slot->patient_id);
        // SmsService::sendCancelNotice($patient->phone, ...);

        return response()->json([
            'success' => true,
            'message' => 'نوبت با موفقیت لغو شد.',
        ]);
    }
}
