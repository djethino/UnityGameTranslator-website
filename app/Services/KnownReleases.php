<?php

namespace App\Services;

use App\Models\Release;
use Illuminate\Support\Facades\DB;

/**
 * Which versions of the mod and the Manager have actually been published.
 *
 * 🔴 **This exists to stop a stranger deciding what our own measurements say.** A User-Agent is
 * written by whoever is calling, so `client_usage_daily` — which counts what is installed out there
 * — was letting anyone invent versions: one line per made-up number, without limit, in a table that
 * should hold a dozen rows a day. Two consequences, and the second is worse than the first: the
 * table grows until the disk complains, and the figures that decide whether JSON compression can be
 * switched on become something an outsider can shape.
 *
 * A ceiling on the number of rows was the obvious answer and a bad one: any number picked here is
 * arbitrary, and the day there really are that many versions in circulation it would stop recording
 * the new ones in silence — a wrong measurement that looks like a normal one.
 *
 * **The non-arbitrary bound is that WE publish the versions.** Anything matching a real release
 * gets its own line; everything else — invented, mistyped, a local build — is added to one single
 * `unrecognised` line.
 *
 * ⚠ **This bounds VERSIONS and nothing else.** The loader a mod reports is deliberately unbounded:
 * see `ClientAgent`, and `analyse/version-inventory-admin.md` for why bounding it by the catalogue
 * would hide the copies one most needs to see.
 *
 * 🔴 **Backed by the `releases` table since 2026-08-29, not by a JSON file in `storage/app/`.** The
 * file was neither versioned nor part of any backup, and — worse — it was replaced wholesale on
 * every refresh while the GitHub API only returns the last 100 releases: the day we shipped our
 * hundred-and-first, every early version would have silently become `unrecognised`. A row is never
 * deleted now, so a withdrawn or long-past release stays recognised for whoever is still running it.
 *
 * ⚠ **No network call is ever made from a web request.** That rule comes from CatalogStore and it
 * holds here for the same reason: rendering, or answering an API call, must never depend on GitHub
 * being up. Only the scheduled `releases:refresh` goes out.
 */
class KnownReleases
{
    /** Read once per request, like the catalogue's memo — the same answer is wanted several times. */
    private static ?array $memo = null;

    /**
     * The repositories that publish what talks to this site.
     */
    public const SOURCES = [
        'mod' => 'djethino/UnityGameTranslator',
        'manager' => 'djethino/unitygametranslator-manager',
    ];

    public static function forget(): void
    {
        self::$memo = null;
    }

    /**
     * Published versions per product, e.g. ['mod' => ['0.11.0', '0.10.2'], 'manager' => []].
     *
     * Returns empty lists when nothing has ever been fetched. Callers must treat that as "we do not
     * know", never as "nothing is published" — see `recognises()`.
     */
    public static function all(): array
    {
        if (self::$memo !== null) {
            return self::$memo;
        }

        $known = ['mod' => [], 'manager' => []];

        foreach (Release::query()->get(['product', 'version']) as $release) {
            if (isset($known[$release->product])) {
                $known[$release->product][] = $release->version;
            }
        }

        return self::$memo = $known;
    }

    /**
     * Is this a version we have actually published?
     *
     * ⚠ **False when we know nothing.** If no list has ever been fetched, every version is
     * unrecognised — the measurement is missing rather than wrong, and a missing measurement cannot
     * be shaped by whoever is calling. The admin screen says so rather than showing an empty table
     * as if nobody used the software.
     */
    public static function recognises(string $product, ?string $version): bool
    {
        if ($version === null || $version === '') {
            return false;
        }

        return in_array($version, self::all()[$product] ?? [], true);
    }

    /** Have we ever managed to fetch the list at all? */
    public static function known(): bool
    {
        $all = self::all();

        return count($all['mod']) > 0 || count($all['manager']) > 0;
    }

    public static function lastFetchedAt(): ?\DateTimeImmutable
    {
        $at = Release::max('updated_at');

        return $at === null ? null : new \DateTimeImmutable((string) $at);
    }

    /**
     * Record what the refresh found. Called only by the scheduled command.
     *
     * Each entry is ['version' => string, 'published_at' => ?string, 'prerelease' => bool].
     *
     * ⚠ Refuses to act on nothing when it already knows something: a GitHub hiccup must not be
     * mistaken for "we have never published anything". Same reasoning as the catalogue refusing a
     * truncated file.
     *
     * 🔴 **Nothing is ever deleted.** A release that stops being returned — withdrawn, or simply
     * pushed past the hundredth — must keep being recognised, or every copy still running it lands
     * in `unrecognised` and the one measurement that would tell us to keep supporting it is the one
     * we destroy.
     */
    public static function store(array $releases): bool
    {
        $seen = 0;

        foreach (['mod', 'manager'] as $product) {
            $seen += count((array) ($releases[$product] ?? []));
        }

        if ($seen === 0 && self::known()) {
            \Illuminate\Support\Facades\Log::warning('KnownReleases: refusing to replace a known list with an empty one');

            return false;
        }

        $now = now();
        $rows = [];

        foreach (['mod', 'manager'] as $product) {
            foreach ((array) ($releases[$product] ?? []) as $entry) {
                if (!is_string($entry['version'] ?? null) || $entry['version'] === '') {
                    continue;
                }

                $rows[] = [
                    'product' => $product,
                    'version' => $entry['version'],
                    // ⚠ GitHub sends ISO 8601 with a Z ("2026-08-27T18:56:03Z"), which MariaDB
                    // refuses outright — the whole refresh threw and every date stayed null, which
                    // silently collapsed the ordering of the admin screen.
                    'published_at' => isset($entry['published_at'])
                        ? \Carbon\Carbon::parse($entry['published_at'])->utc()->toDateTimeString()
                        : null,
                    'prerelease' => (bool) ($entry['prerelease'] ?? false),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if ($rows !== []) {
            // ⚠ Updates the two facts that can legitimately change (a pre-release is promoted, a
            // date is learned for a row imported without one) and never touches anything else.
            DB::table('releases')->upsert($rows, ['product', 'version'], ['published_at', 'prerelease', 'updated_at']);
        }

        self::forget();

        return true;
    }
}
