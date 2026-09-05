<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\Game;
use App\Models\Translation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * check-uuid tells whoever is ABOUT to contribute whether the Main takes contributions.
 *
 * The flag was sent on the caller's own row only — to somebody who had already contributed — and
 * never to the person holding the file with no row of their own, who is exactly the one deciding
 * whether to send. Both clients read an absent field as "not asked" (an older site never says),
 * so both offered "Contribute" over a translation whose author works alone, and the upload was
 * refused by determineOwnership after the work. sync/state said it at the top level all along;
 * the two doors must agree.
 */
class CheckUuidAcceptsBranchesTest extends TestCase
{
    use RefreshDatabase;

    private function makeMain(User $owner, string $uuid, bool $acceptsBranches): Translation
    {
        $game = Game::firstOrCreate(['slug' => 'accepts-game'], ['name' => 'Accepts Game']);

        $translation = new Translation();
        $translation->forceFill([
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'source_language' => 'English',
            'target_language' => 'French',
            'file_path' => 'translations/not-read-by-these-tests.json',
            'file_uuid' => $uuid,
            'visibility' => 'public',
            'file_hash' => 'hash-' . uniqid(),
            'line_count' => 10,
            'accepts_branches' => $acceptsBranches,
        ])->save();

        return $translation->refresh();
    }

    private function check(User $user, string $uuid)
    {
        return $this->withHeader('Authorization', 'Bearer ' . ApiToken::createForUser($user, 'test')->plain_token)
            ->getJson('/api/v1/translations/check-uuid?uuid=' . $uuid);
    }

    private function syncState(User $user, string $uuid)
    {
        return $this->withHeader('Authorization', 'Bearer ' . ApiToken::createForUser($user, 'test')->plain_token)
            ->getJson('/api/v1/sync/state?uuid=' . $uuid);
    }

    public function test_somebody_with_no_row_is_told_the_main_works_alone(): void
    {
        $this->makeMain(User::factory()->create(), 'uuid-solo', acceptsBranches: false);
        $player = User::factory()->create();

        $this->check($player, 'uuid-solo')
            ->assertOk()
            ->assertJsonPath('exists', true)
            ->assertJsonPath('role', 'none')
            ->assertJsonPath('accepts_branches', false);
    }

    public function test_somebody_with_no_row_is_told_the_main_takes_contributions(): void
    {
        $this->makeMain(User::factory()->create(), 'uuid-open', acceptsBranches: true);
        $player = User::factory()->create();

        $this->check($player, 'uuid-open')
            ->assertOk()
            ->assertJsonPath('role', 'none')
            ->assertJsonPath('accepts_branches', true);
    }

    public function test_both_doors_say_the_same_thing(): void
    {
        $this->makeMain(User::factory()->create(), 'uuid-doors', acceptsBranches: false);
        $player = User::factory()->create();

        $fromCheck = $this->check($player, 'uuid-doors')->json('accepts_branches');
        $fromState = $this->syncState($player, 'uuid-doors')->json('accepts_branches');

        $this->assertFalse($fromCheck);
        $this->assertSame($fromCheck, $fromState, 'check-uuid and sync/state must not disagree');
    }

    public function test_the_owner_still_reads_it_on_their_own_row(): void
    {
        $owner = User::factory()->create();
        $this->makeMain($owner, 'uuid-mine', acceptsBranches: true);

        $this->check($owner, 'uuid-mine')
            ->assertOk()
            ->assertJsonPath('role', 'main')
            ->assertJsonPath('accepts_branches', true);
    }
}
