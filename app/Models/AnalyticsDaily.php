<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AnalyticsDaily extends Model
{
    protected $table = 'analytics_daily';

    protected $fillable = [
        'date',
        'page_views',
        'unique_visitors',
        'downloads',
        'uploads',
        'registrations',
        'countries',
        'referrers',
        'devices',
        'browsers',
        'peak_edit_sessions',
        'peak_edit_streams',
        'peak_edit_sessions_at',
        'peak_edit_streams_at',
        'edit_sessions_started',
        'edit_sessions_refused',
        'stream_refusals_capacity',
        'stream_refusals_per_ip',
    ];

    protected $casts = [
        'date' => 'date',
        'page_views' => 'integer',
        'unique_visitors' => 'integer',
        'downloads' => 'integer',
        'uploads' => 'integer',
        'registrations' => 'integer',
        'countries' => 'array',
        'referrers' => 'array',
        'devices' => 'array',
        'browsers' => 'array',
        'peak_edit_sessions' => 'integer',
        'peak_edit_streams' => 'integer',
        'peak_edit_sessions_at' => 'datetime',
        'peak_edit_streams_at' => 'datetime',
        'edit_sessions_started' => 'integer',
        'edit_sessions_refused' => 'integer',
        'stream_refusals_capacity' => 'integer',
        'stream_refusals_per_ip' => 'integer',
    ];

    /**
     * Record a concurrency reading for today, keeping only the day's maximum.
     *
     * Sampled by the scheduler rather than measured continuously: concurrency
     * cannot be counted after the fact the way page views can, and writing on
     * every session change would put an analytics write on the critical path of
     * a user request. A spike shorter than the sampling interval is therefore
     * invisible here — acceptable because edit sessions last tens of minutes,
     * so a saturation worth knowing about outlives any sane interval. The
     * refusal counter below is exact and covers what this cannot.
     *
     * Streams are null when the SSE server cannot be reached: leave the stored
     * peak alone rather than record a zero that would read as "nobody
     * connected" instead of "we could not tell".
     */
    public static function recordCapacitySample(
        int $sessions,
        ?int $streams,
        ?int $refusedAtCapacity = null,
        ?int $refusedPerIp = null
    ): void {
        try {
            $row = self::forDate(now()->toDateString());

            $updates = [];
            if ($sessions > $row->peak_edit_sessions) {
                $updates['peak_edit_sessions'] = $sessions;
                $updates['peak_edit_sessions_at'] = now();
            }
            if ($streams !== null && $streams > $row->peak_edit_streams) {
                $updates['peak_edit_streams'] = $streams;
                $updates['peak_edit_streams_at'] = now();
            }

            // Kept as high-water marks, not sums: the SSE server counts from
            // its own start, and a Passenger restart puts it back to zero.
            // Taking the maximum can lose history across a restart; adding
            // would invent it by counting the same refusals twice.
            if ($refusedAtCapacity !== null && $refusedAtCapacity > $row->stream_refusals_capacity) {
                $updates['stream_refusals_capacity'] = $refusedAtCapacity;
            }
            if ($refusedPerIp !== null && $refusedPerIp > $row->stream_refusals_per_ip) {
                $updates['stream_refusals_per_ip'] = $refusedPerIp;
            }

            if ($updates) {
                $row->update($updates);
            }
        } catch (\Throwable $e) {
            Log::warning("[Analytics] Capacity sample failed: {$e->getMessage()}");
        }
    }

    /**
     * Count an edit session that was created, or refused because the cap was
     * reached. Atomic increment: two sessions starting in the same second must
     * not overwrite each other's count.
     *
     * Never lets a statistic break the action it measures — a failed count is
     * logged and swallowed, exactly as SsePublisher does for signalling.
     */
    public static function countEditSession(bool $refused = false): void
    {
        $column = $refused ? 'edit_sessions_refused' : 'edit_sessions_started';

        try {
            $row = self::forDate(now()->toDateString());
            self::whereKey($row->getKey())->update([
                $column => DB::raw("{$column} + 1"),
            ]);
        } catch (\Throwable $e) {
            Log::warning("[Analytics] Edit session count failed: {$e->getMessage()}");
        }
    }

    /**
     * The row for a given day, created if needed.
     *
     * Looked up with whereDate rather than a plain equality: the `date` cast
     * writes a timestamp, so comparing the column to a bare 'Y-m-d' string
     * matches on MySQL (real DATE column) but not on SQLite (stored verbatim).
     * firstOrCreate would then keep trying to insert a row that already exists.
     *
     * The retry covers the race between two creators on the same day — the
     * unique index is what makes that safe, and losing the race simply means
     * reading the row the winner just wrote.
     */
    private static function forDate(string $date): self
    {
        $row = self::whereDate('date', $date)->first();
        if ($row) {
            return $row;
        }

        try {
            return self::create(['date' => $date]);
        } catch (\Illuminate\Database\QueryException $e) {
            return self::whereDate('date', $date)->firstOrFail();
        }
    }
}
