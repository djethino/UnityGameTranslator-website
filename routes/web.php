<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\Api\DeviceFlowController;
use App\Http\Controllers\Auth\SocialController;
use App\Http\Controllers\GameController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\MergeController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\TranslationController;
use App\Http\Controllers\VoteController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

// Sitemap (no locale prefix)
Route::get('/sitemap.xml', [SitemapController::class, 'index'])->name('sitemap');

// Language switcher
Route::get('/locale/{locale}', [LocaleController::class, 'switch'])->name('locale.switch');

// OAuth (no locale prefix - callbacks must be predictable)
Route::get('/auth/{provider}', [SocialController::class, 'redirect'])->name('auth.redirect');
Route::get('/auth/{provider}/callback', [SocialController::class, 'callback'])->name('auth.callback');
Route::post('/logout', function () {
    $userId = Auth::id();
    Auth::logout();
    request()->session()->invalidate();
    request()->session()->regenerateToken();
    if ($userId) {
        \App\Models\AuditLog::logLogout($userId);
    }
    return redirect('/');
})->name('logout');

// Device Flow link page (no locale prefix - mod uses fixed URL)
Route::get('/link', [DeviceFlowController::class, 'showLinkPage'])->name('link');
Route::post('/link', [DeviceFlowController::class, 'validateCode'])->middleware(['auth', 'throttle:10,1'])->name('link.validate');

// API routes (no locale prefix)
Route::get('/api/games/search', [GameController::class, 'search'])->name('games.search');
Route::get('/api/games/search-external', [GameController::class, 'searchExternal'])->name('games.search.external');

// Download (no locale prefix - direct file access)
Route::get('/download/{translation}', [TranslationController::class, 'download'])->name('translations.download');

// Merge preview - supports token-based auth from mod
Route::get('/translations/{translation}/merge-preview', [TranslationController::class, 'mergePreview'])->name('translations.merge-preview');

/*
|--------------------------------------------------------------------------
| Localized Public Routes
|--------------------------------------------------------------------------
| These routes support optional locale prefix: /, /en/, /fr/, etc.
| The SetLocale middleware handles locale detection from URL prefix.
*/
$localizableRoutes = function () {
    // Home
    Route::get('/', [HomeController::class, 'index'])->name('home');

    // Login
    Route::get('/login', function () {
        return view('auth.login');
    })->name('login');

    // Documentation
    Route::get('/docs', function () {
        return view('docs.index');
    })->name('docs');

    // Legal pages
    Route::get('/legal', function () {
        return view('legal.mentions');
    })->name('legal.mentions');
    Route::get('/privacy', function () {
        return view('legal.privacy');
    })->name('legal.privacy');
    Route::get('/terms', function () {
        return view('legal.terms');
    })->name('legal.terms');

    // Games
    Route::get('/games', [GameController::class, 'index'])->name('games.index');
    Route::get('/games/{game}', [GameController::class, 'show'])->name('games.show');
};

// Routes without locale prefix (default locale detection)
Route::group([], $localizableRoutes);

// Routes with locale prefix (/en/, /fr/, /de/, etc.)
Route::group([
    'prefix' => '{locale}',
    'where' => ['locale' => implode('|', array_keys(config('locales.supported', ['en' => []])))]
], $localizableRoutes);

/*
|--------------------------------------------------------------------------
| Authenticated Routes (no locale prefix for simplicity)
|--------------------------------------------------------------------------
*/
Route::middleware('auth')->group(function () {
    Route::get('/upload', [TranslationController::class, 'create'])->name('translations.create');
    Route::post('/upload', [TranslationController::class, 'store'])->name('translations.store');
    Route::get('/api/translations/check-uuid', [TranslationController::class, 'checkUuid'])->name('translations.check-uuid');
    Route::get('/my-translations', [TranslationController::class, 'myTranslations'])->name('translations.mine');
    Route::get('/my-translations/{translation}/dashboard', [TranslationController::class, 'dashboard'])->name('translations.dashboard');
    Route::post('/my-translations/{translation}/convert-to-fork', [TranslationController::class, 'convertToFork'])->name('translations.convert-to-fork');
    Route::get('/translations/{translation}/edit', [TranslationController::class, 'edit'])->name('translations.edit');
    Route::put('/translations/{translation}', [TranslationController::class, 'update'])->name('translations.update');
    Route::delete('/translations/{translation}', [TranslationController::class, 'destroy'])->name('translations.destroy');
    Route::post('/translations/{translation}/merge-preview', [TranslationController::class, 'applyMergePreview'])->name('translations.merge-preview.apply');

    // Profile
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::get('/profile/export', [ProfileController::class, 'export'])->name('profile.export');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Reports
    Route::post('/report/{translation}', [ReportController::class, 'store'])->name('reports.store');

    // Votes
    Route::post('/vote/{translation}', [VoteController::class, 'vote'])->name('votes.store');

    // Merge View (Main owner only)
    Route::get('/translations/{uuid}/merge', [MergeController::class, 'show'])->name('translations.merge');
    Route::post('/translations/{uuid}/merge', [MergeController::class, 'apply'])->name('translations.merge.apply');
    Route::post('/translations/{translation}/rate-branch', [MergeController::class, 'rateBranch'])->name('translations.rate-branch');
});

// Admin routes
Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [AdminController::class, 'dashboard'])->name('dashboard');
    Route::get('/analytics', [AdminController::class, 'analytics'])->name('analytics');
    Route::get('/reports', [AdminController::class, 'reports'])->name('reports');
    Route::get('/reports/{report}', [AdminController::class, 'showReport'])->name('reports.show');
    Route::post('/reports/{report}', [AdminController::class, 'handleReport'])->name('reports.handle');
    Route::get('/users', [AdminController::class, 'users'])->name('users');
    Route::post('/users/{user}/ban', [AdminController::class, 'banUser'])->name('users.ban');
    Route::post('/users/{user}/unban', [AdminController::class, 'unbanUser'])->name('users.unban');
    Route::get('/translations', [AdminController::class, 'translations'])->name('translations.index');
    Route::get('/translations/{translation}', [AdminController::class, 'showTranslation'])->name('translations.show');
    Route::get('/translations/{translation}/edit', [TranslationController::class, 'edit'])->name('translations.edit');
    Route::put('/translations/{translation}', [TranslationController::class, 'update'])->name('translations.update');
    Route::delete('/translations/{translation}', [AdminController::class, 'destroyTranslation'])->name('translations.destroy');
});
