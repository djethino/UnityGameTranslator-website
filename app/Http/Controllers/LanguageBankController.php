<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Short interface strings, in one language, for the background to play with.
 *
 * The site exists to translate games, so its own decoration may as well be made of translations:
 * every so often a word on screen slips into another of the twenty languages and slips back. This
 * endpoint is where the other twenty languages come from.
 *
 * ── Why an ordered list and not an object ──────────────────────────────────────────────────────
 * The browser needs to do two things: recognise a string it can see (so it needs the CURRENT
 * locale) and find the same line in another language (so it needs a SECOND locale). Keys are what
 * link the two, but `translation.type.ai_corrected_short` costs more to ship than the sentence it
 * names. So every locale is served as a plain array in one canonical order, derived from the
 * English file — index 412 is the same line in every language, and no key travels at all.
 *
 * ⚠ A locale that is missing a line gets an empty string at that index rather than a shorter array.
 * Dropping it would shift everything after it, and the shift would be silent: the page would still
 * work, showing confidently mismatched translations.
 *
 * ── What is deliberately excluded ──────────────────────────────────────────────────────────────
 * Anything with a `:placeholder`, a `|` plural or HTML in it. Those are templates, not sentences,
 * and a half-rendered template on screen reads as a bug rather than as an effect.
 */
class LanguageBankController extends Controller
{
    /** Longest line we are prepared to swap in. Past this it stops being a wink and starts being a
     *  paragraph rearranging itself under the reader. */
    private const MAX_LENGTH = 40;

    public function show(Request $request, string $locale): Response
    {
        abort_unless(array_key_exists($locale, config('locales.supported')), 404);

        $body = Cache::remember(
            "lang-bank:{$locale}:" . $this->stamp(),
            now()->addDay(),
            fn () => $this->build($locale),
        );

        $response = response($body, 200)
            ->header('Content-Type', 'application/json; charset=utf-8')
            // These change when we ship a translation, which is a few times a month at most, and
            // the client re-validates with the ETag anyway.
            ->header('Cache-Control', 'public, max-age=86400');

        $response->setEtag(hash('sha256', $body));

        // Turns the response into an empty 304 in place when the client already holds this version.
        // Called for its effect, not its answer.
        $response->isNotModified($request);

        return $response;
    }

    /** Changes whenever a language file is touched, so a deploy cannot serve a stale bank. */
    private function stamp(): string
    {
        $latest = 0;
        foreach (glob(lang_path('*.json')) ?: [] as $file) {
            $latest = max($latest, (int) filemtime($file));
        }

        return (string) $latest;
    }

    private function build(string $locale): string
    {
        $keys = $this->canonicalKeys();
        $source = $this->read($locale);

        $lines = [];
        foreach ($keys as $key) {
            $value = $source[$key] ?? null;
            $lines[] = is_string($value) && $this->usable($value) ? $value : '';
        }

        return json_encode(
            ['v' => 1, 'lines' => $lines],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }

    /**
     * The order every locale is served in, fixed by the English file.
     *
     * ⚠ Sorted, not left in file order. `en.json` is reordered by `sync-translations.py` whenever
     * keys move, and an index that shifted under a client holding a cached copy of another language
     * would pair French sentences with Korean ones — plausibly, and therefore invisibly.
     */
    private function canonicalKeys(): array
    {
        $en = $this->read(config('locales.fallback', 'en'));

        $keys = [];
        foreach ($en as $key => $value) {
            if (is_string($value) && $this->usable($value)) {
                $keys[] = $key;
            }
        }

        sort($keys);

        return $keys;
    }

    private function read(string $locale): array
    {
        $path = lang_path("{$locale}.json");
        if (! is_file($path)) {
            return [];
        }

        return json_decode(file_get_contents($path), true) ?: [];
    }

    private function usable(string $value): bool
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
