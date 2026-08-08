<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\Translation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Which screen the merge route gives you, and why it depends on nothing but ownership.
 *
 * The view looked broken once — a Main owner landed in the editor instead of the merge screen,
 * with no branch selection. The cause was two rows sharing (file_uuid, user_id), which
 * ownTranslation() states is impossible: it takes the first match, and the first match was the
 * branch. The invariant had been broken by hand while preparing test data.
 *
 * Hence this: the modes are pinned, and so is the invariant they rest on.
 */
class MergeViewModeTest extends TestCase
{
    use RefreshDatabase;

    private function make(User $owner, string $uuid, string $visibility, ?int $parentId = null): Translation
    {
        $game = Game::firstOrCreate(['slug' => 'merge-game'], ['name' => 'Merge Game']);

        $t = new Translation();
        $t->forceFill([
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'parent_id' => $parentId,
            'source_language' => 'English',
            'target_language' => 'French',
            'file_path' => 'translations/not-read-by-these-tests.json',
            'file_uuid' => $uuid,
            'visibility' => $visibility,
            'file_hash' => 'hash-' . uniqid(),
            'human_count' => 10,
        ])->save();

        return $t->refresh();
    }

    public function test_the_main_owner_gets_the_merge_screen_with_its_branches(): void
    {
        $mainOwner = User::factory()->create();
        $main = $this->make($mainOwner, 'shared-uuid', 'public');
        $this->make(User::factory()->create(), 'shared-uuid', 'branch', $main->id);

        $response = $this->actingAs($mainOwner)->get("/translations/shared-uuid/merge");

        $response->assertOk();
        $response->assertViewHas('mode', 'merge');
        $response->assertViewHas('hasBranches', true);
    }

    public function test_a_contributor_gets_the_editor_and_never_the_branches(): void
    {
        $mainOwner = User::factory()->create();
        $main = $this->make($mainOwner, 'shared-uuid', 'public');
        $contributor = User::factory()->create();
        $this->make($contributor, 'shared-uuid', 'branch', $main->id);

        $response = $this->actingAs($contributor)->get("/translations/shared-uuid/merge?mode=merge");

        // Asking for merge mode explicitly must not grant it: the branches of a lineage are
        // private to the Main owner, and a sibling contributor must never see them.
        $response->assertOk();
        $response->assertViewHas('mode', 'edit');
        $response->assertViewHas('hasBranches', false);
    }

    public function test_someone_with_nothing_in_this_lineage_gets_nothing(): void
    {
        $main = $this->make(User::factory()->create(), 'shared-uuid', 'public');

        $this->actingAs(User::factory()->create())
            ->get("/translations/shared-uuid/merge")
            ->assertNotFound();
    }

    public function test_a_lineage_holds_one_row_per_person(): void
    {
        // The assumption ownTranslation() rests on. Breaking it is what made the screen look
        // broken, and it broke silently — firstOrFail() simply returned the other row.
        $owner = User::factory()->create();
        $this->make($owner, 'shared-uuid', 'public');

        $this->assertSame(1, Translation::where('file_uuid', 'shared-uuid')
            ->where('user_id', $owner->id)
            ->count());
    }
}
