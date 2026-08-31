<?php

namespace App\Http\Controllers\Api\Doctor;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DoctorDashboardController extends Controller
{
    public function index(Request $request)
    {
        $doctorId = auth()->user()->id;

        // ۱. اطلاعات پروفایل پزشک
        $profile = DB::table('users as u')
            ->join('doctor_info as d', 'u.id', '=', 'd.user_id')
            ->leftJoin('specialties as s', 'd.specialty_id', '=', 's.id')
            ->where('u.id', $doctorId)
            ->select('u.name', 's.name as specialty','d.status')
            ->first();

        // ۲. مشاوره‌ها / اتاق‌های فعال
        $activeConsultations = DB::table('room_participants')
            ->where('user_id', $doctorId)
            ->where('status', 1)
            ->count();

        // ۳. نوبت‌های امروز
        $todayAppointments = DB::table('appointment_slots')
            ->where('doctor_id', $doctorId)
            ->where('status', 'booked')
            ->whereDate('created_at', Carbon::today())
            ->count();

        // ۴. ۵ نوبت اخیر
        $recentAppointments = DB::table('appointment_slots as s')
            ->join('users as u', 'u.id', '=', 's.patient_id')
            ->where('s.doctor_id', $doctorId)
            ->where('s.status', 'booked')
            ->orderBy('s.created_at', 'desc')
            ->limit(5)
            ->get([
                's.id',
                'u.name as patientName',
                's.status',
                DB::raw("'in-person' as visitType"),
                DB::raw('DATE(s.created_at) as date'),
                DB::raw('TIME(s.created_at) as time')
            ]);

        // ۵. تعداد کل بیماران یکتا
        $totalPatients = DB::table('appointment_slots')
            ->where('doctor_id', $doctorId)
            ->where('status', 'booked')
            ->distinct('patient_id')
            ->count('patient_id');

        // ۶. کیف پول و درآمد ماهانه
        $wallet = DB::table('wallets')->where('user_id', $doctorId)->first();
        $monthlyRevenue = 0;

        if ($wallet) {
            $monthlyRevenue = DB::table('wallet_transactions')
                ->where('wallet_id', $wallet->id)
                ->where('type', 1)
                ->where('subject_type', 2)
                ->whereMonth('created_at', Carbon::now()->month)
                ->whereYear('created_at', Carbon::now()->year)
                ->sum('amount');
        }

        // ۷. محاسبه داده‌های چارت (درآمد، ویزیت‌ها و توضیحات تراکنش‌های ۷ روز اخیر)
        $revenueChart = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = Carbon::today()->subDays($i);

            $dailyRevenue = 0;
            $descriptions = '';

            if ($wallet) {
                // دریافت تراکنش‌های آن روز به جای استفاده مستقیم از sum
                $transactions = DB::table('wallet_transactions')
                    ->where('wallet_id', $wallet->id)
                    ->where('type', 1)
                    ->where('subject_type', 2)
                    ->whereDate('created_at', $date->toDateString())
                    ->get(['amount', 'description']);

                $dailyRevenue = $transactions->sum('amount');

                // چسباندن توضیحات تراکنش‌های آن روز به یکدیگر (در صورت وجود بیش از یک تراکنش)
                $descriptions = $transactions->pluck('description')->filter()->implode(' | ');
            }

            $dailyVisits = DB::table('appointment_slots')
                ->where('doctor_id', $doctorId)
                ->whereDate('created_at', $date->toDateString())
                ->count();

            $revenueChart[] = [
                'day' => $date->format('m/d'),
                'amount' => (int) $dailyRevenue,
                'visits' => $dailyVisits,
                'description' => $descriptions ?: 'بدون تراکنش', // اضافه شدن فیلد توضیحات
            ];
        }

        return response()->json([
            'profile' => [
                'name' => $profile->name ?? 'دکتر',
                'specialty' => $profile->specialty ?? 'نامشخص',
                'rating' => 5,
                'status'=>$profile->status ?? 0
            ],
            'stats' => [
                'todayAppointments' => $todayAppointments,
                'totalPatients' => $totalPatients,
                'monthlyRevenue' => (int) $monthlyRevenue,
                'activeConsultations' => $activeConsultations,
            ],
            'revenueChart' => $revenueChart,
            'recentAppointments' => $recentAppointments,
        ]);
    }
}
