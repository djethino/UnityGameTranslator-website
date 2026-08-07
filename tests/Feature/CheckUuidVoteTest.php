<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\Game;
use App\Models\Translation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What check-uuid tells the mod about votes.
 *
 * The mod's current-translation card is where a player has actually USED the translation, so
 * it is where the vote belongs. It needs three things the mod must not work out for itself:
 * the count, this player's own vote, and whether they are allowed to vote at all. The last one
 * is a server rule (Translation::canBeVotedBy) and has to stay one.
 */
class CheckUuidVoteTest extends TestCase
{
    use RefreshDatabase;

    private function makeTranslation(User $owner, string $uuid, string $visibility = 'public'): Translation
    {
        $game = Game::firstOrCreate(['slug' => 'uuid-game'], ['name' => 'Uuid Game']);

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

    public function test_the_endpoint_the_mods_card_actually_reads_carries_the_vote(): void
    {
        // sync/state, not check-uuid, is what fills the mod's ServerState — and therefore the
        // current-translation card. The SSE server forwards this body verbatim.
        $main = $this->makeTranslation(User::factory()->create(), 'uuid-sync');
        $player = User::factory()->create();
        $main->vote(-1, $player);

        $this->syncState($player, 'uuid-sync')
            ->assertOk()
            ->assertJsonPath('vote.target_id', $main->id)
            ->assertJsonPath('vote.count', -1)
            ->assertJsonPath('vote.user_vote', -1)
            ->assertJsonPath('vote.can_vote', true);
    }

    public function test_both_doors_describe_a_vote_the_same_way(): void
    {
        $main = $this->makeTranslation(User::factory()->create(), 'uuid-both');
        $player = User::factory()->create();
        $main->vote(1, $player);

        // Two endpoints answer "what does the site know about this uuid". They must not
        // disagree, which is why the block is built by the model.
        $fromCheck = $this->check($player, 'uuid-both')->json('vote');
        $fromSync = $this->syncState($player, 'uuid-both')->json('vote');

        $this->assertSame($fromCheck, $fromSync);
    }

    public function test_an_author_reading_sync_state_gets_the_count_but_no_right_to_vote(): void
    {
        $owner = User::factory()->create();
        $main = $this->makeTranslation($owner, 'uuid-own-sync');

        $this->syncState($owner, 'uuid-own-sync')
            ->assertOk()
            ->assertJsonPath('role', 'main')
            ->assertJsonPath('vote.target_id', $main->id)
            ->assertJsonPath('vote.can_vote', false);
    }

    public function test_a_player_using_someone_elses_translation_may_vote_on_it(): void
    {
        $main = $this->makeTranslation(User::factory()->create(), 'uuid-a');
        $player = User::factory()->create();
        $main->vote(1, $player);

        $this->check($player, 'uuid-a')
            ->assertOk()
            ->assertJsonPath('vote.target_id', $main->id)
            ->assertJsonPath('vote.count', 1)
            ->assertJsonPath('vote.user_vote', 1)
            ->assertJsonPath('vote.can_vote', true);
    }

    public function test_an_author_gets_the_count_but_not_the_right_to_vote(): void
    {
        $owner = User::factory()->create();
        $main = $this->makeTranslation($owner, 'uuid-b');

        $this->check($owner, 'uuid-b')
            ->assertOk()
            ->assertJsonPath('role', 'main')
            ->assertJsonPath('vote.target_id', $main->id)
            ->assertJsonPath('vote.can_vote', false)
            ->assertJsonPath('vote.user_vote', null);
    }

    public function test_a_branch_author_votes_on_the_main_they_are_contributing_to(): void
    {
        $main = $this->makeTranslation(User::factory()->create(), 'uuid-c');
        $contributor = User::factory()->create();
        $this->makeTranslation($contributor, 'uuid-c', 'branch');

        // They downloaded it, they played it, they are improving it — the thing they can thank
        // is the published translation, not their own unpublished branch
        $this->check($contributor, 'uuid-c')
            ->assertOk()
            ->assertJsonPath('role', 'branch')
            ->assertJsonPath('vote.target_id', $main->id)
            ->assertJsonPath('vote.can_vote', true);
    }

    public function test_a_lineage_with_nothing_published_offers_nothing_to_vote_on(): void
    {
        $user = User::factory()->create();
        $this->makeTranslation($user, 'uuid-d', 'branch');

        $this->check($user, 'uuid-d')
            ->assertOk()
            ->assertJsonPath('vote', null);
    }

    public function test_an_unknown_uuid_says_so_without_a_vote_block(): void
    {
        $this->check(User::factory()->create(), 'uuid-nothing-here')
            ->assertOk()
            ->assertJsonPath('exists', false)
            ->assertJsonMissingPath('vote');
    }
}
