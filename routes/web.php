<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\Auth\SocialController;
use App\Http\Controllers\GameController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\TranslationController;
use App\Http\Controllers\VoteController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

// Sitemap
Route::get('/sitemap.xml', [SitemapController::class, 'index'])->name('sitemap');

// Home
Route::get('/', [HomeController::class, 'index'])->name('home');

// Login page
Route::get('/login', function () {
    return view('auth.login');
})->name('login');

// OAuth
Route::get('/auth/{provider}', [SocialController::class, 'redirect'])->name('auth.redirect');
Route::get('/auth/{provider}/callback', [SocialController::class, 'callback'])->name('auth.callback');
Route::post('/logout', function () {
    Auth::logout();
    request()->session()->invalidate();
    request()->session()->regenerateToken();
    return redirect('/');
})->name('logout');

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

// Language switcher
Route::get('/locale/{locale}', [LocaleController::class, 'switch'])->name('locale.switch');

// Games
Route::get('/games', [GameController::class, 'index'])->name('games.index');
Route::get('/games/{game}', [GameController::class, 'show'])->name('games.show');
Route::get('/api/games/search', [GameController::class, 'search'])->name('games.search');
Route::get('/api/games/search-external', [GameController::class, 'searchExternal'])->name('games.search.external');

// Translations - public
Route::get('/download/{translation}', [TranslationController::class, 'download'])->name('translations.download');

// Authenticated routes
Route::middleware('auth')->group(function () {
    Route::get('/upload', [TranslationController::class, 'create'])->name('translations.create');
    Route::post('/upload', [TranslationController::class, 'store'])->name('translations.store');
    Route::get('/api/translations/check-uuid', [TranslationController::class, 'checkUuid'])->name('translations.check-uuid');
    Route::get('/my-translations', [TranslationController::class, 'myTranslations'])->name('translations.mine');
    Route::delete('/translations/{translation}', [TranslationController::class, 'destroy'])->name('translations.destroy');

    // Profile
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::get('/profile/export', [ProfileController::class, 'export'])->name('profile.export');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Reports
    Route::post('/report/{translation}', [ReportController::class, 'store'])->name('reports.store');

    // Votes
    Route::post('/vote/{translation}', [VoteController::class, 'vote'])->name('votes.store');
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
    Route::get('/translations/{translation}/edit', [AdminController::class, 'editTranslation'])->name('translations.edit');
    Route::put('/translations/{translation}', [AdminController::class, 'updateTranslation'])->name('translations.update');
});
