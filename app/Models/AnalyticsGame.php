<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * How much attention each game got, day by day.
 *
 * 🔴 **`page_views` counts EVERY event carrying a game id, downloads included** — see
 * `AggregateAnalytics::aggregateGameStats`. So `downloads` is a strict SUBSET of it, not a figure
 * beside it, and showing the two raw next to each other double-counts: measured on 30 days of real
 * traffic, 29% of what was labelled "views" were downloads. A game that is downloaded a lot and
 * browsed little climbed a chart titled "views".
 *
 * ⚠ **Corrected on the way out, not at write time, and that is deliberate.** Changing the
 * aggregation would only fix days yet to come, leaving a silent break in a series that goes back
 * further than the 90 days of raw events we keep — so the old days could never be recomputed and
 * the two halves of the chart would mean different things. Subtracting here is exact for every day
 * already stored, because the subset relation has always held.
 *
 * ⚠ Therefore: **never read `page_views` directly for display.** Go through `topBetween`.
 */
class AnalyticsGame extends Model
{
    protected $table = 'analytics_games';

    protected $fillable = [
        'date',
        'game_id',
        'page_views',
        'downloads',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    /**
     * The most looked-at games of a period, with the two figures kept apart.
     *
     * ⚠ Ranked on views and downloads TOGETHER, because "which games matter" is not answered by
     * either alone: a game nobody browses but everybody downloads is doing well, and so is the
     * reverse. The column it is ranked on is written on the card, since a ranking whose criterion
     * is unstated invites the reader to invent one.
     *
     * ⚠ Games that no longer exist are dropped by the QUERY, not skipped while rendering. Skipping
     * at render is how a "top 10" quietly shows seven rows and still calls itself a top 10.
     */
    public static function topBetween(\DateTimeInterface $from, \DateTimeInterface $to, int $limit = 10): \Illuminate\Support\Collection
    {
        $from = \Carbon\CarbonImmutable::instance($from);
        $to = \Carbon\CarbonImmutable::instance($to);

        // The days still kept, plus the months already folded (foldOldDays). A row is in one table
        // or the other, never both, so adding them counts nothing twice.
        //
        // ⚠ A folded month counts WHOLE once the span touches it: its days no longer exist, so a
        // span from the 20th of a month folded long ago includes that month's first days, and one
        // ending on the 10th includes its last. The screen says when a span reaches folded months
        // (foldedBefore).
        $dayRows = DB::table('analytics_games')
            ->select('game_id', 'page_views', 'downloads')
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()]);
        $monthRows = DB::table('analytics_games_monthly')
            ->select('game_id', 'page_views', 'downloads')
            ->whereBetween('month', [$from->startOfMonth()->toDateString(), $to->toDateString()]);

        $rows = DB::query()->fromSub($dayRows->unionAll($monthRows), 'attention')
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('games')->whereColumn('games.id', 'attention.game_id'))
            ->select(
                'game_id',
                DB::raw('SUM(page_views) - SUM(downloads) as views'),
                DB::raw('SUM(downloads) as downloads'),
                DB::raw('SUM(page_views) as attention'),
            )
            ->groupBy('game_id')
            ->orderByDesc('attention')
            ->limit($limit)
            ->get();

        return self::hydrate($rows->map(fn ($row) => (array) $row)->all())->load('game');
    }

    /** How many months of days are kept before they are folded into months. */
    public const DAYS_KEPT_MONTHS = 13;

    /** The first day still kept day by day: before it, games are counted per month (foldOldDays). */
    public static function foldedBefore(): \Carbon\CarbonImmutable
    {
        return \Carbon\CarbonImmutable::now()->startOfMonth()->subMonths(self::DAYS_KEPT_MONTHS);
    }

    /**
     * Fold every month older than DAYS_KEPT_MONTHS into analytics_games_monthly, and delete its
     * days. Returns how many day rows were folded.
     *
     * ⚠ **One transaction per month, adding to what the month already holds.** A month is folded
     * whole and its days deleted together, so a night that fails leaves the month either entirely
     * in days or entirely in months — never in both, which is what lets topBetween add the two
     * tables. Adding rather than writing makes a late day (a `--date` run on an old day, written
     * after its month was folded) join its month on the next night instead of being lost.
     */
    public static function foldOldDays(): int
    {
        $before = self::foldedBefore()->toDateString();
        $folded = 0;

        $months = self::where('date', '<', $before)
            ->pluck('date')
            ->map(fn ($date) => $date->copy()->startOfMonth()->toDateString())
            ->unique();

        foreach ($months as $month) {
            DB::transaction(function () use ($month, &$folded) {
                $end = \Carbon\Carbon::parse($month)->endOfMonth()->toDateString();
                $days = self::whereBetween('date', [$month, $end]);

                $sums = (clone $days)
                    ->select('game_id', DB::raw('SUM(page_views) as page_views'), DB::raw('SUM(downloads) as downloads'))
                    ->groupBy('game_id')
                    ->get();

                foreach ($sums as $sum) {
                    $row = DB::table('analytics_games_monthly')->where('month', $month)->where('game_id', $sum->game_id);
                    if ((clone $row)->exists()) {
                        $row->update([
                            'page_views' => DB::raw('page_views + ' . (int) $sum->page_views),
                            'downloads' => DB::raw('downloads + ' . (int) $sum->downloads),
                            'updated_at' => now(),
                        ]);
                    } else {
                        DB::table('analytics_games_monthly')->insert([
                            'month' => $month,
                            'game_id' => $sum->game_id,
                            'page_views' => (int) $sum->page_views,
                            'downloads' => (int) $sum->downloads,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }

                $folded += $days->delete();
            });
        }

        return $folded;
    }
}
