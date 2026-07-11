<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\Api\DeviceFlowController;
use App\Http\Controllers\Auth\LocalAuthController;
use App\Http\Controllers\Auth\SocialController;
use App\Http\Controllers\EditSessionController;
use App\Http\Controllers\GameController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\MergeController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\TranslationController;
use App\Http\Controllers\VoteController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

// Sitemaps (no locale prefix)
Route::get('/sitemap.xml', [SitemapController::class, 'index'])->name('sitemap');
Route::get('/sitemap-pages.xml', [SitemapController::class, 'pages'])->name('sitemap.pages');
Route::get('/sitemap-games-{page}.xml', [SitemapController::class, 'games'])->where('page', '[0-9]+')->name('sitemap.games');

// IndexNow key file - lets Bing/Yandex/etc. verify pings sent by IndexNowService
Route::get('/indexnow.txt', function () {
    $key = config('services.indexnow.key');
    abort_unless(!empty($key), 404);
    return response($key, 200)->header('Content-Type', 'text/plain');
})->name('indexnow.key');

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

// API routes (no locale prefix)
Route::get('/api/games/search', [GameController::class, 'search'])->name('games.search');
Route::get('/api/games/search-external', [GameController::class, 'searchExternal'])->name('games.search.external');

// Download (no locale prefix - direct file access)
Route::get('/download/{translation}', [TranslationController::class, 'download'])->name('translations.download');

// Live edit session AJAX endpoints — never NAVIGATED, so they stay out of
// the locale group (the pages call them through route(), unprefixed).
// RULE: any BROWSED page (a URL the language switcher can redirect back to)
// must live in $localizableRoutes below, or switching language on it 404s —
// the mod-given entry URLs keep working, the unprefixed form always exists.
// Legitimate rhythm: state polls every 10s (6/min) and data only refetches
// when the content hash changed — 30/min leaves a wide margin while capping
// a runaway client or a flood on these anonymous endpoints
Route::get('/edit-session-data', [EditSessionController::class, 'data'])->middleware('throttle:30,1')->name('edit-session.data');
Route::get('/edit-session-state', [EditSessionController::class, 'state'])->middleware('throttle:30,1')->name('edit-session.state');
Route::post('/edit-session-save', [EditSessionController::class, 'save'])->middleware('throttle:30,1')->name('edit-session.save');
Route::post('/edit-session-retranslate', [EditSessionController::class, 'retranslate'])->middleware('throttle:20,1')->name('edit-session.retranslate');
Route::post('/edit-session-leave', [EditSessionController::class, 'leave'])->middleware('throttle:30,1')->name('edit-session.leave');
Route::post('/edit-session-end', [EditSessionController::class, 'end'])->middleware('throttle:10,1')->name('edit-session.end');

// Notification AJAX endpoints — polled by the header bell (60s) and used by
// the mark-read buttons; never navigated, so they stay out of the locale group
Route::middleware('auth')->group(function () {
    Route::get('/notifications-count', [NotificationController::class, 'count'])->middleware('throttle:120,1')->name('notifications.count');
    Route::post('/notifications/{id}/read', [NotificationController::class, 'markRead'])->middleware('throttle:60,1')->name('notifications.read');
    Route::post('/notifications-read-all', [NotificationController::class, 'markAllRead'])->middleware('throttle:20,1')->name('notifications.read-all');
});

/*
|--------------------------------------------------------------------------
| Localizable Routes
|--------------------------------------------------------------------------
| All user-facing routes support optional locale prefix: /, /en/, /fr/, etc.
| The SetLocale middleware handles locale detection from URL prefix.
*/
$localizableRoutes = function () {
    // Home
    Route::get('/', [HomeController::class, 'index'])->name('home');

    // Login
    Route::get('/login', function () {
        return view('auth.login');
    })->name('login');

    // Local (platform-less) accounts — anonymity first, no email required
    Route::get('/register', [LocalAuthController::class, 'showRegister'])->name('local.register');
    Route::post('/register', [LocalAuthController::class, 'register'])->middleware('throttle:5,60')->name('local.register.post');
    Route::post('/login-local', [LocalAuthController::class, 'login'])->middleware('throttle:20,1')->name('local.login');
    Route::get('/account-recovery', [LocalAuthController::class, 'showRecover'])->name('local.recover');
    Route::post('/account-recovery', [LocalAuthController::class, 'recover'])->middleware('throttle:10,60')->name('local.recover.post');
    Route::get('/recovery-codes', [LocalAuthController::class, 'showRecoveryCodes'])->name('local.recovery-codes');
    Route::post('/recovery-codes/regenerate', [LocalAuthController::class, 'regenerateCodes'])->middleware(['auth', 'throttle:5,60'])->name('local.recovery-codes.regenerate');

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

    // Device Flow link page. The mod displays the unprefixed URL (which
    // always exists), but the page is browsed so it must be localizable
    Route::get('/link', [DeviceFlowController::class, 'showLinkPage'])->name('link');
    Route::post('/link', [DeviceFlowController::class, 'validateCode'])->middleware(['auth', 'throttle:10,1'])->name('link.validate');

    // Merge preview page — token-based auth from the mod; the tokenized
    // entry URL is unprefixed (mod-generated) but browsed afterwards
    Route::get('/translations/{translation}/merge-preview', [TranslationController::class, 'mergePreview'])->name('translations.merge-preview');
    Route::get('/translations/{translation}/merge-preview/data', [TranslationController::class, 'mergePreviewData'])->name('translations.merge-preview.data');

    // Live edit session pages — anonymous, token-based auth from the mod.
    // The entry route consumes the one-time token and redirects to the
    // session-bound token-less URL
    Route::get('/edit-session/{token}', [EditSessionController::class, 'open'])->middleware('throttle:10,1')->name('edit-session.open');
    Route::get('/edit-session', [EditSessionController::class, 'show'])->name('edit-session.show');

    // Authenticated routes
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

        // Notifications page (browsed → localizable)
        Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');

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
        Route::get('/translations/{uuid}/merge/data', [MergeController::class, 'data'])->name('translations.merge.data');
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
        Route::get('/announcements', [AdminController::class, 'announcements'])->name('announcements');
        Route::post('/announcements', [AdminController::class, 'storeAnnouncement'])->name('announcements.store');
        Route::post('/announcements/{announcement}/expire', [AdminController::class, 'expireAnnouncement'])->name('announcements.expire');
        Route::get('/translations', [AdminController::class, 'translations'])->name('translations.index');
        Route::get('/translations/{translation}', [AdminController::class, 'showTranslation'])->name('translations.show');
        Route::get('/translations/{translation}/edit', [TranslationController::class, 'edit'])->name('translations.edit');
        Route::put('/translations/{translation}', [TranslationController::class, 'update'])->name('translations.update');
        Route::delete('/translations/{translation}', [AdminController::class, 'destroyTranslation'])->name('translations.destroy');
    });
};

// Routes without locale prefix (default locale detection)
Route::group([], $localizableRoutes);

// Routes with locale prefix (/en/, /fr/, /de/, etc.)
// The {locale} group reuses the same closure but must NOT overwrite named routes,
// otherwise route() helpers would require a {locale} parameter everywhere.
// We strip names by wrapping in a name('') prefix — this makes locale routes unnamed.
Route::group([
    'prefix' => '{locale}',
    'where' => ['locale' => implode('|', array_keys(config('locales.supported', ['en' => []])))],
    'as' => 'locale.',
], $localizableRoutes);
