<?php

use App\Http\Api\Controllers\Auth\AuthController;
use App\Http\Controllers\Api\DiagnosisController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\FileUploadController;
use App\Jobs\SendWelcomeEmail;
use Illuminate\Support\Facades\Redis;
use App\Http\Controllers\Api\ReservationController;
use App\Http\Controllers\Api\AppointmentController;
use App\Http\Controllers\Api\MedicalRequestController;
use App\Http\Controllers\Api\UserPharmacyRequestController;
use App\Http\Controllers\Api\PeriodShareController;
use App\Http\Controllers\Api\PeriodTrackerController;
use App\Http\Controllers\Api\Admin\AppointmentManagementController;
use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Api\MedicalServiceProviderController;
use App\Http\Controllers\Api\Admin\AdminServiceController;
use App\Http\Controllers\TourController;


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
Route::get('/get-ip', function (Request $request) {
    dd($request->ip());
});
Route::middleware('auth:sanctum')->prefix('user/medical')->group(function () {
    // مرحله ۱: دریافت لیست خدمات قابل ارائه
    Route::get('/services', [MedicalRequestController::class, 'getServices']);

    // مرحله ۳: دریافت مراکز درمانی نزدیک/ارائه‌دهنده خدمات انتخابی
    Route::post('/centers', [MedicalRequestController::class, 'getCenters']);

    // ثبت نهایی درخواست
    Route::post('/requests', [MedicalRequestController::class, 'storeRequest']);
});
Route::group(['prefix' => 'doctor'], function () {

    Route::post('/login', [\App\Http\Controllers\Api\Doctor\DoctorAuthController::class, 'login'])->middleware('throttle:3,1');

    Route::middleware(['auth:sanctum', 'role:doctor'])->group(function () {
        Route::put('/profile', [\App\Http\Controllers\Api\Doctor\DoctorProfileController::class, 'updateProfile']);
        Route::get('/dashboard', [\App\Http\Controllers\Api\Doctor\DoctorDashboardController::class, 'index']);
        Route::post('/wallet/charge-mock', [\App\Http\Controllers\Api\Doctor\WalletController::class, 'mockCharge']);
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

Route::get('/t', function (Request $request) {
    SendWelcomeEmail::dispatch();
});

Route::post('/upload', [FileUploadController::class, 'upload']);

Route::middleware('auth:sanctum')->prefix('user/addresses')->group(function () {
    Route::get('/', [App\Http\Controllers\Api\AddressController::class, 'index']);
    Route::post('/', [App\Http\Controllers\Api\AddressController::class, 'store']);
    Route::put('/{id}', [App\Http\Controllers\Api\AddressController::class, 'update']);
    Route::delete('/{id}', [App\Http\Controllers\Api\AddressController::class, 'destroy']);
});
Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
Route::group(['prefix' => 'user'],function (){
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:3,1');
    Route::post('verify',[AuthController::class,'verify']);
     Route::middleware('auth:sanctum')->group(function () {
         Route::get('/services', [MedicalServiceProviderController::class, 'activeServices']);
         Route::get('/providers/{type}/{id}', [MedicalServiceProviderController::class, 'show']);
         Route::get('/provider/reviews', [ReviewController::class, 'getProviderReviews']);

         Route::get('/providers', [MedicalServiceProviderController::class, 'index']);

         Route::post('/reviews', [ReviewController::class, 'storeReview']);

         Route::get('/medical-requests/{id}', [MedicalRequestController::class, 'getRequestDetail']);

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
        Route::post('/chat', [DiagnosisController::class, 'chat']);
        Route::get('/doctors', [DiagnosisController::class, 'getDoctorsList']);
        Route::get('/keywords/suggest', [DiagnosisController::class, 'suggestKeywords']);

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

