<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CatalogStore;
use App\Services\GameLanguage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Somebody with no account plays in a language too.
 *
 * 🔴 The selector used to be @auth, so a visitor got a language GUESSED from their browser and no
 * way to correct it — a guess that silently reorders every list of games. The preference now lives
 * in the session for everybody, exactly like the interface language beside it, and on the account
 * when there is one.
 */
class GameLanguageForVisitorsTest extends TestCase
{
    use RefreshDatabase;

    /** A tag the catalogue really knows, so the test does not depend on one being spelt a given way. */
    private function someTag(string $name): string
    {
        $tag = array_search($name, CatalogStore::languageChoices(), true);
        $this->assertNotFalse($tag, "The catalogue no longer holds {$name}.");

        return $tag;
    }

    public function test_a_visitor_can_choose_and_the_choice_is_kept_for_the_next_page(): void
    {
        $tag = $this->someTag('Japanese');

        $this->post(route('game-language.switch'), ['game_language' => $tag])
            ->assertRedirect();

        $this->assertSame($tag, session(GameLanguage::SESSION_KEY));
        $this->assertSame('Japanese', GameLanguage::name());
        $this->assertTrue(GameLanguage::isChosen());
    }

    public function test_the_selector_is_rendered_for_a_visitor(): void
    {
        // The whole defect in one assertion: the control simply was not on the page.
        $this->get(route('games.index'))
            ->assertOk()
            ->assertSee(route('game-language.switch'), escape: false);
    }

    public function test_an_empty_choice_goes_back_to_the_guess(): void
    {
        $this->withSession([GameLanguage::SESSION_KEY => $this->someTag('Japanese')]);

        $this->post(route('game-language.switch'), ['game_language' => '']);

        $this->assertFalse(GameLanguage::isChosen());
    }

    public function test_an_unknown_tag_is_refused(): void
    {
        $this->post(route('game-language.switch'), ['game_language' => 'not-a-language'])
            ->assertSessionHasErrors('game_language');
    }

    public function test_a_tag_the_catalogue_dropped_is_treated_as_no_choice(): void
    {
        // Session content survives a catalogue that shrank; ranking by a language nobody can
        // name any more would be ranking by nothing.
        $this->withSession([GameLanguage::SESSION_KEY => 'zz-nonexistent']);

        $this->assertFalse(GameLanguage::isChosen());
    }

    public function test_the_account_wins_over_the_session(): void
    {
        $japanese = $this->someTag('Japanese');
        $german = $this->someTag('German');

        $user = User::factory()->create(['game_language' => $german]);

        $this->actingAs($user)->withSession([GameLanguage::SESSION_KEY => $japanese]);

        $this->assertSame('German', GameLanguage::name());
    }

    public function test_a_choice_made_before_signing_in_is_not_lost(): void
    {
        $japanese = $this->someTag('Japanese');
        $user = User::factory()->create(['game_language' => null]);

        $this->actingAs($user)->withSession([GameLanguage::SESSION_KEY => $japanese]);

        // The account says nothing; the browser said Japanese a minute ago. Falling straight
        // through to the detection here would drop a stated choice at the door.
        $this->assertSame('Japanese', GameLanguage::name());
    }

    public function test_clearing_it_from_the_profile_form_clears_the_session_too(): void
    {
        $japanese = $this->someTag('Japanese');
        $user = User::factory()->create(['game_language' => $japanese, 'name' => 'tester']);

        $this->actingAs($user)
            ->withSession([GameLanguage::SESSION_KEY => $japanese])
            ->put(route('profile.update'), ['name' => 'tester', 'game_language' => '']);

        // Without one writer for both, the cleared column would fall back to the session and the
        // preference would come back as if nothing had been cleared.
        $this->assertNull($user->fresh()->game_language);
        $this->assertFalse(GameLanguage::isChosen());
    }

    public function test_a_signed_in_choice_writes_the_account(): void
    {
        $tag = $this->someTag('Japanese');
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('game-language.switch'), ['game_language' => $tag]);

        $this->assertSame($tag, $user->fresh()->game_language);
    }
}
