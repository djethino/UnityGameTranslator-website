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
        $events = AnalyticsEvent::whereDate('created_at', $date)->get();

        if ($events->isEmpty()) {
            $this->warn("No events found for {$date}");
        }

        // Count unique visitors
        $uniqueVisitors = $events->pluck('visitor_hash')->unique()->count();

        // Count by country
        $countries = $events->groupBy('country')
            ->map(fn($group) => $group->count())
            ->filter(fn($count, $key) => $key !== null)
            ->sortDesc()
            ->take(50)
            ->toArray();

        // Count by referrer
        $referrers = $events->groupBy('referrer_domain')
            ->map(fn($group) => $group->count())
            ->filter(fn($count, $key) => $key !== null)
            ->sortDesc()
            ->take(20)
            ->toArray();

        // Count by device
        $devices = $events->groupBy('device')
            ->map(fn($group) => $group->count())
            ->toArray();

        // Count by browser
        $browsers = $events->groupBy('browser')
            ->map(fn($group) => $group->count())
            ->filter(fn($count, $key) => $key !== null)
            ->sortDesc()
            ->take(10)
            ->toArray();

        // Count downloads from events
        $downloads = AnalyticsEvent::whereDate('created_at', $date)
            ->where('route', 'translations.download')
            ->count();

        // Count uploads
        $uploads = Translation::whereDate('created_at', $date)->count();

        // Count registrations
        $registrations = User::whereDate('created_at', $date)->count();

        AnalyticsDaily::updateOrCreate(
            ['date' => $date],
            [
                'page_views' => $events->count(),
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

        $this->info("  Global stats: {$events->count()} views, {$uniqueVisitors} unique visitors");
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

        foreach ($gameEvents as $event) {
            // Count downloads for this game's translations on this date
            $downloads = Translation::where('game_id', $event->game_id)
                ->whereDate('updated_at', $date)
                ->sum('download_count');

            AnalyticsGame::updateOrCreate(
                ['date' => $date, 'game_id' => $event->game_id],
                [
                    'page_views' => $event->views,
                    'downloads' => $downloads,
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
    }
}
