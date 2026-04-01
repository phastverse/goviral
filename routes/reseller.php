<?php

use App\Http\Controllers\Reseller;
use Illuminate\Support\Facades\Route;

Route::middleware(['reseller.panel'])->group(function () {

    Route::get('/', function () {
        $reseller = app('current_reseller');
        return view('reseller.welcome', compact('reseller'));
    })->name('reseller.welcome');

    Route::get('/login',     [Reseller\ResellerAuthController::class, 'showLogin'])->name('reseller.login');
    Route::post('/login',    [Reseller\ResellerAuthController::class, 'login'])->name('reseller.login.post');
    Route::get('/register',  [Reseller\ResellerAuthController::class, 'showRegister'])->name('reseller.register');
    Route::post('/register', [Reseller\ResellerAuthController::class, 'register'])->name('reseller.register.post');
    Route::post('/logout',   [Reseller\ResellerAuthController::class, 'logout'])->name('reseller.logout');

    Route::middleware(['auth', 'reseller.customer'])->group(function () {

        Route::get('/dashboard', [Reseller\ResellerDashboardController::class, 'index'])->name('reseller.dashboard');

        Route::get('/orders',             [Reseller\ResellerOrderController::class, 'index'])->name('reseller.orders.index');
        Route::get('/orders/new',         [Reseller\ResellerOrderController::class, 'create'])->name('reseller.order.create');
        Route::post('/orders',            [Reseller\ResellerOrderController::class, 'store'])->name('reseller.order.store');
        Route::get('/orders/{id}/status', [Reseller\ResellerOrderController::class, 'checkStatus'])->name('reseller.order.status');
        Route::get('/orders/{id}/refill', [Reseller\ResellerOrderController::class, 'requestRefill'])->name('reseller.order.refill');

        Route::get('/wallet',          [Reseller\ResellerWalletController::class, 'index'])->name('reseller.wallet.index');
        Route::post('/wallet',         [Reseller\ResellerWalletController::class, 'store'])->name('reseller.wallet.store');
        Route::get('/wallet/callback', [Reseller\ResellerWalletController::class, 'callback'])->name('reseller.wallet.callback');

        Route::get('/profile',  [Reseller\ResellerProfileController::class, 'index'])->name('reseller.profile.index');
        Route::post('/profile', [Reseller\ResellerProfileController::class, 'update'])->name('reseller.profile.update');

        Route::prefix('manage')->name('reseller.manage.')->group(function () {
            Route::get('/settings',  [Reseller\ResellerManageController::class, 'settings'])->name('settings');
            Route::post('/settings', [Reseller\ResellerManageController::class, 'updateSettings'])->name('settings.update');
            Route::get('/services',  [Reseller\ResellerManageController::class, 'services'])->name('services');
            Route::post('/services', [Reseller\ResellerManageController::class, 'updateServiceMarkup'])->name('services.update');
            Route::get('/customers', [Reseller\ResellerManageController::class, 'customers'])->name('customers');
            Route::get('/revenue',   [Reseller\ResellerManageController::class, 'revenue'])->name('revenue');
        });
    });
});