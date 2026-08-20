<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

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
 * the new ones in silence — a wrong measurement that looks like a normal one, which is precisely
 * the defect this whole day went into repairing.
 *
 * **The non-arbitrary bound is that WE publish the versions.** Anything matching a real release
 * gets its own line; everything else — invented, mistyped, a local build — is added to one single
 * `unrecognised` line. The table can then only be as large as the number of releases we have made,
 * and `unrecognised` climbing is information rather than damage.
 *
 * ⚠ **No network call is ever made from a web request.** That rule comes from CatalogStore and it
 * holds here for the same reason: rendering, or answering an API call, must never depend on GitHub
 * being up. Only the scheduled `releases:refresh` goes out, and it only ever replaces the cache
 * with something it has checked.
 */
class KnownReleases
{
    private const CACHE_FILE = 'releases/published.json';

    /** Read once per request, like the catalogue's memo — the same answer is wanted several times. */
    private static ?array $memo = null;

    /**
     * The repositories that publish what talks to this site.
     *
     * ⚠ The Manager's releases are its own repository, and it is deliberately listed even though it
     * has never shipped: the day it does, its versions must be recognised without a deployment
     * here. An empty list for it simply means every Manager is "unrecognised" until then, which is
     * true.
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

        $stored = [];

        try {
            if (Storage::disk('local')->exists(self::CACHE_FILE)) {
                $stored = json_decode(Storage::disk('local')->get(self::CACHE_FILE), true) ?: [];
            }
        } catch (\Throwable $e) {
            // A cache we cannot read is a cache we do not have. Said out loud, because silently
            // treating every caller as unrecognised would look exactly like an attack.
            Log::warning('KnownReleases: cache unreadable', ['error' => $e->getMessage()]);
        }

        return self::$memo = [
            'mod' => array_values(array_filter((array) ($stored['mod'] ?? []), 'is_string')),
            'manager' => array_values(array_filter((array) ($stored['manager'] ?? []), 'is_string')),
        ];
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
        try {
            if (!Storage::disk('local')->exists(self::CACHE_FILE)) {
                return null;
            }

            return new \DateTimeImmutable('@' . Storage::disk('local')->lastModified(self::CACHE_FILE));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Replace the cache. Called only by the scheduled command.
     *
     * ⚠ Refuses to overwrite a list it has with nothing: a GitHub hiccup must not turn every
     * installed copy into "unrecognised" overnight. Same reasoning as the catalogue refusing a
     * truncated file.
     */
    public static function store(array $versions): bool
    {
        $clean = [
            'mod' => array_values(array_filter((array) ($versions['mod'] ?? []), 'is_string')),
            'manager' => array_values(array_filter((array) ($versions['manager'] ?? []), 'is_string')),
        ];

        if ($clean['mod'] === [] && $clean['manager'] === [] && self::known()) {
            Log::warning('KnownReleases: refusing to replace a known list with an empty one');

            return false;
        }

        Storage::disk('local')->put(self::CACHE_FILE, json_encode($clean, JSON_PRETTY_PRINT));
        self::forget();

        return true;
    }
}
