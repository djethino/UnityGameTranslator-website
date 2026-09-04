<?php

namespace Tests\Feature\Api;

use App\Models\Game;
use App\Models\GameIdentifier;
use App\Models\Translation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A demo and the game it is a demo of are one card.
 *
 * Asked for on 2026-09-04: "un jeu, quelque soit la provenance, si c'est le même jeu, c'est la même
 * carte de jeu" — and "une démo a les mêmes textes, le fichier d'une démo peut servir de base à la
 * traduction du jeu complet".
 *
 * 🔴 **Half of this file checks that NOTHING changed.** The alias table is additive: an empty one
 * must leave every resolution doing exactly what it did before. That is the part worth breaking on
 * purpose to see it go red — the rest only proves the new path exists.
 */
class DemoSharesTheGameCardTest extends TestCase
{
    use RefreshDatabase;

    private function game(array $attributes = []): Game
    {
        return Game::create(array_merge([
            'name' => 'Hauntmates',
            'steam_id' => '4400300',
        ], $attributes));
    }

    private function published(Game $game, string $language = 'French'): Translation
    {
        return Translation::create([
            'user_id' => User::factory()->create()->id,
            'game_id' => $game->id,
            'title' => 'A translation',
            'source_language' => 'English',
            'target_language' => $language,
            'file_path' => 'translations/' . uniqid() . '.json',
            'file_hash' => hash('sha256', uniqid()),
            'file_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'visibility' => 'public',
        ]);
    }

    // ── What the alias adds ───────────────────────────────────────────────────────────────────

    public function test_a_demos_app_id_reaches_the_full_games_card(): void
    {
        $game = $this->game();
        $this->published($game);

        GameIdentifier::remember($game, GameIdentifier::Steam, '4428690', GameIdentifier::BecauseDemo);

        $response = $this->getJson('/api/v1/translations?steam_id=4428690');

        $response->assertOk();
        $this->assertSame(1, $response->json('count'), 'the demo finds what is published for the game');
        $this->assertSame($game->id, $response->json('games.0.game.id'));
    }

    public function test_the_batch_answers_under_the_id_that_was_asked(): void
    {
        $game = $this->game();
        $this->published($game);
        GameIdentifier::remember($game, GameIdentifier::Steam, '4428690', GameIdentifier::BecauseDemo);

        $response = $this->postJson('/api/v1/translations/for-games', [
            'games' => [['steam_id' => '4428690']],
        ]);

        $response->assertOk();

        // 🔴 The entry must come back under 4428690 — what the caller sent — and not under the
        // card's own 4400300, which is the mistake a groupBy on the column makes.
        $this->assertSame('4428690', $response->json('results.0.key.steam_id'));
        $this->assertSame(1, $response->json('results.0.games_total'), 'the demo reaches the card');
        $this->assertSame($game->id, $response->json('results.0.games.0.game.id'));
    }

    public function test_the_listing_finds_the_game_by_its_demos_id(): void
    {
        $game = $this->game();
        $this->published($game);
        GameIdentifier::remember($game, GameIdentifier::Steam, '4428690', GameIdentifier::BecauseDemo);

        $response = $this->getJson('/api/v1/games?steam_id=4428690');

        $response->assertOk();
        $this->assertCount(1, $response->json('games'));
    }

    // ── The write rule ────────────────────────────────────────────────────────────────────────

    public function test_an_id_a_card_carries_is_never_taken_as_an_alias(): void
    {
        $full = $this->game();
        $other = $this->game(['name' => 'Another Game', 'steam_id' => '999111']);

        $this->assertFalse(
            GameIdentifier::remember($other, GameIdentifier::Steam, '4400300'),
            'a card is the authority on its own id'
        );

        $this->assertDatabaseCount('game_identifiers', 0);
    }

    public function test_the_first_claim_wins_and_saying_it_twice_is_not_an_error(): void
    {
        $first = $this->game();
        $second = $this->game(['name' => 'Another Game', 'steam_id' => '999111']);

        $this->assertTrue(GameIdentifier::remember($first, GameIdentifier::Steam, '4428690'));
        $this->assertTrue(GameIdentifier::remember($first, GameIdentifier::Steam, '4428690'), 'idempotent');
        $this->assertFalse(GameIdentifier::remember($second, GameIdentifier::Steam, '4428690'));

        $this->assertDatabaseCount('game_identifiers', 1);
        $this->assertSame($first->id, GameIdentifier::first()->game_id);
    }

    public function test_nothing_is_recorded_for_an_empty_or_oversized_id(): void
    {
        $game = $this->game();

        $this->assertFalse(GameIdentifier::remember($game, GameIdentifier::Steam, '   '));
        $this->assertFalse(GameIdentifier::remember($game, GameIdentifier::Steam, str_repeat('9', 65)));

        $this->assertDatabaseCount('game_identifiers', 0);
    }

    public function test_a_card_removed_takes_its_aliases_with_it(): void
    {
        $game = $this->game();
        GameIdentifier::remember($game, GameIdentifier::Steam, '4428690');

        $game->delete();

        $this->assertDatabaseCount('game_identifiers', 0);
    }

    // ── And nothing else moved ────────────────────────────────────────────────────────────────

    public function test_with_no_alias_at_all_every_lookup_behaves_as_before(): void
    {
        $game = $this->game();
        $this->published($game);

        $this->assertDatabaseCount('game_identifiers', 0);

        // The card's own id still resolves it…
        $search = $this->getJson('/api/v1/translations?steam_id=4400300');
        $search->assertOk();
        $this->assertSame(1, $search->json('count'));

        // …and an id nobody carries still resolves nothing, rather than everything.
        $unknown = $this->getJson('/api/v1/translations?steam_id=1234567');
        $unknown->assertOk();
        $this->assertSame(0, $unknown->json('count'));

        $listing = $this->getJson('/api/v1/games?steam_id=4400300');
        $listing->assertOk();
        $this->assertCount(1, $listing->json('games'));

        $none = $this->getJson('/api/v1/games?steam_id=1234567');
        $none->assertOk();
        $this->assertCount(0, $none->json('games'));
    }

    // ── Publishing from a demo ────────────────────────────────────────────────────────────────

    private function publish(array $fields): \Illuminate\Testing\TestResponse
    {
        $token = \App\Models\ApiToken::createForUser(User::factory()->create(), 'test')->plain_token;

        return $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/v1/translations', array_merge([
                'source_language' => 'English',
                'target_language' => 'French',
                // ⚠ Unique per upload: the site refuses a file whose content it already holds.
                'content' => json_encode([
                    '_uuid' => (string) \Illuminate\Support\Str::uuid(),
                    'Hello ' . uniqid() => ['v' => 'Bonjour', 't' => 'H'],
                ]),
            ], $fields));
    }

    public function test_publishing_from_a_demo_lands_on_the_full_games_card(): void
    {
        // ⚠ The store lookup is stubbed — it needs a network — but the shape is what Steam really
        // answered on 2026-09-04 for app 4428690: the demo redirected to 4400300, and the id that
        // was asked comes back as `demo_steam_id`.
        $this->mock(\App\Services\GameSearchService::class, function ($mock) {
            $mock->shouldReceive('findGame')->andReturn([
                'name' => "Hauntmates: Director's Cut",
                'steam_id' => '4400300',
                'image_url' => null,
                'demo_steam_id' => '4428690',
            ]);
        });

        // 🔴 The card carries the SHOP title, the demo reports the build's product name. That gap
        // is the whole case: neither name matches, so nothing but the app id can bridge them — and
        // the demo's app id is not the card's.
        $full = $this->game(['name' => "Hauntmates: Director's Cut"]);
        $before = Game::count();

        $this->publish(['steam_id' => '4428690', 'game_name' => 'Hauntmates'])->assertSuccessful();

        $this->assertSame($before, Game::count(), 'no second card for the same text');
        $this->assertSame(1, $full->fresh()->translations()->count());

        // And the demo's id is kept, so the next player resolves it without asking Steam.
        $this->assertDatabaseHas('game_identifiers', [
            'game_id' => $full->id,
            'source' => GameIdentifier::Steam,
            'value' => '4428690',
            'reason' => GameIdentifier::BecauseDemo,
        ]);
    }

    public function test_a_demos_id_never_becomes_a_cards_main_id(): void
    {
        // 🔴 A card published from a copy with no Steam id (GOG, Epic, a disc) takes one from the
        // first upload that carries one. From a demo, that used to make the DEMO's app id the
        // card's own — and every later player of the full game then resolved nothing.
        $this->mock(\App\Services\GameSearchService::class, function ($mock) {
            $mock->shouldReceive('getGameFromSteam')->with('4428690')->andReturn([
                'name' => 'Hauntmates',
                'steam_id' => '4400300',
                'image_url' => null,
                'source' => 'steam',
                'demo_steam_id' => '4428690',
            ]);
        });

        $card = $this->game(['steam_id' => null]);

        $this->publish(['steam_id' => '4428690', 'game_name' => 'Hauntmates'])->assertSuccessful();

        $this->assertSame('4400300', $card->fresh()->steam_id, 'the game is the id, not the demo');
        $this->assertDatabaseHas('game_identifiers', [
            'game_id' => $card->id,
            'value' => '4428690',
        ]);
    }

    public function test_an_ordinary_id_is_still_written_as_sent(): void
    {
        // ⚠ The guard above must not change the ordinary case, nor refuse the upload when the store
        // says nothing at all — a card without an id gets the one it was handed, as before.
        $this->mock(\App\Services\GameSearchService::class, function ($mock) {
            $mock->shouldReceive('getGameFromSteam')->andReturn(null);
        });

        $card = $this->game(['steam_id' => null]);

        $this->publish(['steam_id' => '4400300', 'game_name' => 'Hauntmates'])->assertSuccessful();

        $this->assertSame('4400300', $card->fresh()->steam_id);
        $this->assertDatabaseCount('game_identifiers', 0);
    }

    public function test_the_second_player_on_that_demo_never_reaches_the_store(): void
    {
        $full = $this->game();
        GameIdentifier::remember($full, GameIdentifier::Steam, '4428690', GameIdentifier::BecauseDemo);

        // 🔴 The point of recording the id: the resolution stops at the database. A stub that
        // FAILS if called is the only way to state that — a passing stub would prove nothing.
        $this->mock(\App\Services\GameSearchService::class, function ($mock) {
            $mock->shouldNotReceive('findGame');
        });

        $this->publish(['steam_id' => '4428690', 'game_name' => 'Hauntmates'])->assertSuccessful();

        $this->assertSame(1, $full->fresh()->translations()->count());
    }

    public function test_an_empty_id_list_selects_nothing_rather_than_everything(): void
    {
        $this->game();
        $this->game(['name' => 'Another Game', 'steam_id' => '999111']);

        // 🔴 The failure this guards against is silent and total: a `whereIn` on an empty array is
        // `false` in Laravel, but a hand-written union of two conditions can end up matching all
        // rows. Asked about nothing, the scope answers nothing.
        $this->assertSame(0, Game::answeringToSteamId([])->count());
        $this->assertSame(0, Game::answeringToSteamId('')->count());
    }
}
