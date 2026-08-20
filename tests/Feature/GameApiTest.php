<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\Translation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The public games endpoints — the mod's first call, and the one nothing covered.
 *
 * 🔴 **Written after `/api/v1/games` answered 500 on every developer machine** while working
 * perfectly in production. The listing filtered with `having('translations_count', '>', 0)` on a
 * query with no GROUP BY: MySQL accepts that, SQLite refuses it. Production runs MySQL, dev runs a
 * SQLite copy of the production dump — so the divergence was invisible from both sides, and no
 * test called the endpoint at all.
 *
 * ⚠ The lesson is the coverage, not the clause: an endpoint nobody calls in tests is an endpoint
 * whose SQL is only ever exercised by one engine.
 */
class GameApiTest extends TestCase
{
    use RefreshDatabase;

    private function makeGame(string $name, string $slug, ?string $steamId = null): Game
    {
        return Game::forceCreate([
            'name' => $name,
            'slug' => $slug,
            'steam_id' => $steamId,
        ]);
    }

    private function makeTranslation(Game $game, string $language = 'French'): Translation
    {
        $translation = new Translation();
        $translation->forceFill([
            'game_id' => $game->id,
            'user_id' => User::factory()->create()->refresh()->id,
            'source_language' => 'English',
            'target_language' => $language,
            'file_path' => 'translations/none.json',
            'file_uuid' => 'uuid-' . uniqid('', true),
            'visibility' => 'public',
            'line_count' => 10,
        ])->save();

        return $translation;
    }

    public function test_the_listing_answers_and_keeps_only_games_with_a_translation(): void
    {
        $withOne = $this->makeGame('Translated Game', 'translated-game');
        $this->makeTranslation($withOne);

        $this->makeGame('Bare Game', 'bare-game');

        $body = $this->getJson('/api/v1/games')->assertOk()->json();

        $slugs = array_column($body['games'] ?? [], 'slug');

        $this->assertContains('translated-game', $slugs);
        $this->assertNotContains('bare-game', $slugs, 'a game nobody has translated is not listed');
    }

    public function test_the_listing_can_be_searched_by_name_and_by_steam_id(): void
    {
        $hollow = $this->makeGame('Hollow Knight', 'hollow-knight', '367520');
        $this->makeTranslation($hollow);

        $other = $this->makeGame('Something Else', 'something-else', '999999');
        $this->makeTranslation($other);

        $byName = $this->getJson('/api/v1/games?q=Hollow')->assertOk()->json();
        $this->assertSame(['hollow-knight'], array_column($byName['games'] ?? [], 'slug'));

        $bySteam = $this->getJson('/api/v1/games?steam_id=367520')->assertOk()->json();
        $this->assertSame(['hollow-knight'], array_column($bySteam['games'] ?? [], 'slug'));
    }

    public function test_the_listing_can_be_narrowed_to_a_language(): void
    {
        $french = $this->makeGame('French Game', 'french-game');
        $this->makeTranslation($french, 'French');

        $german = $this->makeGame('German Game', 'german-game');
        $this->makeTranslation($german, 'German');

        $body = $this->getJson('/api/v1/games?lang=German')->assertOk()->json();

        $this->assertSame(['german-game'], array_column($body['games'] ?? [], 'slug'));
    }

    /**
     * ⚠ Resolved by SLUG, not by id: `Game::getRouteKeyName()` says so. The API reference in
     * CLAUDE.md writes `/api/v1/games/{id}`, which is the documentation being wrong rather than
     * the route — an id answers 404 here.
     */
    public function test_one_game_answers_with_its_translations(): void
    {
        $game = $this->makeGame('Detail Game', 'detail-game');
        $this->makeTranslation($game);

        $this->getJson('/api/v1/games/' . $game->slug)
            ->assertOk()
            ->assertJsonPath('game.slug', 'detail-game');

        $this->getJson('/api/v1/games/' . $game->id)->assertNotFound();
    }
}
