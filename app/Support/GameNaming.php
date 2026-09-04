<?php

namespace App\Support;

/**
 * Whether a name a machine declared is a form of the title a game already carries.
 *
 * 🔴 **Pure, and outside the controller, because it decides a behaviour.** It lived as a private
 * method of TranslationController, which meant it could only be exercised through an HTTP request
 * and a database — so it shipped with tests for everything it REFUSES and none for what it must
 * accept. It then spent an hour in production emptying every non-latin title, because flattening
 * kept `[a-z0-9]` only: 龙胤立志传 became an empty string and no game written in another script
 * could record its own name.
 *
 * Two strings in, a boolean out. Nothing else. That is what makes the table of cases beside it
 * possible, and that table is the thing that stops this being broken again.
 *
 * ## What it is for
 *
 * `unity_name` is the key other machines resolve a game by, and it is DECLARED by whoever
 * publishes. On a game the catalogue already knows, accepting any declared string let one account
 * choose what everybody else resolves to. So a declared name is recorded only when it is plausibly
 * the same game's:
 *
 *  1. **The same as the title** — settled first, with nothing measured. "Rez", "Ib", "VVVVVV",
 *     "HALP!", 龙胤立志传 are real titles, and a rule meant to catch "the" must never refuse a game
 *     its own name.
 *  2. **Shorter than the title, and a real part of it** — "LONESTAR" of "Lonestar: The Game",
 *     "The Haunted Island" of "Frog Detective: The Haunted Island". A product name is the tighter
 *     form of a shop title.
 *  3. **Never wider** — "Cattails" is refused on a game called "Cat", which is the squat wearing a
 *     disguise.
 */
class GameNaming
{
    /**
     * How much of the title a strict substring must cover.
     *
     * ⚠ A quarter, measured against real names rather than picked: "LONESTAR" is 8 flattened
     * characters of 15, "Silksong" 8 of 20, "The Haunted Island" 16 of 28, "LoveNLife" 9 of 21 —
     * and "Rez" is 3 of 11 in "Rez Infinite", which a third would have refused.
     *
     * ⚠ **What it lets through, knowingly**: "The" covers 30% of "The Witness", so a game titled
     * that way could have "the" recorded on it. A product name of one article is improbable, the
     * search unions so nothing is hidden, and an admin can clear it — where refusing "Rez" its
     * place would cost a real game its key. Both mistakes are repairable; this is the rarer one.
     */
    private const MinimumShare = 4;

    /**
     * How short a strict substring may be.
     *
     * ⚠ Only ever applied to something SHORTER than the title: one or two letters name nothing,
     * which is the scale the client search (2 characters) and the batch's loose pass (3) already
     * work on. A title of its own length is settled before this is reached.
     */
    private const MinimumLength = 3;

    public static function isFormOfTitle(?string $declared, ?string $title): bool
    {
        if ($declared === null || $title === null) {
            return false;
        }

        $declared = self::flatten($declared);
        $title = self::flatten($title);

        // Nothing to compare and nothing to resolve by. A title of pure punctuation ("!!!") loses
        // the feature, and saying so is the honest answer.
        if ($declared === '' || $title === '') {
            return false;
        }

        // The name IS the title: there is no substring to be suspicious of.
        if ($declared === $title) {
            return true;
        }

        if (!str_contains($title, $declared)) {
            return false;
        }

        return mb_strlen($declared) >= self::MinimumLength
            && mb_strlen($declared) * self::MinimumShare >= mb_strlen($title);
    }

    /**
     * Case, spaces and punctuation removed — the whole difference between a product name and a
     * shop title.
     *
     * 🔴 **Letters and digits of ANY script.** `[^a-z0-9]` empties a title written in another
     * alphabet, which is how this shipped broken: 龙胤立志传 flattened to nothing, ペルソナ5 to "5",
     * Метро 2033 to "2033".
     */
    private static function flatten(string $value): string
    {
        return preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($value));
    }
}
