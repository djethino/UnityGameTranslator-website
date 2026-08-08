<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\Translation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A file with two lines translated out of thirteen met in game carried the badge "Fully
 * reviewed" — the loudest on the site — and ranked third of the whole catalogue.
 *
 * Reviewing and translating are two different jobs. The review stage judged the first while the
 * second had barely started, and the reader was told the opposite of the truth.
 */
class TranslationCompletenessTest extends TestCase
{
    use RefreshDatabase;

    private function make(Game $game, array $counts): Translation
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
        ], $counts))->save();

        return $translation->refresh();
    }

    public function test_a_barely_translated_file_claims_no_review_stage(): void
    {
        $game = Game::firstOrCreate(['slug' => 'floor-game'], ['name' => 'Floor Game']);

        // The real case: 2 human lines, 11 captured and waiting.
        $barely = $this->make($game, ['human_count' => 2, 'capture_count' => 11]);

        $this->assertSame(1.0, $barely->reviewCoverage(), 'everything translated was read');
        $this->assertNull($barely->reviewStage(), 'but there is almost nothing to have read');
        $this->assertEqualsWithDelta(2 / 13, $barely->completeness(), 0.001);
    }

    public function test_a_finished_file_keeps_its_stage(): void
    {
        $game = Game::firstOrCreate(['slug' => 'finished-game'], ['name' => 'Finished Game']);

        $done = $this->make($game, ['human_count' => 690, 'capture_count' => 0]);
        $this->assertSame('reviewed', $done->reviewStage());
        $this->assertSame(1.0, $done->completeness());

        // A handful of captures on a large file is normal play, not an unfinished translation
        $almost = $this->make($game, ['human_count' => 2880, 'capture_count' => 56]);
        $this->assertSame('reviewed', $almost->reviewStage());
    }

    public function test_pending_lines_weigh_on_the_order(): void
    {
        $game = Game::firstOrCreate(['slug' => 'pending-order'], ['name' => 'Pending Order']);

        // Same yardstick for both, so only completeness separates them.
        $unfinished = $this->make($game, ['human_count' => 100, 'capture_count' => 900]);
        $finished = $this->make($game, ['human_count' => 100]);

        $this->assertGreaterThan($unfinished->usefulness(), $finished->usefulness());
    }

    public function test_the_search_reports_completeness(): void
    {
        $game = Game::firstOrCreate(['slug' => 'completeness-api'], ['name' => 'Completeness Api']);
        $this->make($game, ['human_count' => 2, 'capture_count' => 11]);

        $payload = $this->getJson('/api/v1/translations?game=completeness-api')
            ->assertOk()->json('translations.0');

        $this->assertEqualsWithDelta(2 / 13, $payload['completeness'], 0.001);
    }

    public function test_an_empty_file_has_no_completeness_rather_than_zero(): void
    {
        $game = Game::firstOrCreate(['slug' => 'empty-file'], ['name' => 'Empty File']);

        $this->assertNull($this->make($game, [])->completeness());
    }
}
