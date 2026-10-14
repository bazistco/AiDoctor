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
    protected function getDoctorId(int $secretaryUserId): ?int
    {
        return DB::table('doctor_secretaries')
            ->where('secretary_user_id', $secretaryUserId)
            ->value('doctor_user_id');
    }

    /**
     * تبدیل تاریخ میلادی دیتابیس به هجری شمسی (YYYY/MM/DD)
     */
    protected function toShamsiDate(?string $dateString): string
    {
        if (!$dateString) {
            return '—';
        }
        if (function_exists('verta')) {
            return verta($dateString)->format('Y/m/d');
        }
        return Carbon::parse($dateString)->format('Y-m-d');
    }

    /**
     * تبدیل تاریخ ورودی (چه شمسی چه میلادی) به میلادی Y-m-d برای کوئری دیتابیس
     */
    protected function toMiladiDate(string $inputDate): string
    {
        $inputDate = trim($inputDate);
        if (function_exists('verta')) {
            try {
                // اگر به فرمت شمسی Y/m/d یا Y-m-d فرستاده شده باشد
                return verta()->parse($inputDate)->toCarbon()->format('Y-m-d');
            } catch (\Exception $e) {
                return Carbon::parse($inputDate)->format('Y-m-d');
            }
        }
        return Carbon::parse($inputDate)->format('Y-m-d');
    }

    /**
     * لیست نوبت‌های رزرو شده / حاضر / لغو شده همراه با تاریخ شمسی
     */
    public function index(Request $request): JsonResponse
    {
        $doctorId = $this->getDoctorId($request->user()->id);
        if (!$doctorId) {
            return response()->json(['success' => false, 'message' => 'پزشک مرتبط یافت نشد.'], 404);
        }

        $range  = $request->query('range', 'today');
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
                'u.name as patient_name',
                'u.phone as patient_phone'
            )
            ->where('ap.doctor_id', $doctorId);

        if ($range === 'today') {
            $query->whereDate('ap.slot_date', '=', $today);
        } elseif ($range === 'upcoming') {
            $query->whereDate('ap.slot_date', '>=', $today);
        }

        if ($status !== 'all') {
            $query->where('ap.status', $status);
        }

        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('u.name', 'like', "%{$search}%")
                    ->orWhere('u.phone', 'like', "%{$search}%");
            });
        }

        $slots = $query->orderBy('ap.slot_date', 'asc')
            ->orderBy('ap.start_time', 'asc')
            ->get();

        $data = $slots->map(function ($slot) {
            return [
                'id'           => $slot->id,
                'patientName'  => $slot->patient_name ?? 'بیمار بدون نام',
                'patientPhone' => $slot->patient_phone ?? '—',
                'date'         => $this->toShamsiDate($slot->slot_date), // تبدیل به تاریخ هجری شمسی
                'raw_date'     => $slot->slot_date,
                'time'         => $slot->start_time ? substr($slot->start_time, 0, 5) : '—',
                'type'         => 'in_person',
                'status'       => $slot->status,
                'source'       => $slot->source ?? 'patient',
            ];
        });

        return response()->json([
            'success' => true,
            'data'    => $data,
        ]);
    }

    /**
     * وب‌سرویس دریافت اسلات‌های آزاد پزشک بر اساس تاریخ انتخابی
     */
    public function getAvailableSlots(Request $request): JsonResponse
    {
        $doctorId = $this->getDoctorId($request->user()->id);
        if (!$doctorId) {
            return response()->json(['success' => false, 'message' => 'پزشک مرتبط یافت نشد.'], 404);
        }

        $request->validate([
            'date' => 'required|string',
        ]);

        $miladiDate = $this->toMiladiDate($request->date);

        // واکشی اسلات‌های با وضعیت آزاد (یا اسلات‌هایی که بیمار ندارند و کنسل نیستند)
        $slots = DB::table('appointment_slots')
            ->where('doctor_id', $doctorId)
            ->whereDate('slot_date', $miladiDate)
            ->where('status', 'available')
            ->whereNull('patient_id')
            ->orderBy('start_time', 'asc')
            ->get(['id', 'start_time', 'end_time', 'slot_date']);

        $availableList = $slots->map(function ($s) {
            return [
                'slot_id'    => $s->id,
                'time'       => substr($s->start_time, 0, 5),
                'start_time' => $s->start_time,
                'end_time'   => $s->end_time,
            ];
        });

        return response()->json([
            'success' => true,
            'data'    => [
                'date_shamsi' => $this->toShamsiDate($miladiDate),
                'date_miladi' => $miladiDate,
                'slots'       => $availableList,
            ]
        ]);
    }

    /**
     * رزرو نوبت بر روی یک اسلات مشخص آزاد شده توسط منشی
     */
    public function bookSlot(Request $request): JsonResponse
    {
        $doctorId = $this->getDoctorId($request->user()->id);
        if (!$doctorId) {
            return response()->json(['success' => false, 'message' => 'پزشک مرتبط یافت نشد.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'slot_id'      => 'required|integer',
            'patientName'  => 'required|string|max:190',
            'patientPhone' => 'required|string|regex:/^09[0-9]{9}$/',
        ], [
            'slot_id.required'      => 'انتخاب اسلات نوبت الزامی است.',
            'patientName.required'  => 'نام بیمار را وارد کنید.',
            'patientPhone.required' => 'شماره موبایل بیمار را وارد کنید.',
            'patientPhone.regex'    => 'شماره موبایل وارد شده معتبر نیست.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
                'errors'  => $validator->errors(),
            ], 422);
        }

        return DB::transaction(function () use ($doctorId, $request) {
            // قفل رکورد اسلات برای جلوگیری از رزرو همزمان
            $slot = DB::table('appointment_slots')
                ->where('id', $request->slot_id)
                ->where('doctor_id', $doctorId)
                ->lockForUpdate()
                ->first();

            if (!$slot) {
                return response()->json(['success' => false, 'message' => 'اسلات نوبت مورد نظر یافت نشد.'], 404);
            }

            if ($slot->status !== 'available' || !empty($slot->patient_id)) {
                return response()->json(['success' => false, 'message' => 'این ساعت قبلاً رزرو شده یا آزاد نیست.'], 409);
            }

            // پیدا کردن یا ایجاد بیمار
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
                // به‌روزرسانی نام در صورت نیاز
                if (!empty($request->patientName) && empty($patient->name)) {
                    DB::table('users')->where('id', $patientId)->update(['name' => $request->patientName]);
                }
            }

            // تخصیص بیمار و تغییر وضعیت به booked
            DB::table('appointment_slots')
                ->where('id', $slot->id)
                ->update([
                    'patient_id' => $patientId,
                    'status'     => 'booked',
                    'source'     => 'secretary',
                    'updated_at' => now(),
                ]);

            return response()->json([
                'success' => true,
                'message' => 'نوبت با موفقیت رزرو شد.',
                'data'    => [
                    'id'           => $slot->id,
                    'patientName'  => $request->patientName,
                    'patientPhone' => $request->patientPhone,
                    'date'         => $this->toShamsiDate($slot->slot_date),
                    'time'         => substr($slot->start_time, 0, 5),
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
     * جابه‌جایی نوبت (تخصیص به اسلات جدید یا تاریخ جدید)
     */
    public function reschedule(Request $request, int $id): JsonResponse
    {
        $doctorId = $this->getDoctorId($request->user()->id);
        if (!$doctorId) {
            return response()->json(['success' => false, 'message' => 'پزشک مرتبط یافت نشد.'], 404);
        }

        $request->validate([
            'new_slot_id' => 'required|integer',
        ], [
            'new_slot_id.required' => 'شناسه اسلات جدید الزامی است.',
        ]);

        return DB::transaction(function () use ($doctorId, $id, $request) {
            $oldSlot = DB::table('appointment_slots')
                ->where('id', $id)
                ->where('doctor_id', $doctorId)
                ->lockForUpdate()
                ->first();

            if (!$oldSlot || !$oldSlot->patient_id) {
                return response()->json(['success' => false, 'message' => 'نوبت قبلی معتبر نیست.'], 404);
            }

            $newSlot = DB::table('appointment_slots')
                ->where('id', $request->new_slot_id)
                ->where('doctor_id', $doctorId)
                ->lockForUpdate()
                ->first();

            if (!$newSlot || $newSlot->status !== 'available' || !empty($newSlot->patient_id)) {
                return response()->json(['success' => false, 'message' => 'اسلات جدید انتخابی آزاد نیست.'], 409);
            }

            // آزاد کردن اسلات قبلی
            DB::table('appointment_slots')
                ->where('id', $oldSlot->id)
                ->update([
                    'patient_id' => null,
                    'status'     => 'available',
                    'source'     => null,
                    'updated_at' => now(),
                ]);

            // رزرو اسلات جدید
            DB::table('appointment_slots')
                ->where('id', $newSlot->id)
                ->update([
                    'patient_id' => $oldSlot->patient_id,
                    'status'     => 'booked',
                    'source'     => 'secretary',
                    'updated_at' => now(),
                ]);

            return response()->json([
                'success' => true,
                'message' => 'نوبت با موفقیت جابه‌جا شد.',
            ]);
        });
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

        // نوبت لغو شده و وضعیت آن به canceled تغییر می‌یابد
        DB::table('appointment_slots')
            ->where('id', $id)
            ->update([
                'status'     => 'canceled',
                'updated_at' => now(),
            ]);

        return response()->json([
            'success' => true,
            'message' => 'نوبت با موفقیت لغو شد.',
        ]);
    }
}
