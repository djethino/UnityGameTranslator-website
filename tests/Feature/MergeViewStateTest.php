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
