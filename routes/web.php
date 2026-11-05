<?php

use App\Http\Controllers\Admin\AgrodealerController;
use App\Http\Controllers\Admin\AgrodealerPosController;
use App\Http\Controllers\Admin\ProductController;
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

        Route::get('/products', [ProductController::class, 'index'])->name('products.index');
        Route::post('/products', [ProductController::class, 'store'])->name('products.store');
        Route::put('/products/{product}', [ProductController::class, 'update'])->name('products.update');

        Route::get('/agrodealers', [AgrodealerController::class, 'index'])->name('agrodealers.index');
        Route::post('/agrodealers', [AgrodealerController::class, 'store'])->name('agrodealers.store');
        Route::put('/agrodealers/{agrodealer}', [AgrodealerController::class, 'update'])->name('agrodealers.update');
        Route::post('/agrodealers/{agrodealer}/pos', [AgrodealerPosController::class, 'store'])->name('agrodealers.pos.store');
        Route::put('/agrodealers/{agrodealer}/pos/{pos}', [AgrodealerPosController::class, 'update'])
            ->scopeBindings()
            ->name('agrodealers.pos.update');
    });
});
