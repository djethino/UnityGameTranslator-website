<?php

namespace Tests\Feature\Api;

use App\Models\ApiToken;
use App\Models\Game;
use App\Models\Translation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Who may fetch a branch through the API.
 *
 * A branch is private, but "private" was implemented as "the Main owner's", which is not the same
 * thing and locked contributors out of work they had written themselves: reinstall a game, move to
 * another machine or lose the file, and the only way back was to ask someone else for it. Whoever
 * writes a branch can always fetch it.
 *
 * The Main owner keeps access because merging means reading what a branch holds — a different act
 * from taking a branch as one's own translation, which no screen offers.
 *
 * Both endpoints are covered on purpose. A contributor who could download their branch but not ask
 * whether it had changed would be told nothing about their own work, and one rule split across two
 * routes is one rule waiting to diverge.
 */
class BranchAccessTest extends TestCase
{
    use RefreshDatabase;

    private function makeTranslation(User $owner, string $visibility, string $uuid): Translation
    {
        $game = Game::firstOrCreate(['slug' => 'branch-access-game'], ['name' => 'Branch Access Game']);

        $path = 'translations/branch-access-' . uniqid() . '.json';
        Storage::disk('local')->put($path, json_encode([
            '_uuid' => $uuid,
            'Shop' => ['v' => 'Boutique', 't' => 'H'],
        ], JSON_UNESCAPED_UNICODE));

        $translation = new Translation();
        $translation->forceFill([
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'source_language' => 'English',
            'target_language' => 'French',
            'visibility' => $visibility,
            'file_uuid' => $uuid,
            'file_path' => $path,
            'file_hash' => 'hash-' . uniqid(),
            'human_count' => 1,
            'validated_count' => 1,
        ])->save();

        return $translation->refresh();
    }

    private function tokenFor(User $user): string
    {
        return ApiToken::createForUser($user, 'branch access test')->plain_token;
    }

    private function headers(User $user): array
    {
        return ['Authorization' => 'Bearer ' . $this->tokenFor($user)];
    }

    public function test_the_author_of_a_branch_can_download_it(): void
    {
        $mainOwner = User::factory()->create();
        $contributor = User::factory()->create();

        $main = $this->makeTranslation($mainOwner, 'public', 'uuid-branch-access');
        $branch = $this->makeTranslation($contributor, 'branch', 'uuid-branch-access');

        $this->getJson("/api/v1/translations/{$branch->id}/download", $this->headers($contributor))
            ->assertOk();

        $this->getJson("/api/v1/translations/{$branch->id}/check", $this->headers($contributor))
            ->assertOk();
    }

    public function test_the_main_owner_can_reach_a_branch_to_merge_it(): void
    {
        $mainOwner = User::factory()->create();
        $contributor = User::factory()->create();

        $this->makeTranslation($mainOwner, 'public', 'uuid-branch-access');
        $branch = $this->makeTranslation($contributor, 'branch', 'uuid-branch-access');

        $this->getJson("/api/v1/translations/{$branch->id}/download", $this->headers($mainOwner))
            ->assertOk();
    }

    public function test_a_stranger_cannot_reach_a_branch(): void
    {
        $mainOwner = User::factory()->create();
        $contributor = User::factory()->create();
        $stranger = User::factory()->create();

        $this->makeTranslation($mainOwner, 'public', 'uuid-branch-access');
        $branch = $this->makeTranslation($contributor, 'branch', 'uuid-branch-access');

        $this->getJson("/api/v1/translations/{$branch->id}/download", $this->headers($stranger))
            ->assertForbidden();

        $this->getJson("/api/v1/translations/{$branch->id}/check", $this->headers($stranger))
            ->assertForbidden();
    }

    public function test_an_anonymous_caller_cannot_reach_a_branch(): void
    {
        $mainOwner = User::factory()->create();
        $contributor = User::factory()->create();

        $this->makeTranslation($mainOwner, 'public', 'uuid-branch-access');
        $branch = $this->makeTranslation($contributor, 'branch', 'uuid-branch-access');

        $this->getJson("/api/v1/translations/{$branch->id}/download")->assertForbidden();
        $this->getJson("/api/v1/translations/{$branch->id}/check")->assertForbidden();
    }

    /**
     * A branch author is not shut out of the Main either — it is public, and taking it is how a
     * contributor starts from the current state before writing more.
     */
    public function test_anyone_can_still_download_the_main(): void
    {
        $mainOwner = User::factory()->create();
        $main = $this->makeTranslation($mainOwner, 'public', 'uuid-branch-access');

        $this->getJson("/api/v1/translations/{$main->id}/download")->assertOk();
    }
}
