<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\Translation;
use App\Models\User;
use App\Services\TranslationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A lineage whose Main is unreachable does not change hands.
 *
 * 🔴 **It used to.** determineOwnership treated "no Main for this uuid" as "new translation", so the
 * next person to upload became the owner of a lineage they had never led — inheriting its
 * followers, and turning the other contributors into branches of a stranger. Nothing told anybody.
 *
 * Three situations reach the same end, and the messages differ because what the reader needs to
 * understand differs:
 * · the file was deleted, the account kept  — the Main is gone, the account still fathers the forks;
 * · both were deleted                        — the forks' origin is now [Deleted-…];
 * · the account was deleted, the file kept   — the Main is still readable and still downloadable.
 *
 * Nobody is forced to fork: the refusal names the way out, and the branch stays as it is until its
 * author decides.
 */
class OrphanedLineageTest extends TestCase
{
    use RefreshDatabase;

    private function makeTranslation(User $owner, string $uuid, string $visibility = 'public'): Translation
    {
        $game = Game::firstOrCreate(['slug' => 'orphan-game'], ['name' => 'Orphan Game']);

        $translation = new Translation();
        $translation->forceFill([
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'source_language' => 'English',
            'target_language' => 'French',
            'file_path' => 'translations/not-read-by-these-tests.json',
            'file_uuid' => $uuid,
            'visibility' => $visibility,
            'file_hash' => 'hash-' . uniqid(),
            'line_count' => 10,
            'accepts_branches' => true,
        ])->save();

        return $translation;
    }

    private function ownership(string $uuid, User $sender): array
    {
        return app(TranslationService::class)->determineOwnership($uuid, $sender->id);
    }

    /** The ordinary case must keep working: a uuid nobody has ever published is somebody starting. */
    public function test_an_unknown_uuid_is_still_a_new_translation(): void
    {
        $newcomer = User::factory()->create();

        $decision = $this->ownership('uuid-never-seen', $newcomer);

        $this->assertSame('public', $decision['visibility']);
        $this->assertArrayNotHasKey('refused', $decision);
    }

    /** The account is erased, the translation stays: readable, downloadable, and unmergeable. */
    public function test_a_main_whose_owner_was_erased_takes_no_contribution(): void
    {
        $gone = User::factory()->create(['account_deleted_at' => now()]);
        $this->makeTranslation($gone, 'uuid-abandoned');

        $decision = $this->ownership('uuid-abandoned', User::factory()->create());

        $this->assertSame(TranslationService::MAIN_ABANDONED, $decision['refused']);
        $this->assertNull($decision['visibility']);
    }

    /** ⚠ A ban is not an erasure: it can be undone, and the account is still somebody's. */
    public function test_a_banned_owner_is_not_treated_as_erased(): void
    {
        $banned = User::factory()->create(['banned_at' => now()]);
        $this->makeTranslation($banned, 'uuid-banned');

        $decision = $this->ownership('uuid-banned', User::factory()->create());

        $this->assertSame('branch', $decision['visibility']);
        $this->assertArrayNotHasKey('refused', $decision);
    }

    /** The Main was deleted and its contributors are still holding branches of it. */
    public function test_an_orphaned_branch_cannot_take_over_the_lineage(): void
    {
        $author = User::factory()->create();
        $contributor = User::factory()->create();

        $main = $this->makeTranslation($author, 'uuid-orphaned');
        $this->makeTranslation($contributor, 'uuid-orphaned', 'branch');
        $main->delete();

        $decision = $this->ownership('uuid-orphaned', $contributor);

        $this->assertSame(TranslationService::MAIN_GONE, $decision['refused']);
    }

    /**
     * ⚠ A Main deleted with nobody behind it leaves no trace, and must not lock the uuid: the
     * sender holds the file and no other contributor exists to be surprised.
     */
    public function test_a_deleted_main_with_no_branches_does_not_lock_the_uuid(): void
    {
        $author = User::factory()->create();
        $main = $this->makeTranslation($author, 'uuid-solitary');
        $main->delete();

        $decision = $this->ownership('uuid-solitary', User::factory()->create());

        $this->assertSame('public', $decision['visibility']);
        $this->assertArrayNotHasKey('refused', $decision);
    }

    /** Both refusals must name the way out, since old mods show the sentence as-is. */
    public function test_both_refusals_offer_the_way_out(): void
    {
        foreach ([TranslationService::MAIN_ABANDONED, TranslationService::MAIN_GONE] as $message) {
            $this->assertStringContainsString('Publish my own version', $message);
        }
    }
}
