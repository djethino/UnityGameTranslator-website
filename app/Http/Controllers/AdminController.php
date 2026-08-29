<?php

namespace App\Http\Controllers;

use App\Jobs\SendAnnouncementNotifications;
use App\Models\AnalyticsDaily;
use App\Models\AnalyticsEvent;
use App\Models\AnalyticsGame;
use App\Models\Announcement;
use App\Models\AuditLog;
use App\Models\Game;
use App\Models\Report;
use App\Models\Translation;
use App\Services\TranslationService;
use App\Models\User;
use App\Services\CatalogStore;
use App\Services\KnownReleases;
use App\Services\LiveEditCapacity;
use App\Services\VersionInventory;
use App\Support\AnalyticsPeriods;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AdminController extends Controller
{
    public function dashboard()
    {
        $pendingReports = Report::where('status', 'pending')->count();
        $totalTranslations = Translation::count();
        $totalUsers = User::count();
        // ⚠ Bans only. Deleting an account bans it — that is how its API tokens are cut — so this
        // counted everybody who had ever left as somebody moderation had punished, and the figure
        // grew with departures rather than with abuse.
        $bannedUsers = User::whereNotNull('banned_at')->whereNull('account_deleted_at')->count();
        $recentReports = Report::with(['translation.game', 'translation.user', 'reporter'])
            ->where('status', 'pending')
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get();

        return view('admin.dashboard', compact('pendingReports', 'totalTranslations', 'totalUsers', 'bannedUsers', 'recentReports'));
    }

    public function announcements()
    {
        $announcements = Announcement::with('author')->latest('published_at')->paginate(15);

        return view('admin.announcements', compact('announcements'));
    }

    public function storeAnnouncement(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:150',
            'body' => 'required|string|max:2000',
            'link' => 'nullable|url|max:500',
            'show_banner' => 'nullable|boolean',
        ]);

        $announcement = Announcement::create([
            'title' => $validated['title'],
            'body' => $validated['body'],
            'link' => $validated['link'] ?? null,
            'show_banner' => (bool) ($validated['show_banner'] ?? false),
            'created_by' => $request->user()->id,
            'published_at' => now(),
        ]);
        Announcement::clearBannerCache();

        SendAnnouncementNotifications::dispatch($announcement);

        return redirect()->route('admin.announcements')
            ->with('success', 'Announcement published and sent to all users.');
    }

    public function expireAnnouncement(Announcement $announcement)
    {
        $announcement->update(['expires_at' => now()]);
        Announcement::clearBannerCache();

        return redirect()->route('admin.announcements')
            ->with('success', 'Announcement expired (banner hidden, notifications remain).');
    }

    public function reports(Request $request)
    {
        $query = Report::with(['translation.game', 'translation.user', 'reporter', 'reviewer']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        } else {
            $query->where('status', 'pending'); // Default to pending
        }

        $reports = $query->orderBy('created_at', 'desc')->paginate(20);

        return view('admin.reports', compact('reports'));
    }

    public function showReport(Report $report)
    {
        // No file read here any more. This screen used to inline a hundred lines of the
        // translation, which was the one place a branch could be read — by a path that asked
        // nobody's permission — while the download button beside it answered 403. The lines now
        // open in the inspection screen, on the same grid as every other translation.
        $report->load(['translation.game', 'translation.user', 'translation.parent.user', 'reporter', 'reviewer']);

        return view('admin.report-show', compact('report'));
    }

    public function handleReport(Request $request, Report $report)
    {
        $request->validate([
            'action' => 'required|in:dismiss,delete_translation',
            'admin_notes' => 'nullable|string|max:1000',
        ]);

        if ($request->action === 'delete_translation') {
            // Delete the translation (this also deletes the report via cascade)
            //
            // ⚠ Through the service, which removes the file too. This deleted the row alone until
            // 2026-08-27, so every translation taken down on a report left its content on disk —
            // on the one path where the content is the reason for the removal.
            $translation = $report->translation;
            app(TranslationService::class)->deleteTranslation($translation);

            return redirect()->route('admin.reports')
                ->with('success', 'Translation deleted.');
        }

        // Dismiss the report
        $report->markAsReviewed(auth()->user(), 'dismissed', $request->admin_notes);

        return redirect()->route('admin.reports')
            ->with('success', 'Report dismissed.');
    }

    public function users(Request $request)
    {
        $query = User::withCount('translations')
            ->withSum('translations as downloads_sum', 'download_count')
            ->withMax('apiTokens as last_mod_activity', 'last_used_at');

        if ($request->filled('search')) {
            $search = str_replace(['%', '_'], ['\\%', '\\_'], $request->search);
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        // ⚠ Three states, not two. An erased account carries banned_at, so it used to answer to
        // "banned" — putting somebody who chose to leave in the same list as somebody who was
        // thrown out. It is not "active" either.
        if ($request->filled('status')) {
            if ($request->status === 'banned') {
                $query->whereNotNull('banned_at')->whereNull('account_deleted_at');
            } elseif ($request->status === 'active') {
                $query->whereNull('banned_at')->whereNull('account_deleted_at');
            } elseif ($request->status === 'deleted') {
                $query->whereNotNull('account_deleted_at');
            }
        }

        // Filter by OAuth provider
        if ($request->filled('provider')) {
            $query->where('provider', $request->provider);
        }

        // Sorting (whitelisted columns, including aggregates)
        $sortable = ['created_at', 'translations_count', 'downloads_sum', 'last_mod_activity'];
        $sort = in_array($request->input('sort'), $sortable, true) ? $request->input('sort') : 'created_at';
        $dir = $request->input('dir') === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sort, $dir);

        $users = $query->paginate(20)->appends($request->query());

        // Providers actually present in DB (dynamic filter options)
        $providers = User::whereNotNull('provider')->distinct()->orderBy('provider')->pluck('provider');

        return view('admin.users', compact('users', 'providers'));
    }

    public function banUser(Request $request, User $user)
    {
        if ($user->isAdmin()) {
            return back()->with('error', 'Cannot ban an admin.');
        }

        $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $user->ban($request->reason);

        // Log ban action
        AuditLog::logUserBanned($user->id, auth()->id(), $request->reason);

        return back()->with('success', "User {$user->name} has been banned.");
    }

    public function unbanUser(Request $request, User $user)
    {
        $user->unban();

        // Log unban action
        AuditLog::logUserUnbanned($user->id, auth()->id(), $request);

        return back()->with('success', "User {$user->name} has been unbanned.");
    }

    public function translations(Request $request)
    {
        $query = Translation::with(['game', 'user']);

        // Search by game name or user name
        if ($request->filled('search')) {
            $search = str_replace(['%', '_'], ['\\%', '\\_'], $request->search);
            $query->where(function ($q) use ($search) {
                $q->whereHas('game', fn($g) => $g->where('name', 'like', "%{$search}%"))
                  ->orWhereHas('user', fn($u) => $u->where('name', 'like', "%{$search}%"));
            });
        }

        // Filter by game
        if ($request->filled('game_id')) {
            $query->where('game_id', $request->game_id);
        }

        // Filter by user
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        // Filter by language
        if ($request->filled('language')) {
            $query->where('target_language', $request->language);
        }

        // Filter by status (in_progress / complete)
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Filter by visibility (public / branch)
        if ($request->filled('visibility')) {
            $query->where('visibility', $request->visibility);
        }

        // Sorting (whitelisted columns to prevent SQL injection)
        $sortable = ['created_at', 'content_updated_at', 'download_count', 'vote_count', 'line_count'];
        $sort = in_array($request->input('sort'), $sortable, true) ? $request->input('sort') : 'created_at';
        $dir = $request->input('dir') === 'asc' ? 'asc' : 'desc';

        // ⚠ content_updated_at, never updated_at: increment('vote_count') and
        // increment('download_count') write updated_at, so sorting on it ranked a translation
        // nobody had touched above one rewritten that morning — the column said "Updated" and
        // answered "last voted on".
        //
        // COALESCE because the column is null on rows written before it existed, and those would
        // otherwise all pile up at one end of the list. $dir is already restricted to two literals
        // above, so it is safe to interpolate here.
        if ($sort === 'content_updated_at') {
            $query->orderByRaw("COALESCE(content_updated_at, updated_at) $dir");
        } else {
            $query->orderBy($sort, $dir);
        }

        $translations = $query->paginate(20)->appends($request->query());
        $games = Game::orderBy('name')->get();
        $languages = CatalogStore::languageNames();
        $statuses = Translation::STATUSES;
        $visibilities = Translation::VISIBILITY;

        return view('admin.translations', compact('translations', 'games', 'languages', 'statuses', 'visibilities'));
    }

    public function showTranslation(Request $request, Translation $translation)
    {
        $translation->load(['game', 'user', 'parent.user', 'forks.user']);

        // Only the metadata is read here. The lines go to the shared editor core through the
        // admin endpoint below — this screen inspects a file, it does not need its own
        // filtering, searching and paging, and having them made looking at a translation
        // behave differently from editing one.
        return view('admin.translation-show', [
            'translation' => $translation,
            'metadata' => $translation->fileMetadata(),
        ]);
    }

    /**
     * The lines, for the admin inspection screen.
     *
     * Same payload as the public viewer's endpoint, but reachable for branches too: moderation
     * has to see what it is asked to judge. The permission is this route's middleware and
     * nothing else — Translation::isReadableBy stays untouched, so an admin browsing the public
     * side, or their own translations, is an ordinary user there.
     */
    public function translationData(Translation $translation)
    {
        $lines = $translation->fileLines();

        return response()->json([
            'ok' => $lines !== null,
            'content' => (object) ($lines ?? []),
        ]);
    }

    /** The file itself, for the same reason and under the same rule. */
    public function downloadTranslation(Translation $translation)
    {
        if (!$translation->file_path || !Storage::disk('local')->exists($translation->file_path)) {
            abort(404);
        }

        // Same name the mod expects, and no download counter: an admin opening a file to
        // moderate it is not a player taking it, and counting it would flatter the figure.
        return Storage::disk('local')->download($translation->file_path, 'translations.json');
    }

    public function destroyTranslation(Translation $translation)
    {
        $gameName = $translation->game->name;

        app(TranslationService::class)->deleteTranslation($translation);

        return redirect()->route('admin.translations.index')
            ->with('success', "Translation for {$gameName} deleted.");
    }

    /**
     * How many rows the two side-by-side cards of the analytics page hold, and how many show before
     * the reader asks for the rest.
     *
     * ⚠ One pair of constants because the cards sit next to each other: they held 10 and 5 for no
     * reason anybody could name, which reads as one of them being cut short.
     *
     * ⚠ Everything is fetched and sent; "Show more" only reveals. Ten rows either way is nothing to
     * carry, and fetching the rest on demand would mean a second round trip for a card whose whole
     * point is to be glanced at.
     */
    private const TOP_ROWS = 10;
    private const TOP_ROWS_VISIBLE = 5;

    /**
     * Fetch the shared catalogues now rather than waiting for the nightly run.
     *
     * 🔴 **This failure has no symptom, which is the whole reason the button exists.** When the
     * catalogue cannot be fetched the site keeps serving the copy committed in the repository and
     * stays entirely correct — so a source unreachable for months looks exactly like one that is
     * simply stable. The card says how stale it is; until now the only way to act on that was to
     * wait for 04:30.
     *
     * ⚠ **Run here and now, not queued.** A job would need a worker to be running, and if none is,
     * the button would silently do nothing — worse than a slow answer. This one is allowed to take
     * several seconds: it goes out to the network, and the person clicking it asked for that.
     *
     * ⚠ **The rule this does NOT break**: no network call is ever made while RENDERING a page or
     * answering an API call (CatalogStore, KnownReleases). This is a deliberate admin action with
     * its own route, not a page load.
     *
     * ⚠ **The release list deliberately has no equivalent** (2026-08-29): it refreshes hourly on
     * its own, and a control that only shaves off that hour was not worth a button on a screen this
     * dense. `php artisan releases:refresh` remains, for the rare case of having just published.
     */
    public function refreshCatalogues()
    {
        try {
            $code = Artisan::call('catalog:refresh');
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', "Could not refresh the catalogues: {$e->getMessage()}");
        }

        // ⚠ Reports what the command printed, not "it worked". Which of the four documents moved
        // is the answer somebody came for; a bare confirmation sends them to the server logs.
        $said = trim(Artisan::output());

        return $code === 0
            ? back()->with('success', $said ?: 'Catalogues refreshed.')
            : back()->with('error', $said ?: 'catalog:refresh failed.');
    }

    /**
     * Analytics dashboard
     */
    public function analytics(Request $request)
    {
        // 🔴 **The ceiling used to be a silent 365.** Daily aggregates are kept indefinitely — 167
        // days of them already — so anything asked beyond a year was quietly answered with a year,
        // and from December there would have been data this screen could no longer reach without
        // saying so. The span now follows what is actually stored.
        //
        // ⚠ 1 is a real choice, not a floor: "yesterday and today" is the window for watching
        // something happening now, and the smallest offer used to be a week.
        $oldestDay = AnalyticsDaily::min('date');
        $daysStored = $oldestDay
            ? max(1, (int) Carbon::parse($oldestDay)->diffInDays(now()) + 1)
            : 1;

        // ⚠ The span is no longer only a display filter: the version inventory uses it to decide
        // what reads as extinct, and invites breaking the API those builds use. That makes it a
        // rule, and a rule cannot live in a `@php` block inside the view — see AnalyticsPeriods.
        $maxPeriod = AnalyticsPeriods::ceiling($daysStored);
        $period = AnalyticsPeriods::clamp($request->get('period'), $daysStored);

        // Get aggregated daily stats for the period
        $dailyStats = AnalyticsDaily::where('date', '>=', now()->subDays($period))
            ->orderBy('date')
            ->get();

        // Today is not aggregated yet, so it is counted live from the events.
        //
        // Counted in SQL, never loaded into PHP: this page used to hydrate every
        // event of the day into models on each visit, which costs more the more
        // the site succeeds — the one kind of slowdown that arrives exactly when
        // it hurts most. The breakdowns below follow the same rule.
        $today = now()->toDateString();
        $todayEventsQuery = fn() => AnalyticsEvent::whereDate('created_at', $today);

        $todayStats = [
            'page_views' => $todayEventsQuery()->count(),
            'unique_visitors' => AnalyticsEvent::uniqueVisitorsOn($today),
            'downloads' => $todayEventsQuery()->where('route', 'like', '%translations.download')->count(),
            'uploads' => Translation::whereDate('created_at', $today)->count(),
            'registrations' => User::whereDate('created_at', $today)->count(),
        ];

        $todayBreakdown = fn(string $column, ?int $limit = null) => AnalyticsEvent::breakdownFor($today, $column, $limit);

        // Calculate totals (aggregated days + today's live stats)
        $totals = [
            'page_views' => $dailyStats->sum('page_views') + $todayStats['page_views'],
            'unique_visitors' => $dailyStats->sum('unique_visitors') + $todayStats['unique_visitors'],
            'downloads' => $dailyStats->sum('downloads') + $todayStats['downloads'],
            'uploads' => $dailyStats->sum('uploads') + $todayStats['uploads'],
            'registrations' => $dailyStats->sum('registrations') + $todayStats['registrations'],
        ];

        // Prepare chart data
        $chartLabels = $dailyStats->pluck('date')->map(fn($d) => $d->format('d/m'))->toArray();
        $chartPageViews = $dailyStats->pluck('page_views')->toArray();
        $chartVisitors = $dailyStats->pluck('unique_visitors')->toArray();
        $chartDownloads = $dailyStats->pluck('downloads')->toArray();

        // Aggregate countries from all days
        $allCountries = [];
        foreach ($dailyStats as $day) {
            if ($day->countries) {
                foreach ($day->countries as $country => $count) {
                    if ($country !== '' && $country !== null) {
                        $allCountries[$country] = ($allCountries[$country] ?? 0) + $count;
                    }
                }
            }
        }
        // Add today's countries
        foreach ($todayBreakdown('country', 50) as $country => $count) {
            $allCountries[$country] = ($allCountries[$country] ?? 0) + $count;
        }
        arsort($allCountries);
        $topCountries = array_slice($allCountries, 0, 10, true);

        // Aggregate referrers
        $allReferrers = [];
        foreach ($dailyStats as $day) {
            if ($day->referrers) {
                foreach ($day->referrers as $ref => $count) {
                    if ($ref !== '' && $ref !== null) {
                        $allReferrers[$ref] = ($allReferrers[$ref] ?? 0) + $count;
                    }
                }
            }
        }
        foreach ($todayBreakdown('referrer_domain', 20) as $ref => $count) {
            $allReferrers[$ref] = ($allReferrers[$ref] ?? 0) + $count;
        }
        arsort($allReferrers);
        $topReferrers = array_slice($allReferrers, 0, 10, true);

        // Aggregate devices
        $allDevices = ['desktop' => 0, 'mobile' => 0, 'tablet' => 0];
        foreach ($dailyStats as $day) {
            if ($day->devices) {
                foreach ($day->devices as $device => $count) {
                    $allDevices[$device] = ($allDevices[$device] ?? 0) + $count;
                }
            }
        }
        foreach ($todayBreakdown('device') as $device => $count) {
            $allDevices[$device] = ($allDevices[$device] ?? 0) + $count;
        }

        // Aggregate browsers
        $allBrowsers = [];
        foreach ($dailyStats as $day) {
            if ($day->browsers) {
                foreach ($day->browsers as $browser => $count) {
                    $allBrowsers[$browser] = ($allBrowsers[$browser] ?? 0) + $count;
                }
            }
        }
        foreach ($todayBreakdown('browser', 10) as $browser => $count) {
            $allBrowsers[$browser] = ($allBrowsers[$browser] ?? 0) + $count;
        }
        arsort($allBrowsers);

        // Top games. The two figures are pulled apart in the model — `page_views` counts downloads
        // too, so showing them raw side by side double-counts. See AnalyticsGame.
        $topGames = AnalyticsGame::topOverPeriod($period, self::TOP_ROWS);

        // Global stats
        $globalStats = [
            'total_users' => User::count(),
            'total_translations' => Translation::count(),
            'total_games' => Game::has('translations')->count(),
            'total_downloads' => Translation::sum('download_count'),
        ];

        // What was uploaded during the period.
        //
        // 🔴 **It used to ignore the span entirely** — `limit(5)` and nothing else — while sitting
        // in the section the span drives. On a page where everything else follows the filter, a card
        // that does not is a trap: you compare two spans, this one does not move, and you conclude
        // nothing happened.
        //
        // ⚠ No visibility filter, on purpose: an admin screen showing only the public half would
        // hide contributions, which are precisely what wants looking at. What the list owes the
        // reader instead is to SAY which is which — a branch is a proposal attached to somebody
        // else's Main, not something published.
        // ⚠ **Filtered by the server, not in the browser.** Hiding rows client-side would only ever
        // filter the ten already fetched, so "Branch" could show three while the period holds
        // twelve — a count that lies, which is the defect this card was just repaired for.
        $uploadRole = in_array($request->get('uploads'), ['main', 'branch'], true)
            ? $request->get('uploads')
            : 'all';

        $uploadsQuery = Translation::where('created_at', '>=', now()->subDays($period));

        // 'public' is a Main; anything else in a lineage is a branch — the same reading as
        // Translation::lineageRole(), asked of the database rather than of each row.
        if ($uploadRole === 'main') {
            $uploadsQuery->where('visibility', 'public');
        } elseif ($uploadRole === 'branch') {
            $uploadsQuery->where('visibility', '!=', 'public');
        }

        $recentUploadsTotal = (clone $uploadsQuery)->count();
        $recentUploads = $uploadsQuery->with(['user', 'game'])
            ->orderByDesc('created_at')
            ->limit(self::TOP_ROWS)
            ->get();

        $liveCapacity = LiveEditCapacity::current();

        // How long since the shared catalogues were last confirmed against the published ones.
        //
        // ⚠ This is here because the failure it reports has NO other symptom. When the catalogue
        // cannot be fetched, the site keeps serving the copy committed in resources/catalog/ and
        // stays entirely correct — so a source that went unreachable months ago looks exactly like
        // one that is simply stable. Nothing about it belongs on a visitor's screen; it is an
        // operational fact for us, next to the other operational facts.
        $catalogue = [];
        foreach (CatalogStore::FILES as $document) {
            $confirmed = CatalogStore::lastConfirmedAt($document);
            $catalogue[$document] = [
                'at' => $confirmed,
                'days' => $confirmed === null ? null : (int) $confirmed->diff(new \DateTimeImmutable())->days,
            ];
        }

        // Concurrency peaks over the period. Free: $dailyStats is already
        // loaded, and this is the history the instant gauge above cannot give —
        // nobody watches a dashboard at the moment it saturates.
        $peaks = [
            'sessions' => (int) $dailyStats->max('peak_edit_sessions'),
            'streams' => (int) $dailyStats->max('peak_edit_streams'),
            'started' => (int) $dailyStats->sum('edit_sessions_started'),
            'refused' => (int) $dailyStats->sum('edit_sessions_refused'),
        ];
        $peakSessionsDay = $dailyStats->sortByDesc('peak_edit_sessions')->first();
        $peakStreamsDay = $dailyStats->sortByDesc('peak_edit_streams')->first();
        $peaks['sessions_at'] = $peaks['sessions'] > 0 ? $peakSessionsDay?->peak_edit_sessions_at : null;
        $peaks['streams_at'] = $peaks['streams'] > 0 ? $peakStreamsDay?->peak_edit_streams_at : null;

        $chartPeakSessions = $dailyStats->pluck('peak_edit_sessions')->toArray();
        $chartPeakStreams = $dailyStats->pluck('peak_edit_streams')->toArray();

        // What versions of our own software are calling, and since when.
        //
        // ⚠ Its own tables rather than the events, because the question is different: events answer
        // "what happened", this answers "what is installed, and can I break it yet". Aggregated at
        // write time, so nothing here holds a row about anybody — see VersionInventory.
        $clients = VersionInventory::forSpan($period);
        $spanLabel = AnalyticsPeriods::label($period);

        // How many rows the side-by-side cards show before "Show more", and how many in all.
        $topRows = ['visible' => self::TOP_ROWS_VISIBLE, 'max' => self::TOP_ROWS];

        // ⚠ Without the published list, every caller is filed as unrecognised — the screen says so
        // rather than showing a table that looks like a measurement.
        $releasesKnown = KnownReleases::known();

        return view('admin.analytics', compact(
            'clients',
            'spanLabel',
            'recentUploadsTotal',
            'uploadRole',
            'topRows',
            'releasesKnown',
            'daysStored',
            'maxPeriod',
            'liveCapacity',
            'catalogue',
            'peaks',
            'chartPeakSessions',
            'chartPeakStreams',
            'period',
            'dailyStats',
            'todayStats',
            'totals',
            'chartLabels',
            'chartPageViews',
            'chartVisitors',
            'chartDownloads',
            'topCountries',
            'topReferrers',
            'allDevices',
            'allBrowsers',
            'topGames',
            'globalStats',
            'recentUploads'
        ));
    }

}
