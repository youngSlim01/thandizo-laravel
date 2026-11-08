<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\Admin\AgrodealerCatalogueController;
use App\Http\Controllers\Admin\AgrodealerController;
use App\Http\Controllers\Admin\AgrodealerPosController;
use App\Http\Controllers\Admin\CardController;
use App\Http\Controllers\Admin\CycleController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\HouseholdController;
use App\Http\Controllers\Admin\HouseholdImportController;
use App\Http\Controllers\Admin\PriceImportController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\ProductPriceController;
use App\Http\Controllers\Admin\ProgrammeDistrictController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\SettlementController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\UserPasswordController;
use App\Http\Controllers\Auth\FirstPasswordController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\BrandLogoController;
use App\Http\Controllers\Finance\DashboardController as FinanceDashboardController;
use App\Http\Controllers\Finance\ReportController as FinanceReportController;
use App\Http\Controllers\Finance\SettlementController as FinanceSettlementController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\Pos\PosController;
use App\Http\Controllers\TransactionController;
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
    Route::get('/brand/logos/{logo}', BrandLogoController::class)->name('brand.logo');

    Route::middleware('can:configure-installation')->prefix('admin/settings')->name('admin.settings.')->group(function () {
        Route::get('/', [SettingsController::class, 'index'])->name('index');
        Route::put('/client', [SettingsController::class, 'updateClient'])->name('client.update');
        Route::post('/logos', [SettingsController::class, 'storeLogo'])->name('logos.store');
        Route::put('/logos/{logo}/move-left', [SettingsController::class, 'moveLogo'])->name('logos.move');
        Route::delete('/logos/{logo}', [SettingsController::class, 'destroyLogo'])->name('logos.destroy');
    });
    Route::put('/account/password', [AccountController::class, 'updatePassword'])->name('account.password');

    Route::middleware('can:meal-back-office')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/cards', [CardController::class, 'index'])->name('cards.index');
        Route::post('/cards/batches', [CardController::class, 'store'])->name('cards.batches.store');
        Route::get('/cards/batches/{batch}/cards', [CardController::class, 'cards'])->whereUuid('batch')->name('cards.batches.cards');
        Route::put('/cards/batches/{batch}/printed', [CardController::class, 'printed'])->whereUuid('batch')->name('cards.batches.printed');
        Route::post('/cards/batches/{batch}/distribution', [CardController::class, 'distribute'])->whereUuid('batch')->name('cards.batches.distribute');
        Route::post('/cards/batches/{batch}/cancellation', [CardController::class, 'cancel'])->whereUuid('batch')->name('cards.batches.cancel');
        Route::get('/cards/search', [CardController::class, 'search'])->name('cards.search');
        Route::post('/cards/{voucherCode}/replacement', [CardController::class, 'replace'])->name('cards.replace');

        Route::get('/cycles', [CycleController::class, 'index'])->name('cycles.index');
        Route::post('/cycles', [CycleController::class, 'store'])->name('cycles.store');
        Route::post('/cycles/{cycle}/allocation/preview', [CycleController::class, 'previewAllocation'])->whereUuid('cycle')->name('cycles.allocation.preview');
        Route::post('/cycles/{cycle}/allocation', [CycleController::class, 'allocate'])->whereUuid('cycle')->name('cycles.allocation');
        Route::get('/cycles/{cycle}/closing', [CycleController::class, 'previewClose'])->whereUuid('cycle')->name('cycles.closing.preview');
        Route::post('/cycles/{cycle}/closing', [CycleController::class, 'close'])->whereUuid('cycle')->name('cycles.closing');

        Route::get('/dashboard', DashboardController::class)->name('dashboard');
        Route::get('/transactions', [TransactionController::class, 'index'])->defaults('area', 'meal')->name('transactions');
        Route::get('/settlements', [SettlementController::class, 'index'])->name('settlements.index');
        Route::post('/settlements', [SettlementController::class, 'store'])->name('settlements.store');
        Route::post('/settlements/{settlement}/submission', [SettlementController::class, 'submit'])->whereUuid('settlement')->name('settlements.submit');
        Route::post('/settlements/{settlement}/approval', [SettlementController::class, 'approve'])->whereUuid('settlement')->name('settlements.approve');
        Route::post('/settlements/{settlement}/return', [SettlementController::class, 'return'])->whereUuid('settlement')->name('settlements.return');
        Route::get('/reports', [ReportController::class, 'index'])->name('reports');
        Route::post('/reports/agrodealer', [ReportController::class, 'agrodealer'])->name('reports.agrodealer');
    });
    Route::middleware('can:finance')->prefix('finance')->name('finance.')->group(function () {
        Route::get('/', FinanceDashboardController::class)->name('dashboard');
        Route::get('/settlements', [FinanceSettlementController::class, 'index'])->name('settlements.index');
        Route::post('/settlements/{settlement}/validation', [FinanceSettlementController::class, 'validateSettlement'])->whereUuid('settlement')->name('settlements.validate');
        Route::post('/settlements/{settlement}/return', [FinanceSettlementController::class, 'return'])->whereUuid('settlement')->name('settlements.return');
        Route::get('/transactions', [TransactionController::class, 'index'])->defaults('area', 'finance')->name('transactions');
        Route::get('/reports', FinanceReportController::class)->name('reports');
    });

    // Leituras partilhadas pelo MEAL e pelas Finanças.
    Route::middleware('can:view-operations')->group(function () {
        Route::post('/transactions/search', [TransactionController::class, 'search'])->name('transactions.search');
        Route::get('/transactions/households/{household}', [TransactionController::class, 'household'])->name('transactions.household');
        Route::get('/settlements/{settlement}/transactions', [SettlementController::class, 'transactions'])->whereUuid('settlement')->name('settlements.transactions');
        Route::get('/cycles/{cycle}/summary', [CycleController::class, 'summary'])->whereUuid('cycle')->name('cycles.summary');
    });

    Route::middleware('can:pos')->group(function () {
        Route::post('/pos/terminal', [PosController::class, 'chooseTerminal'])->name('pos.terminal');
        Route::delete('/pos/terminal', [PosController::class, 'forgetTerminal'])->name('pos.terminal.forget');
        Route::get('/pos/products', [PosController::class, 'products'])->name('pos.products');
        Route::get('/pos/{step}', [PosController::class, 'step'])->whereIn('step', ['voucher', 'cart', 'discount', 'payment', 'confirmation'])->name('pos.step');
        Route::post('/pos/lookup', [PosController::class, 'lookup'])->name('pos.lookup');
        Route::post('/pos/preview', [PosController::class, 'preview'])->name('pos.preview');
        Route::post('/pos/sales', [PosController::class, 'confirm'])->name('pos.confirm');
        Route::get('/sales', [PosController::class, 'sales'])->name('sales');
        Route::post('/sales/{transaction}/void', [PosController::class, 'void'])->whereUuid('transaction')->name('sales.void');
        Route::get('/receipt/{transaction}', [PosController::class, 'receipt'])->whereUuid('transaction')->name('receipt');
        Route::get('/catalogue', [PosController::class, 'catalogue'])->name('catalogue');
        Route::get('/profile', [PosController::class, 'profile'])->name('profile');
    });

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

        Route::get('/households', [HouseholdController::class, 'index'])->name('households.index');
        Route::post('/households/search', [HouseholdController::class, 'search'])->name('households.search');
        Route::get('/households/geography', [HouseholdController::class, 'geography'])->name('households.geography');
        Route::post('/households/import', [HouseholdImportController::class, 'store'])->name('households.import');
        Route::get('/households/import/batches', [HouseholdImportController::class, 'batches'])->name('households.import.batches');
        Route::post('/households', [HouseholdController::class, 'store'])->name('households.store');
        Route::get('/households/{household}', [HouseholdController::class, 'show'])->name('households.show');
        Route::get('/households/{household}/history', [HouseholdController::class, 'history'])->name('households.history');
        Route::patch('/households/{household}', [HouseholdController::class, 'update'])->name('households.update');
        Route::put('/households/{household}/status', [HouseholdController::class, 'updateStatus'])->name('households.status');
        Route::delete('/households/{household}', [HouseholdController::class, 'destroy'])->name('households.destroy');

        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::put('/users/{user}/password', [UserPasswordController::class, 'update'])->name('users.password');
    });
});
