<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\Translation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * What moves a translation up or down the default ordering.
 *
 * The ranking read updated_at, which increment('vote_count') and incrementDownloads() both
 * touch. Everything time-based in it was therefore measuring "was this row written to" rather
 * than "was this translation worked on" — and a downvote, which is supposed to demote, reset
 * the decay and promoted instead.
 */
class RankingScoreTest extends TestCase
{
    use RefreshDatabase;

    private function makeTranslation(array $attributes = []): Translation
    {
        $game = Game::firstOrCreate(['slug' => 'rank-game'], ['name' => 'Rank Game']);

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
            'line_count' => 100,
            'human_count' => 100,
        ], $attributes))->save();

        return $translation->refresh();
    }

    /**
     * Backdate both stamps without going through the model, as a year-old row really is.
     *
     * The refresh() matters: the saving hook only writes content_updated_at when the value it
     * assigns DIFFERS from the one held in memory, and a model left holding its creation stamp
     * is assigned the same second back — so the write is skipped and the test lies.
     */
    private function backdate(Translation $translation, int $days): void
    {
        $stamp = now()->subDays($days);
        DB::table('translations')->where('id', $translation->id)->update([
            'created_at' => $stamp,
            'updated_at' => $stamp,
            'content_updated_at' => $stamp,
        ]);
        $translation->refresh();
    }

    public function test_a_downvote_demotes_an_abandoned_translation_instead_of_promoting_it(): void
    {
        // The case: a capture-only upload, published a year ago, never touched since, still
        // collecting downloads. Someone plays it, finds nothing translated, and downvotes.
        $translation = $this->makeTranslation([
            'human_count' => 0,
            'validated_count' => 0,
            'ai_count' => 0,
            'capture_count' => 800,
            'download_count' => 50,
        ]);
        $this->backdate($translation, 365);
        $before = $translation->fresh()->ranking_score;

        $translation->vote(-1, User::factory()->create());
        $after = $translation->fresh()->ranking_score;

        // updated_at HAS moved — that is precisely why the ranking cannot read it
        $this->assertTrue($translation->fresh()->updated_at->gt(now()->subMinute()));
        $this->assertLessThan($before, $after);
    }

    public function test_downloads_alone_do_not_keep_an_abandoned_translation_fresh(): void
    {
        $translation = $this->makeTranslation(['download_count' => 10]);
        $this->backdate($translation, 300);
        $before = $translation->fresh()->ranking_score;

        $translation->incrementDownloads();
        $after = $translation->fresh()->ranking_score;

        // A download is engagement and lifts the score a hair. What it must NOT do is reset the
        // decay: an abandoned translation people keep downloading stayed eternally "fresh" and
        // outranked one that was quietly being maintained. Reading updated_at, this same call
        // multiplied the score by ten.
        $this->assertGreaterThan($before, $after);
        $this->assertLessThan($before * 1.1, $after);
    }

    public function test_maintenance_is_what_keeps_a_translation_fresh(): void
    {
        $translation = $this->makeTranslation();
        $this->backdate($translation, 300);
        $before = $translation->fresh()->ranking_score;

        $translation->update(['file_hash' => 'worked-on-again']);
        $after = $translation->fresh()->ranking_score;

        // New content, and only new content, restores the decay
        $this->assertGreaterThan($before * 5, $after);
    }

    public function test_a_finished_translation_does_not_fade_with_time(): void
    {
        $done = $this->makeTranslation(['status' => 'complete']);
        $this->backdate($done, 400);

        // "Finished" and "abandoned" look identical in time — nothing moves in either — and a
        // decay applied to both drove a finished translation to a fraction of its score within
        // a year, out of sight however good it was. The author's declared status is the only
        // thing that separates them.
        $this->assertEqualsWithDelta(
            $done->fresh()->usefulness() * 30 + log10(1),
            $done->fresh()->ranking_score,
            0.01
        );
    }

    public function test_work_still_in_progress_and_standing_still_does_fade(): void
    {
        $stalled = $this->makeTranslation(['status' => 'in_progress']);
        $this->backdate($stalled, 400);

        // This is where "nothing has moved" really means abandoned
        $this->assertLessThan($stalled->fresh()->usefulness() * 30 * 0.2, $stalled->fresh()->ranking_score);
    }

    public function test_a_finished_translation_still_falls_behind_one_that_goes_further(): void
    {
        $game = Game::firstOrCreate(['slug' => 'stale-game'], ['name' => 'Stale Game']);

        $frozen = $this->makeTranslation(['status' => 'complete', 'human_count' => 1000, 'game_id' => $game->id]);
        $this->backdate($frozen, 400);

        $before = $frozen->fresh()->ranking_score;

        // Someone plays the updated game further and captures what the frozen file never saw.
        // Nothing about the frozen file changed, and yet its share of the game did.
        $this->makeTranslation(['status' => 'in_progress', 'human_count' => 2000, 'game_id' => $game->id]);

        $this->assertLessThan($before, $frozen->fresh()->ranking_score);
    }

    public function test_a_fork_of_a_still_downloaded_but_abandoned_parent_gets_its_bonus(): void
    {
        $parent = $this->makeTranslation();
        $this->backdate($parent, 400);
        $parent->refresh()->incrementDownloads();

        $fork = $this->makeTranslation([
            'file_uuid' => $parent->file_uuid,
            'parent_id' => $parent->id,
        ]);

        // The parent is being downloaded, not maintained. The bonus exists exactly for the fork
        // that picked up an abandoned translation, and updated_at was refusing it.
        $this->assertSame(1.2, $fork->fresh()->fork_bonus);
    }
}
