<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Since when, and until when, each build of ours has been calling.
 *
 * 🔴 **This is the table that answers "can I break this yet?".** `client_usage_daily` cannot: it
 * filters on the chosen span, so a version with no call inside it disappears from the list — and an
 * absent version is indistinguishable from one that never existed. Here every build we have ever
 * heard from keeps a row, and the span decides how the row READS, never whether it exists.
 *
 * 🔴 **Written beside the count, never by a job.** `last_seen = MAX(last_seen, today)` is monotonic:
 * it can only move forward, and it is written in the same flow as the count, so there is no window
 * in which this table and `client_usage_daily` can contradict each other. That is what makes it a
 * record rather than a cache.
 *
 * ⚠ **Fresh traffic REACTIVATES a version, and that is the point.** "Extinct for 94 days, seen again
 * today" is exactly the sentence that must stop a hand before it breaks an API call. A screen that
 * recomputes over a span never produces it: there, a reappearance is one more row, not an event.
 *
 * ⚠ Holds the buckets `legacy` and `unrecognised` too. They are not versions, but without them
 * "before versioning" — the row that decides whether JSON compression can be turned on — would have
 * no last_seen.
 */
class VersionActivity extends Model
{
    protected $table = 'version_activity';

    protected $fillable = ['product', 'version', 'variant', 'first_seen', 'last_seen', 'days_active'];

    protected $casts = [
        'first_seen' => 'date',
        'last_seen' => 'date',
        'days_active' => 'integer',
    ];

    /**
     * ⚠ Stored as '' and read as null, and the translation belongs HERE rather than in every caller
     * — same rule as ClientUsageDaily, for the same index reason.
     */
    protected function variant(): Attribute
    {
        return Attribute::get(fn ($value) => $value === '' ? null : $value);
    }

    /**
     * Note that this build called on this day.
     *
     * 🔴 **The monotonicity lives in the WHERE, not in the values**, and that is deliberate: an
     * upsert expressing "take the later of the two" needs `VALUES()`/`GREATEST` on MySQL and
     * `excluded.`/`MAX` on SQLite — two dialects for one rule, i.e. the class of defect the move to
     * MariaDB was made to stop hiding. A conditional UPDATE says the same thing in plain SQL:
     *
     *  - `last_seen` only advances (`where last_seen < date`), so replaying an old day cannot rewind
     *    it, and calling this twice on the same day cannot inflate `days_active`;
     *  - `first_seen` only moves backwards, and only when a genuinely older day turns up;
     *  - the row is created when the UPDATE matched nothing.
     *
     * ⚠ **The insert IS the race check.** Several different copies of the same build can call on the
     * same day at the same moment; the loser of the insert gets a duplicate key, which means the row
     * now exists and is already at least this recent.
     *
     * ⚠ Called from `ClientUsageDaily::record`, which has already established this is that copy's
     * first call of the day. Do not call it from anywhere that has not.
     *
     * ⚠ Not named `touch`: Eloquent already has one, non-static, and overriding it is a fatal error
     * rather than a warning.
     */
    public static function noteCall(string $product, string $version, string $variant, string $date): void
    {
        $row = DB::table('version_activity')
            ->where('product', $product)
            ->where('version', $version)
            ->where('variant', $variant);

        $advanced = (clone $row)
            ->where('last_seen', '<', $date)
            ->update([
                'last_seen' => $date,
                'days_active' => DB::raw('days_active + 1'),
                'updated_at' => now(),
            ]);

        if ($advanced > 0) {
            return;
        }

        try {
            DB::table('version_activity')->insert([
                'product' => $product,
                'version' => $version,
                'variant' => $variant,
                'first_seen' => $date,
                'last_seen' => $date,
                'days_active' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            // The row was already there and already at least this recent — nothing to advance. The
            // one thing left to check is whether this call is OLDER than what we thought was the
            // beginning, which happens when history is replayed.
            (clone $row)->where('first_seen', '>', $date)->update([
                'first_seen' => $date,
                'updated_at' => now(),
            ]);
        }
    }
}
