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
     * The models somebody can actually install, reference first, then by how small a card they
     * need.
     *
     * ⚠ Entries WITHOUT a `pull` are deliberately excluded, and they are not incomplete records:
     * they exist so the tool can recognise a model somebody already has, matching as a substring
     * ("qwen3.5" catching "qwen3.5:32b"). Listing them as choices would offer a download that does
     * not exist.
     *
     * ⚠ Sorted by memory needed, NEVER by score. The catalogue forbids ranking in as many words,
     * and for a good reason: the suite is a heuristic on free text, and the machine matters as much
     * as the model. Smallest-first answers the only question a reader actually has — what will run
     * on the card I own — and it puts the 4 GB entries in front of somebody who would otherwise
     * conclude they need a 4090.
     */
    public static function installable(): array
    {
        $models = array_values(array_filter(
            self::all(),
            fn ($m) => is_array($m) && !empty($m['pull'])
        ));

        usort($models, function ($a, $b) {
            $aRef = ($a['role'] ?? '') === 'reference';
            $bRef = ($b['role'] ?? '') === 'reference';
            if ($aRef !== $bRef) {
                return $aRef ? -1 : 1;
            }

            return ($a['min_vram_gb'] ?? PHP_INT_MAX) <=> ($b['min_vram_gb'] ?? PHP_INT_MAX);
        });

        return $models;
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
