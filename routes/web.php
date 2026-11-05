<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\Admin\AgrodealerCatalogueController;
use App\Http\Controllers\Admin\AgrodealerController;
use App\Http\Controllers\Admin\AgrodealerPosController;
use App\Http\Controllers\Admin\HouseholdController;
use App\Http\Controllers\Admin\HouseholdImportController;
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
use App\Http\Controllers\Rpc\CardRpcController;
use App\Http\Controllers\Rpc\CycleRpcController;
use App\Http\Controllers\Rpc\HouseholdRpcController;
use App\Http\Controllers\Rpc\PosRpcController;
use App\Http\Controllers\Rpc\ReportRpcController;
use App\Http\Controllers\Rpc\SettlementRpcController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Inertia\Inertia;

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

    // Endpoints das antigas funções SQL usadas pelos ecrãs vindos do Next.js:
    // POST /rpc/<nome>, mesmos parâmetros sem p_ (ver Rpc\RpcController).
    Route::prefix('rpc')->group(function () {
        $rpc = [
            CardRpcController::class => [
                'list_card_communities', 'generate_card_batch', 'list_card_batches', 'get_card_batch_cards',
                'mark_card_batch_printed', 'distribute_cards', 'cancel_card_batch', 'find_household_cards', 'replace_voucher',
            ],
            PosRpcController::class => [
                'lookup_voucher_for_pos', 'get_agrodealer_catalogue', 'preview_voucher_redemption', 'confirm_pos_redemption',
                'get_transaction_receipt', 'list_my_recent_transactions', 'void_voucher_transaction',
            ],
            CycleRpcController::class => [
                'list_programme_cycles', 'cycle_summary', 'open_programme_cycle', 'allocate_cycle', 'close_programme_cycle',
            ],
            SettlementRpcController::class => [
                'list_settlements', 'finance_list_settlements', 'get_settlement_transactions', 'create_agrodealer_settlement',
                'submit_agrodealer_settlement', 'manager_approve_settlement', 'manager_return_settlement',
                'finance_validate_settlement', 'finance_return_settlement',
            ],
            HouseholdRpcController::class => ['search_households', 'list_household_geography', 'get_household_detail'],
            ReportRpcController::class => [
                'list_agrodealer_options', 'search_transactions', 'agrodealer_transactions_report', 'list_report_prints',
                'meal_dashboard_month', 'finance_dashboard_month',
            ],
        ];
        foreach ($rpc as $controller => $names) {
            foreach ($names as $name) {
                Route::post("/{$name}", [$controller, Str::camel($name)])->name("rpc.{$name}");
            }
        }
    });

    // Ecrãs vindos do Next.js (resources/js/legacy), por área.
    Route::middleware('can:meal-back-office')->prefix('admin')->group(function () {
        foreach (['dashboard', 'cards', 'cycles', 'transactions', 'settlements', 'reports'] as $screen) {
            Route::get("/{$screen}", fn () => Inertia::render("legacy/app/admin/{$screen}/page"))->name("admin.{$screen}");
        }
    });
    Route::middleware('can:finance')->prefix('finance')->group(function () {
        Route::get('/', fn () => Inertia::render('legacy/app/finance/page'))->name('finance');
        foreach (['settlements', 'transactions', 'reports'] as $screen) {
            Route::get("/{$screen}", fn () => Inertia::render("legacy/app/finance/{$screen}/page"))->name("finance.{$screen}");
        }
    });
    Route::middleware('can:pos')->group(function () {
        foreach (['voucher', 'products', 'cart', 'discount', 'payment', 'confirmation'] as $step) {
            Route::get("/pos/{$step}", fn () => Inertia::render("legacy/app/pos/{$step}/page"))->name("pos.{$step}");
        }
        Route::get('/sales', fn () => Inertia::render('legacy/app/sales/page'))->name('sales');
        Route::get('/catalogue', fn () => Inertia::render('legacy/app/catalogue/page'))->name('catalogue');
        Route::get('/profile', fn () => Inertia::render('legacy/app/profile/page'))->name('profile');
        Route::get('/receipt/{id}', fn (string $id) => Inertia::render('legacy/app/receipt/[id]/page', ['params' => ['id' => $id]]))
            ->whereUuid('id')
            ->name('receipt');
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
