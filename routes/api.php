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
    // ===========================================
    // PUBLIC ENDPOINTS (anonymous users)
    // Can: browse, download translations
    // ===========================================
    Route::middleware('throttle:60,1')->group(function () {
        // Browse translations and games
        Route::get('translations', [TranslationController::class, 'search']);
        Route::get('games', [GameController::class, 'index']);
        Route::get('games/{game}', [GameController::class, 'show']);
    });

    // Check if translation updated - higher limit for polling
    Route::get('translations/{translation}/check', [TranslationController::class, 'check'])
        ->middleware('throttle:120,1');

    // Download translation file
    Route::get('translations/{translation}/download', [TranslationController::class, 'download'])
        ->middleware('throttle:30,1');

    // ===========================================
    // DEVICE FLOW AUTHENTICATION (public)
    // ===========================================
    Route::post('auth/device', [DeviceFlowController::class, 'initiate'])
        ->middleware('throttle:10,1');
    Route::post('auth/device/poll', [DeviceFlowController::class, 'poll'])
        ->middleware('throttle:12,1');

    // ===========================================
    // AUTHENTICATED ENDPOINTS
    // Can: upload translations, search external games
    // ===========================================
    Route::middleware(['auth.api', 'check.banned.api', 'throttle:60,1'])->group(function () {
        // User info
        Route::get('me', [UserController::class, 'me']);
        Route::get('me/translations', [UserController::class, 'translations']);

        // Game search (uses external APIs - RAWG quota limited)
        Route::get('games/search', [GameController::class, 'search']);

        // Check if UUID exists before upload (to detect UPDATE/FORK/NEW)
        Route::get('translations/check-uuid', [TranslationController::class, 'checkUuid']);

        // Upload translation
        Route::post('translations', [TranslationController::class, 'store'])
            ->middleware('throttle:10,1');

        // Revoke token
        Route::delete('auth/token', [DeviceFlowController::class, 'revoke']);
    });
});
