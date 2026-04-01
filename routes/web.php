<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\WalletController;
use App\Http\Controllers\DashboardController; 
use App\Http\Controllers\OrderController; 
use App\Http\Controllers\SupportController; 
use App\Http\Controllers\ReferralController; 
use App\Http\Controllers\ApiKeyController; 
use App\Http\Controllers\ResellerPanelController;
use App\Http\Controllers\ResellerServiceController;
use App\Http\Controllers\Reseller;

// RESELLER SUBDOMAIN ROUTES - Must come first
// Route::domain('{subdomain}.' . config('app.base_domain', 'lvh.me'))->group(function () {
//     // Load all reseller routes
//     require base_path('routes/reseller.php');
// });

Route::domain(config('app.base_domain', 'boosterr.xyz'))->group(function () {

Route::get('/', function () {
    return view('welcome');
})->name('welcome');

// Legal & Info Pages
Route::get('/refund-policy', function () {
    return view('legal.refund-policy');
})->name('refund-policy');

Route::get('/terms-of-use', function () {
    return view('legal.terms-of-use');
})->name('terms-of-use');

Route::get('/faq', function () {
    return view('legal.faq');
})->name('faq');

Route::get('/api/docs', fn() => view('api.docs'))->name('api.docs');
Route::get('/api/test', fn() => view('api.test-api'))->name('api.test');

// Korapay Webhook
Route::post('/webhook/korapay', [App\Http\Controllers\KorapayWebhookController::class, 'handleWebhook'])
    ->name('wallet.webhook');

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('/wallet', [WalletController::class, 'index'])->name('wallet.index');
    Route::post('/wallet/topup', [WalletController::class, 'store'])->name('wallet.topup');
    Route::get('/wallet/callback', [WalletController::class, 'callback'])->name('wallet.callback');

    Route::get('/orders/new', [OrderController::class, 'create'])->name('order.create');
    Route::post('/orders', [OrderController::class, 'store'])->name('order.store');
    
    // Order History
    Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
    
    // Order Status & Management
    Route::get('/orders/{order}/check-status', [OrderController::class, 'checkStatus'])
        ->name('orders.check-status');
    
    Route::post('/orders/{order}/request-refill', [OrderController::class, 'requestRefill'])
        ->name('order.request-refill');

    // User Support Routes
    Route::prefix('support')->name('support.')->group(function () {
        Route::get('/', [SupportController::class, 'index'])->name('index');
        Route::get('/create', [SupportController::class, 'create'])->name('create');
        Route::post('/store', [SupportController::class, 'store'])->name('store');
        Route::get('/{id}', [SupportController::class, 'show'])->name('show');
        Route::post('/{id}/reply', [SupportController::class, 'reply'])->name('reply');
        
        // AJAX route for fetching messages
        Route::get('/{id}/fetch-messages', [SupportController::class, 'fetchMessages'])->name('fetch-messages');
    });

    Route::prefix('referral')->name('referral.')->group(function () {
        Route::get('/', [ReferralController::class, 'index'])->name('index');
        Route::get('/withdraw', [ReferralController::class, 'withdraw'])->name('withdraw');
        Route::post('/withdraw/wallet', [ReferralController::class, 'withdrawToWallet'])->name('withdraw.wallet');
        Route::post('/withdraw/bank', [ReferralController::class, 'withdrawToBank'])->name('withdraw.bank');
    });

    // Profile & Settings
    Route::get('/profile', [ProfileController::class, 'index'])->name('profile.index');
    Route::post('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::post('/profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');
    Route::get('/notifications/settings', [ProfileController::class, 'notifications'])->name('notifications.settings');
    Route::put('/notifications/read', function () {
        if (auth()->check()) {
            auth()->user()->unreadNotifications->markAsRead();
        }
        return redirect()->back();
    })->name('notifications.mark.read');

        // Reseller Panel Management (user creates/manages their own panel)
    Route::prefix('reseller-panel')->name('reseller-panel.')->group(function () {
        Route::get('/',        [ResellerPanelController::class, 'index'])->name('index');
        Route::get('/create',  [ResellerPanelController::class, 'create'])->name('create');
         Route::get('/services', [ResellerServiceController::class, 'services'])->name('services');
        Route::post('/services', [ResellerServiceController::class, 'updateServiceMarkup'])->name('services.update');
        Route::post('/create', [ResellerPanelController::class, 'store'])->name('store');
        Route::post('/update', [ResellerPanelController::class, 'update'])->name('update');
        Route::put('/reseller-panel/domain', [ResellerPanelController::class, 'updateDomain'])->name('update-domain');
        Route::get('/reseller-panel/verify-domain', [ResellerPanelController::class, 'verifyDomain'])->name('verify-domain');
    });

    Route::get('/api-access', [ApiKeyController::class, 'index'])->name('api.index');
    Route::post('/api-access/generate', [ApiKeyController::class, 'generate'])->name('api.generate');
    Route::post('/api-access/{apiKey}/toggle', [ApiKeyController::class, 'toggle'])->name('api.toggle');
    Route::delete('/api-access/{apiKey}', [ApiKeyController::class, 'destroy'])->name('api.destroy');

});


require __DIR__.'/auth.php';

});
