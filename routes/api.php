<?php

use App\Http\Controllers\Api\DeviceFlowController;
use App\Http\Controllers\Api\GameController;
use App\Http\Controllers\Api\MergePreviewController;
use App\Http\Controllers\Api\SyncStateController;
use App\Http\Controllers\Api\TranslationController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Public and authenticated API endpoints for the Unity mod.
| SSE streams are served by the Node.js micro-server (sse-server/).
|
*/

Route::prefix('v1')->group(function () {
    // ===========================================
    // GAME SEARCH (authenticated - uses external APIs with quota)
    // MUST be defined before games/{game} to avoid route conflict
    // ===========================================
    Route::get('games/search', [GameController::class, 'search'])
        ->middleware(['auth.api', 'check.banned.api', 'throttle:60,1']);

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

    // Check if translation updated (used for ETag-based cache validation)
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

    // ===========================================
    // AUTHENTICATED ENDPOINTS
    // Can: upload translations, check sync state
    // ===========================================
    Route::middleware(['auth.api', 'check.banned.api', 'throttle:60,1'])->group(function () {
        // Sync state — called by Node.js SSE server on each client connection
        Route::get('sync/state', [SyncStateController::class, 'show']);

        // User info
        Route::get('me', [UserController::class, 'me']);
        Route::get('me/translations', [UserController::class, 'translations']);

        // Check if UUID exists before upload (to detect UPDATE/FORK/NEW)
        Route::get('translations/check-uuid', [TranslationController::class, 'checkUuid']);

        // List branches for a Main translation (owner only)
        Route::get('translations/{uuid}/branches', [TranslationController::class, 'branches']);

        // Upload translation
        Route::post('translations', [TranslationController::class, 'store'])
            ->middleware('throttle:10,1');

        // Initialize merge preview (mod sends local content, gets URL to open)
        Route::post('merge-preview/init', [MergePreviewController::class, 'init'])
            ->middleware('throttle:10,1');

        // Vote on translation
        Route::post('translations/{translation}/vote', [TranslationController::class, 'vote'])
            ->middleware('throttle:30,1');

        // Revoke token
        Route::delete('auth/token', [DeviceFlowController::class, 'revoke']);
    });
});
