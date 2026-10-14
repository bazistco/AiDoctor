<?php

namespace App\Http\Controllers\Api\Staff\Doctor;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class DoctorStaffPatientController extends Controller
{
    protected function getDoctorId(int $secretaryUserId): ?int
    {
        return DB::table('doctor_secretaries')
            ->where('secretary_user_id', $secretaryUserId)
            ->value('doctor_user_id');
    }
    public function index(Request $request): JsonResponse
    {
        $doctorId = $this->getDoctorId($request->user()->id);
        if (!$doctorId) {
            return response()->json([
                'success' => false,
                'message' => 'شناسه پزشک مرتبط یافت نشد.',
            ], Response::HTTP_NOT_FOUND);
        }

        $search = trim($request->query('search', ''));

        // واکشی تمامی نوبت‌های این پزشک که نوبت خام یا خالی نیستند
        $query = DB::table('doctor_appointments')
            ->leftJoin('users', 'doctor_appointments.patient_id', '=', 'users.id')
            ->where('doctor_appointments.doctor_id', $doctorId)
            ->whereNotIn('doctor_appointments.status', ['available', 'reserved'])
            ->select([
                'doctor_appointments.id',
                'doctor_appointments.status',
                'doctor_appointments.slot_date',
                'doctor_appointments.start_time',
                'doctor_appointments.extra_detail',
                'doctor_appointments.patient_id',
                'users.name as user_name',
                'users.phone as user_phone',
            ]);

        $appointments = $query->orderBy('doctor_appointments.slot_date', 'desc')->get();

        $patientMap = [];

        foreach ($appointments as $apt) {
            $extra = is_string($apt->extra_detail) ? json_decode($apt->extra_detail, true) : ($apt->extra_detail ?? []);

            // اولویت با نام و شماره در extra_detail (برای ثبت دستی منشی)، سپس دیتای جدول کاربران
            $phone = $extra['patient']['phone'] ?? $apt->user_phone ?? null;
            $name = $extra['patient']['name'] ?? $apt->user_name ?? 'بیمار ناشناس';

            if (!$phone) {
                continue;
            }

            if (!isset($patientMap[$phone])) {
                $patientMap[$phone] = [
                    'name'      => $name,
                    'phone'     => $phone,
                    'visits'    => 0,
                    'lastVisit' => null,
                    'nextVisit' => null,
                ];
            }

            $aptDate = $apt->slot_date;

            // محاسبه تعداد ویزیت‌های انجام‌شده و تاریخ آخرین ویزیت
            if (in_array($apt->status, ['done', 'completed'])) {
                $patientMap[$phone]['visits'] += 1;
                if (!$patientMap[$phone]['lastVisit'] || $aptDate > $patientMap[$phone]['lastVisit']) {
                    $patientMap[$phone]['lastVisit'] = $aptDate;
                }
            }

            // محاسبه نوبت آینده
            if (in_array($apt->status, ['booked', 'checked_in'])) {
                if (!$patientMap[$phone]['nextVisit'] || $aptDate < $patientMap[$phone]['nextVisit']) {
                    $patientMap[$phone]['nextVisit'] = $aptDate;
                }
            }
        }

        $patients = array_values($patientMap);

        // اعمال فیلتر جستجو بر اساس نام یا شماره موبایل
        if ($search !== '') {
            $patients = array_values(array_filter($patients, function ($p) use ($search) {
                return str_contains($p['name'], $search) || str_contains($p['phone'], $search);
            }));
        }

        return response()->json([
            'success' => true,
            'data'    => $patients,
        ]);
    }
}

