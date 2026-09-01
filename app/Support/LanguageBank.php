<?php

namespace App\Support;

/**
 * The shape of the language banks: which lines exist, in what order, and which vintage that is.
 *
 * 🔴 Why this is not private to the controller any more. The whole effect rests on "index N is the
 * same line in every language", and the browser holds TWO banks at once — so the page has to be able
 * to state which shape it is serving today, and a view cannot ask a controller.
 *
 * ⚠ What went wrong without it, on 2026-09-01: the banks were cached per URL for a day and the URL
 * carried no version. A visitor held a Hindi bank from 31 August alongside a French one from today.
 * "Rapide" is line 775 today; on the 31st line 775 was `profile.prompt_title`, so the button showed
 * the Hindi for "Do you want to change your username?" — a real string, correctly fetched, read at
 * an index that meant something else two days earlier.
 *
 * The controller's own note already warned that "an index that shifted under a client holding a
 * cached copy of another language would pair French sentences with Korean ones — plausibly, and
 * therefore invisibly". Sorting the keys answered half of it: it stops a REORDER from shifting
 * anything. It does nothing about an INSERT, and every key added to `en.json` shifts every line
 * after it.
 */
class LanguageBank
{
    /** Longest line we are prepared to swap in. Past this it stops being a wink and starts being a
     *  paragraph rearranging itself under the reader. */
    private const MAX_LENGTH = 40;

    /**
     * Which vintage the banks are. Changes whenever a language file is touched.
     *
     * The page states it, every fetch carries it, and every response repeats it. Two banks built
     * from different key lists therefore cannot meet: they are different URLs, and if a proxy
     * collapses them anyway the client sees two different values here and refuses the pair.
     */
    public static function version(): string
    {
        static $cached = null;

        if ($cached !== null) {
            return $cached;
        }

        $latest = 0;
        foreach (glob(lang_path('*.json')) ?: [] as $file) {
            $latest = max($latest, (int) filemtime($file));
        }

        return $cached = (string) $latest;
    }

    /** One locale, as the ordered array of lines the browser reads by index. */
    public static function lines(string $locale): array
    {
        $source = self::read($locale);

        $lines = [];
        foreach (self::canonicalKeys() as $key) {
            $value = $source[$key] ?? null;
            // ⚠ A locale missing a line gets an empty string, never a shorter array: dropping it
            // would shift everything after it, which is the very failure this class exists to stop.
            $lines[] = is_string($value) && self::usable($value) ? $value : '';
        }

        return $lines;
    }

    /**
     * The order every locale is served in, fixed by the English file.
     *
     * ⚠ Sorted, not left in file order. `en.json` is reordered by `sync-translations.py` whenever
     * keys move, and a reorder would otherwise shift indices for no reason at all.
     */
    public static function canonicalKeys(): array
    {
        $en = self::read(config('locales.fallback', 'en'));

        $keys = [];
        foreach ($en as $key => $value) {
            if (is_string($value) && self::usable($value)) {
                $keys[] = $key;
            }
        }

        sort($keys);

        return $keys;
    }

    private static function read(string $locale): array
    {
        $path = lang_path("{$locale}.json");
        if (! is_file($path)) {
            return [];
        }

        return json_decode(file_get_contents($path), true) ?: [];
    }

    private static function usable(string $value): bool
    {
        $value = trim($value);

        if ($value === '' || mb_strlen($value) > self::MAX_LENGTH) {
            return false;
        }

        // `:name` placeholders, `one|many` plurals, and markup. All three are templates the server
        // fills in, so the raw form is not a sentence anybody should see.
        if (str_contains($value, '|') || str_contains($value, '<') || preg_match('/:\p{L}/u', $value)) {
            return false;
        }

        // At least one letter, in any script — this drops bare numbers, dashes and lone symbols,
        // which carry no language and would make the swap look like nothing happened.
        return (bool) preg_match('/\p{L}/u', $value);
    }
}
