<?php

use App\Livewire\Doctor\Auth\Login;
use App\Livewire\Doctor\Dashboard\DashboardIndex;
use Illuminate\Support\Facades\Route;

Route::get('test',function (){
    throw new Exception('fuck');
});
Route::get('login', Login::class)->middleware('guest')->name('doctor.login');
Route::group(['middleware' => []],function () {
    //dashboard
    Route::get('/', function () {return redirect(route('doctor.dashboard'));});
    Route::get('/dashboard', DashboardIndex::class)->name('dashboard');
    Route::get('/logout', [AuthController::class,'logout'])->name('logout');
});
