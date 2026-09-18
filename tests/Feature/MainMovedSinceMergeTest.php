<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\Translation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Main has published since a branch last merged from it.
 *
 * The game's rule, read from the same value: `_source.main_hash` travels inside the uploaded
 * file, so the site records it and compares it with the Main's current hash. Unknown on either
 * side says nothing at all.
 */
class MainMovedSinceMergeTest extends TestCase
{
    use RefreshDatabase;

    private function row(Game $game, User $user, string $visibility, array $fields): Translation
    {
        $row = new Translation();
        $row->forceFill(array_merge([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'file_uuid' => 'u-1',
            'visibility' => $visibility,
            'source_language' => 'English',
            'target_language' => 'French',
            'line_count' => 10,
            // Not null in the schema; nothing here reads the file.
            'file_path' => 'translations/never-read-' . uniqid('', true) . '.json',
        ], $fields));
        $row->save();

        return $row;
    }

    private function lineage(?string $mergedMainHash, string $mainHash = 'a1'): Translation
    {
        $game = Game::forceCreate(['name' => 'Test Game', 'slug' => 'test-game-' . uniqid('', true)]);
        $owner = User::factory()->create();
        $author = User::factory()->create();

        $main = $this->row($game, $owner, 'public', ['file_hash' => $mainHash]);

        return $this->row($game, $author, 'branch', [
            'parent_id' => $main->id,
            'merged_main_hash' => $mergedMainHash,
        ]);
    }

    public function test_a_branch_built_before_the_main_moved_says_so(): void
    {
        $this->assertTrue($this->lineage('b2', mainHash: 'a1')->mainHasMovedSinceMerge());
    }

    public function test_a_branch_merged_from_the_current_main_says_nothing(): void
    {
        $this->assertFalse($this->lineage('a1', mainHash: 'a1')->mainHasMovedSinceMerge());
    }

    public function test_a_branch_that_never_merged_says_nothing(): void
    {
        // ⚠ Unknown is not "behind": an older mod, or a contribution made without ever taking the
        // Main in, leaves the column null. A guess printed as a fact is worse than a silence.
        $this->assertFalse($this->lineage(null)->mainHasMovedSinceMerge());
    }

    public function test_a_main_never_says_it(): void
    {
        $branch = $this->lineage('b2');
        $main = Translation::where('visibility', 'public')->firstOrFail();
        $main->merged_main_hash = 'b2';
        $main->save();

        $this->assertFalse($main->mainHasMovedSinceMerge());
        $this->assertTrue($branch->mainHasMovedSinceMerge());
    }
}
