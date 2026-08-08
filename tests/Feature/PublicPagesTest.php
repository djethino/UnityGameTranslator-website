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

    public function test_the_language_first_option_can_be_turned_off(): void
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

        $make('Alpha Game', 'alpha-game', 'German');
        $make('Zeta Game', 'zeta-game', 'French');

        // Turned off through the URL: an unchecked box sends nothing, so the form carries a
        // hidden 0 — without it the option could be switched on but never off
        $html = $this->get('/fr/games?lang_first=0')->assertOk()->getContent();

        $this->assertLessThan(
            strpos($html, 'Zeta Game'),
            strpos($html, 'Alpha Game'),
            'With the option off, plain alphabetical order applies'
        );
    }

    public function test_games_can_be_sorted_by_downloads(): void
    {
        $author = \App\Models\User::factory()->create()->refresh();

        $make = function (string $gameName, string $slug, int $downloads) use ($author) {
            $game = \App\Models\Game::forceCreate(['name' => $gameName, 'slug' => $slug]);
            $t = new \App\Models\Translation();
            $t->forceFill([
                'game_id' => $game->id,
                'user_id' => $author->id,
                'source_language' => 'English',
                'target_language' => 'French',
                'file_path' => 'translations/none.json',
                'file_uuid' => (string) \Illuminate\Support\Str::uuid(),
                'visibility' => 'public',
                'line_count' => 1,
                'download_count' => $downloads,
            ])->save();
        };

        $make('Alpha Game', 'alpha-game', 3);
        $make('Zeta Game', 'zeta-game', 500);

        $html = $this->get(route('games.index', ['sort' => 'downloads']))->assertOk()->getContent();

        $this->assertLessThan(
            strpos($html, 'Alpha Game'),
            strpos($html, 'Zeta Game'),
            'The most downloaded game comes first whatever its name'
        );
    }

    public function test_a_games_page_lists_the_visitors_language_first(): void
    {
        $game = \App\Models\Game::forceCreate(['name' => 'Sorted Game', 'slug' => 'sorted-game']);
        $author = \App\Models\User::factory()->create()->refresh();

        $make = function (string $language, int $downloads) use ($game, $author) {
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
                'download_count' => $downloads,
                'human_count' => 1,
            ])->save();
        };

        // The German one wins on every other measure — it must still come second for a
        // French-reading visitor, who cannot use it at all
        $make('German', 5000);
        $make('French', 1);

        $html = $this->get('/fr/games/sorted-game')->assertOk()->getContent();

        // Only what comes AFTER the filter form: the language names also appear in its option
        // values, in alphabetical order, which would make this pass whatever the cards do
        $cards = substr($html, strpos($html, '</form>'));

        $this->assertLessThan(
            strpos($cards, 'German'),
            strpos($cards, 'French'),
            'A translation the visitor can actually read comes first'
        );
    }

    public function test_the_home_page_shows_forks_and_hides_branches(): void
    {
        $game = \App\Models\Game::forceCreate(['name' => 'Storefront Game', 'slug' => 'storefront-game']);
        $author = \App\Models\User::factory()->create()->refresh();

        // human_count, and not only line_count: the storefront shows translations that hold a
        // translated LINE, so a fixture counting lines without translating any would be testing
        // the emptiness rule by accident instead of the fork/branch one.
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
                'human_count' => 1,
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

    /**
     * A file of captured-but-untranslated text is legitimate work in progress, and its author
     * keeps it in the listings and in their own screens. The front page is another matter: it
     * shows translations, and it must not lend such a file's language to a flag that promises
     * the game can be played in it.
     */
    public function test_the_home_page_leaves_out_translations_with_no_translated_line(): void
    {
        $game = \App\Models\Game::forceCreate(['name' => 'Captured Game', 'slug' => 'captured-game']);
        $author = \App\Models\User::factory()->create()->refresh();

        $captureOnly = new \App\Models\Translation();
        $captureOnly->forceFill([
            'game_id' => $game->id,
            'user_id' => $author->id,
            'source_language' => 'English',
            'target_language' => 'Persian',
            'file_path' => 'translations/none.json',
            'file_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'visibility' => 'public',
            'line_count' => 1430,
            'capture_count' => 1430,
        ])->save();

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('Captured Game', $html);
        $this->assertStringNotContainsString('Persian', $html);
    }
}
