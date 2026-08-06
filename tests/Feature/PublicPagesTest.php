<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Smoke tests for the pages any visitor can reach without an account.
 *
 * Replaces Laravel's generated ExampleTest, which had been failing since the
 * initial commit: it called the home page without RefreshDatabase, so the
 * tables it reads did not exist and it answered 500. A test that always fails
 * teaches nothing and hides real regressions in the noise.
 */
class PublicPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_page_renders(): void
    {
        $this->get('/')->assertOk();
    }

    public function test_legal_pages_render(): void
    {
        $this->get('/legal')->assertOk();
        $this->get('/terms')->assertOk();
        $this->get('/privacy')->assertOk();
    }

    public function test_privacy_page_lists_every_cookie_the_site_sets(): void
    {
        // The page names cookies one by one, so setting one without declaring
        // it here would make it false. Anyone adding a cookie must add a line.
        $this->get('/privacy')
            ->assertOk()
            ->assertSee('laravel_session')
            ->assertSee('XSRF-TOKEN')
            ->assertSee('ugt_edit_session');
    }

    public function test_the_game_list_shows_which_languages_exist_without_duplicates(): void
    {
        $game = \App\Models\Game::forceCreate(['name' => 'Listed Game', 'slug' => 'listed-game']);
        $author = \App\Models\User::factory()->create()->refresh();

        $make = function (string $visibility, string $language) use ($game, $author) {
            $t = new \App\Models\Translation();
            $t->forceFill([
                'game_id' => $game->id,
                'user_id' => $author->id,
                'source_language' => 'English',
                'target_language' => $language,
                'file_path' => 'translations/none.json',
                'file_uuid' => (string) \Illuminate\Support\Str::uuid(),
                'visibility' => $visibility,
                'line_count' => 1,
            ])->save();
        };

        // Two French translations of the same game are ONE answer to "is it in my language?"
        $make('public', 'French');
        $make('public', 'French');
        $make('public', 'German');
        // A branch is unpublished work: listing its language would announce it
        $make('branch', 'Japanese');

        $html = $this->get(route('games.index'))->assertOk()->getContent();

        // Counting the language BADGES rather than the word: the language names also appear in
        // the filter dropdown, where their presence means something else entirely.
        $this->assertSame(
            2,
            substr_count($html, 'bg-black/60'),
            'One badge per language available, not one per translation'
        );
        // Japanese exists only as an unpublished branch: neither badge nor filter option
        $this->assertStringNotContainsString('Japanese', $html);
    }

    public function test_games_in_the_visitors_language_come_first_without_hiding_the_others(): void
    {
        $author = \App\Models\User::factory()->create()->refresh();

        $make = function (string $gameName, string $slug, string $language) use ($author) {
            $game = \App\Models\Game::forceCreate(['name' => $gameName, 'slug' => $slug]);
            $t = new \App\Models\Translation();
            $t->forceFill([
                'game_id' => $game->id,
                'user_id' => $author->id,
                'source_language' => 'English',
                'target_language' => $language,
                'file_path' => 'translations/none.json',
                'file_uuid' => (string) \Illuminate\Support\Str::uuid(),
                'visibility' => 'public',
                'line_count' => 1,
            ])->save();
        };

        // Alphabetically "Alpha" comes first, but only "Zeta" is in the visitor's language
        $make('Alpha Game', 'alpha-game', 'German');
        $make('Zeta Game', 'zeta-game', 'French');

        // Browsing the site IN French — no filter applied
        $html = $this->get('/fr/games')->assertOk()->getContent();

        $frenchAt = strpos($html, 'Zeta Game');
        $germanAt = strpos($html, 'Alpha Game');

        $this->assertNotFalse($frenchAt);
        // Sorting, not filtering: the game without French must stay on the page
        $this->assertNotFalse($germanAt, 'A game without the visitor language must not disappear');
        $this->assertLessThan($germanAt, $frenchAt, 'The visitor language must come first');
    }

    public function test_the_home_page_shows_forks_and_hides_branches(): void
    {
        $game = \App\Models\Game::forceCreate(['name' => 'Storefront Game', 'slug' => 'storefront-game']);
        $author = \App\Models\User::factory()->create()->refresh();

        $make = function (string $visibility, ?int $parentId, string $language) use ($game, $author) {
            $translation = new \App\Models\Translation();
            $translation->forceFill([
                'game_id' => $game->id,
                'user_id' => $author->id,
                'source_language' => 'English',
                'target_language' => $language,
                'file_path' => 'translations/none.json',
                'file_uuid' => (string) \Illuminate\Support\Str::uuid(),
                'visibility' => $visibility,
                'parent_id' => $parentId,
                'line_count' => 1,
            ])->save();

            return $translation;
        };

        $main = $make('public', null, 'French');
        // A fork keeps its parent for traceability but IS a Main of its own lineage.
        // Filtering the storefront on parent_id made it disappear from the latest
        // translations, the popular-games count and the language list.
        $make('public', $main->id, 'German');
        $make('branch', $main->id, 'Spanish');

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('German', $html, 'A fork must be visible on the storefront.');
        $this->assertStringNotContainsString('Spanish', $html, 'A branch must stay private.');
    }
}
