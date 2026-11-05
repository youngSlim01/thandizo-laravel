<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\Admin\AgrodealerCatalogueController;
use App\Http\Controllers\Admin\AgrodealerController;
use App\Http\Controllers\Admin\AgrodealerPosController;
use App\Http\Controllers\Admin\PriceImportController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\ProductPriceController;
use App\Http\Controllers\Admin\ProgrammeDistrictController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\UserPasswordController;
use App\Http\Controllers\Auth\FirstPasswordController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\HomeController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);
    Route::post('/forgot-password', [PasswordResetController::class, 'sendLink'])
        ->middleware('throttle:5,1')
        ->name('password.email');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'edit'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'update'])->name('password.update');
});

Route::middleware('auth')->group(function () {
    Route::get('/', HomeController::class)->name('home');
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    Route::get('/password/first', [FirstPasswordController::class, 'show'])->name('password.first');
    Route::put('/password/first', [FirstPasswordController::class, 'update'])->name('password.first.update');

    Route::get('/account', [AccountController::class, 'show'])->name('account');
    Route::put('/account/password', [AccountController::class, 'updatePassword'])->name('account.password');

    Route::prefix('admin')->name('admin.')->group(function () {
        Route::post('/districts', [ProgrammeDistrictController::class, 'store'])->name('districts.store');

        Route::get('/products', [ProductController::class, 'index'])->name('products.index');
        Route::post('/products', [ProductController::class, 'store'])->name('products.store');
        Route::put('/products/{product}', [ProductController::class, 'update'])->name('products.update');
        Route::put('/products/{product}/prices/{district}', [ProductPriceController::class, 'update'])->name('products.prices.update');
        Route::post('/products/import', [PriceImportController::class, 'store'])->name('products.import');

        Route::get('/agrodealers', [AgrodealerController::class, 'index'])->name('agrodealers.index');
        Route::post('/agrodealers', [AgrodealerController::class, 'store'])->name('agrodealers.store');
        Route::put('/agrodealers/{agrodealer}', [AgrodealerController::class, 'update'])->name('agrodealers.update');
        Route::post('/agrodealers/{agrodealer}/pos', [AgrodealerPosController::class, 'store'])->name('agrodealers.pos.store');
        Route::put('/agrodealers/{agrodealer}/pos/{pos}', [AgrodealerPosController::class, 'update'])
            ->scopeBindings()
            ->name('agrodealers.pos.update');
        Route::get('/agrodealers/{agrodealer}/catalogue', [AgrodealerCatalogueController::class, 'show'])->name('agrodealers.catalogue');
        Route::put('/agrodealers/{agrodealer}/catalogue/{product}', [AgrodealerCatalogueController::class, 'update'])->name('agrodealers.catalogue.update');

        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::put('/users/{user}/password', [UserPasswordController::class, 'update'])->name('users.password');
    });
});
