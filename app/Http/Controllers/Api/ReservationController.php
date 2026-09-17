<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FinancialService;
use App\Services\Payment\OrderService;
use App\Services\Payment\PaymentService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Carbon\Carbon;
use App\Models\AppointmentSlot;
use Illuminate\Validation\ValidationException;


class ReservationController extends Controller
{
    public function __construct(
        private readonly FinancialService $financialService,
        private readonly PaymentService $paymentService,
        private readonly OrderService $orderService
    ) {}
    public function reserveChat(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'doctor_id' => 'required|integer|exists:users,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $userId = $request->user()->id;
        $doctorId = $request->doctor_id;

        // بررسی فعال بودن پزشک
        if (method_exists($this, 'isDoctorActive') && !$this->isDoctorActive($doctorId)) {
            return response()->json([
                'success' => false,
                'message' => 'پزشک مورد نظر در حال حاضر غیرفعال است و امکان رزرو چت وجود ندارد'
            ], 422);
        }

        $amount = 15000;
        $roomName = "مشاوره متنی با دکتر #{$doctorId} - کاربر #{$userId}";

        DB::beginTransaction();
        try {

            // ۱. پیدا کردن اتاق چت بر اساس نام یکتا
            $existingRoom = DB::table('chat_rooms')
                ->where('name', $roomName)
                ->first();

            if ($existingRoom) {
                $roomId = $existingRoom->id;

                // بررسی حضور بیمار در اتاق و وضعیت چت او
                $userParticipant = DB::table('room_participants')
                    ->where('room_id', $roomId)
                    ->where('user_id', $userId)
                    ->first();

                // اگر بیمار در اتاق وجود داشت و وضعیت او 0 نبود، یعنی چت فعال است و نیازی به پرداخت نیست
                if ($userParticipant && $userParticipant->status != 0) {
                    DB::rollBack(); // تراکنش را می‌بندیم چون نیازی به تغییر دیتابیس نبود

                    return response()->json([
                        'success' => true,
                        'message' => 'شما یک مشاوره متنی فعال با این پزشک دارید.',
                        'data' => [
                            'room_id' => $roomId,
                            'is_active' => true // فلگ برای فرانت‌اند که بداند نباید به درگاه برود
                        ]
                    ], 200);
                }

            } else {
                // ساخت اتاق جدید
                $roomId = DB::table('chat_rooms')->insertGetId([
                    'name' => $roomName,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);

                // فقط پزشک را در این مرحله به اتاق اضافه می‌کنیم
                DB::table('room_participants')->insert([
                    [
                        'room_id' => $roomId,
                        'user_id' => $doctorId,
                        'joined_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now()
                    ]
                ]);
            }

            // ۲. ایجاد یا بازیابی سفارش مالی (چون چت فعال نیست یا بیمار هنوز اضافه نشده)
            $orderResult = $this->orderService->createOrReuse(
                userId: $userId,
                reasonId: 3, // 3 = مشاوره متنی (Chat)
                reasonRef: $roomId,
                amount: $amount,
                description: "هزینه مشاوره متنی با دکتر #{$doctorId}"
            );

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'اتاق چت و سفارش با موفقیت ایجاد شد.',
                'data' => [
                    'room_id' => $roomId,
                    'order_id' => $orderResult['order_id'],
                    'amount' => $orderResult['amount'],
                    'is_active' => false // یعنی نیاز به پرداخت دارد
                ]
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'خطا در بررسی/ایجاد اتاق و سفارش چت: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getAppointmentDetail(Request $request, $id)
    {
        try {
            $userId = $request->user()->id;

            // دریافت اطلاعات نوبت به همراه نام دکتر
            $appointment = DB::table('appointment_slots')
                ->join('users as doctor_user', 'appointment_slots.doctor_id', '=', 'doctor_user.id')
                ->where('appointment_slots.id', $id)
                ->where('appointment_slots.patient_id', $userId) // امنیت: فقط نوبت‌های خودش را ببیند
                ->select(
                    'appointment_slots.id',
                    'appointment_slots.slot_date',
                    'appointment_slots.start_time',
                    'appointment_slots.status',
                    'appointment_slots.extra_detail',
                    'doctor_user.name as doctor_name'
                )
                ->first();

            // اگر نوبت پیدا نشد یا متعلق به این کاربر نبود
            if (!$appointment) {
                return response()->json([
                    'success' => false,
                    'message' => 'نوبت مورد نظر یافت نشد یا شما دسترسی به آن را ندارید.'
                ], 404);
            }

            // تبدیل extra_detail از رشته JSON به آرایه (اگر به صورت رشته ذخیره شده باشد)
            $extraDetail = $appointment->extra_detail;
            if (!empty($extraDetail) && is_string($extraDetail)) {
                $extraDetail = json_decode($extraDetail, true);
            }

            // ساختاربندی دیتای خروجی دقیقاً مطابق با اینترفیس DoctorAppointmentDetail در فرانت‌اند
            return response()->json([
                'success' => true,
                'data' => [
                    'id'           => $appointment->id,
                    'slot_date'    => $appointment->slot_date,
                    'start_time'   => $appointment->start_time,
                    'status'       => $appointment->status,
                    'doctor_name'  => $appointment->doctor_name,
                    'extra_detail' => $extraDetail
                ]
            ], 200);

        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('[Appointment Detail] Fetch Error', [
                'appointment_id' => $id,
                'user_id'        => $request->user()->id ?? null,
                'error'          => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'خطا در دریافت اطلاعات نوبت. لطفاً مجدداً تلاش کنید.'
            ], 500);
        }
    }
    public function getActiveAppointment(Request $request)
    {
        $userId = $request->user()->id;

        // پیدا کردن نزدیک‌ترین نوبت بیمار (از امروز به بعد)
        $slot = DB::table('appointment_slots')
            ->join('users as doctor_user', 'appointment_slots.doctor_id', '=', 'doctor_user.id')
            ->join('doctor_info', 'doctor_user.id', '=', 'doctor_info.user_id')
            ->join('specialties', 'doctor_info.specialty_id', '=', 'specialties.id')
            ->where('appointment_slots.patient_id', $userId)
            ->where('appointment_slots.slot_date', '>=', now()->format('Y-m-d'))
            ->whereIn('appointment_slots.status', ['available']) // موقت (منتظر پرداخت) یا قطعی
            ->orderBy('appointment_slots.slot_date', 'asc')
            ->orderBy('appointment_slots.start_time', 'asc')
            ->select(
                'appointment_slots.id',
                'appointment_slots.slot_date',
                'appointment_slots.start_time',
                'appointment_slots.status',
                'doctor_user.name as doctor_name',
                'doctor_info.image_url as doctor_image',
                'specialties.name as specialty_name'
            )
            ->first();

        if (!$slot) {
            return response()->json(['success' => true, 'data' => null]);
        }

        $isActive = false;
        $expiresAt = null;
        $isTemporary = false;

        // بررسی وضعیت نوبت
        if ($slot->status === 'available') {
            // نوبتِ available است اما به نام این کاربر خورده، پس چک می‌کنیم در ردیس قفل است یا خیر
            $redisData = Redis::get("slot:reservation:{$slot->id}");
            if ($redisData && $redisData !== 'locking...') {
                $decoded = json_decode($redisData, true);
                // اطمینان از اینکه رزرو موقت هنوز متعلق به همین کاربر است
                if (isset($decoded['user_id']) && $decoded['user_id'] == $userId) {
                    $isActive = true;
                    $isTemporary = true;
                    $expiresAt = \Carbon\Carbon::parse($decoded['reserved_at'])->addMinutes(15)->toDateTimeString();
                }
            }
        } else if ($slot->status === 'booked') {
            // نوبت قطعی پرداخت شده
            $isActive = true;
        }

        // اگر نوبت موقت در ردیس منقضی شده بود، آن را برنگردان
        if (!$isActive) {
            return response()->json(['success' => true, 'data' => null]);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $slot->id,
                'doctor_name' => $slot->doctor_name,
                'doctor_image' => $slot->doctor_image ? asset('storage/' . $slot->doctor_image) : null,
                'specialty_name' => $slot->specialty_name,
                'date' => $slot->slot_date,
                'time' => $slot->start_time,
                'is_temporary' => $isTemporary,
                'expires_at' => $expiresAt,
                'status' => $slot->status,
            ]
        ]);
    }
    public function cancelTempReservation(Request $request)
    {
        $request->validate(['slot_id' => 'required|integer']);
        $userId = $request->user()->id;
        $slotId = $request->slot_id;

        $reservationKey = "slot:reservation:{$slotId}";
        $redisData = Redis::get($reservationKey);

        if ($redisData) {
            $data = json_decode($redisData, true);

            // بررسی اینکه آیا رزرو موقت واقعا متعلق به همین کاربر است
            if (isset($data['user_id']) && $data['user_id'] == $userId) {
                $orderId = $data['order_id'] ?? null;

                DB::beginTransaction();
                try {
                    // ۱. آزاد کردن اسلات در دیتابیس
                    AppointmentSlot::query()->where('id', $slotId)->update([
                        'patient_id'   => null,
                        'booking_time' => null,
                        'extra_detail' => null,
                    ]);

                    // ۲. بررسی و لغو سفارش و تراکنش‌های بانکی
                    if ($orderId) {
                        // الف) لغو سفارش (Order) - فرض بر این است که وضعیت 1 یعنی در انتظار پرداخت و 3 یعنی لغو شده
                        DB::table('orders')
                            ->where('id', $orderId)
                            ->where('status', 1)
                            ->update([
                                'status' => 3, // تغییر وضعیت به لغو شده
                                'updated_at' => now(),
                            ]);

                        // ب) لغو تراکنش درگاه پرداخت (Payments یا Transactions)
                        // نکته: نام این جدول بسته به ساختار دیتابیس شما ممکن است payments یا transactions باشد.
                        // وضعیت 0 یا 1 معمولاً یعنی در انتظار پرداخت، که ما آن را به وضعیت لغو/خطا (مثلاً 2 یا canceled) تغییر می‌دهیم.
                        DB::table('payments') // اگر نام جدول شما چیز دیگری است، اینجا را تغییر دهید
                        ->where('order_id', $orderId)
                            ->whereIn('status', [0, 1, 'pending', 'initiated']) // پیدا کردن تراکنش‌های باز
                            ->update([
                                'status' => 3, // یا 'canceled' / 'failed' بسته به منطق دیتابیس شما
                                'updated_at' => now(),
                                'last_error' => 'درخواست توسط بیمار لغو شد',

                            ]);
                    }

                    // ۳. پاک کردن قفل ردیس
                    Redis::del($reservationKey);

                    DB::commit();

                    return response()->json([
                        'success' => true,
                        'message' => 'رزرو قبلی و سفارشات بانکی مرتبط با موفقیت لغو شدند.'
                    ]);

                } catch (\Exception $e) {
                    DB::rollBack();

                    \Illuminate\Support\Facades\Log::error('[Cancel Temp Reservation] Error', [
                        'slot_id' => $slotId,
                        'user_id' => $userId,
                        'error'   => $e->getMessage()
                    ]);

                    return response()->json([
                        'success' => false,
                        'message' => 'خطا در لغو رزرو. لطفاً مجدداً تلاش کنید.'
                    ], 500);
                }
            }
        }

        return response()->json([
            'success' => false,
            'message' => 'رزرو موقتی یافت نشد یا متعلق به شما نیست.'
        ], 404);
    }
    public function createOrder(Request $request)
    {
        // ۱. اعتبارسنجی ورودی‌ها
        $validated = $request->validate([
            'slot_id'                     => 'required|integer|exists:appointment_slots,id',
            'session_id'                  => 'nullable|string',
            'is_for_other'                => 'required|boolean',
            // اگر نوبت برای دیگری باشد، اطلاعات زیر الزامی هستند:
            'other_patient'               => 'required_if:is_for_other,true|array',
            'other_patient.last_name'     => 'required_if:is_for_other,true|string|max:100',
            'other_patient.national_code' => ['required_if:is_for_other,true', 'string', 'regex:/^[0-9]{10}$/'],
            'other_patient.phone'         => ['required_if:is_for_other,true', 'string', 'regex:/^09[0-9]{9}$/'],
        ], [
            'other_patient.last_name.required_if'     => 'نام و نام خانوادگی بیمار الزامی است.',
            'other_patient.national_code.required_if' => 'کد ملی معتبر ۱۰ رقمی بیمار الزامی است.',
            'other_patient.phone.required_if'         => 'شماره موبایل معتبر بیمار الزامی است.',
        ]);

        $slotId     = $validated['slot_id'];
        $sessionId  = $validated['session_id'] ?? null;
        $isForOther = (bool) $validated['is_for_other'];
        $userId     = $request->user()->id;

        // ۱.۵. بررسی اینکه آیا کاربر نوبت موقتِ در حال انتظاری از قبل دارد یا خیر
        $existingTempSlots = AppointmentSlot::query()
            ->where('patient_id', $userId)
            ->where('status', 'available')
            ->pluck('id');

        // در ردیس چک می‌کنیم که آیا زمان رزروهای قبلی هنوز باقی است؟
        foreach ($existingTempSlots as $tempSlotId) {
            $redisData = Redis::get("slot:reservation:{$tempSlotId}");

            // اگر دیتا در ردیس بود و در مرحله locking اولیه نبود، یعنی رزرو قطعی موقت دارد
            if ($redisData && $redisData !== 'locking...') {
                $decoded = json_decode($redisData, true);

                if (isset($decoded['user_id']) && $decoded['user_id'] == $userId) {
                    return response()->json([
                        'success' => false,
                        'message' => 'شما در حال حاضر یک نوبت در انتظار پرداخت دارید. لطفاً ابتدا آن را تکمیل یا لغو کنید.'
                    ], 409); // Conflict
                }
            }
        }

        // ۲. بررسی وجود و وضعیت اسلات جدید در دیتابیس
        $slot = AppointmentSlot::query()->find($slotId);

        if (!$slot) {
            return response()->json(['success' => false, 'message' => 'اسلات نوبت یافت نشد.'], 404);
        }

        if (method_exists($this, 'isDoctorActive') && !$this->isDoctorActive($slot->doctor_id)) {
            return response()->json(['success' => false, 'message' => 'پزشک مورد نظر در دسترس نیست.'], 422);
        }

        if ($slot->status !== 'available') {
            return response()->json(['success' => false, 'message' => 'این اسلات نوبت قبلاً رزرو شده یا در دسترس نیست.'], 409);
        }

        // ۳. ایجاد قفل اتمیک در Redis (جلوگیری قطعی از Race Condition)
        $reservationKey = "slot:reservation:{$slotId}";

        $isLockAcquired = Redis::set($reservationKey, 'locking...', 'EX', 120, 'NX');

        if (!$isLockAcquired) {
            return response()->json([
                'success' => false,
                'message' => 'این نوبت در حال حاضر توسط شخص دیگری در حال رزرو است. لطفاً چند دقیقه دیگر بررسی کنید.'
            ], 409);
        }

        DB::beginTransaction();
        try {
            // محاسبه مبلغ
            $amount =  15000;

            // ۴. آماده‌سازی اطلاعات اضافی (extra_detail)
            $extraDetail = [
                'is_for_other'  => $isForOther,
                'registered_by' => $userId,
                'created_at'    => now()->toDateTimeString(),
            ];

            if ($isForOther) {
                $extraDetail['patient'] = [
                    'last_name'     => trim($validated['other_patient']['last_name']),
                    'national_code' => trim($validated['other_patient']['national_code']),
                    'phone'         => trim($validated['other_patient']['phone']),
                ];
            } else {
                $extraDetail['patient'] = [
                    'last_name'     => $request->user()->name ?? $request->user()->last_name ?? '',
                    'national_code' => $request->user()->national_code ?? '',
                    'phone'         => $request->user()->phone ?? '',
                ];
            }

            // ۴.۵. ابطال سفارش‌های معلق و قدیمی برای همین نوبت
            // اگر کاربر قبلاً برای این نوبت سفارشی ساخته که پرداخت نشده (status = 1)، آن را منقضی (status = 3) می‌کنیم
            DB::table('orders')
                ->where('user_id', $userId)
                ->where('reason_id', 1) // 1 = نوبت
                ->where('reason_ref', $slotId)
                ->where('status', 1) // وضعیت پرداخت‌نشده / معلق
                ->update([
                    'status' => 3, // 3 = لغو شده / منقضی شده
                    'updated_at' => now(),
                ]);

            // ۵. ایجاد رکورد Order در سیستم مالی
            $orderId = $this->financialService->createOrder(
                userId:      $userId,
                reasonId:    1, // 1 = رزرو نوبت (Appointment)
                reasonRef:   $slotId,
                amount:      $amount,
                description: "سفارش رزرو نوبت تاریخ {$slot->slot_date} ساعت {$slot->start_time}"
            );

            // ۶. به‌روزرسانی نوبت با کاربر رزروکننده، ستون extra_detail و تغییر وضعیت
            AppointmentSlot::query()->where('id', $slotId)->update([
                'ai_session_token'=>$sessionId,
                'patient_id'   => $userId,
                'extra_detail' => json_encode($extraDetail, JSON_UNESCAPED_UNICODE),
                'updated_at'   => now(),
                'booking_time' => now(),
            ]);

            // ۷. ثبت اطلاعات تکمیلی در قفل Redis
            $reservationToken = Str::uuid()->toString();
            $reservationData = [
                'token'        => $reservationToken,
                'order_id'     => $orderId,
                'user_id'      => $userId,
                'slot_id'      => $slotId,
                'doctor_id'    => $slot->doctor_id,
                'slot_date'    => $slot->slot_date,
                'start_time'   => $slot->start_time,
                'end_time'     => $slot->end_time,
                'amount'       => $amount,
                'is_for_other' => $isForOther,
                'session_id'   => $sessionId,
                'reserved_at'  => now()->toDateTimeString(),
            ];

            // چون قفل متعلق به همین پردازش است، حالا دیتا را روی همان کلید با انقضای ۱۵ دقیقه‌ای می‌نویسیم
            Redis::setex($reservationKey, 120, json_encode($reservationData));

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'سفارش نوبت با موفقیت ایجاد شد و نوبت موقتاً برای شما رزرو گردید.',
                'data'    => [
                    'order_id'          => $orderId,
                    'slot_id'           => $slotId,
                    'amount'            => $amount,
                    'reservation_token' => $reservationToken,
                    'expires_at'        => \Carbon\Carbon::now()->timezone('Asia/Tehran')->addMinutes(15)->toDateTimeString(),
                    'patient_type'      => $isForOther ? 'other' : 'self',
                ]
            ], 201);

        } catch (\Throwable $e) {
            DB::rollBack();

            // در صورت بروز خطا در دیتابیس، قفلی که در ابتدا گرفتیم را آزاد می‌کنیم تا اسلات قفل نماند
            Redis::del($reservationKey);

            Log::error('[Appointment Order] Failed to create order', [
                'slot_id' => $slotId,
                'user_id' => $userId,
                'error'   => $e->getMessage(),
                'trace'   => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'خطا در ثبت سفارش نوبت: ' . $e->getMessage()
            ], 500);
        }
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
     * رزرو موقت اسلات (۱۵ دقیقه) + ایجاد سفارش و دریافت توکن از درگاه سامان
     */
    public function reserveWithSaman(Request $request)
    {
        // ۱. اعتبارسنجی ورودی‌ها
        $validated = $request->validate([
            'slot_id'    => 'required|integer|exists:appointment_slots,id',
            'session_id' => 'nullable|string',
        ]);

        $slotId = $validated['slot_id'];
        $sessionId = $validated['session_id'] ?? null;
        $userId = $request->user()->id;

        // ۲. بررسی وجود و وضعیت اسلات و پزشک
        $slot = AppointmentSlot::find($slotId);

        if (!$slot) {
            return response()->json(['success' => false, 'message' => 'اسلات مورد نظر یافت نشد'], 404);
        }

        if (!$this->isDoctorActive($slot->doctor_id)) {
            return response()->json(['success' => false, 'message' => 'پزشک مورد نظر غیرفعال است'], 422);
        }

        if ($slot->status !== 'available') {
            return response()->json(['success' => false, 'message' => 'این اسلات در دسترس نیست'], 409);
        }

        // ۳. بررسی Redis برای جلوگیری از رزرو همزمان (Race Condition)
        $reservationKey = "slot:reservation:{$slotId}";
        if (Redis::exists($reservationKey)) {
            return response()->json(['success' => false, 'message' => 'این اسلات در حال حاضر توسط شخص دیگری در حال رزرو است'], 409);
        }

        try {
            $amount = 15000; // پیشنهاد: از $slot->doctor->visit_price یا دیتابیس خوانده شود

            // ۴. ایجاد سفارش (فقط سفارش ساخته می‌شود. پرداخت به PaymentService سپرده می‌شود)
            DB::beginTransaction();
            $orderId = $this->financialService->createOrder(
                userId:      $userId,
                reasonId:    1, // appointment
                reasonRef:   $slotId,
                amount:      $amount,
                description: "رزرو نوبت پزشک در تاریخ {$slot->slot_date} ساعت {$slot->start_time}"
            );
            AppointmentSlot::query()->where('id',$slotId)->update(['patient_id'=>$userId]);
            // کامیت می‌کنیم تا PaymentService بتواند این Order را پیدا کند
            DB::commit();

            // ۵. فراخوانی اورکستراتور پرداخت برای ایجاد رکورد پرداخت و دریافت توکن
//            $callbackUrl = route('payment.callback.saman'); // آدرس روت کال‌بک شما در فایل web.php یا api.php
            $callbackUrl = 'https://mediraai.com/api/pg/call_back';
            $paymentData = $this->paymentService->initiate(
                orderId:     $orderId,
                userId:      $userId,
                callbackUrl: $callbackUrl,
                cellNumber:  $request->user()->phone // اگر درگاه سامان شماره موبایل می‌خواهد
            );

            // مقادیر بازگشتی از متد initiate
            $token= $paymentData['token'];
            $paymentId  = $paymentData['payment_id'];
            $paymentUrl = $paymentData['payment_url'];
            $resNum     = $paymentData['res_num']; // Authority

            // ۶. ثبت رزرو موقت در Redis (قفل کردن اسلات)
            $reservationToken = Str::uuid()->toString();

            $reservationData = [
                'user_id'     => $userId,
                'slot_id'     => $slotId,
                'doctor_id'   => $slot->doctor_id,
                'slot_date'   => $slot->slot_date,
                'start_time'  => $slot->start_time,
                'end_time'    => $slot->end_time,
                'token'       => $reservationToken,
                'order_id'    => $orderId,
                'payment_id'  => $paymentId,
                'authority'   => $resNum,
                'amount'      => $amount,
                'session_id'  => $sessionId,
                'reserved_at' => Carbon::now()->toDateTimeString(),
            ];

            // قفل کردن اسلات برای ۱۵ دقیقه (هم‌گام با زمان انقضای توکن درگاه)
            Redis::setex($reservationKey, 900, json_encode($reservationData));

            $userReservationKey = "user:reservation:{$userId}:{$reservationToken}";
            Redis::setex($userReservationKey, 900, json_encode([
                'slot_id'    => $slotId,
                'payment_id' => $paymentId,
                'order_id'   => $orderId,
            ]));

            // ۷. ارسال پاسخ موفق به فرانت‌اند (سازگار با رابط کاربری React)
            return response()->json([
                'success' => true,
                'message' => 'در حال انتقال به درگاه بانک...',
                'data' => [
                    'reservation_token' => $reservationToken,
                    'expires_at'        => Carbon::now()->addMinutes(15)->toDateTimeString(),
                    'slot_id'           => $slotId,
                    'payment' => [
                        'order_id'    => $orderId,
                        'payment_id'  => $paymentId,
                        'amount'      => $amount,
                        'gateway'     => 'saman',
                        'payment_url' => "https://mediraai.com/pg?token={$token}", // این لینک به طور مستقیم توسط React باز می‌شود
                    ]
                ]
            ], 200);

        } catch (\Exception $e) {
            // در صورت بروز خطا در فرآیند پرداخت، تراکنش دیتابیس‌ها و کش را پاکسازی می‌کنیم
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            Redis::del($reservationKey); // آزادسازی اسلات در صورت خطای درگاه

            Log::error('[Reserve Controller] Saman Gateway Reservation Failed', [
                'slot_id' => $slotId,
                'user_id' => $userId,
                'error'   => $e->getMessage(),
                'trace'   => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'خطا در ارتباط با درگاه بانکی: ' . $e->getMessage()
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
