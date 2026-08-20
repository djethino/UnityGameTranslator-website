<?php

namespace Tests\Unit;

use App\Services\TranslationService;
use PHPUnit\Framework\TestCase;

/**
 * The ladder that decides who wins a merge, and the asymmetry that protects a Main.
 *
 * 🔴 **This rule exists in three languages and must say one thing.** C# holds it in
 * `common/Merge.PriorityOf` (mod + Manager), JavaScript in `translation-editor.js::priorityOf`
 * (both editing screens), PHP in `TranslationService::priorityOf` (the server's own counts). The
 * socle is the source; these tests are what stop the other two from drifting away from it.
 *
 * Every wrong answer here is somebody's work silently taken or silently dropped.
 */
class MergeLadderTest extends TestCase
{
    public function test_the_ladder_climbs_from_capture_to_human(): void
    {
        // A captured line is an H with nothing in it: the floor, not the top.
        $this->assertSame(0, TranslationService::priorityOf('H', ''));
        $this->assertSame(1, TranslationService::priorityOf('A', 'Bonjour'));
        $this->assertSame(2, TranslationService::priorityOf('V', 'Bonjour'));
        $this->assertSame(3, TranslationService::priorityOf('H', 'Bonjour'));
    }

    public function test_a_refusal_stands_level_with_a_hand_written_line(): void
    {
        // Both are a person's decision about the line: one wrote it, the other ruled it must not
        // be written. Neither outranks the other, and what separates them is who is asking.
        $this->assertSame(
            TranslationService::priorityOf('H', 'Bonjour'),
            TranslationService::priorityOf('S', '')
        );

        $this->assertGreaterThan(
            TranslationService::priorityOf('V', 'Bonjour'),
            TranslationService::priorityOf('S', '')
        );
    }

    public function test_an_untagged_line_reads_as_machine(): void
    {
        $this->assertSame(
            TranslationService::priorityOf('A', 'Bonjour'),
            TranslationService::priorityOf(null, 'Bonjour')
        );
    }

    public function test_the_mods_own_interface_is_not_a_line_of_the_game(): void
    {
        $this->assertFalse(TranslationService::isGameLine('M'));
        $this->assertTrue(TranslationService::isGameLine('H'));
        $this->assertTrue(TranslationService::isGameLine('S'));
    }

    /** A key the Main does not hold at all: the case with no question in it. */
    public function test_a_key_the_main_lacks_is_offered(): void
    {
        $this->assertTrue(TranslationService::contributionWins(null, ['v' => 'Bonjour', 't' => 'A']));
    }

    /**
     * The asymmetry: a Main keeps its own on any tie. These three are the cases a bare ladder
     * gets wrong, and each of them is a published translation being overwritten by a proposal.
     */
    public function test_the_main_keeps_its_own_on_every_tie(): void
    {
        $this->assertFalse(TranslationService::contributionWins(
            ['v' => 'Bonjour', 't' => 'H'],
            ['v' => 'Salut', 't' => 'H']
        ), 'hand-written against hand-written');

        $this->assertFalse(TranslationService::contributionWins(
            ['v' => 'Bonjour', 't' => 'H'],
            ['v' => '', 't' => 'S']
        ), 'a refusal does not displace a hand-written line');

        $this->assertFalse(TranslationService::contributionWins(
            ['v' => '', 't' => 'S'],
            ['v' => 'Salut', 't' => 'H']
        ), 'nor a hand-written line a refusal: the Main ruled, and the ruling is theirs');
    }

    /** A contribution can be a tag and not a word — and that is the work this site asks for. */
    public function test_reviewing_the_mains_machine_line_is_a_contribution(): void
    {
        $this->assertTrue(TranslationService::contributionWins(
            ['v' => 'Bonjour', 't' => 'A'],
            ['v' => 'Bonjour', 't' => 'V']
        ));
    }

    public function test_a_captured_line_on_the_main_loses_to_any_translation(): void
    {
        $this->assertTrue(TranslationService::contributionWins(
            ['v' => '', 't' => 'H'],
            ['v' => 'Bonjour', 't' => 'A']
        ));
    }

    public function test_the_same_line_offered_back_is_not_a_contribution(): void
    {
        $this->assertFalse(TranslationService::contributionWins(
            ['v' => 'Bonjour', 't' => 'A'],
            ['v' => 'Bonjour', 't' => 'A']
        ));
    }

    public function test_interface_lines_are_never_arbitrated(): void
    {
        $this->assertFalse(TranslationService::contributionWins(
            ['v' => 'Bonjour', 't' => 'A'],
            ['v' => 'Menu', 't' => 'M']
        ), 'never offered');

        $this->assertFalse(TranslationService::contributionWins(
            ['v' => 'Menu', 't' => 'M'],
            ['v' => 'Bonjour', 't' => 'H']
        ), 'nor taken over');
    }

    /**
     * 🔴 The browser holds its own copy of the ladder, and a barème that decides who wins a merge
     * must not be able to drift. Read from the file rather than restated here: restating it would
     * make this test agree with itself while the editors disagreed with the server.
     */
    public function test_the_javascript_editors_use_the_same_ladder(): void
    {
        // Path built from this file rather than resource_path(): a unit test runs without booting
        // the application, and a helper that needs one would fail for a reason nothing to do with
        // the ladder.
        $source = file_get_contents(
            dirname(__DIR__, 2) . '/resources/js/components/translation-editor.js'
        );

        $this->assertNotFalse($source, 'the shared editor module must be readable');

        $this->assertMatchesRegularExpression(
            "/'H':\s*3,\s*'S':\s*3,\s*'V':\s*2,\s*'A':\s*1/",
            $source,
            'the JS ladder no longer matches the socle: capture < A < V < H = S'
        );

        $this->assertStringContainsString(
            "if (tag === 'H' && !this.getValue(entry)) return 0;",
            $source,
            'the JS ladder must still put a captured line at the floor'
        );

        $this->assertStringContainsString(
            "return this.getTag(entry) !== 'M';",
            $source,
            "the JS side must still keep the mod's interface out of arbitration"
        );
    }
}
