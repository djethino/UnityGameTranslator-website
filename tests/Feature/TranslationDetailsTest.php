<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\Game;
use App\Models\Translation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Changing what is SAID about a translation, without resending the translation.
 *
 * 🔴 The reason this endpoint exists at all: store() requires the file, so the only way to fix a
 * description was to publish the local one with it — which pushes whatever else that file had
 * gained since. A better wording is not a release, and must not behave like one.
 *
 * The rules it has to keep are the project's ordinary ones, and they are worth checking here
 * because a second write path is exactly where they get forgotten: one writes on one's own row;
 * a contribution inherits whether it is finished; and nothing in this endpoint may make a
 * translation look freshly worked on.
 */
class TranslationDetailsTest extends TestCase
{
    use RefreshDatabase;

    private function makeTranslation(User $owner, array $overrides = []): Translation
    {
        $game = Game::firstOrCreate(['slug' => 'details-game'], ['name' => 'Details Game']);

        $translation = new Translation();
        $translation->forceFill(array_merge([
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'source_language' => 'English',
            'target_language' => 'French',
            'file_path' => 'translations/not-read-by-these-tests.json',
            'file_uuid' => 'uuid-details-' . uniqid(),
            'visibility' => 'public',

            // ⚠ Open, because a branch cannot exist otherwise. Since 2026-08-16 a Main decides
            // whether it takes contributions, and the default is no — so a fixture that made a
            // branch on a closed Main was building a state the site cannot reach, and every test
            // about branch details started life frozen.
            'accepts_branches' => true,
            'file_hash' => 'hash-' . uniqid(),
            'line_count' => 10,
            'status' => 'in_progress',
            'notes' => 'The original description.',
            'resources_url' => 'https://example.com/fonts',
        ], $overrides))->save();

        return $translation->refresh();
    }

    private function reword(User $user, Translation $translation, array $body)
    {
        return $this->withHeader(
            'Authorization',
            'Bearer ' . ApiToken::createForUser($user, 'test')->plain_token
        )->patchJson('/api/v1/translations/' . $translation->id . '/details', $body);
    }

    public function test_an_owner_changes_the_words_about_their_own_translation(): void
    {
        $owner = User::factory()->create();
        $translation = $this->makeTranslation($owner);

        $this->reword($owner, $translation, [
            'notes' => 'Covers the whole story, not the DLC.',
            'resources_url' => 'https://example.com/pack',
            'status' => 'complete',
        ])->assertOk()->assertJsonPath('translation.status', 'complete');

        $translation->refresh();
        $this->assertSame('Covers the whole story, not the DLC.', $translation->notes);
        $this->assertSame('https://example.com/pack', $translation->resources_url);
        $this->assertSame('complete', $translation->status);
    }

    public function test_nobody_writes_on_somebody_elses_row(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $translation = $this->makeTranslation($owner);

        $this->reword($stranger, $translation, ['notes' => 'Mine now.'])->assertForbidden();

        $this->assertSame('The original description.', $translation->refresh()->notes);
    }

    public function test_a_contributor_may_describe_their_contribution(): void
    {
        // The point the whole endpoint turns on for a branch: proposing a clearer description, or
        // the link to the fonts the contribution needs, IS contributing.
        $contributor = User::factory()->create();
        $branch = $this->makeTranslation($contributor, ['visibility' => 'branch']);

        $this->reword($contributor, $branch, [
            'notes' => 'Fixes the menu wording.',
            'resources_url' => 'https://example.com/branch-fonts',
        ])->assertOk();

        $branch->refresh();
        $this->assertSame('Fixes the menu wording.', $branch->notes);
        $this->assertSame('https://example.com/branch-fonts', $branch->resources_url);
    }

    public function test_a_contribution_does_not_declare_itself_finished(): void
    {
        // ⚠ Refused, not ignored. Answering 200 to a request that changed nothing teaches a
        // client it succeeded, and the next screen it draws says something untrue.
        $contributor = User::factory()->create();
        $branch = $this->makeTranslation($contributor, [
            'visibility' => 'branch',
            'status' => 'in_progress',
        ]);

        $this->reword($contributor, $branch, ['status' => 'complete'])->assertStatus(422);

        $this->assertSame('in_progress', $branch->refresh()->status);
    }

    public function test_a_field_that_was_not_sent_is_left_alone(): void
    {
        // The opposite of store()'s rule, and deliberately: a client fixing a link has no reason
        // to restate a description it may never have read.
        $owner = User::factory()->create();
        $translation = $this->makeTranslation($owner);

        $this->reword($owner, $translation, ['resources_url' => 'https://example.com/moved'])
            ->assertOk();

        $translation->refresh();
        $this->assertSame('The original description.', $translation->notes);
        $this->assertSame('in_progress', $translation->status);
    }

    public function test_a_field_sent_empty_is_cleared(): void
    {
        $owner = User::factory()->create();
        $translation = $this->makeTranslation($owner);

        $this->reword($owner, $translation, ['notes' => null, 'resources_url' => null])->assertOk();

        $translation->refresh();
        $this->assertNull($translation->notes);
        $this->assertNull($translation->resources_url);
    }

    public function test_rewording_does_not_make_a_translation_look_freshly_worked_on(): void
    {
        // content_updated_at drives "this translation moved on" everywhere it is shown, and the
        // ranking reads it. Nothing about the FILE changed here.
        $owner = User::factory()->create();
        $translation = $this->makeTranslation($owner);

        $before = $translation->content_updated_at;

        $this->reword($owner, $translation, ['notes' => 'Reworded.'])->assertOk();

        $this->assertEquals($before, $translation->refresh()->content_updated_at);
    }

    public function test_the_site_form_lets_a_contributor_describe_their_contribution(): void
    {
        // 🔴 The form has carried a branch case from the start — the inherited status, locked,
        // with its own translation key in twenty languages — and the controller answered 403 to
        // everyone who could have seen it. Nothing pointed at the contradiction, which is why it
        // was read as deliberate for months. This test is what would have said otherwise.
        $contributor = User::factory()->create();
        $branch = $this->makeTranslation($contributor, ['visibility' => 'branch']);

        $this->actingAs($contributor)
            ->get(route('translations.edit', $branch))
            ->assertOk()
            ->assertSee(__('upload.inherited_from_main'));

        $this->actingAs($contributor)
            ->put(route('translations.update', $branch), [
                'notes' => 'Fixes the menu wording.',
                'resources_url' => 'https://example.com/branch-fonts',
            ])
            ->assertRedirect();

        $branch->refresh();
        $this->assertSame('Fixes the menu wording.', $branch->notes);
        $this->assertSame('https://example.com/branch-fonts', $branch->resources_url);
    }

    public function test_the_site_form_refuses_a_status_forged_onto_a_contribution(): void
    {
        // The control is not rendered on a branch, so this can only be a hand-made request.
        // Refused rather than dropped, for the same reason the API refuses it.
        $contributor = User::factory()->create();
        $branch = $this->makeTranslation($contributor, [
            'visibility' => 'branch',
            'status' => 'in_progress',
        ]);

        $this->actingAs($contributor)
            ->put(route('translations.update', $branch), [
                'notes' => 'Anything.',
                'status' => 'complete',
            ])
            ->assertSessionHasErrors('status');

        $this->assertSame('in_progress', $branch->refresh()->status);
    }

    public function test_the_site_form_still_refuses_somebody_elses_translation(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $translation = $this->makeTranslation($owner);

        $this->actingAs($stranger)
            ->get(route('translations.edit', $translation))
            ->assertForbidden();

        $this->actingAs($stranger)
            ->put(route('translations.update', $translation), [
                'status' => 'complete',
                'notes' => 'Mine now.',
            ])
            ->assertForbidden();

        $this->assertSame('The original description.', $translation->refresh()->notes);
    }

    public function test_a_branch_is_told_its_own_link_apart_from_the_one_it_borrows(): void
    {
        // 🔴 The distinction that stops a branch from pinning a copy of its Main's link: the
        // effective one is for showing, the own one is what an edit field must be filled from.
        $owner = User::factory()->create();
        $main = $this->makeTranslation($owner, ['resources_url' => 'https://example.com/main-pack']);

        $contributor = User::factory()->create();
        $branch = $this->makeTranslation($contributor, [
            'file_uuid' => $main->file_uuid,
            'visibility' => 'branch',
            'parent_id' => $main->id,
            'resources_url' => null,
        ]);

        $response = $this->withHeader(
            'Authorization',
            'Bearer ' . ApiToken::createForUser($contributor, 'test')->plain_token
        )->getJson('/api/v1/translations/check-uuid?uuid=' . $branch->file_uuid);

        $response->assertOk()
            ->assertJsonPath('translation.resources_url', 'https://example.com/main-pack')
            ->assertJsonPath('translation.resources_url_own', null);
    }
}
