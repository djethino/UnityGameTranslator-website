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
use App\Models\User;
use App\Services\LiveEditCapacity;
use App\Support\TranslationContentReader;
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
        $bannedUsers = User::whereNotNull('banned_at')->count();
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
        $report->load(['translation.game', 'translation.user', 'reporter', 'reviewer']);

        // Load JSON content for preview
        $jsonContent = null;
        if ($report->translation && $report->translation->file_path) {
            try {
                $content = Storage::disk('local')->get($report->translation->file_path);
                $jsonContent = json_decode($content, true);
            } catch (\Exception $e) {
                $jsonContent = null;
            }
        }

        return view('admin.report-show', compact('report', 'jsonContent'));
    }

    public function handleReport(Request $request, Report $report)
    {
        $request->validate([
            'action' => 'required|in:dismiss,delete_translation',
            'admin_notes' => 'nullable|string|max:1000',
        ]);

        if ($request->action === 'delete_translation') {
            // Delete the translation (this also deletes the report via cascade)
            $translation = $report->translation;
            $translation->delete();

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

        if ($request->filled('status')) {
            if ($request->status === 'banned') {
                $query->whereNotNull('banned_at');
            } elseif ($request->status === 'active') {
                $query->whereNull('banned_at');
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
        $sortable = ['created_at', 'updated_at', 'download_count', 'vote_count', 'line_count'];
        $sort = in_array($request->input('sort'), $sortable, true) ? $request->input('sort') : 'created_at';
        $dir = $request->input('dir') === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sort, $dir);

        $translations = $query->paginate(20)->appends($request->query());
        $games = Game::orderBy('name')->get();
        $languages = config('languages');
        $statuses = Translation::STATUSES;
        $visibilities = Translation::VISIBILITY;

        return view('admin.translations', compact('translations', 'games', 'languages', 'statuses', 'visibilities'));
    }

    public function showTranslation(Request $request, Translation $translation)
    {
        $translation->load(['game', 'user', 'parent.user', 'forks.user']);

        // Filtering, search, sort and pagination live in TranslationContentReader: the public
        // read-only view needs exactly the same reading of the same file, and two copies would
        // have meant two definitions of what "sort by tag" means.
        return view('admin.translation-show', array_merge(
            ['translation' => $translation],
            TranslationContentReader::read($translation, $request)
        ));
    }

    public function destroyTranslation(Translation $translation)
    {
        $gameName = $translation->game->name;

        // Delete file
        if ($translation->file_path) {
            Storage::disk('local')->delete($translation->file_path);
        }

        // Delete translation
        $translation->delete();

        return redirect()->route('admin.translations.index')
            ->with('success', "Translation for {$gameName} deleted.");
    }

    /**
     * Analytics dashboard
     */
    public function analytics(Request $request)
    {
        $period = max(1, min((int) $request->get('period', 30), 365)); // days, clamped

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

        // Top games
        $topGames = AnalyticsGame::where('date', '>=', now()->subDays($period))
            ->select('game_id', DB::raw('SUM(page_views) as views'), DB::raw('SUM(downloads) as downloads'))
            ->groupBy('game_id')
            ->orderByDesc('views')
            ->limit(10)
            ->with('game')
            ->get();

        // Global stats
        $globalStats = [
            'total_users' => User::count(),
            'total_translations' => Translation::count(),
            'total_games' => Game::has('translations')->count(),
            'total_downloads' => Translation::sum('download_count'),
        ];

        // Recent activity
        $recentUploads = Translation::with(['user', 'game'])
            ->orderByDesc('created_at')
            ->limit(5)
            ->get();

        $liveCapacity = LiveEditCapacity::current();

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

        return view('admin.analytics', compact(
            'liveCapacity',
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
