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

    public function test_my_translations_leads_with_the_one_i_last_worked_on(): void
    {
        $user = User::factory()->create();
        $old = Game::firstOrCreate(['slug' => 'older-game'], ['name' => 'Older Game']);
        $recent = Game::firstOrCreate(['slug' => 'recent-game'], ['name' => 'Recent Game']);

        $first = $this->makeTranslation(['user_id' => $user->id, 'game_id' => $old->id]);
        $this->travel(2)->days();
        $this->makeTranslation(['user_id' => $user->id, 'game_id' => $recent->id]);

        // Uploaded first, worked on last: the reason to open this page is to carry on, so the
        // file you carried on with is the one that must be waiting at the top.
        $this->travel(1)->days();
        $first->update(['file_hash' => 'worked-on-again']);

        $this->actingAs($user)
            ->get(route('translations.mine'))
            ->assertOk()
            ->assertSeeInOrder(['Older Game', 'Recent Game']);
    }

    public function test_my_translations_can_lead_with_what_is_left_to_review(): void
    {
        $user = User::factory()->create();
        $done = Game::firstOrCreate(['slug' => 'done-game'], ['name' => 'Done Game']);
        $todo = Game::firstOrCreate(['slug' => 'todo-game'], ['name' => 'Todo Game']);

        $this->makeTranslation([
            'user_id' => $user->id, 'game_id' => $done->id,
            'human_count' => 500, 'ai_count' => 0,
        ]);
        $this->makeTranslation([
            'user_id' => $user->id, 'game_id' => $todo->id,
            'human_count' => 0, 'ai_count' => 800,
        ]);

        // The sort an author actually works from, and one no visitor has any use for
        $this->actingAs($user)
            ->get(route('translations.mine', ['sort' => 'review']))
            ->assertOk()
            ->assertSeeInOrder(['Todo Game', 'Done Game']);
    }

    public function test_my_translations_tells_publication_from_maintenance(): void
    {
        $translation = $this->makeTranslation();
        $published = $translation->created_at->isoFormat('LL');

        // Fresh upload: one date, because there is only one fact to tell
        $this->actingAs($translation->user)
            ->get(route('translations.mine'))
            ->assertOk()
            ->assertSee(__('translation.published_on', ['date' => $published]))
            ->assertDontSee(__('translation.updated_on', ['date' => $published]));

        $this->travel(40)->days();
        $translation->update(['file_hash' => 'still-being-maintained']);
        $translation->refresh();

        // Maintained since: both dates, so a year-old translation still being worked on does not
        // read like an upload nobody has touched
        $this->actingAs($translation->user)
            ->get(route('translations.mine'))
            ->assertOk()
            ->assertSee(__('translation.published_on', ['date' => $published]))
            ->assertSee(__('translation.updated_on', [
                'date' => $translation->contentChangedAt()->isoFormat('LL'),
            ]));
    }

    public function test_my_translations_says_where_the_review_stands(): void
    {
        $translation = $this->makeTranslation([
            'human_count' => 0,
            'validated_count' => 0,
            'ai_count' => 400,
        ]);

        // Same words as the dashboard and the public page: one screen must not describe a file
        // differently from the next
        $this->actingAs($translation->user)
            ->get(route('translations.mine'))
            ->assertOk()
            ->assertSee(__('progress.stage.machine'))
            ->assertSee(__('progress.left_to_review', ['count' => '400']));
    }

    public function test_a_fully_reviewed_file_says_so_whatever_its_score(): void
    {
        // The case this whole rework started from: reviewed line by line, no unreviewed AI left,
        // yet the old score gave 2.49 — one hundredth short of its top label, because reaching
        // it required retyping by hand what the AI had already got right.
        $translation = $this->makeTranslation([
            'human_count' => 336,
            'validated_count' => 352,
            'ai_count' => 0,
        ]);

        $this->assertSame('reviewed', $translation->reviewStage());
        $this->assertSame(1.0, $translation->reviewCoverage());

        $this->get(route('games.show', $translation->game))
            ->assertOk()
            ->assertSee(__('progress.stage.reviewed'));
    }

    public function test_untouched_machine_output_is_named_a_stage_not_a_verdict(): void
    {
        $translation = $this->makeTranslation([
            'human_count' => 0,
            'validated_count' => 0,
            'ai_count' => 900,
        ]);

        // Where every translation starts, since the mod translates first and one reviews after.
        // Naming that a failure would tell every newcomer their starting point is worthless.
        $this->assertSame('machine', $translation->reviewStage());
        $this->assertSame(0.0, $translation->reviewCoverage());
    }

    public function test_a_file_with_nothing_translated_has_no_stage_at_all(): void
    {
        $translation = $this->makeTranslation([
            'human_count' => 0,
            'validated_count' => 0,
            'ai_count' => 0,
            'capture_count' => 500,
        ]);

        // No coverage, rather than a coverage of zero: there is nothing to have reviewed
        $this->assertNull($translation->reviewStage());
        $this->assertNull($translation->reviewCoverage());
    }

    public function test_half_reviewed_lands_between_the_two(): void
    {
        $translation = $this->makeTranslation([
            'human_count' => 100,
            'validated_count' => 100,
            'ai_count' => 300,
        ]);

        $this->assertSame('advanced', $translation->reviewStage());
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
