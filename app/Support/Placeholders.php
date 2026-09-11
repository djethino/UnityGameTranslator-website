<?php

namespace App\Support;

/**
 * What a game will accept back from a person typing in an editor — the placeholder rule.
 *
 * 🔴 A port of `common/Placeholders.cs`, held to the same cases: resources/corpus/rules/
 * placeholders.json, answered by the C# (mod, Manager), by this class (tests/Unit/CorpusTest.php)
 * and by the editor's JavaScript (resources/js/rules/placeholders.js). Until 2026-09-11 the site
 * only warned about a broken placeholder, on one token family, compared to the wrong reference;
 * the mod refused. Three implementations, one file of cases, is what keeps them from drifting
 * again.
 *
 * A game's text carries technical placeholders — [!v*0] for a value, [!t*0] for a tag,
 * [!STR*0] for a nested string, [!nl] for a line break. The game substitutes them at runtime, so
 * one lost, duplicated or invented token is a line that breaks, whoever wrote it.
 */
final class Placeholders
{
    /** Every frozen token: [!v*N], [!t*N], [!STR*N], [!nl]. One pattern, written once. */
    private const TOKEN = '/\[!(?:v\*\d+|t\*\d+|STR\*\d+|nl)\]/';

    /** Every placeholder in a text, in order, repeats included.
     * @return list<string> */
    public static function tokens(string $text): array
    {
        if ($text === '') {
            return [];
        }
        preg_match_all(self::TOKEN, $text, $matches);

        return $matches[0];
    }

    /** How many times each placeholder appears.
     * @return array<string, int> */
    public static function tally(string $text): array
    {
        $counts = [];
        foreach (self::tokens($text) as $token) {
            $counts[$token] = ($counts[$token] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * Each placeholder together with the delimiters the game wrapped around it, e.g. "({[!v*0]})",
     * once each, in order of first appearance. Expanding left over opening characters only and
     * right over closing ones cannot swallow a neighbour, since every token starts with '[' and
     * ends with ']'.
     * @return list<string>
     */
    public static function frozenSequences(string $source): array
    {
        $sequences = [];
        if ($source === '') {
            return $sequences;
        }
        preg_match_all(self::TOKEN, $source, $matches, PREG_OFFSET_CAPTURE);
        $length = strlen($source);
        foreach ($matches[0] as [$token, $offset]) {
            $start = $offset;
            $end = $offset + strlen($token);
            while ($start > 0 && str_contains('{([', $source[$start - 1])) {
                $start--;
            }
            while ($end < $length && str_contains('})]', $source[$end])) {
                $end++;
            }
            $sequence = substr($source, $start, $end - $start);
            if (!in_array($sequence, $sequences, true)) {
                $sequences[] = $sequence;
            }
        }

        return $sequences;
    }

    /**
     * The tokens an answer carries that its source never had, once each. Asked where the strict
     * gate is not: a source with no placeholder has nothing to keep, so nothing else checks it.
     * @return list<string>
     */
    public static function invented(string $source, ?string $translation): array
    {
        $found = [];
        if ($translation === null || $translation === '') {
            return $found;
        }
        $inSource = self::tally($source);
        foreach (self::tokens($translation) as $token) {
            if (array_key_exists($token, $inSource)) {
                continue;
            }
            if (!in_array($token, $found, true)) {
                $found[] = $token;
            }
        }

        return $found;
    }

    /** Non-overlapping occurrences, like the C#'s IndexOf loop. */
    private static function occurrences(string $text, string $sequence): int
    {
        return $sequence === '' ? 0 : substr_count($text, $sequence);
    }

    /**
     * Whether a game would accept a translation somebody typed here: the frozen sequences
     * verbatim, then the tokens as the same multiset — nothing missing, duplicated or invented.
     *
     * ⚠ One check a MODEL is held to and a person is not: the count of brackets over the whole
     * text. Applied to a person it would refuse "Save" → "Save [F5]", which is theirs to make.
     *
     * @return array{accepted: bool, errors: list<string>} the lines are the corpus's, verbatim.
     */
    public static function acceptsEdit(string $source, string $edited): array
    {
        $errors = [];

        foreach (self::frozenSequences($source) as $sequence) {
            if (self::occurrences($edited, $sequence) < self::occurrences($source, $sequence)) {
                $errors[] = "the exact sequence \"$sequence\" is missing or altered";
            }
        }

        $inAnswer = self::tally($edited);
        foreach (self::tally($source) as $token => $expected) {
            $found = $inAnswer[$token] ?? 0;
            if ($found !== $expected) {
                $errors[] = "token $token appears $found time(s) instead of $expected";
            }
        }

        foreach (self::invented($source, $edited) as $token) {
            $errors[] = "token $token does not exist in the source";
        }

        return ['accepted' => $errors === [], 'errors' => $errors];
    }
}
