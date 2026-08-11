<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * The shared catalogues — languages, mod loaders, AI models — as this site sees them.
 *
 * The catalogues live in their own repository (unitygametranslator-catalogs) because they hold
 * FACTS that change without us: a provider adds a language, a loader ships a release. The mod and
 * the Manager cannot read them at run time — they target netstandard2.0, which has no JSON parser,
 * and the shared library takes no packages — so their copies are generated at build time. PHP has
 * no such excuse, so this site reads the catalogue instead of keeping a hand-written twin of it.
 *
 * TWO RUNGS, and no third:
 *
 *   1. storage/app/catalog/*.json   what the scheduler last fetched from GitHub (not in git)
 *   2. resources/catalog/*.json     the copy that travels with the code (committed)
 *
 * ⚠ There is deliberately NO network rung here. Rendering a page must never depend on GitHub
 * answering. Only `catalog:refresh` goes out, on the scheduler, and it only ever replaces rung 1
 * with something it has already checked.
 *
 * ⚠ The committed copy is the catalogue file itself — a copy of it, never a summary and never a
 * reformatting. It is what /catalog serves when nothing has been fetched yet, and the Manager
 * parses that with the same code it uses on the real file. Two files that merely resemble each
 * other would eventually describe two different catalogues, and which one answered would depend
 * on the network.
 */
class CatalogStore
{
    /** The catalogue documents this site knows about. Also what /catalog is willing to serve. */
    public const FILES = ['languages', 'loaders', 'models'];

    /**
     * A floor, not a target. Any real catalogue has dozens of entries; a handful means something
     * truncated the file, and accepting it would quietly turn most languages into invalid ones.
     */
    private const MINIMUM_LANGUAGES = 50;

    /**
     * Read once per request. The language list is wanted by a form, a validator and an admin
     * filter, and re-reading 21 KB three times to get the same answer is pure waste.
     *
     * Deliberately NOT the application cache: that would need invalidating when the scheduler
     * writes, and a language list stuck on a stale cache entry is exactly the failure this whole
     * arrangement exists to remove. A per-request memo cannot go stale.
     */
    private static array $memo = [];

    public static function refreshedPath(string $name): string
    {
        return storage_path("app/catalog/{$name}.json");
    }

    public static function committedPath(string $name): string
    {
        return resource_path("catalog/{$name}.json");
    }

    /**
     * When this catalogue was last confirmed against the published one, or null if it never was.
     *
     * Worth having because the failure this arrangement is built to survive — the catalogue being
     * unreachable — looks exactly like everything working. Readers fall back to the committed copy
     * and the site stays correct, so a source that has been unreachable for six months produces no
     * symptom at all. This is the one number that would say so.
     *
     * It is the file's modification time, and `catalog:refresh` touches the file even when the
     * payload is identical: the question is "when did we last know this was current", not "when
     * did it last change".
     */
    public static function lastConfirmedAt(string $name): ?\DateTimeImmutable
    {
        self::assertKnown($name);

        $path = self::refreshedPath($name);
        $time = is_readable($path) ? @filemtime($path) : false;

        return $time === false ? null : new \DateTimeImmutable('@' . $time);
    }

    /**
     * The catalogue file as text, freshest usable rung first.
     *
     * A fetched file that no longer parses is stepped over, not repaired: the committed copy is
     * always there, and serving something we cannot read would push the problem to whoever
     * consumes it.
     */
    public static function raw(string $name): string
    {
        self::assertKnown($name);

        $fetched = self::refreshedPath($name);
        if (is_readable($fetched)) {
            $text = @file_get_contents($fetched);
            if ($text !== false && self::looksLikeDocument($text)) {
                return $text;
            }

            Log::warning('Fetched catalogue is unusable, falling back to the committed copy', [
                'catalogue' => $name,
            ]);
        }

        $committed = self::committedPath($name);
        $text = is_readable($committed) ? @file_get_contents($committed) : false;

        if ($text === false) {
            // Not something to paper over. This file is committed; if it is missing or unreadable
            // the deployment is broken, and every language would silently become invalid.
            throw new \RuntimeException(
                "Catalogue '{$name}' is missing from the deployment (expected {$committed})."
            );
        }

        return $text;
    }

    /** The catalogue file, decoded. */
    public static function document(string $name): array
    {
        if (isset(self::$memo[$name])) {
            return self::$memo[$name];
        }

        $decoded = json_decode(self::raw($name), true);

        if (!is_array($decoded)) {
            throw new \RuntimeException("Catalogue '{$name}' is not valid JSON.");
        }

        return self::$memo[$name] = $decoded;
    }

    /**
     * Every language a translation may be published in, in catalogue order.
     *
     * ⚠ This list IS the upload contract. The upload endpoint validates target_language against
     * it and the mod carries the same names, so a name that differs on one side shows up as an
     * upload refused by a validation error the contributor did not cause and cannot read.
     *
     * A name, not a code: five of these have no ISO 639-1 code at all, and the name is what
     * travels everywhere — stored here, resolved by the mod, written into the game's config.
     *
     * It does NOT describe what any model can translate. Models are picked from a catalogue and
     * most will attempt any pair with varying success; this is what the site accepts to host.
     */
    public static function languageNames(): array
    {
        $names = array_values(array_filter(array_map(
            fn ($entry) => is_array($entry) ? ($entry['name'] ?? null) : null,
            self::document('languages')['languages'] ?? []
        )));

        if (count($names) < self::MINIMUM_LANGUAGES) {
            throw new \RuntimeException(
                'The language catalogue holds ' . count($names) . ' names, which cannot be right. '
                . 'Refusing to run with a list that would reject valid uploads.'
            );
        }

        return $names;
    }

    /** Cheap sanity check on fetched text before it is trusted enough to be parsed or served. */
    public static function looksLikeDocument(string $text): bool
    {
        if (strlen($text) < 200) {
            return false;
        }

        $decoded = json_decode($text, true);

        return is_array($decoded) && $decoded !== [];
    }

    private static function assertKnown(string $name): void
    {
        if (!in_array($name, self::FILES, true)) {
            throw new \InvalidArgumentException("Unknown catalogue '{$name}'.");
        }
    }
}
