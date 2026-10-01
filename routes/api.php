<?php

use App\Http\Api\Controllers\Auth\AuthController;
use App\Http\Controllers\Api\DiagnosisController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\HealthInsightController;
use App\Http\Controllers\Api\MealPlanController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PaymentGatewayController;
use App\Services\Payment\OrderService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\ReservationController;
use App\Http\Controllers\Api\MedicalRequestController;
use App\Http\Controllers\Api\UserPharmacyRequestController;
use App\Http\Controllers\Api\PeriodShareController;
use App\Http\Controllers\Api\PeriodTrackerController;
use App\Http\Controllers\Api\Admin\AppointmentManagementController;
use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Api\MedicalServiceProviderController;
use App\Http\Controllers\Api\Admin\AdminServiceController;
use Kavenegar\KavenegarApi;
use Illuminate\Support\Facades\Redis;





Route::get('/test/lab-time-check', function () {
    $labId = 64;
    $targetShiftId = 1;

    // ۱. دریافت تنظیمات آزمایشگاه از ردیس
    $redisKey = "lab_rules_config:{$labId}";
    $rulesJson = Redis::get($redisKey);

    if (!$rulesJson) {
        return response()->json([
            'success' => false,
            'message' => "تنظیمات آزمایشگاه {$labId} در ردیس یافت نشد."
        ], 404);
    }

    $rules = json_decode($rulesJson, true);

    // ۲. دریافت زمان فعلی سرور
    $now = Carbon::now();
    $todayDate = $now->toDateString(); // فقط تاریخ امروز مثلا 2026-09-26

    $shiftAnalysis = [];

    // ۳. تحلیل وضعیت تمامی شیفت‌ها نسبت به زمان فعلی
    foreach ($rules['shifts'] ?? [] as $shiftId => $shiftConfig) {
        // اگر شیفت غیرفعال باشد
        if (empty($shiftConfig['isActive'])) {
            $shiftAnalysis[$shiftId] = [
                'status' => 'inactive',
                'message' => 'این شیفت غیرفعال است.'
            ];
            continue;
        }

        // ترکیب تاریخ امروز با ساعت شروع و پایان شیفت
        $shiftStart = Carbon::parse($todayDate . ' ' . $shiftConfig['start']);
        $shiftEnd = Carbon::parse($todayDate . ' ' . $shiftConfig['end']);

        // زمان کات‌آف: ۱ ساعت مانده به پایان شیفت
        $cutoffTime = $shiftEnd->copy()->subHour();

        $shiftAnalysis[$shiftId] = [
            'config' => [
                'start_time' => $shiftConfig['start'],
                'end_time' => $shiftConfig['end'],
                'capacity' => $shiftConfig['capacity']
            ],
            'timestamps' => [
                'shift_start' => $shiftStart->toDateTimeString(),
                'shift_end' => $shiftEnd->toDateTimeString(),
                'cutoff_time' => $cutoffTime->toDateTimeString(),
            ],
            'analysis' => [
                'has_started' => $now->greaterThanOrEqualTo($shiftStart),
                'is_currently_active' => $now->between($shiftStart, $shiftEnd),
                'is_cutoff_passed' => $now->greaterThanOrEqualTo($cutoffTime), // آیا از زمان مجاز (۱ ساعت به پایان) گذشته؟
                'is_completely_passed' => $now->greaterThanOrEqualTo($shiftEnd), // آیا شیفت کلا تمام شده؟

                // پیام وضعیت نهایی برای درک بهتر
                'status_message' => $now->greaterThanOrEqualTo($shiftEnd) ? 'شیفت کاملا پایان یافته است.' :
                    ($now->greaterThanOrEqualTo($cutoffTime) ? 'شیفت هنوز تمام نشده اما مهلت ثبت‌نام (۱ ساعت آخر) گذشته است.' :
                        ($now->greaterThanOrEqualTo($shiftStart) ? 'شیفت در جریان است و امکان ثبت‌نام وجود دارد.' :
                            'شیفت هنوز شروع نشده است.'))
            ]
        ];
    }

    // ۴. برگرداندن خروجی نهایی
    return response()->json([
        'success' => true,
        'server_current_time' => $now->toDateTimeString(),
        'lab_id' => $labId,
        'target_shift_1_status' => $shiftAnalysis[$targetShiftId] ?? 'تنظیمات شیفت ۱ یافت نشد',
        'all_shifts_analysis' => $shiftAnalysis,
        'raw_settings' => $rules // کل تنظیمات خام ردیس
    ], 200, [], JSON_UNESCAPED_UNICODE);
});
Route::get('/health',function (){
    return response()->json(["status"=>"success","data"=>['date'=>now()]]);
});
 Route::get('pg/{token}',function ($token){
     return redirect("https://sep.shaparak.ir/OnlinePG/SendToken?token={$token}");
 });
// ─── Endpoints نیاز به auth دارند ─────────────────────────────────
Route::middleware('auth:sanctum')->group(function () {
//    Route::post('/payments/order',    [PaymentController::class, 'createOrder']);
    Route::post('/payments/initiate', [PaymentController::class, 'initiate']);
});

Route::prefix('test/payment')->group(function () {

    /**
     * STEP 1 — ایجاد سفارش
     * GET /test/payment/create-order
     *
     * سفارش برای user_id=78 ، reason_id=2 (شارژ کیف پول)، wallet_id=19
     */
    Route::get('/create-order', function (OrderService $orderService) {
        $result = $orderService->createOrReuse(
            userId: 78,
            reasonId: 2,          // payment_reason = شارژ کیف پول
            reasonRef: 19,         // wallet_id = 19
            amount: 15000,     // مبلغ آزمایشی به ریال
            description: 'تست شارژ کیف پول #19 برای کاربر #78',
        );

        return response()->json([
            'step' => '1 - createOrder',
            'order_id' => $result['order_id'],
            'amount' => $result['amount'],
            'is_new' => $result['is_new'],
        ]);
    });


});

// ─── کالبک درگاه — بدون auth ──────────────────────────────────────
// باید throttle محدود داشته باشد ولی بدون Sanctum
Route::match(['get', 'post'], 'pg/call_back', [PaymentController::class, 'callback'])->name('pg.callback');

Route::middleware(['auth:sanctum', 'user.active'])->prefix('user/medical')->group(function () {
    // مرحله ۱: دریافت لیست خدمات قابل ارائه
    Route::get('/services', [MedicalRequestController::class, 'getServices']);

    // مرحله ۳: دریافت مراکز درمانی نزدیک/ارائه‌دهنده خدمات انتخابی
    Route::post('/centers', [MedicalRequestController::class, 'getCenters']);

    // ثبت نهایی درخواست
    Route::post('/requests', [MedicalRequestController::class, 'storeRequest']);
});
Route::group(['prefix' => 'doctor'], function () {

    Route::post('/login', [\App\Http\Controllers\Api\Doctor\DoctorAuthController::class, 'login'])->middleware('throttle:3,1');

    Route::middleware(['auth:sanctum', 'user.active', 'role:doctor'])->group(function () {
        Route::post('/pay-subscription-fee', [\App\Http\Controllers\Api\Doctor\DoctorProfileController::class, 'paySubscriptionFee']);
        Route::post('/toggle-status', [\App\Http\Controllers\Api\Doctor\DoctorProfileController::class, 'toggleStatus']);
        Route::put('/profile', [\App\Http\Controllers\Api\Doctor\DoctorProfileController::class, 'updateProfile']);
        Route::get('/dashboard', [\App\Http\Controllers\Api\Doctor\DoctorDashboardController::class, 'index']);
        Route::post('/wallet/charge-mock', [\App\Http\Controllers\Api\Doctor\WalletController::class, 'mockCharge']);
        Route::get('/schedule/calendar-summary', [\App\Http\Controllers\Api\Doctor\AppointmentController::class, 'getCalendarSummary']);
        Route::get('/schedule/slots', [\App\Http\Controllers\Api\Doctor\AppointmentController::class, 'getSlotsByDate']);
        Route::post('/schedule/generate-slots', [\App\Http\Controllers\Api\Doctor\AppointmentController::class, 'generateSlotsForDate']);
        Route::post('/schedule/generate-slots-batch', [\App\Http\Controllers\Api\Doctor\AppointmentController::class, 'generateBatchSlots']);
        Route::get('/schedule/rules', [\App\Http\Controllers\Api\Doctor\AppointmentController::class, 'getScheduleRules']);
        Route::post('/schedule/rules', [\App\Http\Controllers\Api\Doctor\AppointmentController::class, 'saveScheduleRules']);

        Route::patch('/schedule/slots/{slotId}/toggle-status', [\App\Http\Controllers\Api\Doctor\AppointmentController::class, 'toggleSlotStatus']);
        Route::get('/finance', [\App\Http\Controllers\Api\Doctor\DoctorProfileController::class, 'finance'])->name('profile.finance');

        Route::patch('/appointments/{id}/mark-done', [\App\Http\Controllers\Api\Doctor\AppointmentController::class, 'markAppointmentAsDone']);
        Route::get('/appointments/{id}', [\App\Http\Controllers\Api\Doctor\AppointmentController::class, 'getAppointmentDetail']);
        Route::patch('/appointments/{id}/notes', [\App\Http\Controllers\Api\Doctor\AppointmentController::class, 'updateAppointmentNotes']);
        Route::get('/appointments', [\App\Http\Controllers\Api\Doctor\AppointmentController::class, 'getDoctorAppointments']);
        Route::get('/profile', [\App\Http\Controllers\Api\Doctor\DoctorProfileController::class, 'getProfile']);
        Route::get('/my-rooms', [\App\Http\Controllers\Api\Doctor\ChatController::class, 'getMyRooms']);
        Route::get('/chat/{roomId}/messages', [\App\Http\Controllers\Api\Doctor\ChatController::class, 'getRoomMessages']);
        Route::get('/plans', [\App\Http\Controllers\Api\Doctor\DoctorPanelSubscriptionController::class, 'getPlans']);
        Route::get('/my-plan', [\App\Http\Controllers\Api\Doctor\DoctorPanelSubscriptionController::class, 'getMyPlan']);
        Route::post('/plans/subscribe', [\App\Http\Controllers\Api\Doctor\DoctorPanelSubscriptionController::class, 'subscribeToPlan']);
        Route::prefix('keywords')->group(function () {
            Route::delete('/{id}', [\App\Http\Controllers\Api\Doctor\DoctorPanelKeywordController::class, 'deleteKeyword']);

            Route::post('/custom', [\App\Http\Controllers\Api\Doctor\DoctorPanelKeywordController::class, 'addCustomKeyword']);

            // لیست کلمات قابل خرید (با قابلیت جستجو)
            Route::get('/available', [\App\Http\Controllers\Api\Doctor\DoctorPanelKeywordController::class, 'getAvailableKeywords']);

            // لیست کمپین‌های فعال/غیرفعال پزشک
            Route::get('/mine', [\App\Http\Controllers\Api\Doctor\DoctorPanelKeywordController::class, 'getMyKeywords']);

            // خرید/اشتراک در کلمه کلیدی جدید (محافظت شده با محدودکننده درخواست)
            Route::post('/subscribe', [\App\Http\Controllers\Api\Doctor\DoctorPanelKeywordController::class, 'subscribeToKeyword'])
                ->middleware('throttle:10,1');

            // تغییر وضعیت کمپین (فعال/متوقف کردن)
            Route::patch('/{id}/toggle-status', [\App\Http\Controllers\Api\Doctor\DoctorPanelKeywordController::class, 'toggleKeywordStatus']);

            // گزارش ریز تراکنش‌ها و کلیک‌ها
            Route::get('/logs', [\App\Http\Controllers\Api\Doctor\DoctorPanelKeywordController::class, 'getConsumptionLogs']);

            // دیتای نمودار ۳۰ روزه
            Route::get('/chart', [\App\Http\Controllers\Api\Doctor\DoctorPanelKeywordController::class, 'getDailyConsumptionChart']);
        });
    });

});

Route::group(['prefix' => 'admin'],function (){

    Route::post('/login', [\App\Http\Controllers\Api\AdminAuthController::class, 'login'])->middleware('throttle:3,1');
    Route::middleware(['auth:sanctum', 'user.active',\App\Http\Middleware\CheckApiAdmin::class])->group(function () {
        Route::get('/services', [AdminServiceController::class, 'index']);
        Route::post('/services', [AdminServiceController::class, 'store']);
        Route::patch('/services/{id}/status', [AdminServiceController::class, 'updateStatus']);
        Route::delete('/services/{id}', [AdminServiceController::class, 'destroy']);
        Route::post('/users', [\App\Http\Controllers\Api\Admin\UserManagementController::class, 'store']);
              Route::get('users', [\App\Http\Controllers\Api\Admin\UserController::class, 'index']);
        Route::get('users/{id}', [\App\Http\Controllers\Api\Admin\UserController::class, 'show']);
        Route::put('users/{id}', [\App\Http\Controllers\Api\Admin\UserController::class, 'update']);
        Route::delete('users/{id}', [\App\Http\Controllers\Api\Admin\UserController::class, 'destroy']);
        Route::post('users/bulk-status', [\App\Http\Controllers\Api\Admin\UserController::class, 'bulkStatus']);
        Route::post('users/bulk-delete', [\App\Http\Controllers\Api\Admin\UserController::class, 'bulkDelete']);
        Route::get('/profile', [UserController::class, 'getProfile']);
        Route::get('patient-rooms', [ChatController::class, 'getPatientRooms']);
        Route::get('/payments/report', [ \App\Http\Controllers\Api\PaymentReportController::class, 'index']);
        Route::get('/chat/{roomId}/messages', [ChatController::class, 'getAdminRoomMessages']);
        Route::get('/appointments/list', [AppointmentManagementController::class, 'index']);
        Route::get('/doctors', [AppointmentManagementController::class, 'doctors']);
        Route::get('/appointments/{id}', [AppointmentManagementController::class, 'show']);
        Route::put('/appointments/{id}/status', [AppointmentManagementController::class, 'updateStatus']);
        Route::post('/appointments/{id}/cancel', [AppointmentManagementController::class, 'cancel']);

    });

});

Route::middleware('auth:sanctum')->group(function () {

    Route::post('/logout-all', [AuthController::class, 'logoutAll']);

    Route::post('/logout', [AuthController::class, 'logout']);
});
Route::middleware('auth:sanctum')->prefix('user/addresses')->group(function () {
    Route::get('/', [App\Http\Controllers\Api\AddressController::class, 'index']);
    Route::post('/', [App\Http\Controllers\Api\AddressController::class, 'store']);
    Route::put('/{id}', [App\Http\Controllers\Api\AddressController::class, 'update']);
    Route::delete('/{id}', [App\Http\Controllers\Api\AddressController::class, 'destroy']);
});
Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
Route::middleware(['auth:sanctum', 'user.active'])->post('/chat/upload', [ChatController::class, 'uploadFile']);

Route::group(['prefix' => 'user'],function (){
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:3,1');
    Route::post('verify',[AuthController::class,'verify'])->middleware('throttle:5,1');
     Route::middleware(['auth:sanctum', 'user.active'])->group(function () {
         Route::get('/health-insights', [HealthController::class, 'getInsights']);
         Route::post('/fcm-token/update', [AuthController::class, 'updateFcmToken']);
         // سینک کردن برنامه غذایی و وعده‌های یک روز (افزودن و حذف)
         Route::post('/health-insights', [HealthController::class, 'syncMealPlan']);

         // ذخیره اندازه‌های بدن
         Route::post('/body-measurements', [HealthController::class, 'saveMeasurements']);

         // ذخیره وزن ایده‌آل
         Route::post('/ideal-weight', [HealthController::class, 'saveIdealWeight']);
         Route::prefix('/plans')->group(function () {
             Route::get('/', [\App\Http\Controllers\Api\UserPlanController::class, 'getPlans']);
             Route::get('/current', [\App\Http\Controllers\Api\UserPlanController::class, 'currentPlan']);
             Route::get('/history', [\App\Http\Controllers\Api\UserPlanController::class, 'getHistory']);
             Route::post('/purchase', [\App\Http\Controllers\Api\UserPlanController::class, 'purchasePlan']);
         });
         Route::get('/chat/rooms', [ChatController::class, 'getMyRooms']);

         Route::post('/appointments/pay-order', [\App\Http\Controllers\Api\PaymentController::class, 'initiateAppointmentPayment']);
         Route::post('/wallet/charge', [\App\Http\Controllers\Api\WalletController::class, 'chargeWallet']);
         Route::get('/services', [MedicalServiceProviderController::class, 'activeServices']);
         Route::get('/providers/{type}/{id}', [MedicalServiceProviderController::class, 'show']);
         Route::get('/provider/reviews', [ReviewController::class, 'getProviderReviews']);

         Route::get('/providers', [MedicalServiceProviderController::class, 'index']);

         Route::post('/reviews', [ReviewController::class, 'storeReview']);

         Route::get('/medical-requests/{id}', [MedicalRequestController::class, 'getRequestDetail']);
         Route::post('/medical-requests/{id}/cancel', [MedicalRequestController::class, 'cancelRequest']);

         Route::get('/period-tracker', [PeriodTrackerController::class, 'show']);
         Route::post('/period-tracker/init', [PeriodTrackerController::class, 'storeOrUpdate']);

         Route::post('/period-tracker/log', [PeriodTrackerController::class, 'storePeriodLog']);
         Route::get('/period-tracker/logs', [PeriodTrackerController::class, 'logs']);

         Route::post('/period-tracker/daily-log', [PeriodTrackerController::class, 'storeDailyLog']);
         Route::get('/period-tracker/daily-logs', [PeriodTrackerController::class, 'dailyLogsByMonth']);
         Route::get('/period-tracker/daily-log/{date}', [PeriodTrackerController::class, 'dailyLogByDate']);

         // --- بخش پارتنر (Partner Sync) ---
         Route::post('/period-tracker/partner/connect', [PeriodTrackerController::class, 'connectPartner']);
         Route::delete('/period-tracker/partner/disconnect', [PeriodTrackerController::class, 'disconnectPartner']);
         Route::get('/period-tracker/partner/dashboard', [PeriodTrackerController::class, 'partnerDashboard']);


         Route::post('/period-tracker/share-link', [PeriodShareController::class, 'create']);
         Route::delete('/period-tracker/share-link', [PeriodShareController::class, 'disable']);
         Route::get('/period-tracker/share-link', [PeriodShareController::class, 'activeLink']);
         Route::get('/orders', [\App\Http\Controllers\Api\UserOrderController::class,'index' ]);
         Route::get('/finance_orders', [\App\Http\Controllers\Api\UserOrderController::class,'getUserOrders' ]);
         Route::post('/pharmacy-requests', [UserPharmacyRequestController::class, 'storeRequest']);
         Route::get('pharmacy-requests/{id}', [UserPharmacyRequestController::class, 'show']);
         Route::post('pharmacy-requests/{id}/pay', [UserPharmacyRequestController::class, 'pay']);
         Route::post('pharmacy-requests/{id}/cancel', [UserPharmacyRequestController::class, 'cancelRequest']);

         Route::get('/labs/prescription-types', [\App\Http\Controllers\Api\LabController::class, 'getPrescriptionTypes']);
         Route::get('/labs/test-packs', [\App\Http\Controllers\Api\LabController::class, 'getTestPacks']);
         Route::post('/labs/search-centers', [\App\Http\Controllers\Api\LabController::class, 'searchCenters']);
         Route::post('/labs/requests', [\App\Http\Controllers\Api\LabController::class, 'storeRequest']);
         Route::get('/labs/{lab_id}/shifts', [\App\Http\Controllers\Api\LabController::class, 'getLabShifts']);         Route::get('/labs/requests', [\App\Http\Controllers\Api\LabController::class, 'getUserRequests']);
         Route::get('/labs/requests/{id}', [\App\Http\Controllers\Api\LabController::class, 'getUserRequestDetail']);
         Route::get('/labs-requests/{id}', [\App\Http\Controllers\Api\UserLabRequestController::class, 'show']);
         Route::get('/labs-requests/{id}/pay', [\App\Http\Controllers\Api\UserLabRequestController::class, 'pay']);
         Route::post('/labs-requests/{id}/cancel', [\App\Http\Controllers\Api\UserLabRequestController::class, 'cancelRequest']);
         Route::get('/lab-requests/results/{result_id}/download', [\App\Http\Controllers\Api\UserLabRequestController::class, 'downloadResultFile'])->name('results.download')->where('fileName', '.*');
         Route::get('/user/prescriptions/{id}/download/{file}', [\App\Http\Controllers\Api\LabController::class, 'downloadPrescriptionFile'])->name('user.prescription.download');
         Route::get('/appointments/active', [ReservationController::class, 'getActiveAppointment']);
         Route::post('/appointments/cancel-temp', [ReservationController::class, 'cancelTempReservation']);
// ۱. وب‌سرویس ایجاد سفارش نوبت
         Route::post('/chat/reserve', [ReservationController::class, 'reserveChat']);
         Route::post('/appointments/reserve', [ReservationController::class, 'createOrder']);
         Route::get('/appointments-requests/{id}', [ReservationController::class, 'getAppointmentDetail']);
         // ۲. وب‌سرویس صدور/دریافت درگاه پرداخت برای سفارش
         Route::post('/payments/initiate', [PaymentGatewayController::class, 'initiatePayment']);
         Route::prefix('reservations')->group(function () {
            // رزرو موقت اسلات (15 دقیقه)
            Route::post('/reserve', [ReservationController::class, 'reserveSlot']);
             Route::post('/reserve-saman', [ReservationController::class, 'reserveWithSaman']);

            // تایید نهایی رزرو
            Route::post('/confirm', [ReservationController::class, 'confirmReservation'])->name('payment.callback');
            Route::post('/cancel', [ReservationController::class, 'cancelReservation']);
            Route::get('/active', [ReservationController::class, 'getActiveReservation']);
        });
        // دریافت اطلاعات کاربر با پلن
        Route::get('/profile', [UserController::class, 'getProfile']);
        Route::post('/profile/update', [UserController::class, 'updateProfile']);
        // تغییر پلن
        Route::post('/change-plan', [UserController::class, 'changePlan']);

        // تاریخچه پلن
        Route::get('/plan-history', [UserController::class, 'getPlanHistory']);

        // لغو پلن
        Route::post('/cancel-plan', [UserController::class, 'cancelPlan']);
    });

    Route::middleware('auth:sanctum')->prefix('diagnosis')->group(function () {
        // تشخیص بیماری (عمومی)
        Route::middleware('api.rate.limit')->post('/diagnose', [DiagnosisController::class, 'diagnose']);
        Route::middleware('api.rate.limit')->post('/chat', [DiagnosisController::class, 'chat']);

        Route::get('/doctors', [DiagnosisController::class, 'getDoctorsList']);
        Route::get('/keywords/suggest', [DiagnosisController::class, 'suggestKeywords']);
        Route::post('/doctor/{id}/click', [DiagnosisController::class, 'registerDoctorClick']);

        // تاریخچه تشخیص‌ها (نیاز به احراز هویت)
        Route::get('/history', [DiagnosisController::class, 'history']);

        // دریافت دکترها بر اساس تخصص
        Route::get('/doctors/specialty/{specialtyId}', [DiagnosisController::class, 'getDoctorsBySpecialty']);

        // دریافت آزمایشگاه‌ها بر اساس تخصص
        Route::get('/labs/specialty/{specialtyId}', [DiagnosisController::class, 'getLabsBySpecialty']);
    });

    Route::middleware('auth:sanctum')->prefix('doctors')->group(function () {
        Route::get('/{id}/recommendations', [DiagnosisController::class, 'getRecommendations']);
        Route::post('/{id}/recommend', [DiagnosisController::class, 'toggleRecommendation']);

        Route::get('/{doctorId}/schedule', [DiagnosisController::class, 'getDoctorWithScheduleV1']);
     });
      Route::middleware('auth:sanctum')->prefix('chat')->group(function () {
        Route::post('/rooms', [ChatController::class, 'createRoom']);
    });
});

