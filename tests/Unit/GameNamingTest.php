<?php

namespace Tests\Unit;

use App\Support\GameNaming;
use PHPUnit\Framework\TestCase;

/**
 * Every writing system a title can be in, and every shape a name can take.
 *
 * 🔴 **This table is what stops the rule being broken again.** It lived as a private method of a
 * controller, reachable only through an HTTP request and a database — so it shipped with cases for
 * everything it refuses and none for what it must accept, and spent an hour in production emptying
 * every non-latin title. A pure function with a table of cases costs nothing to run and would have
 * caught it before the first commit.
 *
 * ⚠ **Add a row here before touching the rule, not after.** Each line is a real shape: a short
 * title, a script, a punctuation mark, a sequel. If a change makes a line red, the change is wrong
 * or the line is a decision to take deliberately — never a number to adjust until it passes.
 */
class GameNamingTest extends TestCase
{
    public static function accepted(): array
    {
        return [
            // ── A name that IS the title. Nothing to weigh, whatever its length or script. ──
            'a three-letter title' => ['Rez', 'Rez'],
            'a two-letter title' => ['Ib', 'Ib'],
            'a title of one repeated letter' => ['VVVVVV', 'VVVVVV'],
            'a title carrying punctuation' => ['HALP!', 'HALP!'],
            'a title that is a number' => ['140', '140'],
            'a four-letter title' => ['URBO', 'URBO'],

            // ── Other writing systems: the case that shipped broken. ──
            'chinese' => ['龙胤立志传', '龙胤立志传'],
            'japanese with a digit' => ['ペルソナ5', 'ペルソナ5'],
            'korean' => ['원신', '원신'],
            'cyrillic with a number' => ['Метро 2033', 'Метро 2033'],
            'greek' => ['Ελλάδα', 'Ελλάδα'],
            'arabic' => ['ألعاب', 'ألعاب'],
            'thai' => ['เกม', 'เกม'],
            'hindi' => ['खेल', 'खेल'],
            'hebrew' => ['משחק', 'משחק'],

            // ── Case, spaces and punctuation are not a difference anybody means. ──
            'case only' => ['LONESTAR', 'lonestar'],
            'spaces only' => ['HyperEchelon', 'Hyper Echelon'],
            'punctuation only' => ['HALP', 'HALP!'],

            // ── Shorter than the title, and a real part of it. ──
            'a product name inside a shop title' => ['LONESTAR', 'Lonestar: The Game'],
            'an episode subtitle' => ['The Haunted Island', 'Frog Detective: The Haunted Island'],
            'three characters of eleven' => ['Rez', 'Rez Infinite'],
            // ── Real titles from the catalogue, punctuation and all. ──
            'a chinese title with a full-width colon' => ['侠落：百花杀尽', '侠落：百花杀尽'],
            'and the same one typed with an ascii colon' => ['侠落:百花杀尽', '侠落：百花杀尽'],
            'a title with a comma' => ['Little Kitty, Big City', 'Little Kitty, Big City'],
            'the same one without its spaces' => ['LittleKittyBigCity', 'Little Kitty, Big City'],
            'a title with a colon' => ['Love N Life: Happy Student', 'Love N Life: Happy Student'],
            'a name shorter than a punctuated title' => ['LoveNLife', 'Love N Life: Happy Student'],
            'a subtitle after a colon' => ['Silksong', 'Hollow Knight: Silksong'],
        ];
    }

    public static function refused(): array
    {
        return [
            // ── Wider than the title: the squat wearing a disguise. ──
            'a sequel claiming the first' => ['Cattails', 'Cat'],
            'anything unrelated' => ['SomeoneElsesGame', 'A Popular Game'],
            'an episode on another episode' => ['The Haunted Island', 'Frog Detective 2: The Case of the Invisible Wizard'],

            // ── Substrings too banal to name anything. ──
            'an article' => ['The', 'Frog Detective: The Haunted Island'],
            'a preposition' => ['of', 'The Legend of Heroes'],
            'a digit' => ['2', 'Portal 2'],

            // ── A real part, but far too small a share of the title. ──
            'four characters of sixty' => ['Game', 'The Legend of Heroes: Trails of Cold Steel IV - The End'],

            // ⚠ **A limit worth knowing rather than hiding**: a name spelling a number in digits
            // does not meet a title spelling it in roman numerals, so nothing here can match them.
            // The game keeps its display name and stays findable; it simply records no key.
            'arabic digits against roman numerals' => [
                'TrailsOfColdSteel4',
                'The Legend of Heroes: Trails of Cold Steel IV - The End of Saga',
            ],

            // ── Nothing to compare, and nothing to resolve by. ──
            'punctuation only' => ['!!!', '!!!'],
            'an empty declaration' => ['', 'Some Game'],
            'an empty title' => ['Some Game', ''],
            'a missing declaration' => [null, 'Some Game'],
            'a missing title' => ['Some Game', null],
        ];
    }

    /**
     * @dataProvider accepted
     */
    public function test_a_name_a_game_may_record(?string $declared, ?string $title): void
    {
        $this->assertTrue(
            GameNaming::isFormOfTitle($declared, $title),
            "'{$declared}' must be recordable on '{$title}'"
        );
    }

    /**
     * @dataProvider refused
     */
    public function test_a_name_a_game_may_not_record(?string $declared, ?string $title): void
    {
        $this->assertFalse(
            GameNaming::isFormOfTitle($declared, $title),
            "'{$declared}' must not be recordable on '{$title}'"
        );
    }
}
