<?php

namespace App\Services;

/**
 * The AI models the project has actually run, as the catalogue records them.
 *
 * The PHP counterpart of the Manager's ModelNotes: same file, same rules, so the documentation
 * and the desktop tool cannot end up recommending different things.
 *
 * ⚠ WHY THIS IS READ AND NOT WRITTEN. This page used to carry one hand-written paragraph naming a
 * model, its memory use and its language count — translated into nineteen languages, which meant
 * the day any of those figures changed, correcting them took nineteen edits and a translator for
 * each. It had already drifted: it recommended a `:latest` tag that the catalogue explicitly
 * forbids, precisely because a moving tag turns every figure printed next to it into a lie.
 *
 * So nothing factual is translated any more. The translated strings are labels — "Video memory",
 * "Download" — which do not change when the catalogue does. Every name, figure, source and date
 * comes from here.
 */
class ModelCatalog
{
    /**
     * The model the mod is developed against, or null if the catalogue names none.
     *
     * One entry carries role "reference"; everything else is "tested". This is not a winner: it is
     * the one whose behaviour the mod's prompts and retries were written against, which is a
     * different and more useful claim than "the best".
     */
    public static function reference(): ?array
    {
        foreach (self::all() as $model) {
            if (($model['role'] ?? '') === 'reference') {
                return $model;
            }
        }

        return null;
    }

    /**
     * The models somebody can actually install, in the order this project chooses to present them.
     *
     * ⚠ Entries WITHOUT a `pull` are deliberately excluded, and they are not incomplete records:
     * they exist so the tool can recognise a model somebody already has, matching as a substring
     * ("qwen3.5" catching "qwen3.5:32b"). Listing them as choices would offer a download that does
     * not exist.
     *
     * ORDER — a ladder of thresholds, compared in this order, each rung a cost paid while playing:
     *
     *   1. measured at all;
     *   2. gave up on no line;
     *   3. followed every instruction;
     *   4. never had to be asked twice;
     *   5. video memory HELD, least first;
     *   6. the strict-source option;
     *   7. the wait before the first line, shortest first.
     *
     * ⚠ THRESHOLDS, not a weighted score, and that is the decision. A score would let a tenth of a
     * second of loading buy back a line the model refuses to translate, and the two are not the
     * same kind of thing: one is a wait, the other is text left in its original language on screen.
     * So each rung is asked as a yes-or-no, and memory only decides between models that answered
     * alike.
     *
     * ⚠ Retries are a THRESHOLD too, never a count. Four retries out of twenty against five is not
     * a difference anybody can act on, and ranking on it would seat a 7.8 GB model above a 2.8 GB
     * one over a single line. Above the threshold, what decides is the memory left for the game.
     *
     * 🔴 The reference model is NOT first any more. It is what this project develops against — a
     * fact about us, not a measurement — and it carries a mark saying exactly that. Ranking it
     * first put a 16 GB model at the top of a table people read to find one that fits.
     *
     * ⚠ Languages claimed no longer breaks ties. It is the publisher's claim, unverified, and the
     * catalogue's own rule is that nothing here is ordered by language.
     *
     * ⚠ What the order claims is "start here", not "this one is better". The catalogue keeps no
     * rank of its own — the suite is a heuristic on free text and the machine matters as much as
     * the model — and deciding what to show first is left to whoever shows it. That is this
     * method, and the decision is defensible only while the rest holds: every figure stays on
     * screen next to every model, nothing is withheld for scoring badly, and the note saying which
     * machine and which language produced these numbers travels with them.
     *
     * 🔸 THE SAME ORDER IS APPLIED BY THE MANAGER — `ModelNotes.Installable` in the manager
     * repository. Change one, change the other. They cannot share code (PHP and C#, and the shared
     * library takes no JSON parser), so the rule is written twice on purpose.
     *
     * The Manager adds ONE key in front of these: what fits the card it just read. That is the only
     * legitimate difference — a web page has no idea what card the reader owns, so it never demotes
     * anything. Everything after must match. They had drifted once already: the Manager broke ties
     * by LARGEST memory first, so the same catalogue came out in opposite orders depending on which
     * of our own tools you were looking at.
     */
    public static function installable(): array
    {
        return self::rank(array_filter(
            self::all(),
            fn ($m) => is_array($m) && !empty($m['pull'])
        ));
    }

    /**
     * The same ladder applied to any list of models, so the rule can be asked a question.
     *
     * ⚠ Separated from `installable()` for exactly that: a check that feeds the live catalogue back
     * into the code it is checking only proves the code agrees with itself. What has to be verified
     * is that a lost line outranks memory — not that today's file comes out in today's order.
     *
     * @param  array<int|string, array<string, mixed>>  $models
     * @return array<int, array<string, mixed>>
     */
    public static function rank(array $models): array
    {
        $models = array_values($models);

        usort($models, fn ($a, $b) => self::order($a) <=> self::order($b));

        return $models;
    }

    /**
     * The sort key, as a list compared left to right — the same shape as the order documented
     * above, so changing one means changing the other in the same place.
     *
     * Every entry is 0 or 1 except the last two, which are figures compared ascending. A model with
     * no measurement sorts after every measured one rather than in the middle: an unknown score is
     * not a zero, but it is also not a reason to put it first.
     */
    private static function order(array $model): array
    {
        $measured = $model['measured'] ?? null;

        if (!is_array($measured) || $measured === []) {
            return [1, 1, 1, 1, PHP_INT_MAX, 1, PHP_INT_MAX];
        }

        $incomplete = isset($measured['suite'], $measured['suite_of'])
            && $measured['suite'] < $measured['suite_of'];

        return [
            0,
            ($measured['refused'] ?? 0) > 0 ? 1 : 0,
            $incomplete ? 1 : 0,
            ($measured['retried'] ?? 0) > 0 ? 1 : 0,
            self::held($model),
            ($measured['strict_source'] ?? false) === true ? 0 : 1,
            $measured['load_s'] ?? PHP_INT_MAX,
        ];
    }

    /**
     * The memory a model actually held, in GB — what is left for the game while it runs.
     *
     * ⚠ The MEASURED figure, never `min_vram_gb`. That one is rounded up to real card sizes, so
     * four models holding 1.7, 2.8, 3.1 and 3.1 GB all read "4 GB" and sorted as equals —
     * collapsing the very difference this rung exists to expose. The rounded figure answers "will
     * it fit"; only the measured one answers "how much is left".
     */
    private static function held(array $model): float
    {
        return $model['measured']['vram_gb'] ?? $model['min_vram_gb'] ?? PHP_INT_MAX;
    }

    /**
     * The mark beside a model, or null — which is the answer for most rows, and has to be: a mark
     * on every row is a mark on none.
     *
     * Two of them, and each answers a DIFFERENT question a reader arrives with:
     *
     *   "what do you run yourselves?"  → the reference model
     *   "I have a small card"          → the lightest that followed everything
     *
     * 🔴 The Manager carried a third — awarded to anything that followed every instruction and gave
     * up on nothing — and by 2026-09 it landed on nine rows out of ten. It had not changed; the
     * models had. A mark whose condition the whole field eventually meets stops being a mark,
     * silently, and nothing in the code says so.
     *
     * ⚠ The second mark says LIGHTEST, not best: the model it lands on today needed four retries
     * out of twenty, and its retry column says so in amber right beside the mark. The mark points,
     * the columns qualify. Neither is allowed to say the other's part.
     *
     * 🔸 THE SAME TWO MARKS ARE AWARDED BY THE MANAGER — `ModelNotes.Standout`. Which rows get one
     * is the shared decision and is checked on both sides; the WORDS are not shared, and must not
     * be. The Manager is translated into nothing and returns English; this page is read in twenty
     * languages, so it returns a symbol and the view looks the label up.
     *
     * ⚠ That is the opposite call from `strict source`, three lines down in the view, and the
     * difference is the test in `.claude/rules/name-things-in-ui.md`: does the reader have to FIND
     * these words on a screen? `strict_source_language` is an option they go looking for, so it is
     * cited verbatim. A mark is read where it stands and never searched for, so it is translated.
     *
     * @param  array<int, array<string, mixed>>  $among  the rows being shown; "lightest" is a fact
     *                                                   about a list, not about a model.
     * @return 'reference'|'lightest'|null
     */
    public static function standout(array $model, array $among): ?string
    {
        if (($model['role'] ?? '') === 'reference') {
            return 'reference';
        }

        // Compared over EVERY row including the reference, and only awarded to a row that is not
        // it. If the reference ever were the lightest, the honest outcome is that nothing else
        // carries this mark — handing it to the second lightest would name the wrong model.
        $lightest = null;

        foreach ($among as $candidate) {
            if (!self::flawless($candidate) || !isset($candidate['measured']['vram_gb'])) {
                continue;
            }

            if ($lightest === null
                || $candidate['measured']['vram_gb'] < $lightest['measured']['vram_gb']) {
                $lightest = $candidate;
            }
        }

        $pull = $model['pull'] ?? null;

        return $pull !== null && $lightest !== null && ($lightest['pull'] ?? null) === $pull
            ? 'lightest'
            : null;
    }

    /**
     * Followed every instruction and gave up on nothing.
     *
     * ⚠ A FLOOR, not a distinction: nine of the ten measured models pass it. It separates a model
     * worth listing from one that leaves text untranslated, and nothing more. Retries are
     * deliberately not counted — needing one is a cost, not a fault, and the mod absorbs it.
     */
    private static function flawless(array $model): bool
    {
        $measured = $model['measured'] ?? [];

        return isset($measured['suite'], $measured['suite_of'])
            && $measured['suite'] === $measured['suite_of']
            && ($measured['refused'] ?? 0) === 0;
    }

    /**
     * What the figures were measured on, so a reader can weigh them instead of believing them.
     *
     * ⚠ This must travel with any table of measurements. One machine, one graphics card, one pair
     * of languages: what a model HOLDS is a fact about the model and travels, whether it FITS does
     * not. Printing the numbers without this turns a set of observations into a league table, which
     * is exactly what the catalogue refuses to be.
     */
    public static function measurementContext(): array
    {
        $document = CatalogStore::document('models');

        return [
            'card' => $document['measured_on'] ?? null,
            'language' => self::languageName($document['measured_in'] ?? null),
            'updated' => $document['updated'] ?? null,
        ];
    }

    /**
     * The measurement language written the way it writes itself.
     *
     * The catalogue names languages in English, because English names are the identity the whole
     * project trades in — the mod sends them, the upload endpoint validates them. That is right for
     * data and wrong inside a sentence: a French page reading "en traduisant vers French" looks
     * like a translation nobody finished.
     *
     * So the English name is swapped for the language's own name in exactly one case: when the
     * reader is ALREADY on that language's version of the page. A French page then says "vers
     * Français", and an English one still says "into French" rather than the other way round —
     * which is what a first attempt produced, moving the blemish instead of removing it.
     *
     * Every other combination keeps the catalogue's spelling. Writing a language's name in a third
     * language is a translation we do not have and must not guess.
     */
    private static function languageName(?string $english): ?string
    {
        if ($english === null || $english === '') {
            return null;
        }

        foreach (CatalogStore::document('languages')['languages'] ?? [] as $entry) {
            if (($entry['name'] ?? null) !== $english) {
                continue;
            }

            if (($entry['tag'] ?? null) !== app()->getLocale()) {
                break;
            }

            $native = config('locales.supported.' . $entry['tag'] . '.native');

            return is_string($native) ? $native : $english;
        }

        return $english;
    }

    /**
     * How many languages a model's publisher claims.
     *
     * ⚠ A claim, never our measurement — we have verified none of them, and the catalogue says so
     * about itself. Returned as a bare number so the page can label it as a claim rather than
     * present it as a finding.
     */
    public static function claimedLanguages(array $model): ?int
    {
        $supported = $model['languages']['supported'] ?? null;

        return is_int($supported) ? $supported : null;
    }

    /** Where that claim was read, so it can be checked rather than trusted. */
    public static function languageSource(array $model): ?string
    {
        $source = $model['languages']['source'] ?? null;

        return is_string($source) && str_starts_with($source, 'https://') ? $source : null;
    }

    private static function all(): array
    {
        $models = CatalogStore::document('models')['models'] ?? [];

        return is_array($models) ? $models : [];
    }
}
