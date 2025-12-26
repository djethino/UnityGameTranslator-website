<?php

namespace App\Http\Controllers;

use App\Models\AnalyticsDaily;
use App\Models\AnalyticsEvent;
use App\Models\AnalyticsGame;
use App\Models\AuditLog;
use App\Models\Game;
use App\Models\Report;
use App\Models\Translation;
use App\Models\User;
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
                $content = Storage::disk('public')->get($report->translation->file_path);
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
        $query = User::withCount('translations');

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

        $users = $query->orderBy('created_at', 'desc')->paginate(20);

        return view('admin.users', compact('users'));
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

    public function editTranslation(Translation $translation)
    {
        $translation->load(['game', 'user']);
        $languages = config('languages');

        return view('admin.translation-edit', compact('translation', 'languages'));
    }

    public function updateTranslation(Request $request, Translation $translation)
    {
        $languages = config('languages');

        $request->validate([
            'source_language' => ['required', 'string', 'in:' . implode(',', $languages)],
            'target_language' => ['required', 'string', 'in:' . implode(',', $languages)],
            'status' => 'required|in:in_progress,complete',
            'type' => 'required|in:ai,human,ai_corrected',
            'notes' => 'nullable|string|max:1000',
        ]);

        $translation->update([
            'source_language' => $request->source_language,
            'target_language' => $request->target_language,
            'status' => $request->status,
            'type' => $request->type,
            'notes' => $request->notes,
        ]);

        return redirect()->route('games.show', $translation->game)
            ->with('success', 'Translation updated successfully.');
    }

    /**
     * Analytics dashboard
     */
    public function analytics(Request $request)
    {
        $period = $request->get('period', '30'); // days

        // Get aggregated daily stats for the period
        $dailyStats = AnalyticsDaily::where('date', '>=', now()->subDays($period))
            ->orderBy('date')
            ->get();

        // Get today's live stats from events (not yet aggregated)
        $today = now()->toDateString();
        $todayEvents = AnalyticsEvent::whereDate('created_at', $today)->get();
        $todayStats = [
            'page_views' => $todayEvents->count(),
            'unique_visitors' => $todayEvents->pluck('visitor_hash')->unique()->count(),
        ];

        // Calculate totals
        $totals = [
            'page_views' => $dailyStats->sum('page_views') + $todayStats['page_views'],
            'unique_visitors' => $dailyStats->sum('unique_visitors') + $todayStats['unique_visitors'],
            'downloads' => $dailyStats->sum('downloads'),
            'uploads' => $dailyStats->sum('uploads'),
            'registrations' => $dailyStats->sum('registrations'),
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
        foreach ($todayEvents->groupBy('country') as $country => $events) {
            if ($country !== '' && $country !== null) {
                $allCountries[$country] = ($allCountries[$country] ?? 0) + $events->count();
            }
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
        foreach ($todayEvents->groupBy('referrer_domain') as $ref => $events) {
            if ($ref !== '' && $ref !== null) {
                $allReferrers[$ref] = ($allReferrers[$ref] ?? 0) + $events->count();
            }
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
        foreach ($todayEvents->groupBy('device') as $device => $events) {
            $allDevices[$device] = ($allDevices[$device] ?? 0) + $events->count();
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
        foreach ($todayEvents->groupBy('browser') as $browser => $events) {
            if ($browser) {
                $allBrowsers[$browser] = ($allBrowsers[$browser] ?? 0) + $events->count();
            }
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

        return view('admin.analytics', compact(
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
