<?php

/**
 * The two things the language glitch rests on, neither of which anybody can see by reading a page.
 *
 * The effect swaps a word for the SAME line in another language: the browser recognises the text on
 * screen, looks up its line number, and reads that number out of another locale's array. Every claim
 * in that sentence lives in this controller, and both of them fail silently — a page showing a
 * confidently wrong translation looks exactly like a page showing a right one, in a script the
 * reviewer does not read.
 */

namespace Tests\Feature;

use Tests\TestCase;

class LanguageBankTest extends TestCase
{
    private function lines(string $locale): array
    {
        $res = $this->getJson("/lang-bank/{$locale}.json");
        $res->assertOk();

        return $res->json('lines');
    }

    /**
     * ⚠ The alignment is the whole mechanism. A locale missing a line must get an EMPTY STRING at
     * that index, never a shorter array: dropping it would shift every line after it, and the shift
     * would be invisible — the page would go on working and pair French sentences with Korean ones.
     */
    public function test_every_locale_is_served_in_the_same_order(): void
    {
        $en = $this->lines('en');
        $this->assertNotEmpty($en);

        foreach (['fr', 'ja', 'ar', 'pl'] as $locale) {
            $this->assertCount(count($en), $this->lines($locale), "{$locale} is not aligned with en");
        }
    }

    /** The index is built from the page's own language, so a line has to be findable by its text. */
    public function test_a_line_can_be_found_by_its_text_and_read_back_in_another_language(): void
    {
        $en = $this->lines('en');
        $fr = $this->lines('fr');

        $needle = __('profile.motion_title');            // "Motion" — short, no placeholder, no plural
        $at = array_search($needle, $en, true);

        $this->assertNotFalse($at, 'a short interface string is missing from the English bank');
        $this->assertSame(__('profile.motion_title', [], 'fr'), $fr[$at]);
    }

    /**
     * 🔴 Counted in display COLUMNS, not characters. A CJK glyph occupies two, so a cap of forty
     * characters meant forty columns in French and eighty in Japanese — twice the paragraph this cap
     * exists to refuse, in the scripts where it is hardest to skim. That is what put a full Japanese
     * sentence over a seven-column button.
     */
    public function test_no_line_is_wider_than_the_cap_in_any_script(): void
    {
        foreach (['en', 'fr', 'ja', 'zh', 'ko', 'ar'] as $locale) {
            foreach ($this->lines($locale) as $line) {
                $wide = preg_match_all(
                    '/[\x{1100}-\x{115F}\x{2E80}-\x{303E}\x{3041}-\x{33FF}\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}'
                    . '\x{A000}-\x{A4CF}\x{AC00}-\x{D7A3}\x{F900}-\x{FAFF}\x{FE30}-\x{FE6F}\x{FF00}-\x{FF60}'
                    . '\x{FFE0}-\x{FFE6}]/u',
                    $line,
                );

                $this->assertLessThanOrEqual(
                    40,
                    mb_strlen($line) + $wide,
                    "{$locale} serves a line too wide to swap in: {$line}",
                );
            }
        }
    }

    /** Templates are not sentences: half-rendered, they read as a bug rather than as an effect. */
    public function test_placeholders_plurals_and_markup_never_reach_the_bank(): void
    {
        foreach (['en', 'fr', 'ru'] as $locale) {
            foreach ($this->lines($locale) as $line) {
                $this->assertStringNotContainsString('|', $line);
                $this->assertStringNotContainsString('<', $line);
                $this->assertDoesNotMatchRegularExpression('/:\p{L}/u', $line);
            }
        }
    }

    public function test_an_unknown_locale_is_refused(): void
    {
        $this->getJson('/lang-bank/xx.json')->assertNotFound();
    }
}
