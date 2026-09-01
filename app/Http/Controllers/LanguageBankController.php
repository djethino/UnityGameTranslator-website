<?php

namespace App\Http\Controllers;

use App\Support\LanguageBank;
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
 * 🔴 ...for one vintage of `en.json`, and that qualifier is the whole point of `v`. Any key added to
 * the English file shifts every line after it, so two banks built from different key lists cannot be
 * read against each other. The response says which vintage it is; the client refuses a pair that
 * disagrees. See App\Support\LanguageBank for what this cost before it was there.
 *
 * ── What is deliberately excluded ──────────────────────────────────────────────────────────────
 * Anything with a `:placeholder`, a `|` plural or HTML in it. Those are templates, not sentences,
 * and a half-rendered template on screen reads as a bug rather than as an effect.
 */
class LanguageBankController extends Controller
{
    public function show(Request $request, string $locale): Response
    {
        abort_unless(array_key_exists($locale, config('locales.supported')), 404);

        $version = LanguageBank::version();

        $body = Cache::remember(
            "lang-bank:{$locale}:{$version}",
            now()->addDay(),
            fn () => json_encode(
                ['v' => $version, 'lines' => LanguageBank::lines($locale)],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ),
        );

        $response = response($body, 200)
            ->header('Content-Type', 'application/json; charset=utf-8')
            // ⚠ Safe to cache hard ONLY because the caller puts the version in the query string: a
            // new key list is a new URL, so a held copy can never be paired with a fresh one. Before
            // that it was the same URL for every vintage, and a day-long cache was long enough to
            // mix two of them.
            ->header('Cache-Control', 'public, max-age=86400');

        $response->setEtag(hash('sha256', $body));

        // Turns the response into an empty 304 in place when the client already holds this version.
        // Called for its effect, not its answer.
        $response->isNotModified($request);

        return $response;
    }
}
