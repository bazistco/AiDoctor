<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Carbon\Carbon;

class HomeController extends Controller
{
    public function getHomeData(Request $request)
    {
        try {
            $userId = $request->user()->id;
            $today = now()->format('Y-m-d');

            // ---------------------------------------------------------
            // ۱. دریافت نوبت فعال (بر اساس منطق دیتابیس و ردیس)
            // ---------------------------------------------------------
            $slot = DB::table('appointment_slots')
                ->join('users as doctor_user', 'appointment_slots.doctor_id', '=', 'doctor_user.id')
                ->join('doctor_info', 'doctor_user.id', '=', 'doctor_info.user_id')
                ->leftJoin('specialties', 'doctor_info.specialty_id', '=', 'specialties.id')
                ->where('appointment_slots.patient_id', $userId)
                ->where('appointment_slots.slot_date', '>=', $today)
                ->whereIn('appointment_slots.status', ['available', 'booked']) // موقت (در ردیس) یا قطعی
                ->orderBy('appointment_slots.slot_date', 'asc')
                ->orderBy('appointment_slots.start_time', 'asc')
                ->select(
                    'appointment_slots.id',
                    'appointment_slots.slot_date',
                    'appointment_slots.start_time',
                    'appointment_slots.status',
                    'doctor_info.user_id as doctor_id',
                    'doctor_user.name as doctor_name',
                    'doctor_info.image_url as doctor_image',
                    'specialties.name as specialty_name'
                )
                ->first();

            $activeAppointment = null;

            if ($slot) {
                $isActive = false;
                $expiresAt = null;
                $isTemporary = false;

                if ($slot->status === 'available') {
                    // بررسی وضعیت رزرو موقت در ردیس
                    $redisData = Redis::get("slot:reservation:{$slot->id}");
                    if ($redisData && $redisData !== 'locking...') {
                        $decoded = json_decode($redisData, true);
                        if (isset($decoded['user_id']) && $decoded['user_id'] == $userId) {
                            $isActive = true;
                            $isTemporary = true;
                            $expiresAt = Carbon::parse($decoded['reserved_at'])->addMinutes(15)->toDateTimeString();
                        }
                    }
                } else if ($slot->status === 'booked') {
                    // نوبت قطعی
                    $isActive = true;
                }

                if ($isActive) {
                    $activeAppointment = [
                        'id'             => $slot->id,
                        'doctor_id'      => $slot->doctor_id,
                        'doctor_name'    => $slot->doctor_name,
                        'doctor_image'   => $slot->doctor_image ? asset('storage/' . $slot->doctor_image) : null,
                        'specialty_name' => $slot->specialty_name,
                        'date'           => $slot->slot_date,
                        'time'           => $slot->start_time,
                        'is_temporary'   => $isTemporary,
                        'expires_at'     => $expiresAt,
                        'status'         => $slot->status,
                    ];
                }
            }

            // ---------------------------------------------------------
            // ۲. دریافت پزشک پیشنهادی (رندوم از doctor_info)
            // ---------------------------------------------------------
            $suggestedDoctor = DB::table('doctor_info')
                ->join('users', 'users.id', '=', 'doctor_info.user_id')
                ->leftJoin('specialties', 'doctor_info.specialty_id', '=', 'specialties.id')
                ->where('users.status', 1)
//                ->where('doctor_info.user_id',)// فقط پزشکان فعال
                ->select(
                    'users.id',
                    'users.name',
                    'specialties.name as specialty',
                    'doctor_info.image_url as image'
                )
                ->inRandomOrder()
                ->first();

            if ($suggestedDoctor) {
                $suggestedDoctor->image =  '/doc-Photoroom.png';
                $suggestedDoctor->rating = 4.8; // امتیاز نمونه ثابت تا زمانی که سیستم نمره‌دهی کامل شود
            }

            // ---------------------------------------------------------
            // ۳. بنر تبلیغاتی (داده نمونه Hardcode شده طبق درخواست)
            // ---------------------------------------------------------
//            $promotionalBanner = [
//                'id'        => 1,
//                'title'     => 'مشاوره آنلاین شبانه‌روزی',
//                'image_url' => 'https://api.mediraai.com/assets/img/appo-banner.png',
//                'link'      => '/services' // لینک داخلی در ری‌اکت
//            ];
            $promotionalBanners = [
                [
                    'id'        => 1,
                    'title'     => 'مشاوره آنلاین شبانه‌روزی',
                    'image_url' => 'https://api.mediraai.com/assets/img/appo-banner.png',
                    'link'      => '/services',
                ],
                [
                    'id'        => 2,
                    'title'     => 'انجام آزمایش در منزل با تخفیف ویژه',
                    'image_url' => 'https://api.mediraai.com/assets/img/lab-banner.png',
                    'link'      => '/lab-orders',
                ],
                [
                    'id'        => 3,
                    'title'     => 'سفارش آنلاین دارو و تحویل فوری',
                    'image_url' => 'https://api.mediraai.com/assets/img/pharmacy-banner.png',
                    'link'      => '/pharmacy',
                ],
            ];

            return response()->json([
                'success' => true,
                'data' => [
                    'active_appointment' => $activeAppointment,
                    'suggested_doctor'   => $suggestedDoctor,
                    'promotional_banner' => $promotionalBanner,
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'خطا در دریافت اطلاعات داشبورد خانه',
                'error'   => $e->getMessage()
            ], 500);
        }
    }
}
