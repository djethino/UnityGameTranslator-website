<?php

namespace Tests\Feature\Api;

use App\Models\Game;
use App\Models\Translation;
use App\Models\User;
use App\Support\LatinSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Reaching a game whose title is not written in latin letters.
 *
 * 🔴 **A search handle, and nothing else.** What fills the column is a mechanical romanisation,
 * wrong often enough that showing it would be worse than showing nothing — ICU reads japanese kanji
 * with chinese values, and calls 原神 "yuan shen" for a game everybody knows as Genshin Impact. It
 * is never displayed, and it must never be able to decide which game an upload belongs to: that
 * stays `steam_id` and `unity_name`, so a generated string cannot attach somebody's work to a game.
 *
 * Measured on 2026-09-04, which is why this exists: for 龙胤立志传, Steam has no english title even
 * when asked in english, and IGDB does not know the game at all. There is no reliable source — only
 * a way to let somebody type something.
 */
class LatinSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (!extension_loaded('intl')) {
            $this->markTestSkipped('intl is not loaded, so no handle can be built.');
        }
    }

    private function makeGame(string $name): Game
    {
        return Game::create(['name' => $name, 'steam_id' => (string) random_int(100000, 999999)]);
    }

    public function test_a_title_in_another_script_can_be_typed(): void
    {
        $game = $this->makeGame('龙胤立志传');

        // Both spellings live in one string: a romanisation comes out syllable by syllable while
        // people type it joined up, and a LIKE on one form never matches the other.
        $this->assertStringContainsString('long yin', $game->fresh()->latin_search);
        $this->assertStringContainsString('longyin', $game->fresh()->latin_search);
    }

    public function test_a_latin_title_gets_no_handle_at_all(): void
    {
        // Most of the catalogue. Giving every game a handle would double an index for nothing.
        $this->assertNull($this->makeGame('Metro 2033')->fresh()->latin_search);
        $this->assertNull($this->makeGame('Persona 5')->fresh()->latin_search);

        // No letter to romanise either.
        $this->assertNull($this->makeGame('2033')->fresh()->latin_search);
    }

    public function test_renaming_a_game_moves_its_handle(): void
    {
        $game = $this->makeGame('龙胤立志传');
        $this->assertNotNull($game->fresh()->latin_search);

        // An admin tidying a title, or an IGDB match arriving late: keeping the old handle would
        // leave the game findable under a name it no longer has.
        $game->update(['name' => 'Dragon Legacy']);
        $this->assertNull($game->fresh()->latin_search);
    }

    public function test_the_catalogue_is_reachable_from_a_keyboard(): void
    {
        $game = $this->makeGame('龙胤立志传');

        $path = 'translations/latin-search-' . uniqid() . '.json';
        Storage::disk('local')->put($path, json_encode(['Hello' => ['v' => 'Bonjour', 't' => 'H']]));

        (new Translation())->forceFill([
            'game_id' => $game->id,
            'user_id' => User::factory()->create()->id,
            'source_language' => 'Chinese',
            'target_language' => 'French',
            'visibility' => 'public',
            'file_uuid' => (string) Str::uuid(),
            'file_path' => $path,
            'file_hash' => 'hash-' . uniqid(),
            'line_count' => 1,
            'human_count' => 1,
        ])->save();

        foreach (['longyin', 'long yin', '龙胤'] as $typed) {
            $response = $this->getJson('/api/v1/games?q=' . urlencode($typed))->assertOk();
            $this->assertSame(1, $response->json('count'), "typing '{$typed}' reaches the game");

            $translations = $this->getJson('/api/v1/translations?q=' . urlencode($typed))->assertOk();
            $this->assertSame(1, $translations->json('games_total'), "and its translations");
        }
    }

    public function test_the_handle_never_decides_who_a_translation_belongs_to(): void
    {
        // 🔴 The line that must not be crossed. A generated string may help somebody FIND a game;
        // letting it resolve an upload would let a romanisation attach work to the wrong one.
        $game = $this->makeGame('龙胤立志传');
        $game->update(['unity_name' => 'LongYin']);

        $other = $this->makeGame('Long Yin Li Zhi Chuan');

        // Asked with what the handle contains, the exact machine name still settles it.
        $response = $this->getJson('/api/v1/translations?q=LongYin')->assertOk();

        $this->assertSame(1, $response->json('games_total'));
        $this->assertSame('龙胤立志传', $response->json('games.0.game.name'));
        $this->assertNotSame($other->id, $response->json('games.0.game.id'));
    }

    public function test_nothing_is_written_where_the_extension_is_missing(): void
    {
        // Shared hosting does not guarantee intl. The rule is that a catalogue works slightly less
        // well, never that an upload is refused — so the helper answers null and callers store it.
        $this->assertNull(LatinSearch::for(''));
        $this->assertNull(LatinSearch::for(null));
    }
}
