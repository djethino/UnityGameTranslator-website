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
 * ⚠ Therefore: **never read `page_views` directly for display.** Go through `topOverPeriod`.
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
    public static function topOverPeriod(int $days, int $limit = 10): \Illuminate\Support\Collection
    {
        return self::where('date', '>=', now()->subDays($days)->toDateString())
            ->whereHas('game')
            ->select(
                'game_id',
                DB::raw('SUM(page_views) - SUM(downloads) as views'),
                DB::raw('SUM(downloads) as downloads'),
                DB::raw('SUM(page_views) as attention'),
            )
            ->groupBy('game_id')
            ->orderByDesc('attention')
            ->limit($limit)
            ->with('game')
            ->get();
    }
}
