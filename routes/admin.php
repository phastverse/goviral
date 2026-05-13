<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\OrderController;
use App\Http\Controllers\Admin\WalletController;
use App\Http\Controllers\Admin\SupportController;
use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Admin\ProfileController;
use App\Http\Controllers\Admin\PricingConfigController;
use App\Http\Controllers\Admin\AdminResellerController;
use App\Http\Controllers\Admin\ProviderController;
use App\Http\Controllers\Admin\ExchangeRateController;

Route::prefix('admin')->name('admin.')->group(function () {
    
    // Guest routes (login)
    Route::middleware('guest:admin')->group(function () {
        Route::get('/login', [AuthController::class, 'showLoginForm'])->name('login');
        Route::post('/login', [AuthController::class, 'login'])->name('login.post');
    });

    // Authenticated admin routes
    Route::middleware('admin')->group(function () {
        
        // Dashboard
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
        
        // Logout
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
        
        // Customers Management
        Route::prefix('customers')->name('customers.')->group(function () {
            Route::get('/', [CustomerController::class, 'index'])->name('index');
            Route::get('/{id}', [CustomerController::class, 'show'])->name('show');
            Route::get('/{id}/edit', [CustomerController::class, 'edit'])->name('edit');
            Route::put('/{id}', [CustomerController::class, 'update'])->name('update');
            Route::post('/{id}/adjust-balance', [CustomerController::class, 'adjustBalance'])->name('adjust-balance');
        });

        // Order Management
        Route::prefix('orders')->name('orders.')->group(function () {
            Route::get('/', [OrderController::class, 'index'])->name('index');
            Route::get('/{id}', [OrderController::class, 'show'])->name('show');
            Route::post('/{id}/check-status', [OrderController::class, 'checkStatus'])->name('check-status');
            Route::post('/{id}/update-status', [OrderController::class, 'updateStatus'])->name('update-status');
            Route::post('/{id}/refund', [OrderController::class, 'refund'])->name('refund');
            Route::delete('/{id}', [OrderController::class, 'destroy'])->name('destroy');
        });

        // Wallet Management
        Route::prefix('wallet')->name('wallet.')->group(function () {
            Route::get('/', [WalletController::class, 'index'])->name('index');
            Route::get('/{id}', [WalletController::class, 'show'])->name('show');
            Route::post('/{id}/approve', [WalletController::class, 'approve'])->name('approve');
            Route::post('/{id}/reject', [WalletController::class, 'reject'])->name('reject');
            Route::delete('/{id}', [WalletController::class, 'destroy'])->name('destroy');
        });

        Route::prefix('settings')->name('settings.')->group(function () {
            Route::get('/pricing', [PricingConfigController::class, 'index'])->name('pricing.index');
            Route::post('/pricing', [PricingConfigController::class, 'update'])->name('pricing.update');
        });

        
        Route::prefix('support')->name('support.')->group(function () {
            Route::get('/', [SupportController::class, 'index'])->name('index');
            Route::get('/{id}', [SupportController::class, 'show'])->name('show');
            Route::post('/{id}/reply', [SupportController::class, 'reply'])->name('reply');
            Route::post('/{id}/status', [SupportController::class, 'updateStatus'])->name('status');
            Route::post('/{id}/close', [SupportController::class, 'close'])->name('close');
            Route::post('/{id}/reopen', [SupportController::class, 'reopen'])->name('reopen');
            Route::delete('/{id}', [SupportController::class, 'destroy'])->name('destroy');
            
            // AJAX route for fetching messages
            Route::get('/{id}/fetch-messages', [SupportController::class, 'fetchMessages'])->name('fetch-messages');
        });
        // Admins Management (Super Admin & HR Only)
        Route::prefix('admins')->name('admins.')->group(function () {
            Route::get('/', [AdminController::class, 'index'])->name('index');
            Route::get('/create', [AdminController::class, 'create'])->name('create');
            Route::post('/', [AdminController::class, 'store'])->name('store');
            Route::get('/logs', [AdminController::class, 'logs'])->name('logs');
            Route::get('/{id}', [AdminController::class, 'show'])->name('show');
            Route::get('/{id}/edit', [AdminController::class, 'edit'])->name('edit');
            Route::put('/{id}', [AdminController::class, 'update'])->name('update');
            Route::delete('/{id}', [AdminController::class, 'destroy'])->name('destroy');
        });

        Route::prefix('profile')->name('profile.')->group(function () {
            // View profile
            Route::get('/', [ProfileController::class, 'show'])->name('show');
            
            // Update profile information
            Route::put('/update', [ProfileController::class, 'update'])->name('update');
            
            // Update password
            Route::put('/update-password', [ProfileController::class, 'updatePassword'])->name('update-password');
        });

    Route::prefix('referral')->name('referral.')->group(function () {
        Route::prefix('withdrawals')->name('withdrawals.')->group(function () {
            Route::get('/', [App\Http\Controllers\Admin\ReferralWithdrawalController::class, 'index'])->name('index');
            Route::get('/{id}', [App\Http\Controllers\Admin\ReferralWithdrawalController::class, 'show'])->name('show');
            Route::post('/{id}/approve-wallet', [App\Http\Controllers\Admin\ReferralWithdrawalController::class, 'approveWallet'])->name('approve-wallet');
            Route::post('/{id}/approve-bank', [App\Http\Controllers\Admin\ReferralWithdrawalController::class, 'approveBank'])->name('approve-bank');
            Route::post('/{id}/reject', [App\Http\Controllers\Admin\ReferralWithdrawalController::class, 'reject'])->name('reject');
        });
    });


        Route::prefix('resellers')->name('resellers.')->group(function () {
            Route::get('/',                     [AdminResellerController::class, 'index'])->name('index');
            Route::get('/create',               [AdminResellerController::class, 'create'])->name('create');
            Route::post('/',                    [AdminResellerController::class, 'store'])->name('store');
            Route::get('/{reseller}',           [AdminResellerController::class, 'show'])->name('show');
            Route::patch('/{reseller}/status',  [AdminResellerController::class, 'updateStatus'])->name('status'); 
            Route::patch('/{reseller}/approve', [AdminResellerController::class, 'approve'])->name('approve');
            Route::patch('/{reseller}/reject',  [AdminResellerController::class, 'reject'])->name('reject');
            Route::get('/{reseller}/customers', [AdminResellerController::class, 'customers'])->name('customers');
            Route::get('/{reseller}/orders',    [AdminResellerController::class, 'orders'])->name('orders');
            Route::get('/{reseller}/wallet',    [AdminResellerController::class, 'wallet'])->name('wallet');
        });

            Route::prefix('providers')->name('providers.')->group(function () {
                Route::get('/',                         [ProviderController::class, 'index'])->name('index');
                Route::get('/create',                   [ProviderController::class, 'create'])->name('create');
                Route::post('/',                        [ProviderController::class, 'store'])->name('store');
                Route::get('/{provider}/edit',          [ProviderController::class, 'edit'])->name('edit');
                Route::put('/{provider}',               [ProviderController::class, 'update'])->name('update');
                Route::delete('/{provider}',            [ProviderController::class, 'destroy'])->name('destroy');
                Route::post('/{provider}/toggle',       [ProviderController::class, 'toggle'])->name('toggle');
                Route::post('/{provider}/refresh-balance', [ProviderController::class, 'refreshBalance'])->name('refresh-balance');
                Route::post('/refresh-all-balances',    [ProviderController::class, 'refreshAllBalances'])->name('refresh-all');
            });

        Route::prefix('exchange-rates')->name('exchange-rates.')->group(function () {
            Route::get('/',           [ExchangeRateController::class, 'index'])->name('index');
            Route::post('/refresh',   [ExchangeRateController::class, 'refresh'])->name('refresh');
            Route::post('/refresh-all', [ExchangeRateController::class, 'refreshAll'])->name('refresh-all');
        });
        
    });
});
