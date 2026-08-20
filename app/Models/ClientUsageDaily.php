<?php

namespace App\Models;

use App\Services\KnownReleases;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * How many copies of each version of our own software are in use, per day.
 *
 * ⚠ Written straight as an aggregate — there is never a row about a caller, only a count per
 * version per day. The reasoning is in the migration; the short version is that this table cannot
 * answer "who", by construction rather than by policy.
 *
 * 🔴 **What goes in is bounded by what we publish.** A User-Agent is written by whoever is calling,
 * so without a bound anyone could invent versions, one new row per made-up number, and both fill
 * the table and shape what it says — and what it says decides whether JSON compression can be
 * switched on. A row cap was the obvious answer and a bad one: any number would be arbitrary, and
 * the day there genuinely were that many versions it would stop recording in silence. Instead, a
 * version gets its own row only if it matches a real release (`KnownReleases`); everything else is
 * folded into one `unrecognised` row. The table can then only be as large as our release history.
 */
class ClientUsageDaily extends Model
{
    protected $table = 'client_usage_daily';

    protected $fillable = ['date', 'product', 'version', 'variant', 'installs'];

    protected $casts = [
        'date' => 'date',
        'installs' => 'integer',
    ];

    /**
     * Stored for a build that predates versioned User-Agents: it asks for gzip and cannot read it.
     * Its own value, never confused with a version we failed to recognise.
     */
    public const LEGACY = 'legacy';

    /** Stored for anything we cannot match to a release: a local build, a typo, an invention. */
    public const UNRECOGNISED = 'unrecognised';

    /**
     * ⚠ Stored as '' and read as null, and the translation belongs HERE rather than in every
     * caller. The empty string exists only because SQLite and MySQL both treat NULLs as distinct
     * inside a unique index, which would give a variantless build a fresh row on every call.
     */
    protected function variant(): Attribute
    {
        return Attribute::get(fn ($value) => $value === '' ? null : $value);
    }

    /**
     * Count one copy, once for the day.
     *
     * 🔴 **Nothing is written when this fingerprint has already been seen today**, which is the
     * whole cost model: the first call of the day from an installation writes two small rows, every
     * later call costs one indexed read. It used to write on every single API call — polling
     * included — which on a shared host is a price paid for a number nobody wanted. The question
     * being answered is "how many copies", and a copy calling twenty times is still one copy.
     */
    public static function record(array $client, string $fingerprint, ?string $date = null): void
    {
        $date ??= now()->toDateString();

        if (!self::firstCallToday($date, $fingerprint)) {
            return;
        }

        DB::table('client_usage_daily')->upsert(
            [[
                'date' => $date,
                'product' => $client['product'],
                'version' => self::versionSlot($client),
                'variant' => self::variantSlot($client),
                'installs' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]],
            ['date', 'product', 'version', 'variant'],
            [
                'installs' => DB::raw('client_usage_daily.installs + 1'),
                'updated_at' => now(),
            ]
        );
    }

    /**
     * Which row this caller belongs in.
     *
     * ⚠ Three outcomes, and keeping them apart is the point. `legacy` is a build from before
     * versioned User-Agents — the row that decides whether compression can be enabled.
     * `unrecognised` is a version we cannot match to a release. A version we DO recognise gets its
     * own row. Folding the last two together would let an invented number masquerade as a release;
     * folding the first two would make a local build look like a mod that cannot decompress.
     */
    private static function versionSlot(array $client): string
    {
        if ($client['legacy'] ?? false) {
            return self::LEGACY;
        }

        return KnownReleases::recognises($client['product'], $client['version'] ?? null)
            ? $client['version']
            : self::UNRECOGNISED;
    }

    /**
     * ⚠ A loader is only kept beside a version we recognise. On an unrecognised row it would be a
     * second field the caller controls, and the pair (invented version × invented loader) would
     * multiply rows again — the exact hole the version bound closes.
     */
    private static function variantSlot(array $client): string
    {
        if (self::versionSlot($client) !== $client['version']) {
            return '';
        }

        return $client['variant'] ?? '';
    }

    /**
     * Is this the first call from this fingerprint today?
     *
     * ⚠ The insert IS the test: a duplicate key means it was already seen, which costs one
     * statement instead of a read followed by a write that could race with itself.
     */
    private static function firstCallToday(string $date, string $fingerprint): bool
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
     * ⚠ The fingerprints only serve the day they are written — they are how "already counted today"
     * is answered. Two days is slack for a job that runs at 2 a.m. against rows dated by UTC day.
     */
    public static function purgeFingerprints(int $keepDays = 2): int
    {
        return DB::table('client_daily_seen')
            ->where('date', '<', now()->subDays($keepDays)->toDateString())
            ->delete();
    }

    /**
     * What was running over a period, biggest first.
     *
     * ⚠ Installs are the busiest single DAY, never the days added up: the same copy calling on ten
     * days is one copy, and summing would claim ten.
     */
    public static function overPeriod(int $days): array
    {
        return self::where('date', '>=', now()->subDays($days)->toDateString())
            ->selectRaw('product, version, variant, MAX(installs) as installs')
            ->groupBy('product', 'version', 'variant')
            ->orderByDesc(DB::raw('MAX(installs)'))
            ->get()
            ->map(fn ($row) => [
                'product' => $row->product,
                'version' => $row->version,
                'variant' => $row->variant ?: null,
                'installs' => (int) $row->installs,
            ])
            ->all();
    }
}
