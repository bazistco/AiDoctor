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
use App\Http\Controllers\Api\Owner\Pharmacies\PharmacyMedicineController;
use App\Http\Controllers\Api\Owner\Pharmacies\PharmacyProfileController;
use App\Http\Controllers\Api\Owner\Pharmacies\PharmacyRequestController;
use Illuminate\Support\Facades\Route;


// Lab Owner Routes
Route::prefix('lab')->name('lab.')->group(function () {
    // Authentication
    Route::post('/login', [LabAuthController::class, 'login'])->name('login');
    Route::post('/verify', [LabAuthController::class, 'verify'])->name('verify');

    Route::middleware(['auth:sanctum', 'role:lab', 'ownership:lab'])->group(function () {
        // Profile
        Route::get('/finance', [LabProfileController::class, 'finance'])->name('profile.finance');
        Route::get('/profile', [LabProfileController::class, 'show'])->name('profile.show');
        Route::put('/profile', [LabProfileController::class, 'update'])->name('profile.update');

        // Tests
        Route::get('/tests', [LabTestController::class, 'index'])->name('tests.index');
        Route::post('/tests', [LabTestController::class, 'store'])->name('tests.store');
        Route::get('/tests/{id}', [LabTestController::class, 'show'])->name('tests.show');
        Route::put('/tests/{id}', [LabTestController::class, 'update'])->name('tests.update');
        Route::delete('/tests/{id}', [LabTestController::class, 'destroy'])->name('tests.destroy');
        Route::get('/test-packs', [LabTestController::class, 'getTestPacks'])->name('test-packs.index');
        // Requests
        Route::get('results', [LabRequestController::class, 'getResults']);

        Route::get('/schedule', [LabRequestController::class, 'schedule']);
        Route::get('/requests', [LabRequestController::class, 'index'])->name('requests.index');
        Route::get('/requests/stats', [LabRequestController::class, 'stats'])->name('requests.stats');
        Route::get('/requests/{id}', [LabRequestController::class, 'show'])->name('requests.show');
        Route::put('/requests/{id}/status', [LabRequestController::class, 'updateStatus'])->name('requests.updateStatus');
    });
});


// Pharmacy Owner Routes
Route::middleware(['auth:sanctum', 'role:pharmacy_owner'])->prefix('pharmacy')->name('pharmacy.')->group(function () {
    // Profile
    Route::get('/profile', [PharmacyProfileController::class, 'show'])->name('profile.show');
    Route::put('/profile', [PharmacyProfileController::class, 'update'])->name('profile.update');

    // Medicines
    Route::get('/medicines', [PharmacyMedicineController::class, 'index'])->name('medicines.index');
    Route::post('/medicines', [PharmacyMedicineController::class, 'store'])->name('medicines.store');
    Route::get('/medicines/{id}', [PharmacyMedicineController::class, 'show'])->name('medicines.show');
    Route::put('/medicines/{id}', [PharmacyMedicineController::class, 'update'])->name('medicines.update');
    Route::delete('/medicines/{id}', [PharmacyMedicineController::class, 'destroy'])->name('medicines.destroy');

    // Requests
    Route::get('/requests', [PharmacyRequestController::class, 'index'])->name('requests.index');
    Route::get('/requests/stats', [PharmacyRequestController::class, 'stats'])->name('requests.stats');
    Route::get('/requests/{id}', [PharmacyRequestController::class, 'show'])->name('requests.show');
    Route::put('/requests/{id}/status', [PharmacyRequestController::class, 'updateStatus'])->name('requests.updateStatus');
    Route::put('/requests/{requestId}/items/{itemId}/status', [PharmacyRequestController::class, 'updateItemStatus'])->name('requests.updateItemStatus');
});

// Medical Center Owner Routes
Route::prefix('medical-center')->name('medical_center.')->group(function () {

    Route::post('/login', [\App\Http\Controllers\Api\Owner\MedicalCenters\MedicalCenterAuthController::class, 'login'])->name('login');
    Route::post('/verify', [\App\Http\Controllers\Api\Owner\MedicalCenters\MedicalCenterAuthController::class, 'verify'])->name('verify');

    Route::middleware(['auth:sanctum', 'role:medical_center','ownership:medical_center'])->group(function () {

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
        Route::get('/schedule', [MedicalCenterRequestController::class, 'schedule']);

    });
    // Profile

});
