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
