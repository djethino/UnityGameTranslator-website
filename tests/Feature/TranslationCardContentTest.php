<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\Translation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What a visitor can learn about a translation BEFORE downloading it.
 *
 * Two things were invisible and are the point of these tests:
 * - the external resources link, without which image replacements and custom
 *   fonts simply do not work, and which points at a third-party host;
 * - when the translation itself last changed. updated_at could not answer
 *   that (a vote touches it), so an abandoned translation looked active.
 */
class TranslationCardContentTest extends TestCase
{
    use RefreshDatabase;

    private function makeTranslation(array $attributes = []): Translation
    {
        $game = Game::firstOrCreate(['slug' => 'card-game'], ['name' => 'Card Game']);
        $user = User::factory()->create();

        $translation = new Translation();
        $translation->forceFill(array_merge([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'source_language' => 'English',
            'target_language' => 'French',
            'file_path' => 'translations/not-read-by-these-tests.json',
            'file_uuid' => 'uuid-' . uniqid(),
            'visibility' => 'public',
            'file_hash' => 'hash-' . uniqid(),
            'line_count' => 10,
            'human_count' => 10,
        ], $attributes))->save();

        return $translation->refresh();
    }

    public function test_the_game_page_shows_the_external_resources_link(): void
    {
        $translation = $this->makeTranslation([
            'resources_url' => 'https://example.com/my-french-pack',
        ]);

        $response = $this->get(route('games.show', $translation->game));

        $response->assertOk();
        // Shown in full, never shortened: the reader decides before clicking
        $response->assertSee('https://example.com/my-french-pack');
        $response->assertSee(__('file_settings.resources'));
    }

    public function test_a_branch_shows_the_link_inherited_from_its_parent(): void
    {
        $main = $this->makeTranslation(['resources_url' => 'https://example.com/pack']);
        $branch = $this->makeTranslation([
            'file_uuid' => $main->file_uuid,
            'visibility' => 'branch',
            'parent_id' => $main->id,
            'resources_url' => null,
        ]);

        $this->assertSame('https://example.com/pack', $branch->getEffectiveResourcesUrl());
        $this->assertTrue($branch->getEffectiveResourcesUrl() !== null);
    }

    public function test_image_replacements_without_a_link_are_flagged_as_unusable(): void
    {
        $translation = $this->makeTranslation([
            'settings_summary' => [
                'image_replacements' => ['count' => 3, 'items' => [['name' => 'ui_logo']]],
            ],
        ]);

        // The PNGs live in the mod folder, never in the JSON: without a link
        // there is no way for a downloader to obtain them
        $this->assertTrue($translation->hasUnreachableImageAssets());

        $this->get(route('games.show', $translation->game))
            ->assertOk()
            ->assertSee(__('file_settings.images_missing_resources'));
    }

    public function test_a_link_clears_the_unusable_images_warning(): void
    {
        $translation = $this->makeTranslation([
            'resources_url' => 'https://example.com/pack',
            'settings_summary' => [
                'image_replacements' => ['count' => 3, 'items' => [['name' => 'ui_logo']]],
            ],
        ]);

        $this->assertFalse($translation->hasUnreachableImageAssets());
    }

    public function test_fonts_merely_met_in_game_do_not_count_as_configured(): void
    {
        $translation = $this->makeTranslation([
            'font_config' => [
                // Recorded by FontManager on sight, configures nothing
                'ArialSDF' => ['enabled' => true, 'fallback' => null, 'type' => 'Unknown', 'scale' => 1.0],
                'LiberationSans' => ['enabled' => true, 'fallback' => null, 'type' => 'TMP', 'scale' => 1.0],
                // Actually configured by the author
                'PixelFont' => ['enabled' => false, 'fallback' => null, 'type' => 'TMP', 'scale' => 1.0],
                'TitleFont' => ['enabled' => true, 'fallback' => 'NotoSans', 'type' => 'TMP', 'scale' => 1.0],
                'BodyFont' => ['enabled' => true, 'fallback' => null, 'type' => 'TMP', 'scale' => 1.4],
            ],
        ]);

        $this->assertCount(3, $translation->configuredFonts());
        $this->assertSame(2, $translation->detectedFontCount());
        $this->assertSame(3, $translation->settingsSectionCounts()['fonts']);
    }

    public function test_two_copies_differing_only_by_discovered_fonts_are_not_reported_as_different(): void
    {
        $shared = ['PixelFont' => ['enabled' => false, 'scale' => 1.0]];

        // Same translation, two players: one walked through more screens
        $a = $this->makeTranslation(['font_config' => $shared]);
        $b = $this->makeTranslation([
            'font_config' => $shared + ['SeenOnceFont' => ['enabled' => true, 'scale' => 1.0]],
        ]);

        $this->assertFalse($a->settingsDifferFrom($b));
    }

    public function test_swapping_a_fallback_is_reported_even_though_the_count_holds(): void
    {
        $a = $this->makeTranslation(['font_config' => ['Title' => ['enabled' => true, 'fallback' => 'NotoSans']]]);
        $b = $this->makeTranslation(['font_config' => ['Title' => ['enabled' => true, 'fallback' => 'Roboto']]]);

        $this->assertTrue($a->settingsDifferFrom($b));
    }

    public function test_content_updated_at_is_stamped_on_creation(): void
    {
        $translation = $this->makeTranslation();

        $this->assertNotNull($translation->content_updated_at);
        $this->assertFalse($translation->hasBeenUpdatedSincePublication());
    }

    public function test_a_vote_does_not_make_a_translation_look_freshly_worked_on(): void
    {
        $translation = $this->makeTranslation();
        $stamp = $translation->content_updated_at;

        $this->travel(2)->days();
        $translation->increment('vote_count');
        $translation->incrementDownloads();
        $translation->refresh();

        // updated_at moved — that is exactly why the card cannot rely on it
        $this->assertTrue($translation->updated_at->gt($stamp));
        $this->assertEquals($stamp->timestamp, $translation->content_updated_at->timestamp);
        $this->assertFalse($translation->hasBeenUpdatedSincePublication());
    }

    public function test_new_content_moves_the_date(): void
    {
        $translation = $this->makeTranslation();
        $stamp = $translation->content_updated_at;

        $this->travel(3)->days();
        $translation->update(['file_hash' => 'a-brand-new-hash']);
        $translation->refresh();

        $this->assertTrue($translation->content_updated_at->gt($stamp));
        $this->assertTrue($translation->hasBeenUpdatedSincePublication());

        $this->get(route('games.show', $translation->game))
            ->assertOk()
            ->assertSee(__('translation.updated_on', [
                'date' => $translation->contentChangedAt()->isoFormat('LL'),
            ]));
    }

    public function test_the_owner_is_not_told_less_than_a_visitor(): void
    {
        $translation = $this->makeTranslation([
            'resources_url' => 'https://example.com/my-pack',
            'font_config' => ['PixelFont' => ['enabled' => false]],
            'settings_summary' => [
                'image_replacements' => ['count' => 2, 'items' => [['name' => 'ui_logo']]],
                'exclusions' => ['count' => 3, 'items' => ['Qapla\'']],
            ],
        ]);

        // The one screen where the author can act on their translation used to carry less than
        // the public game page an anonymous visitor sees
        $this->actingAs($translation->user)
            ->get(route('translations.dashboard', $translation))
            ->assertOk()
            ->assertSee(__('file_settings.section_title'))
            ->assertSee('ui_logo')
            ->assertSee('https://example.com/my-pack');
    }

    public function test_the_list_of_my_translations_signals_what_a_file_carries(): void
    {
        $translation = $this->makeTranslation([
            'resources_url' => 'https://example.com/my-pack',
            'settings_summary' => ['image_replacements' => ['count' => 2, 'items' => []]],
        ]);

        // A translation that replaces images does not work without its link: its author has to
        // see that it exists, even in a list
        $this->actingAs($translation->user)
            ->get(route('translations.mine'))
            ->assertOk()
            ->assertSee(__('file_settings.resources'));
    }

    public function test_lines_kept_as_is_are_named_in_the_legend(): void
    {
        $translation = $this->makeTranslation(['skipped_count' => 312]);

        // A coloured band with no name in the key would be unreadable
        $this->get(route('games.show', $translation->game))
            ->assertOk()
            ->assertSee(__('progress.skipped') . ': 312');
    }

    public function test_a_file_without_kept_lines_says_nothing_about_them(): void
    {
        $translation = $this->makeTranslation(['skipped_count' => 0]);

        // "Kept as is: 0" is noise: the absence IS the information
        $this->get(route('games.show', $translation->game))
            ->assertOk()
            ->assertDontSee(__('progress.skipped') . ': 0');
    }

    public function test_kept_lines_take_their_share_of_the_bar_rather_than_the_grey(): void
    {
        // 100 translated, 100 kept as is, nothing left to do
        $translation = $this->makeTranslation([
            'human_count' => 100,
            'capture_count' => 0,
            'skipped_count' => 100,
        ]);

        $html = $this->get(route('games.show', $translation->game))->assertOk()->getContent();

        // Half the bar purple, and not one pixel of grey: nothing is pending here
        $this->assertStringContainsString('bg-purple-500 h-full" style="width: 50%', $html);
        $this->assertStringNotContainsString('bg-gray-500 h-full', $html);
    }
}
