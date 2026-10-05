<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\Api\DeviceFlowController;
use App\Http\Controllers\Auth\LocalAuthController;
use App\Http\Controllers\Auth\SocialController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\ConnectionsController;
use App\Http\Controllers\EditSessionController;
use App\Http\Controllers\FlagController;
use App\Http\Controllers\GameController;
use App\Http\Controllers\GameLanguageController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LanguageBankController;
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

// Shared catalogues (no locale prefix - fetched by the Manager, never navigated).
// This is the mirror rung of the Manager's fetch order: GitHub, here, its cache, its embedded
// copy. The path is compiled into the tool as CatalogMirrorBase, so it cannot be renamed on a
// whim — an older Manager will keep asking for exactly this.
Route::get('/catalog/{name}.json', [CatalogController::class, 'show'])
    ->where('name', '[a-z]+')
    ->name('catalog.show');

// The catalogue's flags, one image each (see FlagController). Out of the `web` group ENTIRELY: an
// image needs no session, cookie or locale, and that group's PublicCacheHeaders rewrites any
// unprefixed anonymous answer to `private, no-cache` — the year of cache that makes a file worth
// having would be thrown away on every page.
Route::get('/flags/{flag}.svg', [FlagController::class, 'show'])
    ->where('flag', '[a-z0-9-]+')
    ->withoutMiddleware('web')
    ->name('flag');

// Short interface strings per language, fetched by the background so a word on screen can slip
// into another of the twenty languages for a second. No locale prefix: the page asks for a locale
// OTHER than its own, which is the whole point, so prefixing it would be nonsense.
Route::get('/lang-bank/{locale}.json', [LanguageBankController::class, 'show'])
    ->where('locale', '[A-Za-z-]+')
    ->middleware('throttle:60,1')
    ->name('lang-bank.show');

// Language switchers — the one the site is READ in, and the one games are PLAYED in.
//
// ⚠ Both are open to visitors with no account. The second one used to sit inside the auth group,
// which left anybody signed out with a guessed play language and no way to correct it; the
// preference now lives in the session for everybody and on the account when there is one.
Route::get('/locale/{locale}', [LocaleController::class, 'switch'])->name('locale.switch');
Route::post('/game-language', [GameLanguageController::class, 'switch'])
    ->middleware('throttle:60,1')
    ->name('game-language.switch');

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
// Settings travel apart from the content, which is streamed without being decoded
Route::get('/edit-session-settings', [EditSessionController::class, 'settings'])->middleware('throttle:30,1')->name('edit-session.settings');
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
    // Removing one. Until this existed the list only ever grew, and the only way to be rid of it
    // was to delete the account.
    Route::delete('/notifications/{id}', [NotificationController::class, 'destroy'])->middleware('throttle:60,1')->name('notifications.destroy');
    Route::post('/username-prompt-seen', [ProfileController::class, 'usernamePromptSeen'])->middleware('throttle:10,1')->name('profile.username-prompt-seen');
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

    // Read-only view of a translation's lines. Public, because the file itself has always been
    // downloadable by anyone — this only lets you look before you take. Throttled all the same:
    // it decodes a JSON file that can hold tens of thousands of entries, which a download of the
    // raw file does not.
    Route::get('/translations/{translation}/view', [TranslationController::class, 'view'])
        ->middleware('throttle:60,1')->name('translations.view');
    Route::get('/translations/{translation}/view/data', [TranslationController::class, 'viewData'])
        ->middleware('throttle:30,1')->name('translations.view.data');

    // Merge preview page — token-based auth from the mod; the tokenized
    // entry URL is unprefixed (mod-generated) but browsed afterwards
    Route::get('/translations/{translation}/merge-preview', [TranslationController::class, 'mergePreview'])->name('translations.merge-preview');
    Route::get('/translations/{translation}/merge-preview/data', [TranslationController::class, 'mergePreviewData'])->name('translations.merge-preview.data');
    // Settings travel apart from the lines: see TranslationController::mergePreviewSettings
    Route::get('/translations/{translation}/merge-preview/settings', [TranslationController::class, 'mergePreviewSettings'])->name('translations.merge-preview.settings');
// Asked when the tab comes back into view, never on a timer — see the controller.
Route::get('/translations/{translation}/merge-preview/state', [TranslationController::class, 'mergePreviewState'])->name('translations.merge-preview.state');

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
        // Filing a lineage under another game: an act of its own, with its own conditions
        // (App\Services\LineageGame) — never a field of the settings form above.
        Route::post('/translations/{translation}/game', [TranslationController::class, 'changeGame'])
            ->middleware('throttle:10,1')->name('translations.game');
        Route::delete('/translations/{translation}', [TranslationController::class, 'destroy'])->name('translations.destroy');
        Route::post('/translations/{translation}/merge-preview', [TranslationController::class, 'applyMergePreview'])->name('translations.merge-preview.apply');
        // Separate route on purpose: this one never writes the translation file, it hands the
        // result back to the mod. See TranslationController::applyMergePreviewLocally.
        Route::post('/translations/{translation}/merge-preview/local', [TranslationController::class, 'applyMergePreviewLocally'])->name('translations.merge-preview.apply-local');

        // The way out of a comparison, from the browser — the twin of edit-session.end. It applies
        // nothing: it releases the token, so the page stops being live and the game is told.
        Route::post('/translations/{translation}/merge-preview/end', [TranslationController::class, 'endMergePreview'])
            ->middleware('throttle:10,1')->name('translations.merge-preview.end');

        // Notifications page (browsed → localizable)
        Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');

        // Profile
        Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
        Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
        Route::post('/profile/avatar', [ProfileController::class, 'avatarReroll'])->middleware('throttle:30,1')->name('profile.avatar');
        Route::get('/profile/export', [ProfileController::class, 'export'])->name('profile.export');
        Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

        // Linked devices — what holds an access to this account, and how to cut it.
        // ⚠ {token} is an id resolved against auth()->user()->apiTokens(), never route-model bound:
        // the ids are sequential, so binding here would hand out every account's rows.
        Route::get('/profile/connections', [ConnectionsController::class, 'index'])->name('profile.connections');
        Route::patch('/profile/connections/{token}', [ConnectionsController::class, 'update'])->name('profile.connections.rename');
        // Moving ONE line elsewhere, where the route above renames a whole group. Two acts, two
        // doors, because naming a pile and picking something out of it are not the same gesture.
        Route::patch('/profile/connections/{token}/group', [ConnectionsController::class, 'move'])->name('profile.connections.move');
        Route::delete('/profile/connections/{token}', [ConnectionsController::class, 'destroy'])->name('profile.connections.destroy');
        Route::delete('/profile/connections', [ConnectionsController::class, 'destroyMany'])->name('profile.connections.destroy-many');
        Route::delete('/profile/browsers', [ConnectionsController::class, 'signOutOtherBrowsers'])->name('profile.browsers.destroy');

        // Taking back one's own "adults only" declaration. The declaring is done by the upload that
        // creates the game, from the mod or the Manager — never here (Game::declareAdultBy). Only
        // the declarer passes the check, in the controller: the stores' mark and an admin's word
        // are nobody else's to undo.
        Route::delete('/games/{game}/adult', [GameController::class, 'withdrawAdult'])
            ->middleware('throttle:10,1')->name('games.adult.withdraw');

        // Reports
        Route::post('/report/{translation}', [ReportController::class, 'store'])->name('reports.store');
        // A game card. Throttled: an adult-content report asks the stores at once.
        Route::post('/report/game/{game:id}', [ReportController::class, 'storeGame'])
            ->middleware('throttle:5,1')->name('reports.game');

        // Votes
        Route::post('/vote/{translation}', [VoteController::class, 'vote'])->name('votes.store');

        // Merge View (Main owner only)
        Route::get('/translations/{uuid}/merge', [MergeController::class, 'show'])->name('translations.merge');
        Route::get('/translations/{uuid}/merge/data', [MergeController::class, 'data'])->name('translations.merge.data');
// Asked when the tab comes back into view, never on a timer — see the controller.
Route::get('/translations/{uuid}/merge/state', [MergeController::class, 'state'])->name('translations.merge.state');
        Route::post('/translations/{uuid}/merge', [MergeController::class, 'apply'])->name('translations.merge.apply');
        Route::post('/translations/{translation}/rate-branch', [MergeController::class, 'rateBranch'])->name('translations.rate-branch');
        // Read/unread, beside the mark and deliberately not the same thing: the mark judges a
        // contributor over time and only the Main sees it; this says one state of one file has
        // been looked at.
        Route::post('/translations/{translation}/read-branch', [MergeController::class, 'readBranch'])->name('translations.read-branch');
    });

    // Admin routes
    Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
        Route::get('/', [AdminController::class, 'dashboard'])->name('dashboard');
        Route::get('/analytics', [AdminController::class, 'analytics'])->name('analytics');
        // What happens to translations: published, edited, moved, deleted, refused (TranslationFlows).
        Route::get('/flows', [AdminController::class, 'flows'])->name('flows');

        // Fetch the shared catalogues now instead of waiting for the nightly run.
        //
        // ⚠ Throttled, and it is not about abuse from outside — this is behind auth+admin. It is
        // about not hammering GitHub because a page got refreshed: the call goes out to a public
        // API we do not own.
        //
        // ⚠ **No parameter, on purpose.** This took `{source}` for a moment, accepting `catalogues`
        // or `releases`; the release button was dropped, and a parameter with one possible value is
        // not a parameter — it is a spare door nobody closes. Adding a second source later means
        // adding a second route, which is also where anyone will look for it.
        Route::post('/refresh-catalogues', [AdminController::class, 'refreshCatalogues'])
            ->middleware('throttle:6,1')
            ->name('refresh-catalogues');
        Route::get('/reports', [AdminController::class, 'reports'])->name('reports');
        Route::get('/reports/{report}', [AdminController::class, 'showReport'])->name('reports.show');
        Route::post('/reports/{report}', [AdminController::class, 'handleReport'])->name('reports.handle');
        // 🔴 **Somewhere to repair what a machine declared.** `unity_name` and `unity_company` are
        // sent by whoever publishes, and they decide which game other machines resolve to. Every
        // guard around them refuses a bad value at the door — and none of them could correct one
        // already stored, so a key taken by mistake or on purpose was final short of raw SQL.
        Route::get('/games', [AdminController::class, 'games'])->name('games');
        // ⚠ `{game:id}`, not the slug the model binds by everywhere else: a slug follows the
        // display name, and this screen exists to repair games whose naming is wrong. Every other
        // admin route addresses its subject by id for the same reason.
        // Forget the name a game carries on disk — the only act on it here: the value comes from
        // the game's files, which an admin does not have (AdminController::clearGameNames).
        Route::delete('/games/{game:id}/names', [AdminController::class, 'clearGameNames'])
            ->name('games.names.clear');

        // The only door that can say a game is NOT for adults only — everything else can merely
        // raise the flag. Same `{game:id}` reasoning as above.
        Route::post('/games/{game:id}/adult', [AdminController::class, 'setGameAdult'])
            ->name('games.adult');
        // Ask the stores again, now — the hourly pass only asks about games a store says changed.
        Route::post('/games/{game:id}/adult/check', [AdminController::class, 'checkGameAdultAgain'])
            ->middleware('throttle:30,1')
            ->name('games.adult.check');

        // What the stores can tell a card that lacks it — PROPOSED, never written: a title match
        // is a guess, and only an admin turns it into a fact. See App\Services\StoreProposals.
        Route::post('/games/check-stores', [AdminController::class, 'checkGameStores'])
            ->name('games.check-stores');
        // One card, now — a card is only due again when it changes; a store adding it later says nothing.
        Route::post('/games/{game:id}/check-stores', [AdminController::class, 'checkGameStoresAgain'])
            ->middleware('throttle:30,1')
            ->name('games.check-stores.one');
        Route::post('/games/proposals/apply', [AdminController::class, 'applyGameProposals'])
            ->name('games.proposals.apply');
        Route::post('/games/proposals/{proposal}/reject', [AdminController::class, 'rejectGameProposal'])
            ->name('games.proposals.reject');

        // Remove a card no translation is filed under any more — a wrong card emptied by moving its
        // translations elsewhere, or by their deletion. Refused while anything is filed under it.
        Route::delete('/games/{game:id}', [AdminController::class, 'destroyGame'])->name('games.destroy');

        Route::get('/users', [AdminController::class, 'users'])->name('users');
        // One account's translations, as its author sees them on "My translations" — branches
        // included, under the same rule as the translation screens below.
        Route::get('/users/{user}', [AdminController::class, 'showUser'])->name('users.show');
        Route::post('/users/{user}/ban', [AdminController::class, 'banUser'])->name('users.ban');
        Route::post('/users/{user}/unban', [AdminController::class, 'unbanUser'])->name('users.unban');
        Route::get('/announcements', [AdminController::class, 'announcements'])->name('announcements');
        Route::post('/announcements', [AdminController::class, 'storeAnnouncement'])->name('announcements.store');
        Route::post('/announcements/{announcement}/expire', [AdminController::class, 'expireAnnouncement'])->name('announcements.expire');
        Route::get('/translations', [AdminController::class, 'translations'])->name('translations.index');
        Route::get('/translations/{translation}', [AdminController::class, 'showTranslation'])->name('translations.show');
        // Moderation reads everything, branches included — a report an admin cannot open is a
        // decision taken blind. That access lives HERE, behind the admin middleware, and not in
        // Translation::isReadableBy: outside these screens an admin is an ordinary user, and
        // "my translations" shows them their own work like anyone else's.
        Route::get('/translations/{translation}/data', [AdminController::class, 'translationData'])
            ->middleware('throttle:30,1')->name('translations.data');
        Route::get('/translations/{translation}/download', [AdminController::class, 'downloadTranslation'])
            ->name('translations.download');
        Route::get('/translations/{translation}/edit', [TranslationController::class, 'edit'])->name('translations.edit');
        Route::put('/translations/{translation}', [TranslationController::class, 'update'])->name('translations.update');
        // The same act as the owner's, free of the owner's limits — from here only.
        Route::post('/translations/{translation}/game', [TranslationController::class, 'changeGame'])->name('translations.game');
        Route::delete('/translations/{translation}', [AdminController::class, 'destroyTranslation'])->name('translations.destroy');
    });
};

// A code that is no longer a locale of ours, but named one for its whole life.
//
// ⚠ Declared BEFORE the groups below, and answering 301 rather than serving the page: /pt/ has
// been linked to and indexed for as long as the site existed. Letting it 404 would throw that
// away; serving the same page at two addresses would split its ranking between them. A permanent
// redirect is the only answer that keeps both.
//
// The list comes from config, so adding an alias never means remembering this file.
Route::get('/{alias}/{rest?}', function (string $alias, ?string $rest = null) {
    $target = config('locales.aliases')[strtolower($alias)] ?? config('locales.default', 'en');
    $path = '/' . $target . ($rest !== null && $rest !== '' ? '/' . $rest : '');
    $query = request()->getQueryString();

    return redirect($path . ($query !== null && $query !== '' ? '?' . $query : ''), 301);
})->where([
    'alias' => implode('|', array_keys(config('locales.aliases', ['pt' => 'pt-BR']))),
    'rest' => '.*',
]);

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
