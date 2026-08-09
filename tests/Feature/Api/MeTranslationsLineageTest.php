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
 * What /me/translations says about each file's place in its lineage.
 *
 * The endpoint listed languages and counts but never the lineage, so a client holding a
 * translations.json had no way to tell whether the row in front of it was the very file on disk,
 * nor whether its owner led that lineage or contributed to it. check-uuid answers both, one uuid at
 * a time — fine before an upload, useless to a tool looking at a library of fifty games under a
 * 60-per-minute budget.
 *
 * The role is asserted here AND in check-uuid's own tests on purpose: they now share one definition
 * on the model, and the point of these tests is to notice the day they stop agreeing.
 */
class MeTranslationsLineageTest extends TestCase
{
    use RefreshDatabase;

    private function makeTranslation(User $owner, string $visibility, string $uuid): Translation
    {
        $game = Game::firstOrCreate(['slug' => 'lineage-game'], ['name' => 'Lineage Game']);

        $path = 'translations/lineage-' . uniqid() . '.json';
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

    private function headers(User $user): array
    {
        return ['Authorization' => 'Bearer ' . ApiToken::createForUser($user, 'lineage test')->plain_token];
    }

    private function entryFor(array $payload, string $uuid): array
    {
        foreach ($payload['translations'] as $entry) {
            if ($entry['file_uuid'] === $uuid) {
                return $entry;
            }
        }

        $this->fail("No entry for lineage {$uuid}");
    }

    public function test_a_main_is_told_how_many_contributions_wait_on_it(): void
    {
        $owner = User::factory()->create();
        $this->makeTranslation($owner, 'public', 'uuid-main-with-branches');
        $this->makeTranslation(User::factory()->create(), 'branch', 'uuid-main-with-branches');
        $this->makeTranslation(User::factory()->create(), 'branch', 'uuid-main-with-branches');

        $entry = $this->entryFor(
            $this->getJson('/api/v1/me/translations', $this->headers($owner))->assertOk()->json(),
            'uuid-main-with-branches'
        );

        $this->assertSame('main', $entry['role']);
        $this->assertSame(2, $entry['branches_count']);
        $this->assertNull($entry['main_missing'], 'A Main cannot be missing its own head');
    }

    /**
     * Zero contributions and "the question does not apply" must not look the same: a branch is told
     * null, a Main with nobody behind it is told 0.
     */
    public function test_a_main_with_no_contribution_is_told_zero_not_null(): void
    {
        $owner = User::factory()->create();
        $this->makeTranslation($owner, 'public', 'uuid-lonely-main');

        $entry = $this->entryFor(
            $this->getJson('/api/v1/me/translations', $this->headers($owner))->assertOk()->json(),
            'uuid-lonely-main'
        );

        $this->assertSame(0, $entry['branches_count']);
    }

    public function test_a_branch_is_a_branch_and_is_asked_nothing_about_contributions(): void
    {
        $contributor = User::factory()->create();
        $this->makeTranslation(User::factory()->create(), 'public', 'uuid-healthy-branch');
        $this->makeTranslation($contributor, 'branch', 'uuid-healthy-branch');

        $entry = $this->entryFor(
            $this->getJson('/api/v1/me/translations', $this->headers($contributor))->assertOk()->json(),
            'uuid-healthy-branch'
        );

        $this->assertSame('branch', $entry['role']);
        $this->assertNull($entry['branches_count']);
        $this->assertFalse($entry['main_missing']);
    }

    /**
     * The case nothing reported before: the Main is gone, and the contributor is still writing
     * towards a head that no longer exists.
     */
    public function test_a_branch_whose_main_is_gone_says_so(): void
    {
        $contributor = User::factory()->create();
        $main = $this->makeTranslation(User::factory()->create(), 'public', 'uuid-orphan-branch');
        $this->makeTranslation($contributor, 'branch', 'uuid-orphan-branch');

        $main->delete();

        $entry = $this->entryFor(
            $this->getJson('/api/v1/me/translations', $this->headers($contributor))->assertOk()->json(),
            'uuid-orphan-branch'
        );

        $this->assertTrue($entry['main_missing']);
    }

    /**
     * A fork IS a main: it is published, it leads its own lineage and it has its own branches. It is
     * simply not the ROOT of that lineage, which is why the role cannot be judged on isMain().
     */
    public function test_a_fork_is_a_main_of_its_own_lineage(): void
    {
        $original = User::factory()->create();
        $forker = User::factory()->create();

        $this->makeTranslation($original, 'public', 'uuid-forked');
        $this->makeTranslation($forker, 'public', 'uuid-forked');

        $entry = $this->entryFor(
            $this->getJson('/api/v1/me/translations', $this->headers($forker))->assertOk()->json(),
            'uuid-forked'
        );

        $this->assertSame('main', $entry['role']);
    }

    public function test_the_endpoint_still_refuses_an_anonymous_caller(): void
    {
        $this->getJson('/api/v1/me/translations')->assertUnauthorized();
    }
}
