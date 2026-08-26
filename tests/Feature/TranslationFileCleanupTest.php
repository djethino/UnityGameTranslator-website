<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\Report;
use App\Models\Translation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A translation that goes takes its file with it — from every door.
 *
 * 🔴 **Four callers had written this out for themselves, and one of them had it wrong.** Handling a
 * report deleted the row and left the JSON on disk, for ever, on the one path where the content is
 * being removed *because somebody complained about it*. Nothing could catch it: each caller had its
 * own two lines, so there was nothing to compare against.
 *
 * These tests exist per door rather than on the service, because the service was never the problem
 * — reaching it was.
 */
class TranslationFileCleanupTest extends TestCase
{
    use RefreshDatabase;

    private function makeTranslationWithFile(User $owner): Translation
    {
        $game = Game::firstOrCreate(['slug' => 'cleanup-game'], ['name' => 'Cleanup Game']);

        $path = 'translations/' . uniqid() . '_cleanup.json';
        Storage::disk('local')->put($path, '{"Hello":"Bonjour"}');

        $translation = new Translation();
        $translation->forceFill([
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'source_language' => 'English',
            'target_language' => 'French',
            'file_path' => $path,
            'file_uuid' => 'uuid-' . uniqid(),
            'visibility' => 'public',
            'file_hash' => 'hash-' . uniqid(),
            'line_count' => 1,
        ])->save();

        return $translation;
    }

    public function test_an_author_deleting_their_own_translation_removes_the_file(): void
    {
        $author = User::factory()->create();
        $translation = $this->makeTranslationWithFile($author);
        $path = $translation->file_path;

        $this->actingAs($author)->delete("/translations/{$translation->id}");

        $this->assertFalse(Storage::disk('local')->exists($path));
    }

    /** 🔴 The one that was wrong: taken down on a report, and the content stayed on disk. */
    public function test_handling_a_report_removes_the_file(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $translation = $this->makeTranslationWithFile(User::factory()->create());
        $path = $translation->file_path;

        $report = new Report();
        $report->forceFill([
            'translation_id' => $translation->id,
            'reporter_id' => User::factory()->create()->id,
            'reason' => 'spam',
            'status' => 'pending',
        ])->save();

        $this->actingAs($admin)->post("/admin/reports/{$report->id}", [
            'action' => 'delete_translation',
        ]);

        $this->assertNull($translation->fresh());
        $this->assertFalse(Storage::disk('local')->exists($path));
    }

    public function test_an_admin_deleting_a_translation_removes_the_file(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $translation = $this->makeTranslationWithFile(User::factory()->create());
        $path = $translation->file_path;

        $this->actingAs($admin)->delete("/admin/translations/{$translation->id}");

        $this->assertFalse(Storage::disk('local')->exists($path));
    }

    public function test_erasing_an_account_with_its_translations_removes_the_files(): void
    {
        $user = User::factory()->create(['name' => 'Leaving']);
        $translation = $this->makeTranslationWithFile($user);
        $path = $translation->file_path;

        $this->actingAs($user)->delete('/profile', [
            'confirm_name' => 'Leaving',
            'delete_translations' => '1',
        ]);

        $this->assertFalse(Storage::disk('local')->exists($path));
    }
}
