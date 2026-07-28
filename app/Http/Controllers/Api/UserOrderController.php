<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserOrderController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $userId = $user->id;

        // --- UNION کوئری اصلی ---
        $q1 = DB::table('appointment_slots as sa')
            ->join('doctor_info as di', 'sa.doctor_id', '=', 'di.user_id')
            ->join('specialties as s', 'di.specialty_id', '=', 's.id')
            ->select(
                'sa.id',
                'sa.status',
                'sa.price',
                'sa.created_at',
                'di.name',
                's.name as detail',
                DB::raw("'doctor' as type")
            )
            ->where('sa.patient_id', $userId);

        $q2 = DB::table('users_labs_requests as ulr')
            ->join('labs_info as li', 'ulr.lab_id', '=', 'li.user_id')
            ->select(
                'ulr.id',
                'ulr.status',
                'ulr.total_price as price',
                'ulr.created_at',
                'li.name',
                DB::raw("IF(ulr.visit_type = 0, 'در منزل', 'حضوری') as detail"),
                DB::raw("'lab' as type")
            )
            ->where('ulr.user_id', $userId);

        $q3 = DB::table('users_pharmacy_requests as upr')
            ->leftJoin('pharmacies_info as pi', 'upr.pharmacy_id', '=', 'pi.user_id')
            ->select(
                'upr.id',
                'upr.status',
                'upr.total_price as price',
                'upr.created_at',
                'pi.name',
                DB::raw("'-' as detail"),
                DB::raw("'pharmacy' as type")
            )
            ->where('upr.user_id', $userId);

        $q4 = DB::table('user_medical_center_requests as umcr')
            ->join('medical_centers_info as mci', 'umcr.medical_center_id', '=', 'mci.user_id')
            ->join('medical_services_time_types as mcty', 'mcty.id', '=', 'umcr.time_type_id')
            ->select(
                'umcr.id',
                'umcr.status',
                DB::raw('umcr.total_price as price'),
                'umcr.created_at',
                'mci.name',
                'mcty.name as detail',
                DB::raw("'nurse' as type")
            )
            ->where('umcr.user_id', $userId);

        $orders = $q1->unionAll($q2)
            ->unionAll($q3)
            ->unionAll($q4)
            ->orderBy('created_at', 'desc')
            ->get();

        // --- تبدیل وضعیت به برچسب فارسی ---
        $orders = $orders->map(function ($order) {
            $order->status_label = $this->mapStatus((string) $order->type, $order->status);
            return $order;
        });

        // --- شمارش کلی هر دسته ---
        $countDoctor   = DB::table('appointment_slots')->where('patient_id', $userId)->count();
        $countLab      = DB::table('users_labs_requests')->where('user_id', $userId)->count();
        $countPharmacy = DB::table('users_pharmacy_requests')->where('user_id', $userId)->count();
        $countNurse    = DB::table('user_medical_center_requests')->where('user_id', $userId)->count();

        return response()->json([
            'success' => true,
            'data' => [
                'orders'         => $orders,
                'count_doctor'   => $countDoctor,
                'count_lab'      => $countLab,
                'count_pharmacy' => $countPharmacy,
                'count_nurse'    => $countNurse,
            ]
        ]);
    }

    /**
     * نگاشت وضعیت هر نوع به فارسی
     */
    private function mapStatus(string $type, $status): string
    {
        switch ($type) {
            case 'doctor':
                // وضعیت‌های متنی برای دکتر
                return match ($status) {
                    'available' => 'در دسترس',
                    'booked'    => 'رزرو شده',
                    'cancelled' => 'لغو شده',
                    default     => $status   // یا 'نامشخص'
                };

            case 'lab':
                return match ((int) $status) {
                    0 => 'درخواست جدید',
                    1 => 'در انتظار پرداخت',
                    2 => 'در انتظار نمونه‌گیری',
                    3 => 'در انتظار اعلام نتیجه',
                    4 => 'تکمیل شده',
                    5 => 'انجام شده',
                    6 => 'لغو شده',
                    default => $status
                };

            case 'pharmacy':
                return match ((int) $status) {
                    0 => 'در انتظار تایید داروخانه',
                    1 => 'در انتظار پرداخت',
                    2 => 'در حال آماده‌سازی',
                    3 => 'آماده ارسال',
                    4 => 'در حال ارسال',
                    5 => 'تحویل شده',
                    6 => 'تکمیل شده',
                    7 => 'لغو شده',
                    default => $status
                };

            case 'nurse':
                return match ((int) $status) {
                    0 => 'در انتظار پرداخت',
                    1 => 'در انتظار انتخاب پرستار',
                    2 => 'در انتظار مراجعه پرستار',
                    3 => 'مراجعه شده',
                    4 => 'تکمیل شده',
                    5 => 'لغو شده',
                    default => $status
                };

            default:
                return $status;
        }
    }
}
