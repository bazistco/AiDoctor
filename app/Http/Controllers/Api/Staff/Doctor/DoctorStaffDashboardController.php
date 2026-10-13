<?php

namespace App\Http\Controllers\Api\Staff\Doctor;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DoctorStaffDashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $secretary = $request->user();

        $doctorId = DB::table('doctor_secretaries')->where('secretary_user_id',$secretary->id)->first()->doctor_user_id;

        if (!$doctorId) {
            return response()->json([
                'success' => false,
                'message' => 'پزشک مرتبط با این حساب یافت نشد.',
            ], 404);
        }

        // دریافت مشخصات پزشک
        $doctor = DB::table('users')->where('id', $doctorId)->first(['id', 'name', 'phone']);

        // تاریخ امروز به میلادی برای کوئری دیتابیس
        $today = Carbon::today()->format('Y-m-d');

        // دریافت تمام اسلات‌های امروز که مربوط به بیماران هستند (رزرو شده، حاضر، یا ویزیت شده)
        $todaySlots = DB::table('appointment_slots as ap')
            ->leftJoin('users as u', 'u.id', '=', 'ap.patient_id')
            ->select(
                'ap.id',
                'ap.slot_date',
                'ap.start_time',
                'ap.end_time',
                'ap.status',
                'ap.patient_id',
                'u.name as patient_name',
                'u.phone as patient_phone'
            )
            ->where('ap.doctor_id', $doctorId)
            ->whereDate('ap.slot_date', $today)
            ->whereIn('ap.status', ['booked', 'checked_in', 'done', 'canceled'])
            ->orderBy('ap.start_time', 'asc')
            ->get();

        // محاسبه آمارهای ۴ گانه کارت‌های KPI
        $kpis = [
            'today_total' => $todaySlots->count(),
            'checked_in'  => $todaySlots->where('status', 'checked_in')->count(),
            'remaining'   => $todaySlots->where('status', 'booked')->count(),
            'done'        => $todaySlots->where('status', 'done')->count(),
        ];

        // شکل‌دهی صف نوبت‌ها برای نمایش در فرانت
        $queue = $todaySlots->map(function ($slot) {
            return [
                'id'            => $slot->id,
                'time'          => $slot->start_time ? substr($slot->start_time, 0, 5) : '—',
                'patient_name'  => $slot->patient_name ?? 'بیمار بدون نام',
                'patient_phone' => $slot->patient_phone ?? '—',
                'type'          => 'in_person',
                'status'        => $slot->status,
            ];
        });

        // تاریخ شمسی امروز برای هدر
        $todayShamsi = function_exists('verta')
            ? verta()->format('Y/m/d')
            : Carbon::now()->format('Y-m-d');

        return response()->json([
            'success' => true,
            'data'    => [
                'staff' => [
                    'id'          => $secretary->id,
                    'name'        => $secretary->name ?? 'منشی مطب',
                    'phone'       => $secretary->phone,
                    'doctor_name' => $doctor ? ($doctor->name ?? 'پزشک') : 'مطب',
                ],
                'today_date' => $todayShamsi,
                'kpis'       => $kpis,
                'queue'      => $queue,
            ],
        ]);
    }
}
