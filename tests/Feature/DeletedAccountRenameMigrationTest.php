<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The accounts erased before deletions carried their own name.
 *
 * Three of them shared the literal `[Deleted]` in production. The migration that renames them runs
 * once and cannot be replayed, so what it does is asserted here rather than checked by hand on the
 * day — and the assertion is the one that matters: they must differ from each other.
 */
class DeletedAccountRenameMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_accounts_left_on_the_old_literal_name_are_given_their_own(): void
    {
        // Written straight to the table: this is the state the migration exists to repair, and it
        // is one the current code can no longer produce.
        $ids = [];
        foreach (['a', 'b', 'c'] as $seed) {
            $user = User::factory()->create(['name' => 'before-' . $seed]);
            DB::table('users')->where('id', $user->id)->update(['name' => '[Deleted]']);
            $ids[] = $user->id;
        }

        (require database_path('migrations/2026_08_27_100000_give_old_deleted_accounts_their_own_name.php'))->up();

        $names = DB::table('users')->whereIn('id', $ids)->pluck('name');

        $this->assertCount(3, $names->unique(), 'Erased accounts must not share a name');

        foreach ($names as $name) {
            $this->assertMatchesRegularExpression('/^\[Deleted-[0-9a-f]{9}\]$/', $name);
        }
    }

    /** ⚠ A live account that merely looks similar must not be touched. */
    public function test_it_leaves_everybody_else_alone(): void
    {
        $live = User::factory()->create(['name' => 'Deleted']);

        (require database_path('migrations/2026_08_27_100000_give_old_deleted_accounts_their_own_name.php'))->up();

        $this->assertSame('Deleted', $live->fresh()->name);
    }
}
