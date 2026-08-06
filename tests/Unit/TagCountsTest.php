<?php

namespace Tests\Unit;

use App\Models\Translation;
use PHPUnit\Framework\TestCase;

/**
 * How a file's tags become the numbers shown on a card.
 *
 * The rule worth protecting is the one about S (kept as is). It is a human decision —
 * leaving a fictional language untouched in a game translated into another one — so
 * the line was met, read and settled. It therefore belongs to the bar, with its own
 * segment and inside the denominator: excluding it would make the bar describe only
 * part of the file while claiming to describe all of it, and dropping it into the
 * grey would count care as work still to do.
 *
 * What it must never do is feed the quality score, which measures translations: a
 * score that rose by marking lines would be trivial to inflate.
 *
 * M (mod UI) is technical noise and is counted nowhere.
 */
class TagCountsTest extends TestCase
{
    private function counts(array $entries): array
    {
        return Translation::extractTagCounts($entries);
    }

    public function test_each_tag_lands_in_its_own_bucket(): void
    {
        $counts = $this->counts([
            '_uuid' => 'meta is never counted',
            'a' => ['v' => 'Bonjour', 't' => 'H'],
            'b' => ['v' => 'Salut', 't' => 'V'],
            'c' => ['v' => 'Coucou', 't' => 'A'],
            'd' => ['v' => '', 't' => 'H'],          // captured, not translated yet
            'e' => ['v' => 'Qapla\'', 't' => 'S'],   // must stay as it is
            'f' => ['v' => 'Settings', 't' => 'M'],  // the mod's own UI
        ]);

        $this->assertSame(1, $counts['human_count']);
        $this->assertSame(1, $counts['validated_count']);
        $this->assertSame(1, $counts['ai_count']);
        $this->assertSame(1, $counts['capture_count']);
        $this->assertSame(1, $counts['skipped_count']);
    }

    public function test_kept_lines_are_counted_apart_from_the_ones_left_to_do(): void
    {
        $careful = $this->counts(array_merge(
            array_fill_keys(range('a', 'j'), ['v' => 'Bonjour', 't' => 'H']),
            array_fill_keys(range('k', 't'), ['v' => 'Qapla\'', 't' => 'S']),
            array_fill_keys(range('u', 'y'), ['v' => '', 't' => 'H']),
        ));

        // The bar covers everything captured — 10 translated, 10 kept as is, 5 still
        // to do — but the kept ones have their own bucket. Were they folded into
        // capture_count, the grey would claim there are 15 lines left to translate
        // when the author has already settled ten of them.
        $this->assertSame(10, $careful['human_count']);
        $this->assertSame(10, $careful['skipped_count']);
        $this->assertSame(5, $careful['capture_count']);
    }

    public function test_marking_lines_cannot_inflate_the_quality_score(): void
    {
        $entries = array_fill_keys(range('a', 'e'), ['v' => 'Bonjour', 't' => 'A']);
        $before = $this->counts($entries);

        $entries += array_fill_keys(range('f', 'z'), ['v' => 'Qapla\'', 't' => 'S']);
        $after = $this->counts($entries);

        // The score is (H*3 + V*2 + A*1) / (H+V+A): marking 21 more lines must
        // leave every term of it untouched.
        $this->assertSame($before['human_count'], $after['human_count']);
        $this->assertSame($before['validated_count'], $after['validated_count']);
        $this->assertSame($before['ai_count'], $after['ai_count']);
    }

    public function test_an_untagged_string_still_counts_as_ai(): void
    {
        // Files written before the tag system: assuming AI is the cautious read,
        // and losing them would understate what the file contains.
        $counts = $this->counts(['a' => 'Bonjour', 'b' => 'Salut']);

        $this->assertSame(2, $counts['ai_count']);
        $this->assertSame(0, $counts['skipped_count']);
    }

    public function test_an_unknown_tag_falls_back_to_ai_rather_than_vanishing(): void
    {
        $counts = $this->counts(['a' => ['v' => 'Bonjour', 't' => 'Z']]);

        $this->assertSame(1, $counts['ai_count']);
    }
}
