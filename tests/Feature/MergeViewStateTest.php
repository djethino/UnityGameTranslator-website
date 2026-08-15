<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\Translation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Merge view tests, client-side era: the table (filters, search, sort,
 * windowing) lives in the shared translation-editor core, so the server
 * only has to (1) render the frame with mode + branch selection preserved,
 * (2) serve the data endpoint to the owner only, and (3) apply changes.
 */
class MergeViewStateTest extends TestCase
{
    use RefreshDatabase;

    private array $createdFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->createdFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    /**
     * Create a translation with a real JSON file in the private storage disk
     * (getSafeFilePath() resolves against storage/app/private directly).
     */
    private function makeTranslation(User $user, Game $game, string $uuid, string $visibility, array $content): Translation
    {
        $dir = storage_path('app/private/translations');
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $relativePath = 'translations/test_' . uniqid('', true) . '.json';
        $fullPath = storage_path('app/private/' . $relativePath);
        file_put_contents($fullPath, json_encode($content, JSON_UNESCAPED_UNICODE));
        $this->createdFiles[] = $fullPath;

        $translation = new Translation();
        $translation->forceFill([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'source_language' => 'English',
            'target_language' => 'French',
            'file_path' => $relativePath,
            'file_uuid' => $uuid,
            'visibility' => $visibility,
            'line_count' => count($content),
        ])->save();

        return $translation;
    }

    /**
     * Setup: a Main with a couple of keys and one branch from another user.
     *
     * @return array{0: User, 1: string, 2: Translation, 3: Translation} [owner, uuid, main, branch]
     */
    private function makeMergeView(): array
    {
        // refresh() loads DB defaults (is_admin=false) absent from factory attributes
        $owner = User::factory()->create()->refresh();
        $contributor = User::factory()->create()->refresh();
        $game = Game::forceCreate(['name' => 'Test Game', 'slug' => 'test-game-' . uniqid()]);
        $uuid = (string) \Illuminate\Support\Str::uuid();

        $main = $this->makeTranslation($owner, $game, $uuid, 'public', [
            '_uuid' => $uuid,
            'Shared' => ['v' => 'Main value', 't' => 'H'],
            'MainOnly' => ['v' => 'Main only', 't' => 'A'],
        ]);

        $branch = $this->makeTranslation($contributor, $game, $uuid, 'branch', [
            'Shared' => ['v' => 'Branch value', 't' => 'H'],
            'BranchOnly' => ['v' => 'Branch only', 't' => 'A'],
        ]);

        return [$owner, $uuid, $main, $branch];
    }

    public function test_the_merge_serves_the_settings_of_the_main_and_of_each_branch(): void
    {
        [$owner, $uuid, $main, $branch] = $this->makeMergeView();

        $main->forceFill(['file_path' => $main->file_path])->save();
        file_put_contents(storage_path('app/private/' . $main->file_path), json_encode([
            '_uuid' => $uuid,
            '_fonts' => ['Title' => ['enabled' => true, 'fallback' => 'NotoSans']],
            'Shared' => ['v' => 'Main value', 't' => 'H'],
        ]));
        file_put_contents(storage_path('app/private/' . $branch->file_path), json_encode([
            '_fonts' => ['Title' => ['enabled' => true, 'fallback' => 'Roboto']],
            'Shared' => ['v' => 'Branch value', 't' => 'H'],
        ]));

        $data = $this->actingAs($owner)->getJson(
            route('translations.merge.data', ['uuid' => $uuid]) . '?branches=' . $branch->id
        );

        $data->assertOk();
        // The Main could see THAT the fonts differed, never which one
        $this->assertStringContainsString('NotoSans', $data->json('main_settings.fonts:Title.value'));
        $this->assertStringContainsString('Roboto', $data->json('branches.0.settings.fonts:Title.value'));
    }

    public function test_a_setting_taken_from_a_branch_is_copied_into_the_main(): void
    {
        [$owner, $uuid, $main, $branch] = $this->makeMergeView();

        file_put_contents(storage_path('app/private/' . $main->file_path), json_encode([
            '_uuid' => $uuid,
            '_fonts' => ['Title' => ['enabled' => true, 'fallback' => 'NotoSans']],
            'Shared' => ['v' => 'Main value', 't' => 'H'],
        ]));
        file_put_contents(storage_path('app/private/' . $branch->file_path), json_encode([
            '_fonts' => ['Title' => ['enabled' => true, 'fallback' => 'Roboto', 'type' => 'TMP']],
            'Shared' => ['v' => 'Branch value', 't' => 'H'],
        ]));

        $this->actingAs($owner)->post(route('translations.merge.apply', ['uuid' => $uuid]), [
            'settings_json' => json_encode([$branch->id => ['fonts:Title' => true]]),
        ])->assertRedirect();

        $saved = json_decode(file_get_contents(storage_path('app/private/' . $main->file_path)), true);

        // Copied whole from the branch, including what the comparison never displayed
        $this->assertSame('Roboto', $saved['_fonts']['Title']['fallback']);
        $this->assertSame('TMP', $saved['_fonts']['Title']['type']);
        // ...and the lines are untouched, since none were selected
        $this->assertSame('Main value', $saved['Shared']['v']);
    }

    /**
     * What a contribution SAYS about its work, offered to the Main beside the work itself.
     *
     * 🔴 The merge dealt in lines and file settings only, so a contributor could write a clearer
     * description or link the fonts their contribution needs and nobody would ever see either.
     * The right to write them existed; the way to read them did not.
     */
    public function test_the_merge_serves_what_each_branch_says_about_its_work(): void
    {
        [$owner, $uuid, $main, $branch] = $this->makeMergeView();

        $main->forceFill(['notes' => 'The whole game.', 'resources_url' => null])->save();
        $branch->forceFill([
            'notes' => 'Menus reworded, and the credits.',
            'resources_url' => 'https://example.com/branch-fonts',
        ])->save();

        $data = $this->actingAs($owner)->getJson(
            route('translations.merge.data', ['uuid' => $uuid]) . '?branches=' . $branch->id
        );

        $data->assertOk()
            ->assertJsonPath('main_notes', 'The whole game.')
            ->assertJsonPath('branches.0.notes', 'Menus reworded, and the credits.')
            ->assertJsonPath('branches.0.resources_url', 'https://example.com/branch-fonts');
    }

    public function test_the_main_takes_a_description_in_its_own_final_wording(): void
    {
        // ⚠ A value, not "take branch N's": the screen pre-fills the contribution's wording and
        // lets the Main adjust it, exactly as it does for a translation line.
        [$owner, $uuid, $main, $branch] = $this->makeMergeView();

        $main->forceFill(['notes' => 'The whole game.'])->save();

        $this->actingAs($owner)->post(route('translations.merge.apply', ['uuid' => $uuid]), [
            'publication_json' => json_encode([
                'notes' => 'Menus and credits reworded.',
                'resources_url' => 'https://example.com/pack',
            ]),
        ])->assertRedirect();

        $main->refresh();
        $this->assertSame('Menus and credits reworded.', $main->notes);
        $this->assertSame('https://example.com/pack', $main->resources_url);
    }

    public function test_a_merge_never_takes_whether_a_translation_is_finished(): void
    {
        // Finished descends from the Main to its contributions and never travels back. The
        // screen offers no row for it; a forged field must not create one.
        [$owner, $uuid, $main, $branch] = $this->makeMergeView();

        $main->forceFill(['status' => 'in_progress'])->save();

        $this->actingAs($owner)->post(route('translations.merge.apply', ['uuid' => $uuid]), [
            'publication_json' => json_encode(['status' => 'complete', 'notes' => 'Reworded.']),
        ])->assertRedirect();

        $main->refresh();
        $this->assertSame('in_progress', $main->status);
        $this->assertSame('Reworded.', $main->notes);
    }

    public function test_a_link_that_is_not_a_web_address_is_refused(): void
    {
        // It goes on the Main's public page, and it arrives from a form.
        [$owner, $uuid, $main, $branch] = $this->makeMergeView();

        $this->actingAs($owner)->post(route('translations.merge.apply', ['uuid' => $uuid]), [
            'publication_json' => json_encode(['resources_url' => 'javascript:alert(1)']),
        ])->assertSessionHasErrors('error');

        $this->assertNull($main->refresh()->resources_url);
    }

    /**
     * A merge leaves a mark on the branch it took from.
     *
     * merged_at shipped with the lineage migration and nothing ever wrote it, so "this Main is
     * ignoring your work" could not be told apart from "this Main has not merged anything yet" —
     * the difference between being overlooked and being early, which is the whole point of the
     * question. Stamped on the branch, because that is the side asking.
     */
    public function test_merging_a_branch_records_that_it_was_taken_in(): void
    {
        [$owner, $uuid, $main, $branch] = $this->makeMergeView();

        $this->assertNull($branch->merged_at, 'Nothing has taken this work in yet.');
        $before = $branch->updated_at;

        $this->actingAs($owner)->post(route('translations.merge.apply', ['uuid' => $uuid]), [
            'selections_json' => json_encode([
                ['key' => 'Shared', 'value' => 'Branch value', 'tag' => 'H', 'source' => 'branch_' . $branch->id],
            ]),
        ])->assertRedirect();

        $branch->refresh();

        $this->assertNotNull($branch->merged_at);
        // Merging is not a content change: touching updated_at would move the branch in every
        // list ordered by freshness, for something its author did not do.
        $this->assertEquals($before, $branch->updated_at);
    }

    public function test_settings_of_a_translation_outside_this_lineage_are_ignored(): void
    {
        [$owner, $uuid, $main] = $this->makeMergeView();
        $stranger = User::factory()->create()->refresh();
        $otherGame = Game::forceCreate(['name' => 'Other', 'slug' => 'other-' . uniqid()]);
        $foreign = $this->makeTranslation($stranger, $otherGame, (string) \Illuminate\Support\Str::uuid(), 'branch', [
            '_fonts' => ['Title' => ['enabled' => false]],
        ]);

        // A branch id is a number in a form: it must be checked against THIS lineage
        $this->actingAs($owner)->post(route('translations.merge.apply', ['uuid' => $uuid]), [
            'settings_json' => json_encode([$foreign->id => ['fonts:Title' => true]]),
        ]);

        $saved = json_decode(file_get_contents(storage_path('app/private/' . $main->file_path)), true);
        $this->assertArrayNotHasKey('_fonts', $saved);
    }

    public function test_show_renders_for_owner_and_keeps_mode_in_switcher(): void
    {
        [$owner, $uuid] = $this->makeMergeView();

        $response = $this->actingAs($owner)->get(route('translations.merge', ['uuid' => $uuid, 'mode' => 'edit']));

        $response->assertOk();
        $html = $response->getContent();
        // The client editor container and its data URL carry the mode
        $this->assertStringContainsString('x-data="mergeView"', $html);
        $this->assertStringContainsString('mode=edit', html_entity_decode($html));
        // Mode switcher present (branches exist)
        $this->assertStringContainsString('mode=merge', html_entity_decode($html));
    }

    public function test_what_differs_beyond_the_lines_starts_folded(): void
    {
        // 🔴 The screen is a line-by-line merge. A block sitting open above the table pushes the
        // actual work off the screen for something usually empty and never urgent — so it is
        // folded, and its header carries the count that decides whether to open it.
        [$owner, $uuid] = $this->makeMergeView();

        $html = $this->actingAs($owner)
            ->get(route('translations.merge', ['uuid' => $uuid]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('beyondLinesOpen: false', $html);
        $this->assertStringContainsString('beyondLinesCount', $html);
        $this->assertStringContainsString(__('merge.beyond_lines'), $html);

        // One frame around both tables: the settings caption and the publication caption are
        // inside it, neither is a block of its own any more.
        $this->assertStringContainsString(__('merge.settings_from_branches'), $html);
        $this->assertStringContainsString(__('merge.publication_from_branches'), $html);
    }

    public function test_show_is_owner_only(): void
    {
        [, $uuid] = $this->makeMergeView();
        $stranger = User::factory()->create()->refresh();

        $this->actingAs($stranger)
            ->get(route('translations.merge', ['uuid' => $uuid]))
            ->assertNotFound();
    }

    public function test_data_returns_main_and_selected_branches_to_owner(): void
    {
        [$owner, $uuid, , $branch] = $this->makeMergeView();

        $response = $this->actingAs($owner)->getJson(
            route('translations.merge.data', ['uuid' => $uuid]) . '?mode=merge&branches[]=' . $branch->id
        );

        $response->assertOk();
        $payload = $response->json();
        $this->assertSame('Main value', $payload['main']['Shared']['v']);
        // Metadata keys are stripped
        $this->assertArrayNotHasKey('_uuid', $payload['main']);
        $this->assertCount(1, $payload['branches']);
        $this->assertSame('Branch value', $payload['branches'][0]['content']['Shared']['v']);
    }

    public function test_data_ignores_branches_in_edit_mode(): void
    {
        [$owner, $uuid, , $branch] = $this->makeMergeView();

        $response = $this->actingAs($owner)->getJson(
            route('translations.merge.data', ['uuid' => $uuid]) . '?mode=edit&branches[]=' . $branch->id
        );

        $response->assertOk();
        $this->assertSame([], $response->json('branches'));
    }

    public function test_data_is_owner_only(): void
    {
        [, $uuid] = $this->makeMergeView();
        $stranger = User::factory()->create()->refresh();

        $this->actingAs($stranger)
            ->getJson(route('translations.merge.data', ['uuid' => $uuid]))
            ->assertNotFound();
    }

    public function test_apply_selections_deletions_and_tag_changes(): void
    {
        [$owner, $uuid, $main, $branch] = $this->makeMergeView();

        $response = $this->actingAs($owner)->post(route('translations.merge.apply', ['uuid' => $uuid]), [
            'mode' => 'merge',
            'branches' => [$branch->id],
            'selections_json' => json_encode([
                // Take the branch version of Shared (H stays H)
                ['key' => 'Shared', 'value' => 'Branch value', 'tag' => 'H', 'source' => 'branch_' . $branch->id],
                // Add the branch-only key (A selected by a human -> V)
                ['key' => 'BranchOnly', 'value' => 'Branch only', 'tag' => 'A', 'source' => 'branch_' . $branch->id],
            ]),
            'deletions_json' => json_encode(['MainOnly']),
            'tag_changes_json' => '',
        ]);

        $response->assertRedirect();

        $stored = json_decode(file_get_contents($main->fresh()->getSafeFilePath()), true);
        $this->assertSame(['v' => 'Branch value', 't' => 'H'], $stored['Shared']);
        $this->assertSame(['v' => 'Branch only', 't' => 'V'], $stored['BranchOnly']);
        $this->assertArrayNotHasKey('MainOnly', $stored);
        // Metadata untouched
        $this->assertSame($uuid, $stored['_uuid']);
    }

    public function test_apply_accepts_explicit_validate_tag_change(): void
    {
        [$owner, $uuid, $main] = $this->makeMergeView();

        // The tag dropdown offers V (validate), A (invalidate) and S (skip):
        // all three must be written as-is
        $response = $this->actingAs($owner)->post(route('translations.merge.apply', ['uuid' => $uuid]), [
            'mode' => 'edit',
            'selections_json' => '',
            'deletions_json' => '',
            'tag_changes_json' => json_encode([
                ['key' => 'MainOnly', 'tag' => 'V', 'value' => 'Main only'],
            ]),
        ]);

        $response->assertRedirect();
        $response->assertSessionDoesntHaveErrors();

        $stored = json_decode(file_get_contents($main->fresh()->getSafeFilePath()), true);
        $this->assertSame(['v' => 'Main only', 't' => 'V'], $stored['MainOnly']);
    }

    // ── Branch authors edit their own work too ───────────────────────────
    // Correcting one's own lines from the site is not a Main privilege. What
    // stays Main-only is the merge view: a branch never sees another branch.

    /** The branch author, not the Main owner. */
    private function branchAuthor(Translation $branch): User
    {
        return User::findOrFail($branch->user_id);
    }

    public function test_a_branch_author_can_edit_their_own_translation(): void
    {
        [, $uuid, , $branch] = $this->makeMergeView();

        $response = $this->actingAs($this->branchAuthor($branch))
            ->get(route('translations.merge', ['uuid' => $uuid, 'mode' => 'edit']));

        $response->assertOk();
        $this->assertStringContainsString('x-data="mergeView"', $response->getContent());
    }

    public function test_a_branch_author_never_reaches_the_merge_mode(): void
    {
        [, $uuid, , $branch] = $this->makeMergeView();
        $author = $this->branchAuthor($branch);

        // Asking for it explicitly must not leak the Main's other contributors
        $html = html_entity_decode(
            $this->actingAs($author)
                ->get(route('translations.merge', ['uuid' => $uuid, 'mode' => 'merge']))
                ->assertOk()
                ->getContent()
        );
        $this->assertStringContainsString('mode=edit', $html);
        $this->assertStringNotContainsString('mode=merge', $html);

        $payload = $this->actingAs($author)
            ->getJson(route('translations.merge.data', ['uuid' => $uuid, 'mode' => 'merge']))
            ->assertOk()
            ->json();
        $this->assertSame([], $payload['branches']);
        // And the content served is the branch's own, never the Main's
        $this->assertArrayHasKey('BranchOnly', $payload['main']);
    }

    public function test_a_branch_author_saves_into_their_own_file(): void
    {
        [, $uuid, $main, $branch] = $this->makeMergeView();

        $this->actingAs($this->branchAuthor($branch))
            ->post(route('translations.merge.apply', ['uuid' => $uuid]), [
                'mode' => 'edit',
                'selections_json' => json_encode([
                    ['key' => 'BranchOnly', 'value' => 'Corrected', 'tag' => 'A', 'source' => 'manual'],
                ]),
            ])
            ->assertRedirect()
            ->assertSessionDoesntHaveErrors();

        $branchContent = json_decode(file_get_contents($branch->fresh()->getSafeFilePath()), true);
        $mainContent = json_decode(file_get_contents($main->fresh()->getSafeFilePath()), true);

        $this->assertSame('Corrected', $branchContent['BranchOnly']['v']);
        $this->assertArrayNotHasKey('BranchOnly', $mainContent);
        $this->assertSame('Main value', $mainContent['Shared']['v']);
    }

    // ── Concurrent edits ─────────────────────────────────────────────────
    // The normal multi-device case: correcting on a laptop while the game
    // uploads captures from the desktop.

    public function test_a_line_changed_on_the_server_is_not_overwritten(): void
    {
        [$owner, $uuid, $main] = $this->makeMergeView();

        // The game uploaded while the page was open
        $path = $main->getSafeFilePath();
        $content = json_decode(file_get_contents($path), true);
        $content['Shared'] = ['v' => 'Uploaded by the game', 't' => 'A'];
        file_put_contents($path, json_encode($content, JSON_UNESCAPED_UNICODE));

        $response = $this->actingAs($owner)->post(route('translations.merge.apply', ['uuid' => $uuid]), [
            'mode' => 'edit',
            'selections_json' => json_encode([
                // base = what the page loaded, now stale
                ['key' => 'Shared', 'value' => 'My edit', 'tag' => 'H', 'source' => 'manual', 'base' => 'Main value'],
                // untouched by the game: must still apply
                ['key' => 'MainOnly', 'value' => 'Also mine', 'tag' => 'H', 'source' => 'manual', 'base' => 'Main only'],
            ]),
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('warning');

        $stored = json_decode(file_get_contents($main->fresh()->getSafeFilePath()), true);
        $this->assertSame('Uploaded by the game', $stored['Shared']['v'], 'The concurrent change must survive.');
        $this->assertSame('Also mine', $stored['MainOnly']['v'], 'One conflict must not cost the other lines.');
    }

    public function test_an_unchanged_line_still_applies_with_its_base(): void
    {
        [$owner, $uuid, $main] = $this->makeMergeView();

        $this->actingAs($owner)->post(route('translations.merge.apply', ['uuid' => $uuid]), [
            'mode' => 'edit',
            'selections_json' => json_encode([
                ['key' => 'Shared', 'value' => 'My edit', 'tag' => 'H', 'source' => 'manual', 'base' => 'Main value'],
            ]),
        ])->assertRedirect()->assertSessionMissing('warning');

        $stored = json_decode(file_get_contents($main->fresh()->getSafeFilePath()), true);
        $this->assertSame('My edit', $stored['Shared']['v']);
    }
}
