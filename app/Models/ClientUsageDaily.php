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
     * The day this table started being written.
     *
     * 🔴 **Anything published before it and never seen is OUT OF REACH, not unused**, and the two
     * must not be shown the same way. A release from June cannot have been seen by a counter that
     * started in August, so writing "never" against it states as a measurement something that was
     * never measured — which is exactly the failure this whole screen was rebuilt to stop. Thirty of
     * them in a row is also the noise that buries the handful of rows that mean something.
     */
    public const COUNTING_STARTED = '2026-08-20';

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

        $product = $client['kind'];
        $version = self::versionSlot($client);
        $variant = self::variantSlot($client);

        DB::table('client_usage_daily')->upsert(
            [[
                'date' => $date,
                'product' => $product,
                'version' => $version,
                'variant' => $variant,
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

        // 🔴 The twin write, and the reason it is here rather than in a nightly job: this table
        // answers "how many, that day", and it structurally cannot answer "is this still running" —
        // a version outside the chosen span vanishes from it entirely, and an absent version is
        // indistinguable from one that never existed. `version_activity` keeps the row and lets the
        // span decide how it READS.
        //
        // ⚠ Its cost is one more statement per copy per day — acceptable only because `..._180000`
        // stopped writing on every call. Reintroducing a per-request write reopens this.
        VersionActivity::noteCall($product, $version, $variant, $date);
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

        return KnownReleases::recognises($client['kind'], $client['version'] ?? null)
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
     * Day by day, how many copies of each version called — `[version => [date => copies]]`.
     *
     * 🔴 **This is the only thing `client_usage_daily` is still read for**, and it is the one thing
     * `version_activity` cannot hold: the shape of the last N days. Where it stands overall — since
     * when, until when — is read from the register in one row, so nothing here has to scan history.
     *
     * ⚠ Copies for one day are SUMMED across loaders, then the period keeps the busiest single day.
     * Two loaders on the same day are two different copies (summing is right); the same copy calling
     * on ten days is one copy (adding the days would claim ten).
     *
     * @param string $by 'version' or 'variant' — the two questions the screen asks of the same rows.
     */
    public static function dailySeries(int $days, string $product, string $by = 'version'): array
    {
        if (!in_array($by, ['version', 'variant'], true)) {
            throw new \InvalidArgumentException("Cannot group client usage by [{$by}].");
        }

        $series = [];

        $rows = self::where('date', '>=', now()->subDays($days)->toDateString())
            ->where('product', $product)
            ->selectRaw("{$by} as bucket, date, SUM(installs) as copies")
            ->groupBy($by, 'date')
            ->get();

        foreach ($rows as $row) {
            // ⚠ Kept as the empty string rather than folded into `unrecognised`: a call with no
            // loader named is not a call from an unrecognised loader. It is a build from before the
            // User-Agent carried one, or one whose version we could not place (the loader is then
            // dropped on purpose). The screen names it; conflating the two here would make a real
            // adapter and an absence share a row.
            $bucket = (string) ($row->bucket ?? '');

            $date = $row->date instanceof \DateTimeInterface
                ? $row->date->format('Y-m-d')
                : substr((string) $row->date, 0, 10);

            $series[$bucket][$date] = ($series[$bucket][$date] ?? 0) + (int) $row->copies;
        }

        return $series;
    }
}
