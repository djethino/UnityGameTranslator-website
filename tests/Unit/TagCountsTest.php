<?php

namespace Tests\Unit;

use App\Models\Translation;
use PHPUnit\Framework\TestCase;

/**
 * How a file's tags become the numbers shown on a card.
 *
 * The rule worth protecting is the one about S (marked as not to translate). It is
 * a human decision — leaving a fictional language untouched in a game translated
 * into another one — so it must NOT count as missing work: keeping it in the bar's
 * denominator would make the careful author look less complete than the one who
 * let the AI translate everything, which is the opposite of the truth. It is
 * reported on its own instead, and never feeds the quality score (a score that
 * rose by marking lines would be trivial to inflate).
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

    public function test_skipped_lines_stay_out_of_the_bar_denominator(): void
    {
        $careful = $this->counts(array_merge(
            array_fill_keys(range('a', 'j'), ['v' => 'Bonjour', 't' => 'H']),
            array_fill_keys(range('k', 't'), ['v' => 'Qapla\'', 't' => 'S']),
        ));

        // 10 human + 10 marked. The bar is built from H+V+A+capture, so the ten
        // marked lines must not dilute it: this file reads as fully translated.
        $barTotal = $careful['human_count'] + $careful['validated_count']
            + $careful['ai_count'] + $careful['capture_count'];

        $this->assertSame(10, $barTotal);
        $this->assertSame(10, $careful['skipped_count']);
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
