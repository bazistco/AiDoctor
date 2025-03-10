<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
})->name('home');
Route::get('/test',\App\Livewire\Test\Test::class)->name('test');
