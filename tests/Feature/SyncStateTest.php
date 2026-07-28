<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\Game;
use App\Models\Translation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The state the mod polls (and the SSE server pushes) to know where it stands.
 *
 * Both paths return this exact payload, so what is missing here is missing
 * everywhere: a branch that cannot see its Main diverges in silence, and a Main
 * owner who only gets a branch COUNT never learns that a contributor pushed new
 * work to a branch already counted.
 */
class SyncStateTest extends TestCase
{
    use RefreshDatabase;

    private function makeUserWithToken(): array
    {
        $user = User::factory()->create()->refresh();
        // plain_token is only readable on the instance that created it
        $token = ApiToken::createForUser($user)->plain_token;

        return [$user, $token];
    }

    private function makeTranslation(User $user, string $uuid, string $visibility, string $hash, ?string $reviewedHash = null): Translation
    {
        $game = Game::firstOrCreate(
            ['slug' => 'sync-state-game'],
            ['name' => 'Sync State Game']
        );

        $translation = new Translation();
        $translation->forceFill([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'source_language' => 'English',
            'target_language' => 'French',
            'file_path' => 'translations/does-not-need-to-exist.json',
            'file_uuid' => $uuid,
            'visibility' => $visibility,
            'file_hash' => $hash,
            'reviewed_hash' => $reviewedHash,
            'line_count' => 1,
        ])->save();

        return $translation;
    }

    private function state(string $token, string $uuid, ?string $hash = null): array
    {
        $query = ['uuid' => $uuid];
        if ($hash !== null) {
            $query['hash'] = $hash;
        }

        return $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/sync/state?' . http_build_query($query))
            ->assertOk()
            ->json();
    }

    public function test_a_branch_is_told_about_the_main_it_derives_from(): void
    {
        $uuid = (string) \Illuminate\Support\Str::uuid();
        [$mainOwner] = $this->makeUserWithToken();
        [$contributor, $token] = $this->makeUserWithToken();

        $this->makeTranslation($mainOwner, $uuid, 'public', 'main-hash');
        $this->makeTranslation($contributor, $uuid, 'branch', 'branch-hash');

        $state = $this->state($token, $uuid, 'branch-hash');

        $this->assertSame('branch', $state['role']);
        $this->assertNotNull($state['main'], 'A branch must be able to see that its Main moved.');
        $this->assertSame('main-hash', $state['main']['file_hash']);
        $this->assertSame($mainOwner->name, $state['main']['uploader']);
    }

    public function test_a_main_owner_is_told_how_many_branches_await_review(): void
    {
        $uuid = (string) \Illuminate\Support\Str::uuid();
        [$owner, $token] = $this->makeUserWithToken();
        [$alice] = $this->makeUserWithToken();
        [$bob] = $this->makeUserWithToken();
        [$carol] = $this->makeUserWithToken();

        $this->makeTranslation($owner, $uuid, 'public', 'main-hash');
        // Never reviewed
        $this->makeTranslation($alice, $uuid, 'branch', 'a1');
        // Reviewed, then pushed more work: the count would not have moved
        $this->makeTranslation($bob, $uuid, 'branch', 'b2', 'b1');
        // Reviewed and untouched since
        $this->makeTranslation($carol, $uuid, 'branch', 'c1', 'c1');

        $state = $this->state($token, $uuid, 'main-hash');

        $this->assertSame('main', $state['role']);
        $this->assertSame(3, $state['branches_count']);
        $this->assertSame(2, $state['branches_pending_review']);
    }

    public function test_a_main_owner_is_not_told_about_a_main_of_their_own(): void
    {
        $uuid = (string) \Illuminate\Support\Str::uuid();
        [$owner, $token] = $this->makeUserWithToken();
        $this->makeTranslation($owner, $uuid, 'public', 'main-hash');

        $state = $this->state($token, $uuid, 'main-hash');

        // "main" describes someone else's upstream; for the Main itself it is noise
        $this->assertNull($state['main']);
        $this->assertFalse($state['has_update']);
    }

    public function test_a_branch_with_no_main_gets_null_rather_than_an_error(): void
    {
        $uuid = (string) \Illuminate\Support\Str::uuid();
        [$contributor, $token] = $this->makeUserWithToken();
        $this->makeTranslation($contributor, $uuid, 'branch', 'branch-hash');

        $state = $this->state($token, $uuid, 'branch-hash');

        $this->assertSame('branch', $state['role']);
        $this->assertNull($state['main']);
    }
}
