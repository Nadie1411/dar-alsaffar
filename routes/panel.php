<?php

use App\Http\Controllers\Panel\ActivityLogController;
use App\Http\Controllers\Panel\AddonController;
use App\Http\Controllers\Panel\CategoryController;
use App\Http\Controllers\Panel\ContentController;
use App\Http\Controllers\Panel\CustomerController;
use App\Http\Controllers\Panel\DashboardController;
use App\Http\Controllers\Panel\DeliveryController;
use App\Http\Controllers\Panel\GatewayController;
use App\Http\Controllers\Panel\InboxController;
use App\Http\Controllers\Panel\LanguageController;
use App\Http\Controllers\Panel\LoginController;
use App\Http\Controllers\Panel\OrderController;
use App\Http\Controllers\Panel\OrderFeedController;
use App\Http\Controllers\Panel\PaymentController;
use App\Http\Controllers\Panel\ProductController;
use App\Http\Controllers\Panel\ProfileController;
use App\Http\Controllers\Panel\ReportController;
use App\Http\Controllers\Panel\StaffController;
use App\Http\Controllers\Panel\VoucherController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin panel
|--------------------------------------------------------------------------
|
| Where the shop team runs the store once it lives on its own database. The
| whole prefix answers 404 while the storefront still runs on Overzaki, and
| every page behind the login is limited to what the signed-in role may open
| (see App\Enums\AdminRole).
|
*/

Route::prefix('panel')->name('panel.')->middleware(['panel.enabled', 'panel.locale'])->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->name('login.store')->middleware('throttle:20,1');
    Route::get('/language/{locale}', LanguageController::class)->where('locale', 'ar|en')->name('language');

    Route::middleware('panel.auth')->group(function () {
        Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

        Route::get('/', DashboardController::class)->name('dashboard');

        // Everyone who can sign in may change their own name, language and password.
        Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
        Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');

        // ---- orders ---------------------------------------------------------
        Route::middleware('panel.module:orders')->prefix('orders')->name('orders.')->group(function () {
            Route::get('/', [OrderController::class, 'index'])->name('index');
            Route::get('/export', [OrderController::class, 'export'])->name('export');
            Route::get('/feed', OrderFeedController::class)->name('feed');
            Route::get('/{order}', [OrderController::class, 'show'])->name('show');
            Route::get('/{order}/print', [OrderController::class, 'print'])->name('print');
            Route::post('/{order}/status', [OrderController::class, 'status'])->name('status');
            Route::post('/{order}/note', [OrderController::class, 'note'])->name('note');
            Route::post('/{order}/paid', [OrderController::class, 'markPaid'])->name('paid');
            Route::post('/{order}/refunded', [OrderController::class, 'markRefunded'])->name('refunded');
        });

        // ---- catalogue ------------------------------------------------------
        Route::middleware('panel.module:products')->group(function () {
            Route::post('/products/{product}/toggle', [ProductController::class, 'toggle'])->name('products.toggle');
            Route::post('/products/{product}/duplicate', [ProductController::class, 'duplicate'])->name('products.duplicate');
            Route::resource('products', ProductController::class)->except('show');
        });

        Route::middleware('panel.module:categories')->group(function () {
            Route::resource('categories', CategoryController::class)->except('show');
        });

        Route::middleware('panel.module:addons')->group(function () {
            Route::resource('addons', AddonController::class)->except('show');
        });

        // ---- the store ------------------------------------------------------
        Route::middleware('panel.module:vouchers')->group(function () {
            Route::resource('vouchers', VoucherController::class)->except('show');
        });

        Route::middleware('panel.module:delivery')->prefix('delivery')->name('delivery.')->group(function () {
            Route::get('/', [DeliveryController::class, 'index'])->name('index');
            Route::put('/settings', [DeliveryController::class, 'updateSettings'])->name('settings');
            Route::post('/cities', [DeliveryController::class, 'storeCity'])->name('cities.store');
            Route::get('/cities/{city}', [DeliveryController::class, 'editCity'])->name('cities.edit');
            Route::put('/cities/{city}', [DeliveryController::class, 'updateCity'])->name('cities.update');
            Route::post('/cities/{city}/fee', [DeliveryController::class, 'setCityFee'])->name('cities.fee');
            Route::delete('/cities/{city}', [DeliveryController::class, 'destroyCity'])->name('cities.destroy');
        });

        // The payment keys: owners only (see PanelModule::Gateway).
        Route::middleware('panel.module:gateway')->prefix('payments/gateway')->name('payments.gateway.')->group(function () {
            Route::get('/', [GatewayController::class, 'edit'])->name('edit');
            Route::put('/', [GatewayController::class, 'update'])->middleware('throttle:10,1')->name('update');
        });

        Route::middleware('panel.module:payments')->prefix('payments')->name('payments.')->group(function () {
            Route::get('/', [PaymentController::class, 'index'])->name('index');
            Route::put('/settings', [PaymentController::class, 'updateSettings'])->name('settings');
            Route::post('/test', [PaymentController::class, 'testConnection'])->name('test');
            Route::post('/{payment}/verify', [PaymentController::class, 'verify'])->name('verify');
            Route::post('/{payment}/review', [PaymentController::class, 'review'])->name('review');
        });

        // ---- customers and what they send ----------------------------------
        Route::middleware('panel.module:customers')->prefix('customers')->name('customers.')->group(function () {
            Route::get('/', [CustomerController::class, 'index'])->name('index');
            Route::get('/export', [CustomerController::class, 'export'])->name('export');
            Route::get('/{customer}', [CustomerController::class, 'show'])->name('show');
        });

        Route::middleware('panel.module:inbox')->prefix('inbox')->name('inbox.')->group(function () {
            Route::get('/', [InboxController::class, 'index'])->name('index');
            Route::get('/subscribers', [InboxController::class, 'subscribers'])->name('subscribers');
            Route::get('/subscribers/export', [InboxController::class, 'exportSubscribers'])->name('subscribers.export');
            Route::delete('/subscribers/{subscriber}', [InboxController::class, 'destroySubscriber'])->name('subscribers.destroy');
            Route::get('/{message}', [InboxController::class, 'show'])->name('show');
            Route::post('/{message}/read', [InboxController::class, 'toggleRead'])->name('read');
            Route::delete('/{message}', [InboxController::class, 'destroy'])->name('destroy');
        });

        // ---- site content ---------------------------------------------------
        Route::middleware('panel.module:content')->prefix('content')->name('content.')->group(function () {
            Route::get('/', [ContentController::class, 'edit'])->name('edit');
            Route::put('/', [ContentController::class, 'update'])->name('update');
        });

        // ---- reports ---------------------------------------------------------
        Route::middleware('panel.module:reports')->prefix('reports')->name('reports.')->group(function () {
            Route::get('/', [ReportController::class, 'index'])->name('index');
            Route::get('/export', [ReportController::class, 'export'])->name('export');
        });

        // ---- team ---------------------------------------------------------------
        Route::middleware('panel.module:staff')->group(function () {
            Route::resource('staff', StaffController::class)->except('show')->parameters(['staff' => 'member']);
        });

        Route::middleware('panel.module:log')->get('/log', [ActivityLogController::class, 'index'])->name('log.index');
    });
});
