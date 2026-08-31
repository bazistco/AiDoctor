<?php

use App\Http\Controllers\Api\Owner\Labs\LabAuthController;
use App\Http\Controllers\Api\Owner\Labs\LabProfileController;
use App\Http\Controllers\Api\Owner\Labs\LabRequestController;
use App\Http\Controllers\Api\Owner\Labs\LabTestController;
use App\Http\Controllers\Api\Owner\MedicalCenters\CoverageController;
use App\Http\Controllers\Api\Owner\MedicalCenters\MedicalCenterProfileController;
use App\Http\Controllers\Api\Owner\MedicalCenters\MedicalCenterRequestController;
use App\Http\Controllers\Api\Owner\MedicalCenters\MedicalCenterServiceController;
use App\Http\Controllers\Api\Owner\MedicalCenters\MedicalCenterStaffController;
use App\Http\Controllers\Api\Owner\Pharmacies\PharmacyAuthController;
use App\Http\Controllers\Api\Owner\Pharmacies\PharmacyRequestController;
use App\Http\Controllers\Api\Owner\Pharmacies\PharmacyProfileController;
use Illuminate\Support\Facades\Route;

Route::prefix('pharmacy')->name('pharmacy.')->group(function () {

    // 1. احراز هویت (بدون نیاز به توکن)
    Route::post('/login', [PharmacyAuthController::class, 'login'])->name('login');
    Route::post('/verify', [PharmacyAuthController::class, 'verify'])->name('verify');

    // 2. روت‌های نیازمند احراز هویت و دسترسی داروخانه
    Route::middleware(['auth:sanctum', 'user.active', 'role:pharmacy', 'ownership:pharmacy'])->group(function () {
        Route::get('/dashboard', [PharmacyProfileController::class, 'dashboard']);
        Route::post('/toggle-status', [PharmacyProfileController::class, 'toggleStatus']);
        Route::get('/profile', [PharmacyProfileController::class, 'show']);
        Route::post('/profile/update', [PharmacyProfileController::class, 'update']);
        Route::get('/medicines/search', [PharmacyRequestController::class, 'searchMedicines']);

        Route::prefix('requests')->name('requests.')->group(function () {
            // لیست درخواست‌ها (خام و پذیرفته شده)
            Route::get('/', [PharmacyRequestController::class, 'index'])->name('index');

            // آمار داشبورد
            Route::get('/stats', [PharmacyRequestController::class, 'stats'])->name('stats');

            // جزئیات یک درخواست خاص
            Route::get('/{id}', [PharmacyRequestController::class, 'show'])->name('show');

            // تغییر وضعیت درخواست (تکمیل و ...)
            Route::patch('/{id}/status', [PharmacyRequestController::class, 'updateStatus'])->name('updateStatus');

            // مدیریت اقلام یک درخواست
            Route::post('/{id}/items', [PharmacyRequestController::class, 'addItem']);
            Route::delete('/{id}/items/{itemId}', [PharmacyRequestController::class, 'removeItem']);

            // پذیرش و آزادسازی درخواست‌های خام
            Route::post('/{id}/accept', [PharmacyRequestController::class, 'acceptRequest'])->name('accept');
            Route::post('/{id}/release', [PharmacyRequestController::class, 'releaseRequest'])->name('release');
            Route::patch('/{id}/mark-preparing', [PharmacyRequestController::class, 'markAsPreparing']);
            Route::patch('/{id}/mark-ready', [PharmacyRequestController::class, 'markAsReadyForDelivery']);
            Route::patch('/{id}/mark-delivering', [PharmacyRequestController::class, 'markAsDelivering']);
            Route::patch('/{id}/mark-delivered', [PharmacyRequestController::class, 'markAsDelivered']);
            Route::patch('/{id}/mark-completed', [PharmacyRequestController::class, 'markAsCompleted']);
            Route::patch('/{id}/cancel', [PharmacyRequestController::class, 'cancelRequest']);
        });

    });
});

// Lab Owner Routes
Route::prefix('lab')->name('lab.')->group(function () {
    // Authentication
    Route::post('/login', [LabAuthController::class, 'login'])->name('login');
    Route::post('/verify', [LabAuthController::class, 'verify'])->name('verify');

    Route::middleware(['auth:sanctum', 'user.active', 'role:lab', 'ownership:lab'])->group(function () {
        // Profile
        Route::get('/finance', [LabProfileController::class, 'finance'])->name('profile.finance');
        Route::get('/profile', [LabProfileController::class, 'show'])->name('profile.show');
        Route::put('/profile', [LabProfileController::class, 'update'])->name('profile.update');
        Route::put('/status', [LabProfileController::class, 'toggleStatus'])->name('profile.status');

        Route::get('/tests/available', [LabRequestController::class, 'getAvailableTests']);
        Route::get('/test-packs', [LabTestController::class, 'getTestPacks'])->name('test-packs.index');
        Route::get('/tests', [LabTestController::class, 'index'])->name('tests.index');
        Route::post('/tests', [LabTestController::class, 'store'])->name('tests.store');
        Route::get('/tests/{id}', [LabTestController::class, 'show'])->name('tests.show');
        Route::put('/tests/{id}', [LabTestController::class, 'update'])->name('tests.update');
        Route::delete('/tests/{id}', [LabTestController::class, 'destroy'])->name('tests.destroy');
        // Requests
        Route::post('requests/{id}/accept', [LabRequestController::class, 'acceptRequest']);
        Route::delete('/requests/{id}/assign-tests', [LabRequestController::class, 'unassignTestPacks']);
        Route::post('/requests/{id}/assign-tests', [LabRequestController::class, 'assignTestPacks']);
        Route::get('results', [LabRequestController::class, 'getResults']);
        Route::post('/requests/{id}/results', [LabRequestController::class, 'uploadResult']);
        Route::get('/schedule', [LabRequestController::class, 'schedule']);
        Route::get('/requests', [LabRequestController::class, 'index'])->name('requests.index');
        Route::get('/requests/stats', [LabRequestController::class, 'stats'])->name('requests.stats');
        Route::get('/requests/{id}', [LabRequestController::class, 'show'])->name('requests.show');
        Route::put('/requests/{id}/status', [LabRequestController::class, 'updateStatus'])->name('requests.updateStatus');
    });
});


// Medical Center Owner Routes
Route::prefix('medical-center')->name('medical_center.')->group(function () {

    Route::post('/login', [\App\Http\Controllers\Api\Owner\MedicalCenters\MedicalCenterAuthController::class, 'login'])->name('login');
    Route::post('/verify', [\App\Http\Controllers\Api\Owner\MedicalCenters\MedicalCenterAuthController::class, 'verify'])->name('verify');

    Route::middleware(['auth:sanctum', 'user.active', 'role:medical_center','ownership:medical_center'])->group(function () {

        Route::get('/dashboard', [MedicalCenterProfileController::class, 'dashboard']);
        Route::post('/toggle-status', [MedicalCenterProfileController::class, 'toggleStatus']);
        Route::get('/finance', [MedicalCenterProfileController::class, 'finance'])->name('profile.finance');
        Route::get('/profile', [MedicalCenterProfileController::class, 'show'])->name('profile.show');
        Route::put('/profile', [MedicalCenterProfileController::class, 'update'])->name('profile.update');

        Route::get('/coverage/regions', [CoverageController::class, 'getAvailableRegions'])->name('coverage.regions');
        Route::get('/coverage', [CoverageController::class, 'getCoverage'])->name('coverage.show');
        Route::post('/coverage', [CoverageController::class, 'updateCoverage'])->name('coverage.update');

        // Staff
        Route::get('/staff', [MedicalCenterStaffController::class, 'index'])->name('staff.index');
        Route::post('/staff', [MedicalCenterStaffController::class, 'store'])->name('staff.store');
        Route::get('/staff/{id}', [MedicalCenterStaffController::class, 'show'])->name('staff.show');
        Route::put('/staff/{id}', [MedicalCenterStaffController::class, 'update'])->name('staff.update');
        Route::delete('/staff/{id}', [MedicalCenterStaffController::class, 'destroy'])->name('staff.destroy');

        // Services
        Route::get('/services/list', [MedicalCenterServiceController::class, 'getAvailableServices']);
        Route::get('/services', [MedicalCenterServiceController::class, 'index'])->name('services.index');
        Route::post('/services', [MedicalCenterServiceController::class, 'store'])->name('services.store');
        Route::get('/services/{id}', [MedicalCenterServiceController::class, 'show'])->name('services.show');
        Route::put('/services/{id}', [MedicalCenterServiceController::class, 'update'])->name('services.update');
        Route::delete('/services/{id}', [MedicalCenterServiceController::class, 'destroy'])->name('services.destroy');

        // Requests
        Route::get('/requests', [MedicalCenterRequestController::class, 'index'])->name('requests.index');
        Route::get('/requests/stats', [MedicalCenterRequestController::class, 'stats'])->name('requests.stats');
        Route::get('/requests/{id}', [MedicalCenterRequestController::class, 'show'])->name('requests.show');
        Route::put('/requests/{id}/status', [MedicalCenterRequestController::class, 'updateStatus'])->name('requests.updateStatus');
        Route::post('/requests/{id}/assign', [MedicalCenterRequestController::class, 'assignStaff'])->name('requests.assignStaff');
        Route::post('/requests/{id}/report', [MedicalCenterRequestController::class, 'submitReport'])->name('requests.submitReport');

        // برنامه‌ریزی
        Route::get('/schedule', [MedicalCenterRequestController::class, 'schedule']);

        // درخواست‌های پرسنل
        Route::get('/staff/{staffId}/requests', [MedicalCenterRequestController::class, 'staffAssignedRequests']);


    });
    // Profile

});
