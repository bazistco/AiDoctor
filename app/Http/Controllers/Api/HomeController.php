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
                ->whereIn('appointment_slots.status', ['available', 'booked'])
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
                    $redisData = Redis::get("slot:reservation:{$slot->id}");
                    if ($redisData && $redisData !== 'locking...') {
                        $decoded = json_decode($redisData, true);
                        if (isset($decoded['user_id']) && $decoded['user_id'] == $userId) {
                            $isActive = true;
                            $isTemporary = true;
                            $expiresAt = Carbon::parse($decoded['reserved_at'])->addMinutes(15)->toDateTimeString();
                        }
                    }
                } elseif ($slot->status === 'booked') {
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
                ->select(
                    'users.id',
                    'users.name',
                    'specialties.name as specialty',
                    'doctor_info.image_url as image'
                )
                ->inRandomOrder()
                ->first();

            if ($suggestedDoctor) {
                $suggestedDoctor->image = '/doc-Photoroom.png';
                $suggestedDoctor->rating = 4.8;
            }

            // ---------------------------------------------------------
            // ۳. بنرهای تبلیغاتی
            // ---------------------------------------------------------
            $promotionalBanners = [
                [
                    'id'        => 1,
                    'title'     => null,
                    'image_url' => 'https://api.mediraai.com/assets/img/appo-banner.png',
                    'link'      => '/doctors',
                ],
                [
                    'id'        => 2,
                    'title'     => null,
                    'image_url' => 'https://api.mediraai.com/assets/img/period.jpg',
                    'link'      => '/period-tracker',
                ],
                [
                    'id'        => 3,
                    'title'     => null,
                    'image_url' => 'https://api.mediraai.com/assets/img/drug.jpeg',
                    'link'      => '/pill-organizer',
                ],
            ];

            // ---------------------------------------------------------
            // ۴. وضعیت پلن فعلی کاربر
            // ---------------------------------------------------------
            $currentPlan = DB::table('user_plans')
                ->join('subscription_plans', 'user_plans.plan_id', '=', 'subscription_plans.id')
                ->where('user_plans.user_id', $userId)
                ->where('user_plans.is_active', 1)
                ->where(function ($query) {
                    $query->whereNull('user_plans.end_date')
                        ->orWhere('user_plans.end_date', '>', now());
                })
                ->orderByDesc('user_plans.id')
                ->select(
                    'user_plans.id',
                    'user_plans.plan_id',
                    'user_plans.start_date',
                    'user_plans.end_date',
                    'user_plans.is_active',
                    'subscription_plans.name as plan_name',
                    'subscription_plans.slug',
                    'subscription_plans.price'
                )
                ->first();

            $hasNonBasicPlan = $currentPlan !== null && $currentPlan->slug !== 'basic';

            // ---------------------------------------------------------
            // ۵. مقالات نمونه سلامت
            // ---------------------------------------------------------
            $blogPosts = [
                [
                    'id'        => 1,
                    'title'     => '۱۰ نکته برای سلامت بهتر قلب',
                    'excerpt'   => 'با این تغییرات ساده قلبی سالم‌تر داشته باشید.',
                    'image'     => 'https://app.mediraai.com/scan.jpg',
                    'date'      => '۲۸ اسفند ۱۴۰۴',
                    'read_time' => '۵ دقیقه مطالعه',
                    'tag'       => 'قلب و عروق',
                ],
                [
                    'id'        => 2,
                    'title'     => 'راهنمای تغذیه متعادل',
                    'excerpt'   => 'با مواد مغذی ضروری عملکرد سالم بدن خود آشنا شوید.',
                    'image'     => 'https://app.mediraai.com/scan.jpg',
                    'date'      => '۲۵ اسفند ۱۴۰۴',
                    'read_time' => '۷ دقیقه مطالعه',
                    'tag'       => 'تغذیه',
                ],
                [
                    'id'        => 3,
                    'title'     => 'مزایای ورزش منظم',
                    'excerpt'   => 'فعال ماندن چرا برای سلامت جسم و روان ضروری است.',
                    'image'     => 'https://app.mediraai.com/scan.jpg',
                    'date'      => '۲۰ اسفند ۱۴۰۴',
                    'read_time' => '۶ دقیقه مطالعه',
                    'tag'       => 'ورزش',
                ],
                [
                    'id'        => 4,
                    'title'     => 'سلامت روان: شکستن تابوها',
                    'excerpt'   => 'اهمیت سلامت روان در تندرستی کلی بدن را بشناسید.',
                    'image'     => 'https://app.mediraai.com/scan.jpg',
                    'date'      => '۱۵ اسفند ۱۴۰۴',
                    'read_time' => '۸ دقیقه مطالعه',
                    'tag'       => 'سلامت روان',
                ],
            ];

            return response()->json([
                'success' => true,
                'data'    => [
                    'active_appointment' => $activeAppointment,
                    'suggested_doctor'   => $suggestedDoctor,
                    'promotional_banner' => $promotionalBanners,
                    'current_plan'       => $currentPlan,
                    'has_non_basic_plan' => $hasNonBasicPlan,
                    'blog_posts'         => $blogPosts,
                ],
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'خطا در دریافت اطلاعات داشبورد خانه',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
}
