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

Route::group(['prefix' => 'admin'],function (){

    Route::post('/login', [\App\Http\Controllers\Api\AdminAuthController::class, 'login'])->middleware('throttle:3,1');
    Route::middleware(['auth:sanctum',\App\Http\Middleware\CheckApiAdmin::class])->group(function () {
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
        Route::get('/appointments/list', [ReservationController::class, 'getAppointments']);
        Route::get('/appointments/generate', [AppointmentController::class, 'generateWeeklySlotsForAllDoctors']);

    });

});
Route::get('/redis-set-test', function () {
    Redis::setex('test_key', 300, 'Value expires in 5 minutes');
    
    return response()->json([
        'status' => 'success',
        'value' => Redis::get('test_key'),
        'ttl_seconds' => Redis::ttl('test_key')
    ]);
});
Route::get('/redis-get-test', function () {
    $value = Redis::get('test_key');
    $ttl = Redis::ttl('test_key');
    
    if ($value === null) {
        return response()->json([
            'status' => 'not_found',
            'message' => 'Key does not exist or has expired',
            'value' => null,
            'ttl_seconds' => $ttl
        ], 404);
    }
    
    return response()->json([
        'status' => 'success',
        'value' => $value,
        'ttl_seconds' => $ttl,
        'expires_in' => $ttl > 0 ? gmdate('i:s', $ttl) . ' minutes' : 'No expiration'
    ]);
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
Route::get('/t', function (Request $request) {
    SendWelcomeEmail::dispatch();
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
Route::group(['prefix' => 'user'],function (){
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:3,1');
    Route::post('verify',[AuthController::class,'verify']);
     Route::middleware('auth:sanctum')->group(function () {
           

          Route::prefix('reservations')->group(function () {
            // رزرو موقت اسلات (15 دقیقه)
            Route::post('/reserve', [ReservationController::class, 'reserveSlot']);

            // تایید نهایی رزرو
            Route::post('/confirm', [ReservationController::class, 'confirmReservation']);
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
        // تاریخچه تشخیص‌ها (نیاز به احراز هویت)
        Route::get('/history', [DiagnosisController::class, 'history']);

        // دریافت دکترها بر اساس تخصص
        Route::get('/doctors/specialty/{specialtyId}', [DiagnosisController::class, 'getDoctorsBySpecialty']);

        // دریافت آزمایشگاه‌ها بر اساس تخصص
        Route::get('/labs/specialty/{specialtyId}', [DiagnosisController::class, 'getLabsBySpecialty']);
    });
     
    Route::middleware('auth:sanctum')->prefix('doctors')->group(function () {
        Route::get('/{doctorId}/schedule', [DiagnosisController::class, 'getDoctorWithScheduleV1']);
     });
      Route::middleware('auth:sanctum')->prefix('chat')->group(function () {
        Route::post('/rooms', [ChatController::class, 'createRoom']);
    });
});

