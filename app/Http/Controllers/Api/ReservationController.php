<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Carbon\Carbon;
use App\Models\AppointmentSlot;
use Illuminate\Validation\ValidationException;

class ReservationController extends Controller
{
    /**
     * لغو رزرو موقت کاربر
     */
    public function cancelReservation(Request $request)
    {
        try {
            $validated = $request->validate([
                'reservation_token' => 'required|string'
            ]);

            $userId = $request->user()->id;
            $reservationToken = $validated['reservation_token'];

            // بررسی وجود رزرو موقت
            $userReservationKey = "user:reservation:{$userId}:{$reservationToken}";

            if (!Redis::exists($userReservationKey)) {
                return response()->json([
                    'success' => false,
                    'message' => 'رزرو موقت یافت نشد یا منقضی شده است'
                ], 404);
            }

            // دریافت slot_id
            $slotId = Redis::get($userReservationKey);

            // بررسی وجود اطلاعات رزرو
            $slotReservationKey = "slot:reservation:{$slotId}";
            $reservationData = Redis::get($slotReservationKey);

            if (!$reservationData) {
                // پاک کردن کلید کاربر اگر اطلاعات رزرو وجود ندارد
                Redis::del($userReservationKey);

                return response()->json([
                    'success' => false,
                    'message' => 'اطلاعات رزرو یافت نشد'
                ], 404);
            }

            $reservationData = json_decode($reservationData, true);

            // بررسی تطابق user_id
            if ($reservationData['user_id'] != $userId) {
                return response()->json([
                    'success' => false,
                    'message' => 'شما مجاز به لغو این رزرو نیستید'
                ], 403);
            }

            // حذف کلیدهای رزرو موقت از Redis
            Redis::del($slotReservationKey);
            Redis::del($userReservationKey);

            return response()->json([
                'success' => true,
                'message' => 'رزرو موقت با موفقیت لغو شد',
                'data' => [
                    'slot_id' => $slotId,
                    'cancelled_at' => now()->toIso8601String()
                ]
            ], 200);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'خطای اعتبارسنجی',
                'errors' => $e->errors()
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'خطا در لغو رزرو: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getActiveReservation(Request $request)
    {
        try {
            $request->validate([
                'doctor_id' => 'required|integer|exists:users,id'
            ]);

            $userId = $request->user()->id;
            $doctorId = $request->input('doctor_id');

            // جستجوی کلیدهای رزرو کاربر در Redis
            $pattern = "user:reservation:{$userId}:*";
            $keys = Redis::connection()->keys($pattern);

            if (empty($keys)) {
                return response()->json([
                    'success' => false,
                    'message' => 'هیچ رزرو فعالی یافت نشد'
                ], 404);
            }

            // بررسی هر کلید برای یافتن رزرو مربوط به این دکتر
            foreach ($keys as $key) {
                // حذف prefix اگر وجود دارد
                $cleanKey = str_replace('laravel_database_', '', $key);

                $slotId = Redis::get($cleanKey);

                if (!$slotId) {
                    continue;
                }

                // دریافت اطلاعات رزرو از Redis
                $reservationKey = "slot:reservation:{$slotId}";
                $reservationDataJson = Redis::get($reservationKey);

                if (!$reservationDataJson) {
                    continue;
                }

                $reservationData = json_decode($reservationDataJson, true);

                // بررسی اینکه آیا این رزرو برای دکتر مورد نظر است
                if ($reservationData['doctor_id'] == $doctorId) {
                    // دریافت TTL برای محاسبه زمان انقضا
                    $ttl = Redis::ttl($reservationKey);

                    return response()->json([
                        'success' => true,
                        'message' => 'رزرو فعال یافت شد',
                        'data' => [
                            'reservation_token' => $reservationData['token'],
                            'slot_id' => $reservationData['slot_id'],
                            'doctor_id' => $reservationData['doctor_id'],
                            'slot_date' => $reservationData['slot_date'],
                            'start_time' => $reservationData['start_time'],
                            'end_time' => $reservationData['end_time'],
                            'reserved_at' => $reservationData['reserved_at'],
                            'expires_at' => now()->addSeconds($ttl)->toIso8601String(),
                            'remaining_seconds' => $ttl
                        ]
                    ], 200);
                }
            }

            return response()->json([
                'success' => false,
                'message' => 'هیچ رزرو فعالی برای این دکتر یافت نشد'
            ], 404);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'خطا در اعتبارسنجی داده‌ها',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            \Log::error('خطا در دریافت رزرو فعال: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }


    /**
     * رزرو موقت اسلات (15 دقیقه)
     */
    public function reserveSlot(Request $request)
    {
        // اعتبارسنجی ورودی
        $validated = $request->validate([
            'slot_id' => 'required|integer|exists:appointment_slots,id',
        ]);

        $slotId = $validated['slot_id'];
        $userId = $request->user()->id; // دریافت user_id از توکن احراز هویت

        // بررسی وجود اسلات در دیتابیس
        $slot = AppointmentSlot::find($slotId);

        if (!$slot) {
            return response()->json([
                'success' => false,
                'message' => 'اسلات مورد نظر یافت نشد'
            ], 404);
        }

        // بررسی وضعیت اسلات
        if ($slot->status !== 'available') {
            return response()->json([
                'success' => false,
                'message' => 'این اسلات در دسترس نیست'
            ], 409);
        }

        // کلید Redis برای رزرو موقت
        $reservationKey = "slot:reservation:{$slotId}";

        // بررسی اینکه آیا اسلات قبلاً رزرو شده است
        if (Redis::exists($reservationKey)) {
            return response()->json([
                'success' => false,
                'message' => 'این اسلات در حال حاضر رزرو شده است'
            ], 409);
        }

        // ایجاد توکن منحصر به فرد برای رزرو
        $reservationToken = Str::uuid()->toString();

        // اطلاعات رزرو
        $reservationData = [
            'user_id' => $userId,
            'slot_id' => $slotId,
            'doctor_id' => $slot->doctor_id,
            'slot_date' => $slot->slot_date,
            'start_time' => $slot->start_time,
            'end_time' => $slot->end_time,
            'token' => $reservationToken,
            'reserved_at' => Carbon::now()->toDateTimeString(),
        ];

        // ذخیره در Redis با زمان انقضا 15 دقیقه (900 ثانیه)
        Redis::setex(
            $reservationKey,
            900,
            json_encode($reservationData)
        );

        // ذخیره کلید مجزا برای کاربر
        $userReservationKey = "user:reservation:{$userId}:{$reservationToken}";
        Redis::setex($userReservationKey, 900, $slotId);

        // محاسبه زمان انقضا
        $expiresAt = Carbon::now()->addMinutes(15)->toDateTimeString();

        return response()->json([
            'success' => true,
            'message' => 'اسلات با موفقیت برای 15 دقیقه رزرو شد',
            'data' => [
                'reservation_token' => $reservationToken,
                'expires_at' => $expiresAt,
                'slot_id' => $slotId,
                'slot_date' => $slot->slot_date,
                'start_time' => $slot->start_time,
                'end_time' => $slot->end_time,
            ]
        ], 200);
    }

    /**
     * تایید نهایی رزرو
     */
    public function confirmReservation(Request $request)
    {
        // اعتبارسنجی ورودی
        $validated = $request->validate([
            'reservation_token' => 'required|string',
        ]);

        $reservationToken = $validated['reservation_token'];
        $userId = $request->user()->id; // دریافت user_id از توکن احراز هویت

        // کلید Redis برای بررسی توکن کاربر
        $userReservationKey = "user:reservation:{$userId}:{$reservationToken}";

        // بررسی وجود رزرو
        if (!Redis::exists($userReservationKey)) {
            return response()->json([
                'success' => false,
                'message' => 'رزرو منقضی شده یا نامعتبر است'
            ], 404);
        }

        // دریافت slot_id از Redis
        $slotId = Redis::get($userReservationKey);

        // کلید Redis برای اطلاعات رزرو
        $reservationKey = "slot:reservation:{$slotId}";

        // دریافت اطلاعات رزرو
        $reservationDataJson = Redis::get($reservationKey);

        if (!$reservationDataJson) {
            return response()->json([
                'success' => false,
                'message' => 'اطلاعات رزرو یافت نشد'
            ], 404);
        }

        $reservationData = json_decode($reservationDataJson, true);

        // بررسی تطابق user_id
        if ($reservationData['user_id'] != $userId) {
            return response()->json([
                'success' => false,
                'message' => 'این رزرو متعلق به شما نیست'
            ], 403);
        }

        // تایید نهایی رزرو در دیتابیس
        $slot = AppointmentSlot::find($slotId);

        if (!$slot) {
            return response()->json([
                'success' => false,
                'message' => 'اسلات مورد نظر یافت نشد'
            ], 404);
        }

        // بررسی مجدد وضعیت اسلات
        if ($slot->status !== 'available') {
            // حذف کلیدهای Redis
            Redis::del($reservationKey);
            Redis::del($userReservationKey);

            return response()->json([
                'success' => false,
                'message' => 'این اسلات دیگر در دسترس نیست'
            ], 409);
        }

        // به‌روزرسانی اسلات در دیتابیس
        $slot->update([
            'status' => 'booked',
            'patient_id' => $userId,
            'booking_time' => Carbon::now(),
            'reserved_until' => null, // پاک کردن reserved_until چون رزرو نهایی شد
        ]);

        // حذف کلیدهای موقت از Redis
        Redis::del($reservationKey);
        Redis::del($userReservationKey);

        return response()->json([
            'success' => true,
            'message' => 'رزرو با موفقیت تایید شد',
            'data' => [
                'slot_id' => $slotId,
                'slot_date' => $slot->slot_date,
                'start_time' => $slot->start_time,
                'end_time' => $slot->end_time,
                'booking_time' => $slot->booking_time->toDateTimeString(),
            ]
        ], 200);
    }
}

