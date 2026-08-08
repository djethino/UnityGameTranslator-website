<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\Translation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The read-only view of a translation's lines.
 *
 * What is really under test is the partition: the file has always been downloadable by anyone,
 * so a page that shows it must be open to the same people and closed to the same people — never
 * one rule for the button and another for the route.
 */
class TranslationViewTest extends TestCase
{
    use RefreshDatabase;

    /** No factories for Game/Translation in this codebase: the rows are built by hand, as elsewhere. */
    private function makeTranslation(User $owner, string $visibility = 'public', ?array $content = null): Translation
    {
        $game = Game::firstOrCreate(['slug' => 'view-game'], ['name' => 'View Game']);

        $content = $content ?? [
            '_uuid' => 'uuid-' . $visibility . '-' . $owner->id,
            'Shop' => ['v' => 'Boutique', 't' => 'V'],
            'Repair' => ['v' => 'Réparer', 't' => 'H'],
            'Only captured' => ['v' => '', 't' => 'H'],
        ];

        $path = 'translations/test-' . uniqid() . '.json';
        Storage::disk('local')->put($path, json_encode($content, JSON_UNESCAPED_UNICODE));

        $translation = new Translation();
        $translation->forceFill([
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'source_language' => 'English',
            'target_language' => 'French',
            'visibility' => $visibility,
            'file_uuid' => $content['_uuid'] ?? null,
            'file_path' => $path,
            'file_hash' => 'hash-' . uniqid(),
            'human_count' => 1,
            'validated_count' => 1,
        ])->save();

        return $translation->refresh();
    }

    public function test_anonymous_visitor_can_read_a_public_translation(): void
    {
        $translation = $this->makeTranslation(User::factory()->create());

        $response = $this->get(route('translations.view', $translation));

        $response->assertOk();
        $response->assertSee('Boutique');
        $response->assertSee('Réparer');
    }

    /**
     * The page exists to be looked at, not to be found. Deciding this once, in a test, is what
     * keeps a later refactor from quietly publishing a game's whole script to search engines.
     */
    public function test_the_view_asks_search_engines_to_stay_away(): void
    {
        $translation = $this->makeTranslation(User::factory()->create());

        $this->get(route('translations.view', $translation))
            ->assertSee('name="robots"', false)
            ->assertSee('noindex', false);
    }

    public function test_a_branch_is_not_readable_by_a_stranger(): void
    {
        $main = $this->makeTranslation(User::factory()->create());
        $branch = $this->makeTranslation(User::factory()->create(), 'branch', [
            '_uuid' => $main->file_uuid,
            'Shop' => ['v' => 'Magasin', 't' => 'H'],
        ]);

        $this->get(route('translations.view', $branch))->assertForbidden();

        $this->actingAs(User::factory()->create())
            ->get(route('translations.view', $branch))
            ->assertForbidden();
    }

    public function test_a_branch_is_readable_by_the_main_owner(): void
    {
        $mainOwner = User::factory()->create();
        $main = $this->makeTranslation($mainOwner);
        $branch = $this->makeTranslation(User::factory()->create(), 'branch', [
            '_uuid' => $main->file_uuid,
            'Shop' => ['v' => 'Magasin', 't' => 'H'],
        ]);

        $this->actingAs($mainOwner)
            ->get(route('translations.view', $branch))
            ->assertOk()
            ->assertSee('Magasin');
    }

    /**
     * The eye and the route have to agree. Offering a way in that answers 403 is the defect this
     * guards against — the same reason a branch shows neither a download button nor vote arrows.
     */
    public function test_the_game_page_offers_the_eye_only_where_it_leads_somewhere(): void
    {
        $mainOwner = User::factory()->create();
        $main = $this->makeTranslation($mainOwner);
        $branch = $this->makeTranslation(User::factory()->create(), 'branch', [
            '_uuid' => $main->file_uuid,
            'Shop' => ['v' => 'Magasin', 't' => 'H'],
        ]);

        $anonymous = $this->get(route('games.show', $main->game));
        $anonymous->assertOk();
        $anonymous->assertSee(route('translations.view', $main), false);
        $anonymous->assertDontSee(route('translations.view', $branch), false);

        $owner = $this->actingAs($mainOwner)->get(route('games.show', $main->game));
        $owner->assertSee(route('translations.view', $branch), false);
    }

    /** A captured line has no translation yet, and the page must not pass it off as an empty one. */
    public function test_a_captured_line_is_named_rather_than_shown_blank(): void
    {
        $translation = $this->makeTranslation(User::factory()->create());

        $this->get(route('translations.view', $translation))
            ->assertSee(__('progress.capture'));
    }

    public function test_filtering_by_tag_narrows_the_lines(): void
    {
        $translation = $this->makeTranslation(User::factory()->create());

        $response = $this->get(route('translations.view', ['translation' => $translation, 'validated' => 1]));

        $response->assertOk();
        $response->assertSee('Boutique');     // tagged V
        $response->assertDontSee('Réparer');  // tagged H
    }

    public function test_search_narrows_the_lines(): void
    {
        $translation = $this->makeTranslation(User::factory()->create());

        $response = $this->get(route('translations.view', ['translation' => $translation, 'search' => 'Repair']));

        $response->assertOk();
        $response->assertSee('Réparer');
        $response->assertDontSee('Boutique');
    }

    /** A file that has gone missing is a page that says so, never a 500. */
    public function test_a_missing_file_does_not_break_the_page(): void
    {
        $translation = $this->makeTranslation(User::factory()->create());
        Storage::disk('local')->delete($translation->file_path);

        $this->get(route('translations.view', $translation))
            ->assertOk()
            ->assertSee(__('translation.content_unavailable'));
    }

    /** Looking is not taking: the number people judge a translation by must not move. */
    public function test_looking_does_not_count_as_a_download(): void
    {
        $translation = $this->makeTranslation(User::factory()->create());
        $before = $translation->download_count;

        $this->get(route('translations.view', $translation))->assertOk();

        $this->assertSame($before, $translation->fresh()->download_count);
    }
}
