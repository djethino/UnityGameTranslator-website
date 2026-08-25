<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\Translation;
use App\Models\User;
use App\Services\TranslationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Leaving a lineage from the website creates a translation and KEEPS the branch.
 *
 * 🔴 It used to rewrite the branch in place — new uuid, visibility public, one row — so the
 * contribution simply stopped existing along with everything attached to it, and the same act
 * taken from the mod (which uploads, and therefore creates) left two rows. One act, two outcomes,
 * depending only on where it was taken.
 *
 * ⚠ Creating is the safer of the two: removing the branch becomes a separate deliberate act, and
 * keeping both is a legitimate choice — contributing to a Main while running one's own version is
 * not a contradiction.
 */
class BranchLeavesLineageTest extends TestCase
{
    use RefreshDatabase;

    private function lineage(): array
    {
        $game = Game::forceCreate(['name' => 'Leaving Game', 'slug' => 'leaving-game']);
        $service = app(TranslationService::class);

        $owner = User::factory()->create();
        $main = new Translation();
        $main->forceFill([
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'source_language' => 'English',
            'target_language' => 'French',
            'file_path' => $service->storeFile(json_encode([
                '_uuid' => 'uuid-lineage',
                'Hello' => ['v' => 'Bonjour', 't' => 'H'],
            ]), 'uuid-lineage'),
            'file_uuid' => 'uuid-lineage',
            'visibility' => 'public',
            'accepts_branches' => true,
            'line_count' => 1,
            'human_count' => 1,
        ])->save();

        $contributor = User::factory()->create();
        $branch = new Translation();
        $branch->forceFill([
            'game_id' => $game->id,
            'user_id' => $contributor->id,
            'parent_id' => $main->id,
            'source_language' => 'English',
            'target_language' => 'French',
            'file_path' => $service->storeFile(json_encode([
                '_uuid' => 'uuid-lineage',
                'Hello' => ['v' => 'Bonjour', 't' => 'H'],
                'Play' => ['v' => 'Jouer', 't' => 'H'],
            ]), 'uuid-lineage'),
            'file_uuid' => 'uuid-lineage',
            'visibility' => 'branch',
            'line_count' => 2,
            'human_count' => 2,
            'notes' => 'My contribution',
            'merged_lines_total' => 5,
        ])->save();

        return [$main->refresh(), $branch->refresh(), $contributor];
    }

    private function leave(User $user, Translation $branch)
    {
        return $this->actingAs($user)
            ->post(route('translations.convert-to-fork', $branch));
    }

    public function test_the_branch_is_kept_and_a_translation_is_created(): void
    {
        [$main, $branch, $contributor] = $this->lineage();

        $this->leave($contributor, $branch)->assertSuccessful();

        $this->assertSame(3, Translation::count(), 'the main, the branch and the new translation');

        $branch->refresh();
        $this->assertSame('branch', $branch->visibility, 'the branch is not consumed');
        $this->assertSame('uuid-lineage', $branch->file_uuid, 'nor is it moved out of its lineage');
        $this->assertSame($main->id, $branch->parent_id);
        $this->assertSame(5, $branch->merged_lines_total, 'its contribution record is untouched');
        $this->assertSame($contributor->id, $branch->user_id, 'and so is its author');
    }

    public function test_what_is_created_is_a_main_of_its_own_crediting_where_it_came_from(): void
    {
        [$main, $branch, $contributor] = $this->lineage();

        $this->leave($contributor, $branch)->assertSuccessful();

        $fork = Translation::latest('id')->first();
        $this->assertSame('public', $fork->visibility);
        $this->assertNull($fork->parent_id, 'a fork left the lineage — it contributes to nobody');
        $this->assertNotSame($branch->file_uuid, $fork->file_uuid, 'a lineage of its own');
        $this->assertSame($main->id, $fork->origin_translation_id);
        $this->assertSame($main->user_id, $fork->origin_user_id);
        $this->assertSame(2, $fork->line_count, 'it carries what the branch held');
        $this->assertSame('My contribution', $fork->notes);
        $this->assertFalse((bool) $fork->accepts_branches, 'closed, like every new Main');
    }

    public function test_the_branch_file_is_left_alone(): void
    {
        [, $branch, $contributor] = $this->lineage();
        $before = file_get_contents($branch->getSafeFilePath());
        $beforeHash = $branch->file_hash;

        $this->leave($contributor, $branch)->assertSuccessful();

        $branch->refresh();
        $this->assertSame($before, file_get_contents($branch->getSafeFilePath()));
        $this->assertSame($beforeHash, $branch->file_hash, 'so its stored hash still describes it');

        $fork = Translation::latest('id')->first();
        $this->assertNotSame($branch->file_path, $fork->file_path, 'two rows, two files');
    }

    public function test_a_branch_may_be_left_only_once(): void
    {
        [, $branch, $contributor] = $this->lineage();
        $this->leave($contributor, $branch)->assertSuccessful();

        $this->leave($contributor, $branch->refresh())
            ->assertSessionHasErrors('error');

        $this->assertSame(3, Translation::count(), 'no second identical translation');
    }

    public function test_nobody_leaves_somebody_elses_lineage(): void
    {
        [, $branch] = $this->lineage();

        $this->leave(User::factory()->create(), $branch)->assertForbidden();
        $this->assertSame(2, Translation::count());
    }

    public function test_a_translation_that_is_not_a_branch_has_nothing_to_leave(): void
    {
        [$main, , ] = $this->lineage();

        $this->actingAs($main->user)
            ->post(route('translations.convert-to-fork', $main))
            ->assertSessionHasErrors('error');

        $this->assertSame(2, Translation::count());
    }
}
