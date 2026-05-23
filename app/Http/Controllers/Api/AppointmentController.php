<?php
// app/Http/Controllers/AppointmentController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Services\PaymentGateway;


class AppointmentController extends Controller
{


    /**
     * دریافت اسلات‌های خالی یک پزشک
     */
    public function getAvailableSlots(Request $request, int $doctorId)
    {


        $request->validate([
            'from_date' => 'required|date',
            'to_date' => 'required|date|after_or_equal:from_date',
        ]);

        $slots = DB::table('appointment_slots')
            ->where('doctor_id', $doctorId)
            ->where('status', 'available')
            ->whereBetween('slot_date', [$request->from_date, $request->to_date])
            ->orderBy('slot_date')
            ->orderBy('start_time')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $slots,
        ]);
    }
    // app/Http/Controllers/AppointmentController.php

    public function releaseExpiredSlots(Request $request)
    {
        try {
            $now = now();
            $releasedSlots = [];
            $releasedCount = 0;

            DB::transaction(function () use ($now, &$releasedSlots, &$releasedCount) {
                // پیدا کردن و قفل کردن اسلات‌های منقضی شده
                $expiredSlots = DB::table('appointment_slots')
                    ->where('status', 'pending_payment')
                    ->where('reserved_until', '<', $now)
                    ->lockForUpdate()
                    ->get(['id', 'slot_date', 'slot_time', 'doctor_id', 'user_id']);

                if ($expiredSlots->isEmpty()) {
                    return;
                }

                $slotIds = $expiredSlots->pluck('id')->toArray();

                // آزاد کردن اسلات‌ها
                $releasedCount = DB::table('appointment_slots')
                    ->whereIn('id', $slotIds)
                    ->update([
                        'status' => 'available',
                        'user_id' => null,
                        'reserved_until' => null,
                        'updated_at' => $now
                    ]);

                // به‌روزرسانی پرداخت‌ها
                DB::table('payments')
                    ->whereIn('slot_id', $slotIds)
                    ->where('status', 'pending')
                    ->update([
                        'status' => 'expired',
                        'updated_at' => $now
                    ]);

                $releasedSlots = $expiredSlots->toArray();
            });

            return response()->json([
                'success' => true,
                'message' => $releasedCount > 0
                    ? "$releasedCount اسلات آزاد شد"
                    : 'هیچ اسلات منقضی شده‌ای یافت نشد',
                'released_count' => $releasedCount,
                'slots' => $releasedSlots
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'خطا در آزادسازی اسلات‌ها',
                'error' => $e->getMessage()
            ], 500);
        }
    }



    /**
     * مرحله 1: شروع فرایند رزرو و ایجاد پرداخت
     */
    public function initiateBooking(Request $request)
    {
        $request->validate([
            'slot_id' => 'required|integer|exists:appointment_slots,id',
            'gateway' => 'required|in:zarinpal,idpay,zibal',
        ]);

        return DB::transaction(function () use ($request) {
            // قفل اسلات
            $slot = DB::table('appointment_slots')
                ->where('id', $request->slot_id)
                ->lockForUpdate()
                ->first();

            if (!$slot) {
                return response()->json([
                    'success' => false,
                    'message' => 'اسلات یافت نشد.',
                ], 404);
            }

            if ($slot->status !== 'available') {
                return response()->json([
                    'success' => false,
                    'message' => 'این نوبت دیگر در دسترس نیست.',
                ], 409);
            }

            // بررسی advance_booking_days
            $setting = DB::table('doctor_appointment_settings')
                ->where('doctor_id', $slot->doctor_id)
                ->first();

            if ($setting && $setting->advance_booking_days) {
                $maxDate = today()->addDays($setting->advance_booking_days);
                if (Carbon::parse($slot->slot_date)->gt($maxDate)) {
                    return response()->json([
                        'success' => false,
                        'message' => "رزرو بیش از {$setting->advance_booking_days} روز جلوتر امکان‌پذیر نیست.",
                    ], 422);
                }
            }

            // رزرو موقت اسلات (15 دقیقه)
            DB::table('appointment_slots')
                ->where('id', $slot->id)
                ->update([
                    'status' => 'pending_payment',
                    'patient_id' => auth()->id(),
                    'reserved_until' => now()->addMinutes(15),
                ]);

            // ایجاد رکورد پرداخت
            $paymentId = DB::table('payments')->insertGetId([
                'user_id' => auth()->id(),
                'doctor_id' => $slot->doctor_id,
                'slot_id' => $slot->id,
                'amount' => $setting->visit_price ?? 100000,
                'gateway' => $request->gateway,
                'status' => 'pending',
                'expires_at' => now()->addMinutes(15),
                'created_at' => now(),
            ]);

            // ارسال به درگاه
            $gateway = app(PaymentGateway::class);
            $result = $gateway->request(
                amount: $setting->visit_price ?? 100000,
                description: "رزرو نوبت دکتر - کد {$slot->id}",
                callbackUrl: route('payment.callback'),
                mobile: auth()->user()->phone ?? null,
                email: auth()->user()->email ?? null
            );

            if (!$result['success']) {
                // بازگشت اسلات به حالت available
                DB::table('appointment_slots')
                    ->where('id', $slot->id)
                    ->update([
                        'status' => 'available',
                        'patient_id' => null,
                        'reserved_until' => null,
                    ]);

                return response()->json([
                    'success' => false,
                    'message' => 'خطا در اتصال به درگاه پرداخت.',
                ], 500);
            }

            // ذخیره authority
            DB::table('payments')
                ->where('id', $paymentId)
                ->update(['authority' => $result['authority']]);

            return response()->json([
                'success' => true,
                'payment_id' => $paymentId,
                'payment_url' => $result['payment_url'],
                'expires_at' => now()->addMinutes(15)->toIso8601String(),
            ]);
        });
    }

    /**
     * مرحله 2: تایید پرداخت بعد از بازگشت از درگاه
     */
    public function verifyPayment(Request $request)
    {
        $request->validate([
            'authority' => 'required|string',
            'status' => 'required|in:OK,NOK',
        ]);

        if ($request->status === 'NOK') {
            return response()->json([
                'success' => false,
                'message' => 'پرداخت توسط کاربر لغو شد.',
            ], 400);
        }

        return DB::transaction(function () use ($request) {
            $payment = DB::table('payments')
                ->where('authority', $request->authority)
                ->where('user_id', auth()->id())
                ->lockForUpdate()
                ->first();

            if (!$payment) {
                return response()->json([
                    'success' => false,
                    'message' => 'پرداخت یافت نشد.',
                ], 404);
            }

            if ($payment->status === 'paid') {
                return response()->json([
                    'success' => true,
                    'message' => 'این پرداخت قبلاً تایید شده است.',
                    'ref_id' => $payment->ref_id,
                ]);
            }

            // بررسی انقضا
            if ($payment->expires_at && Carbon::parse($payment->expires_at)->lt(now())) {
                $this->releaseSlot($payment->slot_id);
                return response()->json([
                    'success' => false,
                    'message' => 'زمان پرداخت منقضی شده است.',
                ], 410);
            }

            // تایید با درگاه
            $gateway = app(PaymentGateway::class);
            $verify = $gateway->verify($payment->authority, $payment->amount);

            if (!$verify['success']) {
                DB::table('payments')
                    ->where('id', $payment->id)
                    ->update(['status' => 'failed']);

                $this->releaseSlot($payment->slot_id);

                return response()->json([
                    'success' => false,
                    'message' => 'پرداخت تایید نشد: ' . $verify['message'],
                ], 400);
            }

            // پرداخت موفق - نهایی کردن رزرو
            DB::table('payments')
                ->where('id', $payment->id)
                ->update([
                    'status' => 'paid',
                    'ref_id' => $verify['ref_id'],
                    'paid_at' => now(),
                ]);

            DB::table('appointment_slots')
                ->where('id', $payment->slot_id)
                ->update([
                    'status' => 'booked',
                    'booking_time' => now(),
                    'reserved_until' => null,
                ]);

            $slot = DB::table('appointment_slots')
                ->where('id', $payment->slot_id)
                ->first();

            return response()->json([
                'success' => true,
                'message' => 'نوبت با موفقیت رزرو شد.',
                'ref_id' => $verify['ref_id'],
                'appointment' => $slot,
            ]);
        });
    }

    /**
     * Callback از درگاه پرداخت
     */
    public function paymentCallback(Request $request)
    {
        // این متد معمولاً کاربر را به صفحه verify هدایت می‌کند
        $authority = $request->input('Authority');
        $status = $request->input('Status');

        // هدایت به فرانت‌اند با پارامترها
        return redirect()->away(
            config('app.frontend_url') . "/payment/verify?authority={$authority}&status={$status}"
        );
    }

    /**
     * بررسی وضعیت پرداخت
     */
    public function paymentStatus(int $paymentId)
    {
        $payment = DB::table('payments')
            ->where('id', $paymentId)
            ->where('user_id', auth()->id())
            ->first();

        if (!$payment) {
            return response()->json([
                'success' => false,
                'message' => 'پرداخت یافت نشد.',
            ], 404);
        }

        $slot = DB::table('appointment_slots')
            ->where('id', $payment->slot_id)
            ->first();

        $isExpired = $payment->expires_at && Carbon::parse($payment->expires_at)->lt(now());

        return response()->json([
            'success' => true,
            'data' => [
                'status' => $payment->status,
                'amount' => $payment->amount,
                'ref_id' => $payment->ref_id,
                'paid_at' => $payment->paid_at,
                'is_expired' => $isExpired,
                'slot' => $slot,
            ],
        ]);
    }

    /**
     * نمایش نوبت‌های بیمار
     */
    public function myAppointments(Request $request)
    {
        $status = $request->input('status'); // booked, cancelled, etc.

        $query = DB::table('appointment_slots')
            ->where('patient_id', auth()->id())
            ->orderBy('slot_date', 'desc')
            ->orderBy('start_time', 'desc');

        if ($status) {
            $query->where('status', $status);
        }

        $appointments = $query->get();

        return response()->json([
            'success' => true,
            'data' => $appointments,
        ]);
    }

    /**
     * لغو نوبت توسط بیمار
     */
    public function cancelSlot(int $slotId)
    {
        return DB::transaction(function () use ($slotId) {
            $slot = DB::table('appointment_slots')
                ->where('id', $slotId)
                ->where('patient_id', auth()->id())
                ->lockForUpdate()
                ->first();

            if (!$slot) {
                return response()->json([
                    'success' => false,
                    'message' => 'نوبت یافت نشد یا متعلق به شما نیست.',
                ], 404);
            }

            if ($slot->status !== 'booked') {
                return response()->json([
                    'success' => false,
                    'message' => 'فقط نوبت‌های رزرو شده قابل لغو هستند.',
                ], 422);
            }

            // بررسی زمان (مثلاً حداقل 24 ساعت قبل)
            $slotDateTime = Carbon::parse($slot->slot_date . ' ' . $slot->start_time);
            if ($slotDateTime->lt(now()->addHours(24))) {
                return response()->json([
                    'success' => false,
                    'message' => 'لغو نوبت کمتر از 24 ساعت قبل امکان‌پذیر نیست.',
                ], 422);
            }

            // لغو نوبت
            DB::table('appointment_slots')
                ->where('id', $slotId)
                ->update([
                    'status' => 'available',
                    'patient_id' => null,
                    'booking_time' => null,
                ]);

            // به‌روزرسانی وضعیت پرداخت به refunded
            DB::table('payments')
                ->where('slot_id', $slotId)
                ->where('status', 'paid')
                ->update(['status' => 'refunded']);

            return response()->json([
                'success' => true,
                'message' => 'نوبت با موفقیت لغو شد.',
            ]);
        });
    }

    /**
     * تولید خودکار اسلات‌ها توسط پزشک
     */
    public function generateSlots(Request $request)
    {
        $request->validate([
            'doctor_id' => 'required|integer|exists:doctors,id',
            'from_date' => 'required|date',
            'to_date' => 'required|date|after_or_equal:from_date',
        ]);

        $doctorId = $request->doctor_id;
        $fromDate = Carbon::parse($request->from_date);
        $toDate = Carbon::parse($request->to_date);

        // دریافت تنظیمات پزشک
        $settings = DB::table('doctor_appointment_settings')
            ->where('doctor_id', $doctorId)
            ->first();

        if (!$settings) {
            return response()->json([
                'success' => false,
                'message' => 'تنظیمات نوبت‌دهی برای این پزشک یافت نشد.',
            ], 404);
        }

        // دریافت روزهای کاری
        $workingDays = DB::table('doctor_working_days')
            ->where('doctor_id', $doctorId)
            ->where('is_active', true)
            ->pluck('day_of_week')
            ->toArray();

        if (empty($workingDays)) {
            return response()->json([
                'success' => false,
                'message' => 'روز کاری فعالی برای این پزشک تعریف نشده است.',
            ], 404);
        }

        // دریافت تاریخ‌های استثنا
        $exceptions = DB::table('doctor_exceptions')
            ->where('doctor_id', $doctorId)
            ->whereBetween('exception_date', [$fromDate, $toDate])
            ->pluck('exception_date')
            ->toArray();

        $generatedCount = 0;
        $currentDate = $fromDate->copy();

        while ($currentDate->lte($toDate)) {
            // بررسی روز کاری
            $dayOfWeek = $currentDate->dayOfWeek; // 0=یکشنبه, 6=شنبه

            if (!in_array($dayOfWeek, $workingDays)) {
                $currentDate->addDay();
                continue;
            }

            // بررسی استثنا
            if (in_array($currentDate->toDateString(), $exceptions)) {
                $currentDate->addDay();
                continue;
            }

            // دریافت ساعات کاری این روز
            $workingHours = DB::table('doctor_working_hours')
                ->where('doctor_id', $doctorId)
                ->where('day_of_week', $dayOfWeek)
                ->get();

            foreach ($workingHours as $hours) {
                $startTime = Carbon::parse($hours->start_time);
                $endTime = Carbon::parse($hours->end_time);

                while ($startTime->lt($endTime)) {
                    $slotEnd = $startTime->copy()->addMinutes($settings->duration_minutes);

                    if ($slotEnd->gt($endTime)) {
                        break;
                    }

                    // بررسی وجود اسلات (جلوگیری از تکراری)
                    $exists = DB::table('appointment_slots')
                        ->where('doctor_id', $doctorId)
                        ->where('slot_date', $currentDate->toDateString())
                        ->where('start_time', $startTime->format('H:i:s'))
                        ->exists();

                    if (!$exists) {
                        DB::table('appointment_slots')->insert([
                            'doctor_id' => $doctorId,
                            'slot_date' => $currentDate->toDateString(),
                            'start_time' => $startTime->format('H:i:s'),
                            'end_time' => $slotEnd->format('H:i:s'),
                            'status' => 'available',
                            'created_at' => now(),
                        ]);
                        $generatedCount++;
                    }

                    // اضافه کردن buffer
                    $startTime->addMinutes($settings->duration_minutes + ($settings->buffer_minutes ?? 0));
                }
            }

            $currentDate->addDay();
        }

        return response()->json([
            'success' => true,
            'message' => "{$generatedCount} اسلات جدید ایجاد شد.",
            'generated_count' => $generatedCount,
        ]);
    }

    /**
     * نمایش اسلات‌های پزشک با فیلتر
     */
    public function doctorSlots(Request $request)
    {
        $request->validate([
            'doctor_id' => 'required|integer|exists:doctors,id',
            'from_date' => 'nullable|date',
            'to_date' => 'nullable|date',
            'status' => 'nullable|in:available,booked,blocked,pending_payment',
        ]);

        $query = DB::table('appointment_slots')
            ->where('doctor_id', $request->doctor_id)
            ->orderBy('slot_date')
            ->orderBy('start_time');

        if ($request->from_date) {
            $query->where('slot_date', '>=', $request->from_date);
        }

        if ($request->to_date) {
            $query->where('slot_date', '<=', $request->to_date);
        }

        if ($request->status) {
            $query->where('status', $request->status);
        }

        $slots = $query->get();

        return response()->json([
            'success' => true,
            'data' => $slots,
        ]);
    }

    /**
     * مسدود کردن اسلات توسط پزشک
     */
    public function blockSlot(int $slotId)
    {
        $slot = DB::table('appointment_slots')
            ->where('id', $slotId)
            ->first();

        if (!$slot) {
            return response()->json([
                'success' => false,
                'message' => 'اسلات یافت نشد.',
            ], 404);
        }

        if ($slot->status === 'booked') {
            return response()->json([
                'success' => false,
                'message' => 'نمی‌توان اسلات رزرو شده را مسدود کرد.',
            ], 422);
        }

        DB::table('appointment_slots')
            ->where('id', $slotId)
            ->update(['status' => 'blocked']);

        return response()->json([
            'success' => true,
            'message' => 'اسلات با موفقیت مسدود شد.',
        ]);
    }

    /**
     * آزاد کردن اسلات در صورت عدم پرداخت
     */
    private function releaseSlot(int $slotId)
    {
        DB::table('appointment_slots')
            ->where('id', $slotId)
            ->where('status', 'pending_payment')
            ->update([
                'status' => 'available',
                'patient_id' => null,
                'reserved_until' => null,
            ]);
    }
}
