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
 * 🔴 **Written after `/api/v1/games` answered 500 in development** while working perfectly in
 * production. The listing filtered with `having('translations_count', '>', 0)` on a query with no
 * GROUP BY: MySQL accepts that, SQLite refuses it. Development ran on SQLite at the time and
 * production on MySQL — so the divergence was invisible from both sides, and no test called the
 * endpoint at all.
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
        $wanted = $this->makeGame('Wanted Game', 'wanted-game', '111111');
        $this->makeTranslation($wanted);

        $other = $this->makeGame('Something Else', 'something-else', '999999');
        $this->makeTranslation($other);

        $byName = $this->getJson('/api/v1/games?q=Wanted')->assertOk()->json();
        $this->assertSame(['wanted-game'], array_column($byName['games'] ?? [], 'slug'));

        $bySteam = $this->getJson('/api/v1/games?steam_id=111111')->assertOk()->json();
        $this->assertSame(['wanted-game'], array_column($bySteam['games'] ?? [], 'slug'));
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

    /**
     * 🔴 **A branch is not a catalogue entry, and this route listed it as one.** It inherits its
     * Main's status, so once the Main was complete every branch came out with an id, an uploader
     * and a line count — and /download then answered 403. The API must not announce what it will
     * not serve, and the listing must not count it either.
     */
    public function test_a_branch_is_neither_listed_nor_counted_nor_a_language(): void
    {
        $game = $this->makeGame('Branched Game', 'branched-game');

        $main = $this->makeTranslation($game, 'French');
        $main->forceFill(['status' => 'complete'])->save();

        $branch = $this->makeTranslation($game, 'German');
        $branch->forceFill([
            'status' => 'complete',
            'visibility' => 'branch',
            'parent_id' => $main->id,
            'file_uuid' => $main->file_uuid,
        ])->save();

        $detail = $this->getJson('/api/v1/games/branched-game')->assertOk();

        $this->assertSame([$main->id], collect($detail->json('translations'))->pluck('id')->all());
        $this->assertSame(['French'], $detail->json('available_languages'));

        $listing = $this->getJson('/api/v1/games')->assertOk();
        $this->assertSame(1, collect($listing->json('games'))->firstWhere('slug', 'branched-game')['translations_count']);

        // A language only a branch holds is not a language the game is available in.
        $this->getJson('/api/v1/games?lang=German')->assertOk()->assertJsonPath('count', 0);
    }
}
