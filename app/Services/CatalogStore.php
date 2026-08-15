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
    public const FILES = ['languages', 'loaders', 'models', 'flags'];

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

    /**
     * The canonical tag of the language a system locale is asking for, or null if it is none of
     * ours. The PHP counterpart of UnityGameTranslator.Common.Languages.FromLocale — same rule,
     * because a machine reporting zh-Hant-TW must not be understood differently by the site and
     * by the mod.
     *
     * The locale is shortened ONE SEGMENT AT A TIME (RFC 4647 lookup), never truncated to two
     * letters. That distinction is the whole point: "zh-Hant-TW" shortens to "zh-Hant", which the
     * catalogue knows as Traditional Chinese, and stops there. Cutting to "zh" would have answered
     * Simplified Chinese — a different language, offered to somebody who asked for the other one.
     *
     * ⚠ What comes back is a catalogue tag, NOT a site locale. Mapping one to the other is a
     * separate decision and deliberately not made here: the catalogue knows 90 languages and the
     * interface is translated into 19, and what happens to the other 71 is a policy (English, see
     * the `about.interface_fallback` note in the catalogue), not a lookup.
     */
    public static function canonicalTag(?string $locale): ?string
    {
        if ($locale === null || trim($locale) === '') {
            return null;
        }

        // Underscores because some systems report fr_FR; case because BCP 47 is case-insensitive
        // and every source spells the script subtag its own way (zh-Hant, zh-hant, ZH-HANT).
        $wanted = strtolower(str_replace('_', '-', trim($locale)));
        $index = self::localeIndex();

        while ($wanted !== '') {
            if (isset($index[$wanted])) {
                return $index[$wanted];
            }

            $cut = strrpos($wanted, '-');
            if ($cut === false) {
                return null;
            }

            $wanted = substr($wanted, 0, $cut);
        }

        return null;
    }

    /**
     * Every code a language answers to, pointing at the one code it is written down as.
     *
     * The aliases are not decoration: a system reports zh-CN, a browser sends zh-Hans, Java still
     * emits iw for Hebrew and most systems say no for Norwegian Bokmål. Dropping them would not
     * lose a language, it would lose the ability to recognise the machine somebody is actually on.
     */
    /**
     * What marks one language: its flag, its tag, and whether the tag has to be shown.
     *
     * 🔴 **This mirrors UnityGameTranslator.Common.Flags.Mark, which PHP cannot consume.** Same
     * situation as the theme and the badge words. The rule it mirrors, stated once so the two
     * cannot drift on a reading: a flag names a COUNTRY and this control names a LANGUAGE, so when
     * one flag stands for several languages of the catalogue — ten Indian ones, because no Indian
     * state has a flag of its own; the two Norwegians, because they are two written standards of
     * one country — every one of them shows its tag beside it. A language with no flag drawn yet
     * shows its tag alone.
     *
     * ⚠ Derived from the catalogue, never from a list: adding an eleventh Indian language turns the
     * chips on for all eleven with nobody having to remember why.
     *
     * @return array{flag: ?string, tag: ?string, showTag: bool}
     */
    public static function languageMark(?string $languageName): array
    {
        $none = ['flag' => null, 'tag' => null, 'showTag' => false];

        if ($languageName === null || $languageName === '') {
            return $none;
        }

        $marks = self::$memo['#marks'] ??= self::buildMarks();

        return $marks[$languageName] ?? $none;
    }

    /** @return array<string, array{flag: ?string, tag: ?string, showTag: bool}> */
    private static function buildMarks(): array
    {
        $entries = self::document('languages')['languages'] ?? [];

        $carriers = [];
        foreach ($entries as $entry) {
            $flag = $entry['flag'] ?? null;
            if ($flag !== null) {
                $carriers[$flag] = ($carriers[$flag] ?? 0) + 1;
            }
        }

        $marks = [];
        foreach ($entries as $entry) {
            $name = (string) ($entry['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $flag = $entry['flag'] ?? null;

            $marks[$name] = [
                'flag' => $flag,
                'tag' => $entry['tag'] ?? null,
                // No flag means the tag is the only thing naming this language; a shared flag means
                // it names ten of them at once. Both need the chip, for the same reason.
                'showTag' => $flag === null || ($carriers[$flag] ?? 0) > 1,
            ];
        }

        return $marks;
    }

    /**
     * One flag as its grid and palette, or null when it has not been drawn.
     *
     * @return array{width: int, height: int, palette: array<string, string>, rows: string[]}|null
     */
    public static function flag(?string $id): ?array
    {
        if ($id === null || $id === '') {
            return null;
        }

        $document = self::document('flags');
        $flag = $document['flags'][$id] ?? null;

        if (!is_array($flag) || !isset($flag['palette'], $flag['rows'])) {
            return null;
        }

        return [
            'width' => (int) ($document['grid']['width'] ?? 0),
            'height' => (int) ($document['grid']['height'] ?? 0),
            'palette' => $flag['palette'],
            'rows' => $flag['rows'],
        ];
    }

    private static function localeIndex(): array
    {
        if (isset(self::$memo['#locales'])) {
            return self::$memo['#locales'];
        }

        $index = [];

        foreach (self::document('languages')['languages'] ?? [] as $entry) {
            $tag = strtolower((string) ($entry['tag'] ?? ''));
            if ($tag === '') {
                continue;
            }

            $index[$tag] = $tag;

            foreach ($entry['also'] ?? [] as $alias) {
                $alias = strtolower((string) $alias);
                // An alias never displaces a canonical tag: if two entries ever disagree, the one
                // that writes the code down wins, rather than whichever came last in the file.
                if ($alias !== '' && !isset($index[$alias])) {
                    $index[$alias] = $tag;
                }
            }
        }

        return self::$memo['#locales'] = $index;
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
