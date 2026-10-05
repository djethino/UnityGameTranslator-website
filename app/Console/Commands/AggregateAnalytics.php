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

        // Days of games older than thirteen months become months (AnalyticsGame::foldOldDays).
        $folded = AnalyticsGame::foldOldDays();
        if ($folded > 0) {
            $this->info("  Folded {$folded} game day(s) older than " . AnalyticsGame::DAYS_KEPT_MONTHS . ' months into months');
        }

        // The month the day belongs to — recounted every night, never waiting for its end.
        $this->aggregateMonth($date);

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

        // 🔴 Summed before the raw events go at 90 days, or never again (user, 2026-10-05): which
        // pages, in which of the site's languages, and which languages translations are taken into.
        // Not capped: their values are our own route names, our own locales and the catalogue's
        // languages — bounded by what we publish, not by what a caller sends.
        $routes = AnalyticsEvent::breakdownFor($date, 'route');
        $locales = AnalyticsEvent::breakdownFor($date, 'locale');
        $downloadLanguages = AnalyticsEvent::downloadBreakdownFor($date, 'target_language');

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
                'routes' => $routes,
                'locales' => $locales,
                'download_languages' => $downloadLanguages,
            ]
        );

        $this->info("  Global stats: {$pageViews} views, {$uniqueVisitors} unique visitors");
    }

    /**
     * Aggregate per-game stats
     */
    /**
     * ⚠ **`page_views` here means "every event carrying a game id", downloads included.** The name
     * is wrong and the column is kept as it is on purpose: it goes back further than the 90 days of
     * raw events we keep, so narrowing it now would leave a break nobody could ever recompute
     * across. `AnalyticsGame::topBetween` subtracts the downloads on the way out, which is exact
     * for every day already stored. Do not "fix" it here without recomputing the whole series —
     * which cannot be done.
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
     * The month `$date` belongs to: its distinct visitors and its distinct copies of the mod and of
     * the Manager, written (or rewritten) into analytics_monthly.
     *
     * 🔴 **Recounted every night, not on the month's last day** ("rien n'attend"): the row of the
     * month in progress is never more than a day behind, and a night that is missed loses nothing —
     * the next one counts the same month again from the same fingerprints.
     *
     * ⚠ Then the fingerprints of every EARLIER month are forgotten — those months are counted, and
     * the working-out has no further use. Idempotent, so a missed night is caught up by the next.
     */
    protected function aggregateMonth(string $date): void
    {
        $month = \Carbon\Carbon::parse($date)->startOfMonth();
        $monthKey = $month->toDateString();

        // ⚠ Only a month whose fingerprints are still there: yesterday's — which is the previous
        // month on the 1st, at 2 a.m. — or this one. Re-running the job by hand for a day of an
        // older month (`--date`) would recount a month already forgotten and write zero over it.
        if ($month->lt(now()->subDay()->startOfMonth())) {
            $this->warn("  Month {$month->format('Y-m')} is already counted and forgotten: left as it is");
            return;
        }

        $copies = fn (string $product) => DB::table('client_monthly_seen')
            ->where('month', $monthKey)
            ->where('product', $product)
            ->count();

        \App\Models\AnalyticsMonthly::updateOrCreate(
            ['month' => $monthKey],
            [
                'unique_visitors' => AnalyticsEvent::uniqueVisitorsInMonth($month),
                'mod_copies' => $copies(\App\Support\ClientAgent::MOD),
                'manager_copies' => $copies(\App\Support\ClientAgent::MANAGER),
            ]
        );

        // Counted months: forget who. The current month keeps its fingerprints until it is over.
        $current = now()->startOfMonth();
        $forgotten = AnalyticsEvent::forgetMonthVisitorsBefore($current)
            + DB::table('client_monthly_seen')->where('month', '<', $current->toDateString())->delete();

        $this->info("  Month {$month->format('Y-m')} so far: counted"
            . ($forgotten > 0 ? "; forgot {$forgotten} fingerprint(s) of earlier months" : ''));
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
