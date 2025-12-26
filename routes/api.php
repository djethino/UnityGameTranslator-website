<?php

use App\Http\Controllers\Api\DeviceFlowController;
use App\Http\Controllers\Api\GameController;
use App\Http\Controllers\Api\TranslationController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Public and authenticated API endpoints for the Unity mod.
|
*/

Route::prefix('v1')->group(function () {
    // Public endpoints (no auth required) - with rate limiting
    Route::middleware('throttle:60,1')->group(function () {
        Route::get('translations', [TranslationController::class, 'search']);
        Route::get('games', [GameController::class, 'index']);
        Route::get('games/{game}', [GameController::class, 'show']);
    });

    // Check endpoint - higher limit for polling
    Route::get('translations/{translation}/check', [TranslationController::class, 'check'])
        ->middleware('throttle:120,1');

    // Download endpoint - lower limit
    Route::get('translations/{translation}/download', [TranslationController::class, 'download'])
        ->middleware('throttle:30,1');

    // Device Flow authentication (public) - polling limit
    Route::post('auth/device', [DeviceFlowController::class, 'initiate'])
        ->middleware('throttle:10,1');
    Route::post('auth/device/poll', [DeviceFlowController::class, 'poll'])
        ->middleware('throttle:12,1');

    // Authenticated endpoints (require API token + not banned)
    Route::middleware(['auth.api', 'check.banned.api', 'throttle:60,1'])->group(function () {
        Route::get('me', [UserController::class, 'me']);
        Route::get('me/translations', [UserController::class, 'translations']);
        Route::post('translations', [TranslationController::class, 'store'])
            ->middleware('throttle:10,1'); // Stricter limit for uploads
        Route::delete('auth/token', [DeviceFlowController::class, 'revoke']);
    });
});
