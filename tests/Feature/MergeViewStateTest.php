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
}
