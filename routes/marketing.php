<?php

use App\Http\Controllers\CampaignAdminController;
use App\Http\Controllers\MarketingController;
use Illuminate\Support\Facades\Route;

Route::middleware('web')->group(function () {
    Route::get('/unsubscribe/{token}', [MarketingController::class, 'unsubscribe'])
        ->name('marketing.unsubscribe');

    Route::middleware('auth')->prefix('account/marketing')->group(function () {
        Route::get('/', [MarketingController::class, 'preferences']);
        Route::post('/request', [MarketingController::class, 'requestConsent']);
        Route::post('/confirm', [MarketingController::class, 'confirm']);
        Route::post('/withdraw', [MarketingController::class, 'withdraw']);
    });

    Route::middleware(['auth', 'private.access', 'admin.communications'])->prefix('admin/campaigns')->group(function () {
        Route::get('/', [CampaignAdminController::class, 'index']);
        Route::post('/', [CampaignAdminController::class, 'store']);
        Route::get('/{campaign}', [CampaignAdminController::class, 'show']);
        Route::post('/{campaign}/start', [CampaignAdminController::class, 'start'])->middleware('recent.auth');
        Route::post('/{campaign}/resume', [CampaignAdminController::class, 'start'])->middleware('recent.auth');
        Route::post('/{campaign}/pause', [CampaignAdminController::class, 'pause']);
        Route::post('/{campaign}/recipients/{recipient}/reconcile', [CampaignAdminController::class, 'reconcileRecipient'])
            ->middleware('recent.auth');
    });
});
