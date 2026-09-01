<?php

/**
 * What the language glitch rests on, none of which anybody can see by looking at a page.
 *
 * The effect swaps a word for the SAME line in another language: the browser recognises the text on
 * screen, looks up its line number, and reads that number out of a second locale. Every claim in
 * that sentence lives here, and each of them fails SILENTLY — a page showing a confidently wrong
 * translation looks exactly like a page showing a right one, in a script the reviewer does not read.
 */

namespace Tests\Feature;

use App\Support\LanguageBank;
use Tests\TestCase;

class LanguageBankTest extends TestCase
{
    private function bank(string $locale): array
    {
        $res = $this->getJson("/lang-bank/{$locale}.json?v=" . LanguageBank::version());
        $res->assertOk();

        return $res->json();
    }

    /**
     * ⚠ The alignment is the whole mechanism. A locale missing a line must get an EMPTY STRING at
     * that index, never a shorter array: dropping it would shift every line after it, and the shift
     * would be invisible — the page would go on working and pair French sentences with Korean ones.
     */
    public function test_every_locale_is_served_in_the_same_order(): void
    {
        $en = $this->bank('en')['lines'];
        $this->assertNotEmpty($en);

        foreach (['fr', 'ja', 'ar', 'pl'] as $locale) {
            $this->assertCount(count($en), $this->bank($locale)['lines'], "{$locale} is not aligned with en");
        }
    }

    /** The index is built from the page's own language, so a line has to be findable by its text. */
    public function test_a_line_can_be_found_by_its_text_and_read_back_in_another_language(): void
    {
        $en = $this->bank('en')['lines'];
        $fr = $this->bank('fr')['lines'];

        $needle = __('profile.motion_title');            // "Motion" — short, no placeholder, no plural
        $at = array_search($needle, $en, true);

        $this->assertNotFalse($at, 'a short interface string is missing from the English bank');
        $this->assertSame(__('profile.motion_title', [], 'fr'), $fr[$at]);
    }

    /**
     * 🔴 The guard that was missing on 2026-09-01.
     *
     * Index N is the same line in every language only for ONE key list; a key added to `en.json`
     * shifts everything after it. The banks were cached for a day under a URL carrying no version,
     * so a visitor held Hindi from 31 August beside French from that morning and the effect read one
     * at the other's index — a real sentence, correctly fetched, about something else.
     *
     * The version has to reach the client for it to be able to refuse such a pair, so it is part of
     * the contract, not an implementation detail.
     */
    public function test_the_bank_states_which_key_list_it_was_built_from(): void
    {
        foreach (['en', 'fr', 'ja'] as $locale) {
            $this->assertSame(LanguageBank::version(), $this->bank($locale)['v']);
        }
    }

    /** A vintage is a key list. Two locales asked for at once must be built from the same one. */
    public function test_two_locales_fetched_together_share_a_vintage(): void
    {
        $this->assertSame($this->bank('fr')['v'], $this->bank('ko')['v']);
    }

    /** Templates are not sentences: half-rendered, they read as a bug rather than as an effect. */
    public function test_placeholders_plurals_and_markup_never_reach_the_bank(): void
    {
        foreach (['en', 'fr', 'ru'] as $locale) {
            foreach ($this->bank($locale)['lines'] as $line) {
                $this->assertStringNotContainsString('|', $line);
                $this->assertStringNotContainsString('<', $line);
                $this->assertDoesNotMatchRegularExpression('/:\p{L}/u', $line);
            }
        }
    }

    /** The page has to be able to tell the client which vintage to ask for. */
    public function test_the_layout_publishes_the_vintage(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('data-lang-bank="' . LanguageBank::version() . '"', false);
    }

    public function test_an_unknown_locale_is_refused(): void
    {
        $this->getJson('/lang-bank/xx.json')->assertNotFound();
    }
}
