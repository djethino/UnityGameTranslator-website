<?php

namespace Tests\Feature;

use App\Models\AccountDeletion;
use App\Models\ApiToken;
use App\Models\Game;
use App\Models\RecoveryCode;
use App\Models\Translation;
use App\Models\User;
use App\Models\Vote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Erasing a person without erasing other people's work.
 *
 * 🔴 **This screen had no test at all**, and it was the most sensitive one on the site: it claimed
 * to delete an account while leaving every API token valid, so the mod went on publishing under a
 * name its owner had asked us to erase.
 *
 * The three questions it has to answer, and they pull in different directions:
 * · is the PERSON gone — every identifying column, every way back in;
 * · is the WORK still there — translations, and the counters other people's pages are built from;
 * · can a RESTORE be told, since a backup from before today brings the account back whole.
 */
class AccountDeletionTest extends TestCase
{
    use RefreshDatabase;

    private function makeTranslation(User $owner): Translation
    {
        $game = Game::firstOrCreate(['slug' => 'deletion-game'], ['name' => 'Deletion Game']);

        $translation = new Translation();
        $translation->forceFill([
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'source_language' => 'English',
            'target_language' => 'French',
            'file_path' => 'translations/not-read-by-these-tests.json',
            'file_uuid' => 'uuid-' . uniqid(),
            'visibility' => 'public',
            'file_hash' => 'hash-' . uniqid(),
            'line_count' => 10,
        ])->save();

        return $translation;
    }

    private function deleteAccountOf(User $user): void
    {
        $this->actingAs($user)
            ->delete('/profile', ['confirm_name' => $user->name])
            ->assertRedirect('/');
    }

    public function test_the_person_is_gone_from_every_identifying_column(): void
    {
        $user = User::factory()->create([
            'name' => 'RealName',
            'username' => 'realname',
            'email' => 'real@example.com',
            'provider' => 'github',
            'provider_id' => 'gh-12345',
            'locale' => 'fr',
        ]);

        $this->deleteAccountOf($user);

        $row = $user->fresh();
        $this->assertSame('[Deleted]', $row->name);
        $this->assertNull($row->username);
        $this->assertNull($row->password);
        $this->assertNull($row->provider);
        $this->assertNull($row->provider_id);
        $this->assertNull($row->avatar);
        $this->assertNull($row->locale);
        $this->assertNotNull($row->banned_at);

        // Nothing anywhere on the row still carries what identified them
        $left = json_encode($row->toArray());
        $this->assertStringNotContainsString('RealName', $left);
        $this->assertStringNotContainsString('realname', $left);
        $this->assertStringNotContainsString('real@example.com', $left);
        $this->assertStringNotContainsString('gh-12345', $left);
    }

    /**
     * 🔴 The defect this whole test file exists for.
     *
     * destroy() used to write banned_at by hand instead of calling ban(), so it inherited the flag
     * and not the cut. AuthenticateApi never looks at banned_at, so the token stayed usable.
     */
    public function test_every_api_token_is_cut(): void
    {
        $user = User::factory()->create();
        ApiToken::createForUser($user, 'Unity Mod (test)');
        $this->assertSame(1, ApiToken::where('user_id', $user->id)->count());

        $this->deleteAccountOf($user);

        $this->assertSame(0, ApiToken::where('user_id', $user->id)->count());
    }

    public function test_the_other_ways_back_in_are_removed(): void
    {
        $user = User::factory()->create();
        RecoveryCode::create(['user_id' => $user->id, 'code_hash' => bcrypt('one-time')]);
        DB::table('sessions')->insert([
            'id' => 'session-under-test',
            'user_id' => $user->id,
            'ip_address' => '203.0.113.4',
            'user_agent' => 'test',
            'payload' => '',
            'last_activity' => time(),
        ]);

        $this->deleteAccountOf($user);

        $this->assertSame(0, RecoveryCode::where('user_id', $user->id)->count());
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
    }

    /**
     * 🔴 What belongs to other people stays.
     *
     * Votes used to be deleted here, which lowered the score of translations belonging to somebody
     * else — a stranger's page changed because a third party closed their account. Attached to an
     * anonymised row the vote identifies nobody, so there is nothing to gain by removing it and a
     * ranking to lose.
     */
    public function test_work_and_other_peoples_counters_survive(): void
    {
        $author = User::factory()->create();
        $leaver = User::factory()->create(['name' => 'Leaver']);

        $ownTranslation = $this->makeTranslation($leaver);
        $othersTranslation = $this->makeTranslation($author);

        Vote::create([
            'translation_id' => $othersTranslation->id,
            'user_id' => $leaver->id,
            'value' => 1,
        ]);

        $this->deleteAccountOf($leaver);

        // The work stays, and stays attached to the (now anonymous) row
        $this->assertNotNull($ownTranslation->fresh());
        $this->assertSame($leaver->id, $ownTranslation->fresh()->user_id);

        // And so does the vote cast on somebody else's translation
        $this->assertSame(1, Vote::where('translation_id', $othersTranslation->id)->count());
    }

    public function test_the_deletion_is_recorded_so_a_restore_can_be_told(): void
    {
        $user = User::factory()->create();

        $this->deleteAccountOf($user);

        $noted = AccountDeletion::where('user_id', $user->id)->first();
        $this->assertNotNull($noted);
        $this->assertNotNull($noted->deleted_at);
    }

    public function test_a_wrong_confirmation_changes_nothing(): void
    {
        $user = User::factory()->create(['name' => 'Careful']);

        $this->actingAs($user)
            ->delete('/profile', ['confirm_name' => 'Not my name'])
            ->assertSessionHasErrors('confirm_name');

        $this->assertSame('Careful', $user->fresh()->name);
        $this->assertNull($user->fresh()->banned_at);
        $this->assertSame(0, AccountDeletion::where('user_id', $user->id)->count());
    }
}
