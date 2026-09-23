<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Game;
use App\Models\Report;
use App\Models\Translation;
use App\Models\User;
use App\Services\TranslationService;
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

    /**
     * 🔴 And it leaves a trace saying who and how. A translation vanished and nothing could tell
     * its author from an admin, a report or an account deletion (2026-09-23).
     */
    private function assertDeletionTraced(int $translationId, string $how, int $byUserId, int $ownerId): void
    {
        $entry = AuditLog::where('action', AuditLog::ACTION_TRANSLATION_DELETE)
            ->where('entity_id', $translationId)
            ->first();

        $this->assertNotNull($entry, 'the deletion is traced');
        $this->assertSame($how, $entry->metadata['how']);
        $this->assertSame($byUserId, $entry->user_id, 'by whom');
        $this->assertSame($ownerId, $entry->metadata['owner_id'], 'whose it was');
        $this->assertSame('Cleanup Game', $entry->metadata['game']);
    }

    public function test_an_author_deleting_their_own_translation_removes_the_file(): void
    {
        $author = User::factory()->create();
        $translation = $this->makeTranslationWithFile($author);
        $path = $translation->file_path;

        $this->actingAs($author)->delete("/translations/{$translation->id}");

        $this->assertFalse(Storage::disk('local')->exists($path));
        $this->assertDeletionTraced($translation->id, TranslationService::DELETED_BY_AUTHOR, $author->id, $author->id);
    }

    public function test_an_admin_deleting_from_the_translations_page_is_told_apart_from_its_author(): void
    {
        // The same page is open to both; the trace must not credit the author with an admin's act.
        $admin = User::factory()->create(['is_admin' => true]);
        $owner = User::factory()->create();
        $translation = $this->makeTranslationWithFile($owner);

        $this->actingAs($admin)->delete("/translations/{$translation->id}");

        $this->assertNull($translation->fresh());
        $this->assertDeletionTraced($translation->id, TranslationService::DELETED_BY_ADMIN, $admin->id, $owner->id);
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
        $this->assertDeletionTraced($translation->id, TranslationService::DELETED_BY_REPORT, $admin->id, $translation->user_id);
    }

    public function test_an_admin_deleting_a_translation_removes_the_file(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $translation = $this->makeTranslationWithFile(User::factory()->create());
        $path = $translation->file_path;

        $this->actingAs($admin)->delete("/admin/translations/{$translation->id}");

        $this->assertFalse(Storage::disk('local')->exists($path));
        $this->assertDeletionTraced($translation->id, TranslationService::DELETED_BY_ADMIN, $admin->id, $translation->user_id);
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
        $this->assertDeletionTraced($translation->id, TranslationService::DELETED_WITH_ACCOUNT, $user->id, $user->id);
    }
}
