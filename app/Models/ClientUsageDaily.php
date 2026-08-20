<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * How much of each version of our own software is in use, per day.
 *
 * ⚠ Written straight as an aggregate — there is never a row about a caller, only a count per
 * version per day. The reasoning is in the migration; the short version is that this table cannot
 * answer "who", by construction rather than by policy.
 */
class ClientUsageDaily extends Model
{
    protected $table = 'client_usage_daily';

    protected $fillable = ['date', 'product', 'version', 'variant', 'requests', 'installs'];

    protected $casts = [
        'date' => 'date',
        'requests' => 'integer',
        'installs' => 'integer',
    ];

    /**
     * ⚠ Stored as '' and read as null, and the translation belongs HERE rather than in every
     * caller. The empty string exists only because SQLite and MySQL both treat NULLs as distinct
     * inside a unique index, which would give an unversioned build a fresh row on every call. That
     * is a storage constraint; the meaning is "we do not know which version this was".
     */
    protected function version(): Attribute
    {
        return Attribute::get(fn ($value) => $value === '' ? null : $value);
    }

    protected function variant(): Attribute
    {
        return Attribute::get(fn ($value) => $value === '' ? null : $value);
    }

    /**
     * Count one call from one of our programs.
     *
     * ⚠ **Two writes, and the second is conditional.** `requests` always goes up. `installs` only
     * goes up when this fingerprint is new for the day, which is what makes it a count of copies
     * rather than a count of chattiness — a mod polling hourly would otherwise weigh as much as
     * twenty-four separate installations.
     *
     * ⚠ Empty strings rather than nulls in the key: both SQLite and MySQL treat NULLs as distinct
     * in a unique index, so an unversioned build would create a fresh row on every single call.
     */
    public static function record(array $client, string $fingerprint, ?string $date = null): void
    {
        $date ??= now()->toDateString();
        $version = $client['version'] ?? '';
        $variant = $client['variant'] ?? '';

        $isNewToday = self::rememberFingerprint($date, $fingerprint);

        DB::table('client_usage_daily')->upsert(
            [[
                'date' => $date,
                'product' => $client['product'],
                'version' => $version,
                'variant' => $variant,
                'requests' => 1,
                'installs' => $isNewToday ? 1 : 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]],
            ['date', 'product', 'version', 'variant'],
            [
                'requests' => DB::raw('client_usage_daily.requests + 1'),
                'installs' => DB::raw('client_usage_daily.installs + ' . ($isNewToday ? 1 : 0)),
                'updated_at' => now(),
            ]
        );
    }

    /**
     * Is this the first call from this fingerprint today?
     *
     * ⚠ The insert IS the test: a duplicate key means it was already seen, which costs one
     * statement instead of a read followed by a write that could race with itself.
     */
    private static function rememberFingerprint(string $date, string $fingerprint): bool
    {
        try {
            DB::table('client_daily_seen')->insert([
                'date' => $date,
                'fingerprint' => $fingerprint,
            ]);

            return true;
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            return false;
        }
    }

    /**
     * What was running over a period, biggest first.
     *
     * Returns one entry per (product, version, variant) with the days summed, so the admin screen
     * can show "which builds are out there" without knowing how the rows are stored.
     */
    public static function overPeriod(int $days): array
    {
        return self::where('date', '>=', now()->subDays($days)->toDateString())
            ->selectRaw('product, version, variant, SUM(requests) as requests, MAX(installs) as installs')
            ->groupBy('product', 'version', 'variant')
            ->orderByDesc(DB::raw('MAX(installs)'))
            ->orderByDesc(DB::raw('SUM(requests)'))
            ->get()
            ->map(fn ($row) => [
                'product' => $row->product,
                'version' => $row->version ?: null,
                'variant' => $row->variant ?: null,
                'requests' => (int) $row->requests,
                // ⚠ MAX of the daily counts, never the sum: the same copy calling on ten days is
                // one copy, and adding the days up would claim ten.
                'installs' => (int) $row->installs,
            ])
            ->all();
    }
}
