<?php

namespace App\Console\Commands;

use App\Models\AnalyticsDaily;
use App\Models\AnalyticsEvent;
use App\Models\AnalyticsGame;
use App\Models\Translation;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AggregateAnalytics extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'analytics:aggregate {--date= : Specific date to aggregate (YYYY-MM-DD), defaults to yesterday}';

    /**
     * The console command description.
     */
    protected $description = 'Aggregate analytics events into daily stats and purge old events';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $date = $this->option('date')
            ? \Carbon\Carbon::parse($this->option('date'))->toDateString()
            : now()->subDay()->toDateString();

        $this->info("Aggregating analytics for {$date}...");

        // Aggregate global daily stats
        $this->aggregateDailyStats($date);

        // Aggregate per-game stats
        $this->aggregateGameStats($date);

        // 🔴 **After the counting, never before.** The fingerprint answers COUNT(DISTINCT) for the
        // day just aggregated; once that number is in analytics_daily it has no further use, and it
        // used to sit in the table for ninety days regardless. Clearing it here bounds its life to
        // roughly one day — the rows themselves stay, since route, game, referrer and country are
        // what the figures are read from and none of them points at anybody.
        $forgotten = AnalyticsEvent::forgetVisitorsUpTo($date);
        if ($forgotten > 0) {
            $this->info("  Forgot {$forgotten} visitor fingerprint(s) — the counting is done");
        }

        // Purge old events (older than 90 days)
        $this->purgeOldEvents();

        $this->info('Analytics aggregation complete!');

        return Command::SUCCESS;
    }

    /**
     * Aggregate global daily stats
     */
    protected function aggregateDailyStats(string $date): void
    {
        // Everything below is counted by the database rather than hydrated into
        // models: this job runs unattended on a shared host, where a busy day's
        // events would otherwise be loaded into memory all at once.
        $pageViews = AnalyticsEvent::whereDate('created_at', $date)->count();

        if ($pageViews === 0) {
            $this->warn("No events found for {$date}");
        }

        $uniqueVisitors = AnalyticsEvent::uniqueVisitorsOn($date);
        $countries = AnalyticsEvent::breakdownFor($date, 'country', 50);
        $referrers = AnalyticsEvent::breakdownFor($date, 'referrer_domain', 20);
        $devices = AnalyticsEvent::breakdownFor($date, 'device');
        $browsers = AnalyticsEvent::breakdownFor($date, 'browser', 10);

        // Count downloads from events (web + API/mod)
        $downloads = AnalyticsEvent::whereDate('created_at', $date)
            ->where('route', 'like', '%translations.download')
            ->count();

        // Count uploads
        $uploads = Translation::whereDate('created_at', $date)->count();

        // Count registrations
        $registrations = User::whereDate('created_at', $date)->count();

        AnalyticsDaily::updateOrCreate(
            ['date' => $date],
            [
                'page_views' => $pageViews,
                'unique_visitors' => $uniqueVisitors,
                'downloads' => $downloads,
                'uploads' => $uploads,
                'registrations' => $registrations,
                'countries' => $countries,
                'referrers' => $referrers,
                'devices' => $devices,
                'browsers' => $browsers,
            ]
        );

        $this->info("  Global stats: {$pageViews} views, {$uniqueVisitors} unique visitors");
    }

    /**
     * Aggregate per-game stats
     */
    protected function aggregateGameStats(string $date): void
    {
        $gameEvents = AnalyticsEvent::whereDate('created_at', $date)
            ->whereNotNull('game_id')
            ->select('game_id', DB::raw('COUNT(*) as views'))
            ->groupBy('game_id')
            ->get();

        // Count downloads per game from analytics events
        $gameDownloads = AnalyticsEvent::whereDate('created_at', $date)
            ->where('route', 'like', '%translations.download')
            ->whereNotNull('game_id')
            ->select('game_id', DB::raw('COUNT(*) as downloads'))
            ->groupBy('game_id')
            ->pluck('downloads', 'game_id');

        foreach ($gameEvents as $event) {
            AnalyticsGame::updateOrCreate(
                ['date' => $date, 'game_id' => $event->game_id],
                [
                    'page_views' => $event->views,
                    'downloads' => $gameDownloads[$event->game_id] ?? 0,
                ]
            );
        }

        $this->info("  Game stats: {$gameEvents->count()} games tracked");
    }

    /**
     * Purge events older than 90 days
     */
    protected function purgeOldEvents(): void
    {
        $cutoff = now()->subDays(90)->toDateString();
        $deleted = AnalyticsEvent::whereDate('created_at', '<', $cutoff)->delete();

        if ($deleted > 0) {
            $this->info("  Purged {$deleted} old events (> 90 days)");
        }

        // ⚠ The client fingerprints only serve the day they are written — they answer "already
        // counted today" and nothing else. Without this they would accumulate forever for a
        // question nobody asks about yesterday. The daily counts themselves are kept: they are the
        // history of what is installed out there.
        $fingerprints = \App\Models\ClientUsageDaily::purgeFingerprints();

        if ($fingerprints > 0) {
            $this->info("  Purged {$fingerprints} client fingerprints (> 2 days)");
        }
    }
}
