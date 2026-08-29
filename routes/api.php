<?php

use App\Http\Api\Controllers\Auth\AuthController;
use App\Http\Controllers\Api\Admin\AdminServiceController;
use App\Http\Controllers\Api\Admin\AppointmentManagementController;
use App\Http\Controllers\Api\AppointmentController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\DiagnosisController;
use App\Http\Controllers\Api\FoodExtractController;
use App\Http\Controllers\Api\MedicalRequestController;
use App\Http\Controllers\Api\PeriodShareController;
use App\Http\Controllers\Api\PeriodTrackerController;
use App\Http\Controllers\Api\ReservationController;
use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Api\MedicalServiceProviderController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\UserPharmacyRequestController;
use App\Http\Controllers\FileUploadController;
use App\Http\Controllers\TourController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('tours')->group(function () {
    Route::get('/', [TourController::class, 'index']);
    Route::post('/', [TourController::class, 'store']);
});
Route::get('/tours/{id}', [TourController::class, 'show']);       // دریافت جزئیات یک تور برای صفحه دعوت
Route::post('/tours/{id}/join', [TourController::class, 'join']);
Route::get('/tours/{id}/participants', [TourController::class, 'getParticipants']);
Route::put('/tours/{id}/participants', [TourController::class, 'updateParticipant']);
Route::delete('/tours/{id}/participants/{mobile}', [TourController::class, 'removeParticipant']);

Route::any('pg/call_back', function (Request $request) {
   return response()->json(['success'=>1,'data'=>$request->all() ?? []]) ;
});
Route::post('/send-date-invite', function (Request $request) {
    $token = "182541559:ERwiwkliF-Q4fg29DE-rdDu9halqRh5cIaU"; // بهتر است این را در فایل .env بگذارید
    $chatId = "6068713488";

    $message = "💖 دعوت به قرار پذیرفته شد!\n\n📅 تاریخ: {$request->date}\n⏰ ساعت: {$request->time}\n🍽 سفارش/غذا: {$request->food}";

    $response = Http::post("https://tapi.bale.ai/bot{$token}/sendMessage", [
        'chat_id' => $chatId,
        'text' => $message,
    ]);

    if ($response->successful()) {
        return response()->json(['status' => 'success']);
    }

    return response()->json(['status' => 'error'], 500);
});
Route::post('/upload', [FileUploadController::class, 'upload']);

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
Route::post('predict',function (Request $request) {
    try {
        $symptoms=translateExample($request->question);
        $predictions = predict_illness($symptoms);
        return response()->json([$predictions]);
    }catch (\Exception $e){
        return response()->json([$e->getMessage()]);
    }
});
Route::post('v2/predict',function (Request $request) {
    try {
        $illnesses=[];
        $symptoms=translateExample($request->question);
        sleep(1);
        $predictions = predict_illness($symptoms);
        foreach ($predictions as $prediction) {
            sleep(1);
            $illnesses[]=translateExample($prediction,'en','fa');
        }
        return response()->json([$illnesses]);
    }catch (\Exception $e){
        return response()->json([$e->getMessage()]);
    }

});

Route::get('test',function (Request $request) {dd(\App\Models\Illness::all());});
Route::prefix('exercise-extract')->group(function () {
    Route::post('/', [\App\Http\Controllers\Api\ExerciseExtractController::class, 'extract']);


});
Route::middleware('auth:sanctum')->prefix('user/medical')->group(function () {
    // مرحله ۱: دریافت لیست خدمات قابل ارائه
    Route::get('/services', [MedicalRequestController::class, 'getServices']);

    // مرحله ۳: دریافت مراکز درمانی نزدیک/ارائه‌دهنده خدمات انتخابی
    Route::post('/centers', [MedicalRequestController::class, 'getCenters']);

    // ثبت نهایی درخواست
    Route::post('/requests', [MedicalRequestController::class, 'storeRequest']);
});
Route::get('/redis-test', function (Request $request) {
    try {
        // تست اتصال
        Redis::connection()->ping();

        // تست set و get
        $testKey = 'test_key_' . time();
        $testValue = 'Hello Redis! ' . now();

        Redis::set($testKey, $testValue);
        $retrieved = Redis::get($testKey);

        // پاک کردن کلید تست
        Redis::del($testKey);

        return response()->json([
            'status' => 'success',
            'message' => 'Redis connection is working',
            'test' => [
                'set_value' => $testValue,
                'retrieved_value' => $retrieved,
                'match' => $testValue === $retrieved
            ],
            'redis_info' => [
                'host' => config('database.redis.default.host'),
                'port' => config('database.redis.default.port'),
                'database' => config('database.redis.default.database')
            ]
        ], 200);

    } catch (\Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => 'Redis connection failed',
            'error' => $e->getMessage(),
            'redis_config' => [
                'host' => config('database.redis.default.host'),
                'port' => config('database.redis.default.port'),
                'database' => config('database.redis.default.database')
            ]
        ], 500);
    }
});
Route::prefix('food-extract')->group(function () {
    Route::post('/', [FoodExtractController::class, 'extract']);
    Route::get('/health', [FoodExtractController::class, 'health']);
    Route::get('/food-extract/search', [FoodExtractController::class, 'searchFood']);

});
Route::middleware('auth:sanctum')->prefix('user/addresses')->group(function () {
    Route::get('/', [App\Http\Controllers\Api\AddressController::class, 'index']);
    Route::post('/', [App\Http\Controllers\Api\AddressController::class, 'store']);
    Route::put('/{id}', [App\Http\Controllers\Api\AddressController::class, 'update']);
    Route::delete('/{id}', [App\Http\Controllers\Api\AddressController::class, 'destroy']);
});
Route::group(['prefix' => 'user'],function (){

    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:3,1');
    Route::post('verify',[AuthController::class,'verify']);
    Route::middleware('auth:sanctum')->group(function () {

        Route::get('/providers/{type}/{id}', [MedicalServiceProviderController::class, 'show']);

        Route::get('/providers', [MedicalServiceProviderController::class, 'index']);

        Route::post('/reviews', [ReviewController::class, 'storeReview']);
        Route::get('/provider/reviews', [ReviewController::class, 'getProviderReviews']);

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

        // دریافت لیست پکیج‌های آزمایش پایه برای نمایش در مرحله اول

        Route::get('/labs/prescription-types', [\App\Http\Controllers\Api\LabController::class, 'getPrescriptionTypes']);
        Route::get('/labs/test-packs', [\App\Http\Controllers\Api\LabController::class, 'getTestPacks']);
        Route::post('/labs/search-centers', [\App\Http\Controllers\Api\LabController::class, 'searchCenters']);
        Route::post('/labs/requests', [\App\Http\Controllers\Api\LabController::class, 'storeRequest']);
        Route::get('/labs/requests', [\App\Http\Controllers\Api\LabController::class, 'getUserRequests']);
        Route::get('/labs/requests/{id}', [\App\Http\Controllers\Api\LabController::class, 'getUserRequestDetail']);
        Route::get('/labs-requests/{id}', [\App\Http\Controllers\Api\UserLabRequestController::class, 'show']);
        Route::get('/labs-requests/{id}/pay', [\App\Http\Controllers\Api\UserLabRequestController::class, 'pay']);

        Route::prefix('reservations')->group(function () {
            // رزرو موقت اسلات (15 دقیقه)
            Route::post('/reserve', [ReservationController::class, 'reserveSlot']);
            Route::post('/cancel', [ReservationController::class, 'cancelReservation']);
            // تایید نهایی رزرو
            Route::post('/confirm', [ReservationController::class, 'confirmReservation'])->name('payment.callback');
            Route::get('/active', [ReservationController::class, 'getActiveReservation']);
        });
        Route::post('/profile/update', [UserController::class, 'updateProfile']);
        // دریافت اطلاعات کاربر با پلن
        Route::get('/profile', [UserController::class, 'getProfile']);

        // تغییر پلن
        Route::post('/change-plan', [UserController::class, 'changePlan']);

        // تاریخچه پلن
        Route::get('/plan-history', [UserController::class, 'getPlanHistory']);

        // لغو پلن
        Route::post('/cancel-plan', [UserController::class, 'cancelPlan']);
    });
    Route::middleware('auth:sanctum')->prefix('diagnosis')->group(function () {

        Route::get('/doctors', [DiagnosisController::class, 'getDoctorsList']);
        Route::get('/keywords/suggest', [DiagnosisController::class, 'suggestKeywords']);


        Route::get('/doctors/{doctorId}/schedule', [DiagnosisController::class, 'getDoctorWithSchedule']);

        Route::get('/doctors/{doctorId}/slots', [DiagnosisController::class, 'getDoctorSlotsByDate']);

        Route::get('/doctors/availability', [DiagnosisController::class, 'getDoctorsWithAvailability']);

        Route::post('/diagnose', [DiagnosisController::class, 'diagnose']);
        Route::post('/chat', [DiagnosisController::class, 'chat']);
        Route::get('/history', [DiagnosisController::class, 'history']);

        // دریافت دکترها بر اساس تخصص
        Route::get('/doctors/specialty/{specialtyId}', [DiagnosisController::class, 'getDoctorsBySpecialty']);

        // دریافت آزمایشگاه‌ها بر اساس تخصص
        Route::get('/labs/specialty/{specialtyId}', [DiagnosisController::class, 'getLabsBySpecialty']);
    });
    Route::middleware('auth:sanctum')->prefix('doctors')->group(function () {
        Route::get('/{doctorId}/schedule', [DiagnosisController::class, 'getDoctorWithSchedule']);

        Route::get('/{doctorId}/slots', [DiagnosisController::class, 'getDoctorSlotsByDate']);

        Route::get('/availability', [DiagnosisController::class, 'getDoctorsWithAvailability']);

    });
    Route::get('/medical-requests/{id}', [MedicalRequestController::class, 'getRequestDetail']);

    Route::middleware('auth:sanctum')->prefix('chat')->group(function () {
        Route::get('/rooms', [ChatController::class, 'getUserRooms']);
        Route::get('/rooms/{room_id}/participants', [ChatController::class, 'getRoomParticipants']);
        Route::get('/rooms/{room_id}/messages', [ChatController::class, 'getRoomMessages']);
        Route::post('/rooms', [ChatController::class, 'createRoom']);
    });
    Route::prefix('appointments')->group(function () {
        Route::get('available-slots/{doctorId}', [AppointmentController::class, 'getAvailableSlots']);
    });

// مسیرهای احراز هویت شده
    Route::middleware('auth:sanctum')->prefix('appointments')->group(function () {
        // رزرو و پرداخت
        Route::post('initiate-booking', [AppointmentController::class, 'initiateBooking']);
        Route::post('verify-payment', [AppointmentController::class, 'verifyPayment']);
        Route::get('payment-status/{paymentId}', [AppointmentController::class, 'paymentStatus']);

        // مدیریت نوبت‌های بیمار
        Route::get('my-appointments', [AppointmentController::class, 'myAppointments']);
        Route::delete('cancel/{slotId}', [AppointmentController::class, 'cancelSlot']);
    });

// مسیرهای پزشک/ادمین
    Route::middleware(['auth:sanctum', 'role:doctor,admin'])->prefix('appointments')->group(function () {
        Route::post('generate-slots', [AppointmentController::class, 'generateSlots']);
        Route::get('doctor-slots', [AppointmentController::class, 'doctorSlots']);
        Route::patch('block/{slotId}', [AppointmentController::class, 'blockSlot']);
    });

// Callback درگاه پرداخت (بدون auth)
    Route::post('payment/callback', [AppointmentController::class, 'paymentCallback']);
    Route::post('/appointments/release-expired', [AppointmentController::class, 'releaseExpiredSlots']);

});
Route::group(['prefix' => 'admin'],function (){

    Route::post('/login', [\App\Http\Controllers\Api\AdminAuthController::class, 'login'])->middleware('throttle:3,1');
    Route::middleware(['auth:sanctum',\App\Http\Middleware\CheckApiAdmin::class])->group(function () {
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
        Route::get('doctor-rooms', [ChatController::class, 'getDoctorRooms']);
        Route::get('/payments/report', [ \App\Http\Controllers\Api\PaymentReportController::class, 'index']);
        Route::get('/chat/{roomId}/messages', [ChatController::class, 'getAdminRoomMessages']);
        Route::get('/appointments/list', [AppointmentManagementController::class, 'index']);
        Route::get('/doctors', [AppointmentManagementController::class, 'doctors']);
        Route::get('/appointments/{id}', [AppointmentManagementController::class, 'show']);
        Route::put('/appointments/{id}/status', [AppointmentManagementController::class, 'updateStatus']);
        Route::post('/appointments/{id}/cancel', [AppointmentManagementController::class, 'cancel']);
    });

});
Route::group(['prefix' => 'doctor'], function () {

    Route::post('/login', [\App\Http\Controllers\Api\Doctor\DoctorAuthController::class, 'login'])->middleware('throttle:3,1');

    Route::middleware(['auth:sanctum', 'role:doctor'])->group(function () {
        Route::get('/dashboard', [\App\Http\Controllers\Api\Doctor\DoctorDashboardController::class, 'index']);
        Route::put('/profile', [\App\Http\Controllers\Api\Doctor\DoctorProfileController::class, 'updateProfile']);

        Route::get('/schedule/calendar-summary', [\App\Http\Controllers\Api\Doctor\AppointmentController::class, 'getCalendarSummary']);
        Route::get('/schedule/slots', [\App\Http\Controllers\Api\Doctor\AppointmentController::class, 'getSlotsByDate']);
        Route::post('/schedule/generate-slots', [\App\Http\Controllers\Api\Doctor\AppointmentController::class, 'generateSlotsForDate']);
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


