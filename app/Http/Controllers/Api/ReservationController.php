<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FinancialService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Carbon\Carbon;
use App\Models\AppointmentSlot;
use Illuminate\Validation\ValidationException;


class ReservationController extends Controller
{
    private FinancialService $financialService;

    public function __construct(FinancialService $financialService)
    {
        $this->financialService = $financialService;
    }
    public function getAppointments()
    {
        // ۱. دریافت اطلاعات نوبت‌ها و بیماران
        $appointments = DB::table('appointment_slots as a')
            ->join('users as u', 'a.patient_id', '=', 'u.id')
            ->join('cities as c', 'u.city_id', '=', 'c.id')
            ->join('provinces as p', 'u.province_id', '=', 'p.id')
            ->select(
                'a.id', 'a.slot_date', 'a.status', 'a.start_time', 'a.doctor_id',
                'u.name as patient_name', 'u.phone as patient_phone',
                'p.name as province_name', 'c.name as city_name'
            )
            ->get();

        $doctorIds = $appointments->pluck('doctor_id')->unique()->toArray();

        // ۲. دریافت اطلاعات پزشکان و تخصص‌ها
        $doctors = DB::table('doctor_info as df')
            ->join('specialties as s', 'df.specialty_id', '=', 's.id')
            ->whereIn('df.user_id', $doctorIds)
            ->select('df.user_id', 'df.name as doctor_name', 's.name as specialty')
            ->get()
            ->keyBy('user_id');

        // ۳. فرمت‌دهی نهایی برای خروجی وب‌سرویس
        $result = $appointments->map(function ($item) use ($doctors) {
            $doctor = $doctors->get($item->doctor_id);

            // تبدیل وضعیت دیتابیس به متن فارسی و استایل مناسب
            $statusMap = [
                'booked'    => ['text' => 'رزرو شده', 'color' => 'blue'],
                'available' => ['text' => 'آزاد', 'color' => 'gray'],
                'completed' => ['text' => 'انجام شده', 'color' => 'green'], // اگر در آینده اضافه شد
            ];

            $currentStatus = $statusMap[$item->status] ?? ['text' => $item->status, 'color' => 'default'];

            return [
                'id' => $item->id,
                'patient' => [
                    'name' => $item->patient_name,
                    'location' => "{$item->province_name} — {$item->city_name}",
                ],
                'mobile' => $item->patient_phone,
                'doctor' => [
                    'name' => $doctor ? "دکتر " . $doctor->doctor_name : 'نامشخص',
                    'specialty' => $doctor ? $doctor->specialty : '-',
                ],
                'datetime' => [
                    // تبدیل تاریخ میلادی دیتابیس به شمسی مشابه تصویر
                    'date' => $item->slot_date ,
                    'time' => substr($item->start_time, 0, 5), // تبدیل 09:00:00 به 09:00
                ],
                'status' => $currentStatus,
            ];
        });

        return response()->json($result);
    }
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
            $userReservationData = json_decode(Redis::get($userReservationKey), true);
            $slotId = $userReservationData['slot_id'];
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

    private function isDoctorActive(int $doctorId): bool
    {
        return DB::table('users as u')
            ->join('doctor_info as df', 'u.id', '=', 'df.user_id')
            ->where('u.status', 1)
            ->where('df.status', 1)
            ->where('u.id', $doctorId)
            ->exists();
    }
    /**
     * رزرو موقت اسلات + ایجاد سفارش و پرداخت
     */
    public function reserveSlot(Request $request)
    {
        // اعتبارسنجی ورودی
        $validated = $request->validate([
            'slot_id' => 'required|integer|exists:appointment_slots,id',
            'session_id' => 'nullable|string',
            ]);

        $sessionId = $validated['session_id'] ?? null;
        $slotId = $validated['slot_id'];
        $userId = $request->user()->id;

        // بررسی وجود اسلات در دیتابیس
        $slot = AppointmentSlot::find($slotId);



        if (!$slot) {
            return response()->json([
                'success' => false,
                'message' => 'اسلات مورد نظر یافت نشد'
            ], 404);
        }
        if (!$this->isDoctorActive($slot->doctor_id)) {
            return response()->json([
                'success' => false,
                'message' => 'پزشک مورد نظر در حال حاضر غیرفعال است و امکان رزرو نوبت وجود ندارد'
            ], 422);
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

        try {
            // ═══════════════════════════════════════════════════════
            // فرآیند مالی: ایجاد سفارش و پرداخت
            // ═══════════════════════════════════════════════════════

            // مبلغ نوبت (در اینجا مبلغ ثابت 100,000 تومان فرض شده)
            // در پروداکشن باید از جدول doctors یا appointment_slots خوانده شود
            $amount = 100000; // 100,000 تومان

            // ایجاد سفارش
            $orderId = $this->financialService->createOrder(
                userId: $userId,
                reasonId: 1, // appointment (از جدول payment_reasons)
                reasonRef: $slotId,
                amount: $amount,
                description: "رزرو نوبت پزشک در تاریخ {$slot->slot_date} ساعت {$slot->start_time}"
            );

            // ایجاد پرداخت
            $paymentId = $this->financialService->createPayment(
                userId: $userId,
                orderId: $orderId,
                reasonId: 1, // appointment
                reasonRef: $slotId,
                amount: $amount,
                gateway: 'zarinpal'
            );

            // ═══════════════════════════════════════════════════════
            // شبیه‌سازی درگاه زرین‌پال (نمونه)
            // ═══════════════════════════════════════════════════════

            // در پروداکشن واقعی باید درخواست به API زرین‌پال ارسال شود
            $authority = 'A' . str_pad($paymentId, 35, '0', STR_PAD_LEFT); // شبیه‌سازی authority
            $paymentUrl = "https://payment.zarinpal.com/pg/StartPay/{$authority}";

            // ثبت لاگ درخواست اولیه درگاه
            \DB::table('payment_gateway_logs')->insert([
                'payment_id' => $paymentId,
                'gateway' => 'zarinpal',
                'step' => 'request',
                'request_data' => json_encode([
                    'merchant_id' => 'XXXXXXXX-XXXX-XXXX-XXXX-XXXXXXXXXXXX',
                    'amount' => $amount,
                    'callback_url' => route('payment.callback'),
                    'description' => "پرداخت نوبت #{$slotId}",
                    'metadata' => [
                        'mobile' => $request->user()->mobile ?? '',
                        'email' => $request->user()->email ?? '',
                    ]
                ]),
                'response_data' => json_encode([
                    'status' => 100,
                    'authority' => $authority,
                ]),
                'ip_address' => request()->ip(),
                'created_at' => now(),
            ]);

            // به‌روزرسانی authority در جدول payments
            \DB::table('payments')
                ->where('id', $paymentId)
                ->update([
                    'authority' => $authority,
                    'updated_at' => now(),
                ]);

            // ═══════════════════════════════════════════════════════
            // رزرو موقت در Redis
            // ═══════════════════════════════════════════════════════

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
                'order_id' => $orderId,
                'payment_id' => $paymentId,
                'authority' => $authority,
                'amount' => $amount,
                'session_id' => $sessionId,
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
            Redis::setex($userReservationKey, 900, json_encode([
                'slot_id' => $slotId,
                'payment_id' => $paymentId,
                'order_id' => $orderId,
            ]));

            // محاسبه زمان انقضا
            $expiresAt = Carbon::now()->addMinutes(15)->toDateTimeString();

            return response()->json([
                'success' => true,
                'message' => 'اسلات با موفقیت رزرو شد. لطفاً پرداخت را تکمیل کنید',
                'data' => [
                    'reservation_token' => $reservationToken,
                    'expires_at' => $expiresAt,
                    'slot_id' => $slotId,
                    'slot_date' => $slot->slot_date,
                    'start_time' => $slot->start_time,
                    'end_time' => $slot->end_time,
                    'payment' => [
                        'order_id' => $orderId,
                        'payment_id' => $paymentId,
                        'amount' => $amount,
                        'gateway' => 'zarinpal',
                        'authority' => $authority,
                        'payment_url' => $paymentUrl, // لینک پرداخت
                    ]
                ]
            ], 200);

        } catch (\Exception $e) {
            \Log::error('Slot reservation with payment failed', [
                'slot_id' => $slotId,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'خطا در رزرو اسلات: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * تایید نهایی رزرو + تکمیل پرداخت
     */
    public function confirmReservation(Request $request)
    {
        // اعتبارسنجی ورودی
        $validated = $request->validate([
            'reservation_token' => 'required|string',
            'authority' => 'required|string', // از query string callback زرین‌پال
            'status' => 'required|string', // OK یا NOK
        ]);

        $reservationToken = $validated['reservation_token'];
        $authority = $validated['authority'];
        $status = $validated['status']; // OK = موفق، NOK = ناموفق
        $userId = $request->user()->id;

        // کلید Redis برای بررسی توکن کاربر
        $userReservationKey = "user:reservation:{$userId}:{$reservationToken}";

        // بررسی وجود رزرو
        if (!Redis::exists($userReservationKey)) {
            return response()->json([
                'success' => false,
                'message' => 'رزرو منقضی شده یا نامعتبر است'
            ], 404);
        }

        // دریافت اطلاعات رزرو از Redis
        $userReservationData = json_decode(Redis::get($userReservationKey), true);
        $slotId = $userReservationData['slot_id'];
        $paymentId = $userReservationData['payment_id'];
        $orderId = $userReservationData['order_id'];

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

        // بررسی وضعیت پرداخت از callback
        if ($status !== 'OK') {
            // پرداخت ناموفق

            // به‌روزرسانی وضعیت پرداخت
            \DB::table('payments')
                ->where('id', $paymentId)
                ->update([
                    'status' => 3, // ناموفق
                    'updated_at' => now(),
                ]);

            // به‌روزرسانی وضعیت سفارش
            \DB::table('orders')
                ->where('id', $orderId)
                ->update([
                    'status' => 3, // لغو شده
                    'updated_at' => now(),
                ]);

            // حذف رزرو از Redis
            Redis::del($reservationKey);
            Redis::del($userReservationKey);

            // ثبت لاگ callback ناموفق
            \DB::table('payment_gateway_logs')->insert([
                'payment_id' => $paymentId,
                'gateway' => 'zarinpal',
                'step' => 'callback',
                'request_data' => json_encode([
                    'authority' => $authority,
                    'status' => $status,
                ]),
                'response_data' => json_encode([
                    'result' => 'failed',
                ]),
                'ip_address' => request()->ip(),
                'created_at' => now(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'پرداخت ناموفق بود. رزرو لغو شد'
            ], 400);
        }

        try {
            // ═══════════════════════════════════════════════════════
            // شبیه‌سازی verify زرین‌پال
            // ═══════════════════════════════════════════════════════

            // در پروداکشن باید به API verify زرین‌پال درخواست داده شود
            $refId = 'REF' . time() . rand(1000, 9999); // شبیه‌سازی RefID

            // ثبت لاگ verify موفق
            \DB::table('payment_gateway_logs')->insert([
                'payment_id' => $paymentId,
                'gateway' => 'zarinpal',
                'step' => 'verify',
                'request_data' => json_encode([
                    'merchant_id' => 'XXXXXXXX-XXXX-XXXX-XXXX-XXXXXXXXXXXX',
                    'authority' => $authority,
                    'amount' => $reservationData['amount'],
                ]),
                'response_data' => json_encode([
                    'status' => 100,
                    'ref_id' => $refId,
                    'card_pan' => '123456******1234',
                    'card_hash' => 'ABCDEF1234567890ABCDEF1234567890ABCDEF1234567890ABCDEF1234567890',
                ]),
                'ip_address' => request()->ip(),
                'created_at' => now(),
            ]);

            // ═══════════════════════════════════════════════════════
            // تکمیل پرداخت با FinancialService
            // ═══════════════════════════════════════════════════════

            $paymentCompleted = $this->financialService->completePayment(
                paymentId: $paymentId,
                authority: $authority,
                refId: $refId,
                providerId: $reservationData['doctor_id']
            );

            if (!$paymentCompleted) {
                throw new \Exception('Payment completion failed (possible duplicate)');
            }

            // ═══════════════════════════════════════════════════════
            // تایید نهایی رزرو در دیتابیس
            // ═══════════════════════════════════════════════════════

            $slot = AppointmentSlot::find($slotId);

            if (!$slot) {
                throw new \Exception('Slot not found');
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
                'ai_session_token' => $reservationData['session_id'] ?? null,
                 'order_id'=>$orderId,
                'status' => 'booked',
                'patient_id' => $userId,
                'booking_time' => Carbon::now(),
                'reserved_until' => null,
            ]);

            // حذف کلیدهای موقت از Redis
            Redis::del($reservationKey);
            Redis::del($userReservationKey);

            return response()->json([
                'success' => true,
                'message' => 'رزرو با موفقیت تایید و پرداخت تکمیل شد',
                'data' => [
                    'slot_id' => $slotId,
                    'slot_date' => $slot->slot_date,
                    'start_time' => $slot->start_time,
                    'end_time' => $slot->end_time,
                    'booking_time' => $slot->booking_time->toDateTimeString(),
                    'payment' => [
                        'order_id' => $orderId,
                        'payment_id' => $paymentId,
                        'ref_id' => $refId,
                        'amount' => $reservationData['amount'],
                        'status' => 'completed',
                    ]
                ]
            ], 200);

        } catch (\Exception $e) {
            \Log::error('Reservation confirmation failed', [
                'slot_id' => $slotId,
                'payment_id' => $paymentId,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'خطا در تایید رزرو: ' . $e->getMessage()
            ], 500);
        }
    }
}
