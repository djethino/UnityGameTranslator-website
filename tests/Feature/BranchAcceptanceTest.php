<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\Translation;
use App\Models\User;
use App\Services\TranslationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A Main decides whether contributions are accepted, and nothing goes round that decision.
 *
 * 🔴 The whole point of these tests is the WAYS ROUND. The rule itself is one boolean; what has to
 * be proved is that neither door is open — a new contribution, and an update to a branch that
 * already existed when the Main was still open. The second one skips determineOwnership entirely,
 * which is exactly how it would have been missed.
 */
class BranchAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    private function main(User $owner, bool $open): Translation
    {
        $game = Game::forceCreate(['name' => 'Some Game', 'slug' => 'some-game']);

        $t = new Translation();
        $t->forceFill([
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'source_language' => 'English',
            'target_language' => 'French',
            'file_path' => 'translations/none.json',
            'file_uuid' => (string) Str::uuid(),
            'visibility' => 'public',
            'accepts_branches' => $open,
            'line_count' => 10,
        ])->save();

        return $t->refresh();
    }

    public function test_a_closed_main_refuses_a_new_contribution(): void
    {
        $owner = User::factory()->create();
        $main = $this->main($owner, open: false);
        $other = User::factory()->create();

        $ownership = app(TranslationService::class)->determineOwnership($main->file_uuid, $other->id);

        $this->assertArrayHasKey('refused', $ownership);
        $this->assertNull($ownership['visibility']);
    }

    public function test_an_open_main_still_takes_contributions(): void
    {
        $owner = User::factory()->create();
        $main = $this->main($owner, open: true);
        $other = User::factory()->create();

        $ownership = app(TranslationService::class)->determineOwnership($main->file_uuid, $other->id);

        $this->assertArrayNotHasKey('refused', $ownership);
        $this->assertSame('branch', $ownership['visibility']);
        $this->assertSame($main->id, $ownership['parent_id']);
    }

    public function test_the_owner_is_never_refused_their_own_update(): void
    {
        // ⚠ A closed Main updating their own translation is not a contribution to themselves.
        // Reading the flag before the ownership test would have locked somebody out of their own
        // work the moment they chose to work alone.
        $owner = User::factory()->create();
        $main = $this->main($owner, open: false);

        $ownership = app(TranslationService::class)->determineOwnership($main->file_uuid, $owner->id);

        $this->assertArrayNotHasKey('refused', $ownership);
        $this->assertSame('public', $ownership['visibility']);
    }

    public function test_a_branch_is_frozen_once_its_main_closes(): void
    {
        $owner = User::factory()->create();
        $main = $this->main($owner, open: true);
        $contributor = User::factory()->create();

        $branch = new Translation();
        $branch->forceFill([
            'game_id' => $main->game_id,
            'user_id' => $contributor->id,
            'parent_id' => $main->id,
            'source_language' => 'English',
            'target_language' => 'French',
            'file_path' => 'translations/branch.json',
            'file_uuid' => $main->file_uuid,
            'visibility' => 'branch',
            'line_count' => 3,
        ])->save();

        $this->assertFalse($branch->refresh()->isFrozenBranch());

        $main->update(['accepts_branches' => false]);

        // 🔴 The door that skips determineOwnership: this branch already exists, so an upload
        // reads it directly. Without this the Main would go on receiving updates after closing.
        $this->assertTrue($branch->refresh()->isFrozenBranch());
    }

    public function test_a_fork_is_never_frozen(): void
    {
        // The way out has to stay open, whatever the Main decides — a fork left the lineage and
        // leads its own.
        $owner = User::factory()->create();
        $main = $this->main($owner, open: false);

        $forkOwner = User::factory()->create();
        $fork = new Translation();
        $fork->forceFill([
            'game_id' => $main->game_id,
            'user_id' => $forkOwner->id,
            'origin_translation_id' => $main->id,
            'source_language' => 'English',
            'target_language' => 'French',
            'file_path' => 'translations/fork.json',
            'file_uuid' => (string) Str::uuid(),
            'visibility' => 'public',
            'line_count' => 12,
        ])->save();

        $this->assertFalse($fork->refresh()->isFrozenBranch());
    }
}
