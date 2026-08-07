<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\Translation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The order the mod's Community list and first-run wizard show.
 *
 * This is the one screen where players actually pick a translation, and it was ordered by votes
 * then downloads — the weakest signal in the catalogue, since most translations have never been
 * voted on. Review, coverage and maintenance were all ignored.
 */
class TranslationSearchOrderTest extends TestCase
{
    use RefreshDatabase;

    private function make(Game $game, array $counts, array $extra = []): Translation
    {
        $translation = new Translation();
        $translation->forceFill(array_merge([
            'game_id' => $game->id,
            'user_id' => User::factory()->create()->id,
            'source_language' => 'English',
            'target_language' => 'French',
            'file_path' => 'translations/not-read-by-these-tests.json',
            'file_uuid' => 'uuid-' . uniqid(),
            'visibility' => 'public',
            'file_hash' => 'hash-' . uniqid(),
            'human_count' => 0,
            'validated_count' => 0,
            'ai_count' => 0,
            'skipped_count' => 0,
            'capture_count' => 0,
        ], $counts, $extra))->save();

        return $translation->refresh();
    }

    public function test_a_translation_covering_the_game_comes_before_a_polished_fragment(): void
    {
        $game = Game::firstOrCreate(['slug' => 'order-game'], ['name' => 'Order Game']);

        $fragment = $this->make($game, ['human_count' => 300], ['notes' => 'fragment']);
        $whole = $this->make($game, ['human_count' => 1000, 'validated_count' => 2000], ['notes' => 'whole']);

        $ids = collect($this->getJson('/api/v1/translations?game=order-game')
            ->assertOk()->json('translations'))->pluck('id')->all();

        $this->assertSame([$whole->id, $fragment->id], $ids);
    }

    public function test_a_single_vote_no_longer_decides_the_order(): void
    {
        $game = Game::firstOrCreate(['slug' => 'vote-order-game'], ['name' => 'Vote Order Game']);

        // The old order was votes first. One vote on an otherwise thin file put it on top of a
        // translation covering the whole game — and most files have no votes at all.
        $thin = $this->make($game, ['ai_count' => 200]);
        $thin->vote(1, User::factory()->create());
        $full = $this->make($game, ['human_count' => 2000]);

        $ids = collect($this->getJson('/api/v1/translations?game=vote-order-game')
            ->assertOk()->json('translations'))->pluck('id')->all();

        $this->assertSame([$full->id, $thin->id], $ids);
    }

    public function test_the_response_carries_both_coverages(): void
    {
        $game = Game::firstOrCreate(['slug' => 'cov-game'], ['name' => 'Cov Game']);
        $this->make($game, ['human_count' => 500, 'validated_count' => 500]);
        $half = $this->make($game, ['human_count' => 250, 'ai_count' => 250]);

        $row = collect($this->getJson('/api/v1/translations?game=cov-game')->assertOk()->json('translations'))
            ->firstWhere('id', $half->id);

        // The mod cannot work game coverage out on its own: it would need every other
        // translation of the game.
        $this->assertSame(0.5, $row['game_coverage']);
        $this->assertSame(0.5, $row['review_coverage']);
        // Still sent for mods already published that read it
        $this->assertArrayHasKey('quality_score', $row);
    }

    public function test_a_capture_only_upload_reports_no_review_coverage(): void
    {
        $game = Game::firstOrCreate(['slug' => 'cap-game'], ['name' => 'Cap Game']);
        $captured = $this->make($game, ['capture_count' => 900]);

        $row = collect($this->getJson('/api/v1/translations?game=cap-game')->assertOk()->json('translations'))
            ->firstWhere('id', $captured->id);

        // Null, not zero: nothing was translated, so there is nothing to have read
        $this->assertNull($row['review_coverage']);
    }
}
