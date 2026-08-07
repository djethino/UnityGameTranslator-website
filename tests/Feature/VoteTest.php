<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\Game;
use App\Models\Translation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Who may vote, and on what.
 *
 * The vote is the only signal that says someone was GLAD to find a translation — downloads
 * merely say they tried it — and it feeds ranking_score directly. Nothing stopped an author
 * from voting for their own work: one request, invisible, and in a catalogue whose highest
 * score is a single vote it outranked everything nobody thought to vote for.
 *
 * The mod and the site must refuse exactly the same things, hence a test for each door.
 */
class VoteTest extends TestCase
{
    use RefreshDatabase;

    private function makeTranslation(User $owner, string $visibility = 'public'): Translation
    {
        $game = Game::firstOrCreate(['slug' => 'vote-game'], ['name' => 'Vote Game']);

        $translation = new Translation();
        $translation->forceFill([
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'source_language' => 'English',
            'target_language' => 'French',
            'file_path' => 'translations/not-read-by-these-tests.json',
            'file_uuid' => 'uuid-' . uniqid(),
            'visibility' => $visibility,
            'file_hash' => 'hash-' . uniqid(),
            'line_count' => 10,
        ])->save();

        return $translation->refresh();
    }

    private function tokenFor(User $user): string
    {
        return ApiToken::createForUser($user, 'test')->plain_token;
    }

    public function test_a_player_can_vote_from_the_site(): void
    {
        $translation = $this->makeTranslation(User::factory()->create());

        $this->actingAs(User::factory()->create())
            ->postJson(route('votes.store', $translation), ['value' => 1])
            ->assertOk()
            ->assertJson(['vote_count' => 1, 'user_vote' => 1]);
    }

    public function test_an_author_cannot_vote_for_their_own_translation_from_the_site(): void
    {
        $owner = User::factory()->create();
        $translation = $this->makeTranslation($owner);

        $this->actingAs($owner)
            ->postJson(route('votes.store', $translation), ['value' => 1])
            ->assertForbidden();

        $this->assertSame(0, $translation->fresh()->vote_count);
    }

    public function test_an_author_cannot_vote_for_their_own_translation_from_the_mod(): void
    {
        $owner = User::factory()->create();
        $translation = $this->makeTranslation($owner);

        // The same refusal has to hold at the API door, or the mod becomes the way around it
        $this->withHeader('Authorization', 'Bearer ' . $this->tokenFor($owner))
            ->postJson("/api/v1/translations/{$translation->id}/vote", ['value' => 1])
            ->assertForbidden();

        $this->assertSame(0, $translation->fresh()->vote_count);
    }

    public function test_a_player_can_vote_from_the_mod(): void
    {
        $translation = $this->makeTranslation(User::factory()->create());
        $voter = User::factory()->create();

        $this->withHeader('Authorization', 'Bearer ' . $this->tokenFor($voter))
            ->postJson("/api/v1/translations/{$translation->id}/vote", ['value' => 1])
            ->assertOk()
            ->assertJson(['vote_count' => 1, 'user_vote' => 1]);
    }

    public function test_a_branch_cannot_be_voted_on(): void
    {
        $translation = $this->makeTranslation(User::factory()->create(), 'branch');

        // A branch is an unpublished contribution: voting on it would rank work its author
        // has not offered to anyone
        $this->actingAs(User::factory()->create())
            ->postJson(route('votes.store', $translation), ['value' => 1])
            ->assertForbidden();
    }

    public function test_an_author_is_shown_the_count_without_the_arrows(): void
    {
        $owner = User::factory()->create();
        $translation = $this->makeTranslation($owner);

        // A button that can only answer 403 is worse than no button
        $this->actingAs($owner)
            ->get(route('games.show', $translation->game))
            ->assertOk()
            ->assertSee(__('translation.cannot_vote_own'))
            ->assertDontSee('id="upvote-' . $translation->id . '"', false);
    }

    public function test_a_visitor_is_invited_to_sign_in_rather_than_shown_a_dead_arrow(): void
    {
        $translation = $this->makeTranslation(User::factory()->create());

        $this->get(route('games.show', $translation->game))
            ->assertOk()
            ->assertSee(__('translation.login_to_vote'));
    }
}
