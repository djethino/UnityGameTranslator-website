<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\Translation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Nobody deletes what is not theirs.
 *
 * The deletion paths were reworked on 2026-08-26/27 — one entry point for translations, an account
 * erasure that now removes files — so the guards are asserted rather than assumed. Each test states
 * the way in that a stranger would try.
 *
 * ⚠ The file is checked as well as the row: a refusal that still removed the JSON would be the
 * worst of both, and the row is the only thing a status code proves anything about.
 */
class DeletionAuthorisationTest extends TestCase
{
    use RefreshDatabase;

    private function makeTranslationWithFile(User $owner): Translation
    {
        $game = Game::firstOrCreate(['slug' => 'guard-game'], ['name' => 'Guard Game']);

        $path = 'translations/' . uniqid() . '_guard.json';
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

    public function test_a_stranger_cannot_delete_somebody_elses_translation(): void
    {
        $owner = User::factory()->create();
        $translation = $this->makeTranslationWithFile($owner);
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->delete("/translations/{$translation->id}")
            ->assertForbidden();

        $this->assertNotNull($translation->fresh());
        $this->assertTrue(Storage::disk('local')->exists($translation->file_path));
    }

    public function test_a_visitor_cannot_delete_a_translation(): void
    {
        $translation = $this->makeTranslationWithFile(User::factory()->create());

        $this->delete("/translations/{$translation->id}")->assertRedirect();

        $this->assertNotNull($translation->fresh());
        $this->assertTrue(Storage::disk('local')->exists($translation->file_path));
    }

    public function test_a_plain_account_cannot_reach_the_admin_deletion(): void
    {
        $translation = $this->makeTranslationWithFile(User::factory()->create());

        $this->actingAs(User::factory()->create())
            ->delete("/admin/translations/{$translation->id}");

        $this->assertNotNull($translation->fresh());
        $this->assertTrue(Storage::disk('local')->exists($translation->file_path));
    }

    /**
     * 🔴 Erasure has no target: it always acts on whoever is signed in.
     *
     * Asserted because the route takes no id and the temptation, the day somebody adds an admin
     * screen for it, is to give it one.
     */
    public function test_erasing_an_account_cannot_be_pointed_at_somebody_else(): void
    {
        $victim = User::factory()->create(['name' => 'Victim']);
        $attacker = User::factory()->create(['name' => 'Attacker']);

        // Every shape an attempt could take: an id, a name, and the victim's confirmation word.
        $this->actingAs($attacker)->delete('/profile', [
            'confirm_name' => 'Victim',
            'user_id' => $victim->id,
            'id' => $victim->id,
            'name' => 'Victim',
        ]);

        // The victim is untouched — and the attacker is not erased either, since the confirmation
        // has to match THEIR name.
        $this->assertFalse($victim->fresh()->isDeletedAccount());
        $this->assertFalse($attacker->fresh()->isDeletedAccount());
        $this->assertSame('Victim', $victim->fresh()->name);
    }

    /**
     * 🔴 Erasing an account touches that account's translations and no others.
     *
     * `delete_translations` is a boolean with no target; the rows come from the relation. Asserted
     * so it stays that way.
     */
    public function test_erasing_an_account_leaves_other_peoples_files_alone(): void
    {
        $leaver = User::factory()->create(['name' => 'Leaver']);
        $bystander = User::factory()->create();

        $mine = $this->makeTranslationWithFile($leaver);
        $theirs = $this->makeTranslationWithFile($bystander);

        $this->actingAs($leaver)->delete('/profile', [
            'confirm_name' => 'Leaver',
            'delete_translations' => '1',
        ]);

        $this->assertNull($mine->fresh());
        $this->assertFalse(Storage::disk('local')->exists($mine->file_path));

        $this->assertNotNull($theirs->fresh());
        $this->assertTrue(Storage::disk('local')->exists($theirs->file_path));
    }
}
