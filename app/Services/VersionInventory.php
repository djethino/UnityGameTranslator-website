<?php

namespace App\Services;

use App\Models\ClientUsageDaily;
use App\Models\Release;
use App\Models\VersionActivity;
use Carbon\CarbonImmutable;

/**
 * What is running out there, assembled for the admin screen.
 *
 * 🔴 **The question this answers is "can I break this yet?"** — deprecate before reworking a part,
 * drop an obsolete API call, stop maintaining a loader adapter. That is a question of RECENCY, and
 * the screen it replaces showed a historical volume: a version dead for three weeks that once peaked
 * at 40 sat at the top, a version alive yesterday with 2 copies sat at the bottom.
 *
 * 🔴 **Every version keeps its row, and the span decides how it READS.** The previous screen filtered
 * the LIST by the span, so a version with no recent call vanished — and nobody decides to break
 * something on an absence. Here the span draws a line: above it, what has called; below it, what has
 * not. Below the line is what can be broken.
 *
 * Three sources, joined here and nowhere else:
 *
 *  - `releases`         what we published, and when — so "last seen 40 days ago" can be judged, and
 *                       so a release NOBODY has ever run is visible at all (it has no usage row).
 *  - `version_activity` since when and until when each build has called, whole history, one row.
 *  - `client_usage_daily` the shape of the last N days — the only thing the register cannot hold.
 */
class VersionInventory
{
    /** Below this, a band of one segment says nothing; the dates already do. */
    private const MIN_DAYS_FOR_BAND = 7;

    /** Past this many days, one segment is a week — 365 segments do not fit and do not read. */
    private const DAILY_SEGMENTS_UP_TO = 90;

    /**
     * Everything the screen needs, per product.
     *
     * Returns ['mod' => [...], 'manager' => [...]], each with 'versions', 'buckets', 'adapters' and
     * the band geometry.
     */
    public static function forSpan(int $days): array
    {
        $inventory = [];

        foreach (['mod', 'manager'] as $product) {
            $inventory[$product] = self::product($product, $days);
        }

        return $inventory;
    }

    private static function product(string $product, int $days): array
    {
        $slots = self::segmentStarts($days);

        $byVersion = ClientUsageDaily::dailySeries($days, $product, 'version');
        $seenByVersion = self::registerBy($product, 'version');

        // ⚠ The loader grain is only asked for where it exists — see the adapters block below.
        $byVariant = $product === 'mod' ? ClientUsageDaily::dailySeries($days, $product, 'variant') : [];
        $seenByVariant = $product === 'mod' ? self::registerBy($product, 'variant') : [];

        $versions = [];
        $buckets = [];
        $outOfReach = [];
        $releases = Release::forProduct($product);

        foreach ($releases as $release) {
            $line = self::line(
                $release->version,
                $byVersion[$release->version] ?? [],
                $seenByVersion[$release->version] ?? null,
                $slots,
                $release,
            );

            // 🔴 Published before the counter existed and never seen since: nothing can be known
            // about it, so it is summarised rather than listed. Showing thirty of these as "never"
            // states a measurement that was never taken, and buries the handful of rows that mean
            // something under noise.
            //
            // ⚠ **Activity inside the span vetoes the fold, whatever the register says.** The two
            // sources are written together and should agree, but a version that is visibly calling
            // must never be summarised away because the other table has not heard of it — a partial
            // import or a replayed history would otherwise hide a live build.
            if (!$line['active'] && $line['last_seen'] === null && self::predatesCounting($release)) {
                $outOfReach[] = $line;
                continue;
            }

            $versions[] = $line;
        }

        // 🔴 **The line separates what CAN be broken from what cannot, and "never seen" is on the
        // wrong side of it by default.** A release published two days ago that nobody has run yet is
        // not extinct — it is waiting, and the question it raises is "why has nobody taken it",
        // never "may I break it". Sorting on activity alone filed it under "nothing in the last 30
        // days", i.e. exactly beside the versions one is invited to drop.
        //
        // So the line is drawn on `retired`: seen once, and not since. Publication order inside each
        // group, so the list stays stable from one visit to the next.
        usort($versions, fn ($a, $b) => [$a['retired'], self::order($b)] <=> [$b['retired'], self::order($a)]);
        usort($outOfReach, fn ($a, $b) => self::order($b) <=> self::order($a));

        // ⚠ Anything that has called but is not a release of ours: the two buckets, and any version
        // that was recognised once and is no longer returned by GitHub. Shown apart rather than
        // dropped — a build we cannot name is still a build somebody is running.
        $published = $releases->pluck('version')->all();

        // 🔴 **The union of both tables, never one of them.** Listing only what the register knows
        // loses anything that is calling but has no register row — and a version that vanishes from
        // the screen is the exact failure this card was rebuilt to fix. They are written together
        // and should always agree; the day they do not, the screen must show the discrepancy rather
        // than silently pick a side.
        foreach (self::namesIn($seenByVersion, $byVersion) as $version) {
            if (in_array($version, $published, true)) {
                continue;
            }

            $buckets[] = self::line(
                (string) $version,
                $byVersion[$version] ?? [],
                $seenByVersion[$version] ?? null,
                $slots,
                null,
            );
        }

        // ⚠ Only the mod runs under a loader. Asking the same question of the Manager would produce
        // one row named after the absence of something it never had.
        $adapters = [];
        if ($product === 'mod') {
            foreach (self::namesIn($seenByVariant, $byVariant) as $variant) {
                $adapters[] = self::line(
                    (string) $variant,
                    $byVariant[$variant] ?? [],
                    $seenByVariant[$variant] ?? null,
                    $slots,
                    null,
                );
            }
        }

        // What has called most recently comes first, and the busiest breaks the tie: for a loader
        // there is no publication date to order by, so recency is the only meaningful axis.
        usort($adapters, fn ($a, $b) => [$b['last_seen'] ?? '', $b['copies']] <=> [$a['last_seen'] ?? '', $a['copies']]);
        usort($buckets, fn ($a, $b) => [$b['last_seen'] ?? '', $b['copies']] <=> [$a['last_seen'] ?? '', $a['copies']]);

        // ⚠ **One scale per block, not one for the card.** The loader block counts every version
        // together, so its busiest day is necessarily higher than any single version's — scaling the
        // versions against it flattened them all into a line barely a pixel tall. Bars are only ever
        // compared against their neighbours in the same table, which is the only comparison the
        // reader can actually make.
        $peak = fn (array $group) => max(1, ...array_map(fn ($line) => $line['copies'], $group ?: [['copies' => 0]]));

        return [
            'versions' => $versions,
            'buckets' => $buckets,
            'adapters' => $adapters,
            'out_of_reach' => $outOfReach,
            'peaks' => [
                'versions' => $peak($versions),
                'buckets' => $peak($buckets),
                'adapters' => $peak($adapters),
            ],
            'band' => count($slots) >= self::MIN_DAYS_FOR_BAND ? count($slots) : 0,
            'weekly' => $days > self::DAILY_SEGMENTS_UP_TO,
            'anything' => $versions !== [] || $buckets !== [] || $adapters !== [] || $outOfReach !== [],
        ];
    }

    /**
     * Was this release out before anything was being counted?
     *
     * ⚠ A release whose date we never learned is treated as old. The dates only arrive with the
     * hourly refresh, and until they do, claiming "never seen" about a version we cannot even place
     * in time would be the same false measurement in another form.
     */
    private static function predatesCounting(Release $release): bool
    {
        return $release->published_at === null
            || $release->published_at->toDateString() < ClientUsageDaily::COUNTING_STARTED;
    }

    /**
     * Every name either table knows about.
     *
     * ⚠ Keys are compared as strings: PHP turns a numeric-looking array key into an integer, so a
     * version named "12" would otherwise not match itself across the two sources.
     */
    private static function namesIn(array $register, array $series): array
    {
        $names = array_merge(array_keys($register), array_keys($series));

        return array_values(array_unique(array_map('strval', $names)));
    }

    /** Publication order, with an unknown date sorting oldest — see `predatesCounting`. */
    private static function order(array $line): string
    {
        return $line['published_at']?->format('Y-m-d H:i:s') ?? '';
    }

    /**
     * One row of the table.
     *
     * ⚠ `active` is what the span decides, and nothing else: has this called at all inside it. It is
     * the line of the table, not a threshold anybody had to invent — asking for 7 days asks a
     * different question from asking for a year, and the answer moves with the question.
     */
    private static function line(string $name, array $series, ?array $seen, array $slots, ?Release $release): array
    {
        $segments = self::segments($series, $slots);

        return [
            'name' => $name,
            'published_at' => $release?->published_at,
            'prerelease' => (bool) $release?->prerelease,
            'first_seen' => $seen['first_seen'] ?? null,
            'last_seen' => $seen['last_seen'] ?? null,
            // The busiest single day of the span, never the days added up.
            'copies' => $series === [] ? 0 : max($series),
            'days_in_span' => count($series),
            'segments' => $segments,
            'active' => $series !== [],
            // ⚠ Never seen is NOT retired: a release published this week that nobody has taken yet
            // has to be read as "why", not as "droppable". Only something that called once and has
            // gone quiet since belongs below the line.
            'retired' => $series === [] && ($seen['last_seen'] ?? null) !== null,
        ];
    }

    /**
     * The whole history, one row per name — `[name => ['first_seen' => ..., 'last_seen' => ...]]`.
     *
     * ⚠ `days_active` is deliberately NOT summed up here. It is written per (version × loader), and
     * two loaders calling on the same day would count that day twice. The number of days inside the
     * span is counted from the series instead, where it is exact.
     */
    private static function registerBy(string $product, string $column): array
    {
        $rows = VersionActivity::where('product', $product)
            ->selectRaw("{$column} as bucket, MIN(first_seen) as first_seen, MAX(last_seen) as last_seen")
            ->groupBy($column)
            ->get();

        $register = [];

        foreach ($rows as $row) {
            // ⚠ Read through the alias, so the model's `variant` accessor (which turns '' into null)
            // does not apply: the empty string is a meaningful bucket here — "no loader named" — and
            // it must stay distinct from any real one.
            $bucket = (string) ($row->getAttributes()['bucket'] ?? '');

            $existing = $register[$bucket] ?? null;

            // ⚠ Two rows can land in the same bucket (a mod that called before its adapter was
            // known, folded in with the unrecognised ones), so the widest span wins rather than the
            // last row read.
            $register[$bucket] = [
                'first_seen' => min($existing['first_seen'] ?? '9999-12-31', substr((string) $row->first_seen, 0, 10)),
                'last_seen' => max($existing['last_seen'] ?? '', substr((string) $row->last_seen, 0, 10)),
            ];
        }

        return $register;
    }

    /**
     * The first day of each segment of the band, oldest first.
     *
     * ⚠ Weekly past 90 days: 365 segments neither fit in a table cell nor read as a shape. The band
     * always covers exactly the chosen span, so its right-hand end is always today.
     */
    private static function segmentStarts(int $days): array
    {
        $today = CarbonImmutable::now()->startOfDay();
        $step = $days > self::DAILY_SEGMENTS_UP_TO ? 7 : 1;
        $count = (int) ceil($days / $step);

        $starts = [];
        for ($i = $count - 1; $i >= 0; $i--) {
            $starts[] = $today->subDays($i * $step);
        }

        return $starts;
    }

    /**
     * Copies per segment, 0 where nothing called.
     *
     * The zeroes are the information: a band that stops halfway is a version that died halfway.
     */
    private static function segments(array $series, array $slots): array
    {
        if ($slots === []) {
            return [];
        }

        $step = count($slots) > 1
            ? $slots[0]->diffInDays($slots[1])
            : 1;

        $segments = [];

        foreach ($slots as $start) {
            $total = 0;

            for ($offset = 0; $offset < $step; $offset++) {
                $day = $start->addDays($offset)->format('Y-m-d');
                $total = max($total, $series[$day] ?? 0);
            }

            $segments[] = $total;
        }

        return $segments;
    }
}
