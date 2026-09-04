<?php

namespace App\Support;

use Transliterator;

/**
 * A latin handle for a title written in another script, so it can be TYPED into a search box.
 *
 * 🔴 **For finding, never for showing, and never for identifying.** What comes out is a mechanical
 * romanisation, and it is wrong often enough that displaying it would be worse than saying nothing:
 * ICU turns 龙胤立志传 into "long yin li zhi chuan" where the publisher's own folder says
 * LongYinLiZhiZhuan, it romanises 原神 as "yuan shen" for a game everybody calls Genshin Impact, and
 * it reads Japanese kanji with Chinese values — 無双 comes out "wu shuang" instead of "musou".
 *
 * None of that matters for a search box: somebody typing "long yin" or "genshin" has to land on
 * something, and a near miss on one syllable still matches. It matters enormously beside a title,
 * which is why nothing here is ever printed.
 *
 * ⚠ **It takes no part in identifying a game.** `steam_id` and `unity_name` resolve which game an
 * upload belongs to; this column is read by name searches only. A generated string must never be
 * able to attach somebody's translation to a game.
 *
 * ⚠ Only for titles carrying **no latin letter at all**. "Metro 2033" needs no handle, and giving
 * one to every game would double the index for nothing.
 */
class LatinSearch
{
    /**
     * The handle for a title, or null when it needs none — or when this server cannot make one.
     *
     * ⚠ Returns null rather than throwing when `intl` is missing: the extension is not guaranteed
     * on shared hosting, and a catalogue that works slightly less well is not a reason to refuse an
     * upload. Callers store null and the game is still findable by its own name.
     */
    public static function for(?string $name): ?string
    {
        $name = trim((string) $name);

        if ($name === '') {
            return null;
        }

        // A title already written in latin needs no handle — and a title with no letter at all
        // ("2033") has nothing to romanise.
        if (!preg_match('/\p{L}/u', $name) || preg_match('/\p{Latin}/u', $name)) {
            return null;
        }

        if (!extension_loaded('intl') || !class_exists(Transliterator::class)) {
            return null;
        }

        $transliterator = Transliterator::create('Any-Latin; Latin-ASCII; Lower');

        if ($transliterator === null) {
            return null;
        }

        $latin = $transliterator->transliterate($name);

        if ($latin === false || trim($latin) === '') {
            return null;
        }

        // Keep letters and digits, everything else becomes a space: diacritics ICU leaves behind
        // ("alʿab"), punctuation, and the separators between syllables.
        $latin = preg_replace('/[^a-z0-9]+/u', ' ', $latin);
        $latin = trim(preg_replace('/\s+/', ' ', $latin));

        if ($latin === '') {
            return null;
        }

        // ⚠ **Both spellings, in one string.** A romanisation comes out syllable by syllable
        // ("long yin li zhi chuan") while people type it joined up ("longyin"), and a LIKE on one
        // form never matches the other. Storing both is what makes either work, and costs a column
        // nobody reads.
        $joined = str_replace(' ', '', $latin);

        return $joined === $latin ? $latin : $latin . ' ' . $joined;
    }
}
