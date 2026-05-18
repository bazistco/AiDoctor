<?php

use App\Http\Api\Controllers\Auth\AuthController;
use App\Http\Controllers\Api\AppointmentController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\DiagnosisController;
use App\Http\Controllers\Api\FoodExtractController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\FileUploadController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;


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
Route::prefix('food-extract')->group(function () {
    Route::post('/', [FoodExtractController::class, 'extract']);
    Route::get('/health', [FoodExtractController::class, 'health']);
    Route::get('/food-extract/search', [FoodExtractController::class, 'searchFood']);

});
Route::group(['prefix' => 'user'],function (){
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:3,1');
    Route::post('verify',[AuthController::class,'verify']);
    Route::middleware('auth:sanctum')->group(function () {
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

        Route::get('/doctors/{doctorId}/schedule', [DiagnosisController::class, 'getDoctorWithSchedule']);

        Route::get('/doctors/{doctorId}/slots', [DiagnosisController::class, 'getDoctorSlotsByDate']);

        Route::get('/doctors/availability', [DiagnosisController::class, 'getDoctorsWithAvailability']);

        Route::post('/diagnose', [DiagnosisController::class, 'diagnose']);

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
    Route::middleware('auth:sanctum')->prefix('chat')->group(function () {
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

