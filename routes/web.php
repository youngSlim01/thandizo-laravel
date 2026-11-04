<?php

use App\Http\Controllers\Admin\ProgrammeDistrictController;
use App\Http\Controllers\Auth\LoginController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);
});

Route::middleware('auth')->group(function () {
    Route::get('/', fn () => Inertia::render('home'))->name('home');
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    Route::prefix('admin')->name('admin.')->group(function () {
        Route::get('/districts', [ProgrammeDistrictController::class, 'index'])->name('districts.index');
        Route::post('/districts', [ProgrammeDistrictController::class, 'store'])->name('districts.store');
    });
});
