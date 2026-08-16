<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\Translation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * What happens to translations that already existed when the decision was introduced.
 *
 * 🔴 **This is the half of a migration nobody tests, and it is the half that touches real
 * people's work.** Getting it wrong does not break a page: it silently closes a lineage its
 * owner had plainly kept open, and freezes every contributor writing into it.
 *
 * The rule, as arbitrated: a Main that already HAS a contribution, or has ever MERGED one, was
 * accepting them and keeps accepting them. Everything else starts closed, because keeping a
 * translation open is work nobody agreed to by publishing.
 */
class BranchAcceptanceBackfillTest extends TestCase
{
    use RefreshDatabase;

    private function backfill(): void
    {
        // The migration's own code, not a copy of it. A test that re-implements the rule proves
        // only that two people agreed on the same mistake.
        require_once database_path('migrations/2026_08_16_120000_add_accepts_branches_to_translations.php');

        $migration = require database_path('migrations/2026_08_16_120000_add_accepts_branches_to_translations.php');
        $migration::backfill();
    }

    private function row(string $visibility, string $uuid, ?string $mergedAt = null): Translation
    {
        $game = Game::firstOrCreate(['slug' => 'backfill-game'], ['name' => 'Backfill Game']);

        $t = new Translation();
        $t->forceFill([
            'game_id' => $game->id,
            'user_id' => User::factory()->create()->id,
            'source_language' => 'English',
            'target_language' => 'French',
            'file_path' => 'translations/' . uniqid() . '.json',
            'file_uuid' => $uuid,
            'visibility' => $visibility,
            'merged_at' => $mergedAt,
            'accepts_branches' => false,
            'line_count' => 5,
        ])->save();

        return $t->refresh();
    }

    public function test_a_main_that_already_has_a_contribution_stays_open(): void
    {
        $main = $this->row('public', 'uuid-has-branch');
        $this->row('branch', 'uuid-has-branch');

        $this->backfill();

        $this->assertTrue((bool) $main->refresh()->accepts_branches);
    }

    /**
     * 🔴 The case the first version of this migration got wrong: it matched on parent_id, which
     * is "on delete set null". A contribution that lost its link is still a contribution, and its
     * Main still accepted it.
     */
    public function test_a_contribution_with_no_parent_id_still_counts(): void
    {
        $main = $this->row('public', 'uuid-orphan-link');
        $branch = $this->row('branch', 'uuid-orphan-link');
        $branch->forceFill(['parent_id' => null])->save();

        $this->backfill();

        $this->assertTrue((bool) $main->refresh()->accepts_branches);
    }

    public function test_a_main_that_merged_one_stays_open_even_with_none_left(): void
    {
        $main = $this->row('public', 'uuid-merged-away', mergedAt: '2026-01-01 00:00:00');

        $this->backfill();

        $this->assertTrue((bool) $main->refresh()->accepts_branches);
    }

    public function test_a_main_nobody_ever_contributed_to_starts_closed(): void
    {
        $main = $this->row('public', 'uuid-untouched');

        $this->backfill();

        $this->assertFalse((bool) $main->refresh()->accepts_branches);
    }

    /**
     * ⚠ A branch must not read as accepting anything. Keying the search on file_uuid makes a
     * branch match ITSELF unless the write is scoped to Mains — and a branch showing "accepts
     * contributions" would be a card claiming a decision it cannot take.
     */
    public function test_a_contribution_never_carries_the_decision_itself(): void
    {
        $this->row('public', 'uuid-self-match');
        $branch = $this->row('branch', 'uuid-self-match');

        $this->backfill();

        $this->assertFalse((bool) $branch->refresh()->accepts_branches);
    }
}
