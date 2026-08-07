<?php

namespace Tests\Unit;

use App\Models\Game;
use App\Models\Translation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The three numbers, and the question each one answers.
 *
 * They were one number, and it answered none of them well: a 0-3 average of where each line came
 * from. It gave a third of its scale to machine output nobody had opened, capped a file reviewed
 * line by line at two thirds unless its author retyped what the AI had right, and could not see
 * how much of the game a file reached.
 *
 * - reviewCoverage: how much a human settled. Plain, public, read as a stage.
 * - reviewRate: the same, weighted by how well evidenced the validations are. Owner and ranking.
 * - gameCoverage: how much of the game is in there, measured against the game's other translations.
 * - usefulness: the two combined, which is what "which one do I take" actually asks.
 */
class TranslationRatesTest extends TestCase
{
    use RefreshDatabase;

    private function make(array $counts, ?Game $game = null, string $visibility = 'public'): Translation
    {
        $game ??= Game::firstOrCreate(['slug' => 'rates-game'], ['name' => 'Rates Game']);

        $translation = new Translation();
        $translation->forceFill(array_merge([
            'game_id' => $game->id,
            'user_id' => User::factory()->create()->id,
            'source_language' => 'English',
            'target_language' => 'French',
            'file_path' => 'translations/not-read-by-these-tests.json',
            'file_uuid' => 'uuid-' . uniqid(),
            'visibility' => $visibility,
            'file_hash' => 'hash-' . uniqid(),
            'human_count' => 0,
            'validated_count' => 0,
            'ai_count' => 0,
            'skipped_count' => 0,
            'capture_count' => 0,
        ], $counts))->save();

        return $translation->refresh();
    }

    public function test_a_file_reviewed_line_by_line_reaches_the_top(): void
    {
        // The case that started the rework: reviewed end to end, no unreviewed AI left, and the
        // old score gave 2.49 out of 3 — one hundredth short of its top label.
        $t = $this->make(['human_count' => 336, 'validated_count' => 352]);

        $this->assertSame(1.0, $t->reviewCoverage());
        $this->assertSame(1.0, $t->reviewRate());
    }

    public function test_untouched_machine_output_starts_at_zero(): void
    {
        $t = $this->make(['ai_count' => 900]);

        // Not a third of the scale, as the 0-3 average gave it
        $this->assertSame(0.0, $t->reviewCoverage());
        $this->assertSame(0.0, $t->reviewRate());
    }

    public function test_lines_kept_as_is_count_as_settled(): void
    {
        // Klingon in a Star Trek game translated to Japanese: it has to stay Klingon, and ruling
        // on those lines is work. The author who did it beat the one who let the machine run.
        $kept = $this->make(['human_count' => 400, 'validated_count' => 300, 'skipped_count' => 300]);
        $ran = $this->make(['human_count' => 400, 'validated_count' => 300, 'ai_count' => 300]);

        $this->assertSame(1.0, $kept->reviewCoverage());
        $this->assertGreaterThan($ran->reviewCoverage(), $kept->reviewCoverage());
    }

    public function test_only_kept_lines_and_nothing_translated_has_no_rate(): void
    {
        // Every line settled, and not one translation in the file: there is nothing to have an
        // opinion about. Same answer as capture-only.
        $t = $this->make(['skipped_count' => 500]);

        $this->assertNull($t->reviewCoverage());
        $this->assertNull($t->reviewRate());
    }

    public function test_bulk_validation_is_worth_less_than_evidenced_review(): void
    {
        $bulk = $this->make(['validated_count' => 2000]);
        $read = $this->make(['human_count' => 200, 'validated_count' => 1800]);

        // Both settled every line, so the public stage cannot tell them apart — and says so
        $this->assertSame(1.0, $bulk->reviewCoverage());
        $this->assertSame(1.0, $read->reviewCoverage());

        // The rate can: someone who truly read two thousand machine lines changed some
        $this->assertSame(0.8, $bulk->reviewRate());
        $this->assertSame(1.0, $read->reviewRate());
    }

    public function test_one_intervention_in_ten_credits_validations_in_full(): void
    {
        // A small game where the machine got nearly everything right must not be punished for
        // it — nobody should retype correct text to score well.
        $t = $this->make(['human_count' => 3, 'validated_count' => 27]);

        $this->assertSame(1.0, $t->reviewRate());
    }

    public function test_game_coverage_is_measured_against_the_games_other_translations(): void
    {
        $game = Game::firstOrCreate(['slug' => 'big-game'], ['name' => 'Big Game']);
        $far = $this->make(['human_count' => 4000], $game);
        $near = $this->make(['human_count' => 1000], $game);

        // A game's real size is unknowable, but whoever got furthest says something about it
        $this->assertSame(1.0, $far->gameCoverage());
        $this->assertSame(0.25, $near->gameCoverage());
    }

    public function test_captured_lines_do_not_claim_to_cover_a_game(): void
    {
        $game = Game::firstOrCreate(['slug' => 'capture-game'], ['name' => 'Capture Game']);
        $this->make(['human_count' => 1000], $game);
        $hoarder = $this->make(['capture_count' => 5000], $game);

        // Five thousand lines captured and none translated covers nothing at all
        $this->assertSame(0.0, $hoarder->gameCoverage());
    }

    public function test_an_unpublished_branch_does_not_set_the_bar(): void
    {
        $game = Game::firstOrCreate(['slug' => 'branch-game'], ['name' => 'Branch Game']);
        $published = $this->make(['human_count' => 500], $game);
        $this->make(['human_count' => 5000], $game, 'branch');

        // Measuring everyone against work nobody has offered would be unfair to all of them
        $this->assertSame(1.0, $published->gameCoverage());
    }

    public function test_a_complete_raw_translation_outranks_a_perfect_fragment(): void
    {
        $game = Game::firstOrCreate(['slug' => 'useful-game'], ['name' => 'Useful Game']);
        $whole = $this->make(['ai_count' => 4000], $game);
        $fragment = $this->make(['human_count' => 400], $game);

        // Someone about to play the whole game needs the text to exist first. The fragment is
        // reviewed to the last comma and reaches a tenth of the game.
        $this->assertGreaterThan($fragment->usefulness(), $whole->usefulness());
    }

    public function test_reviewing_beats_not_reviewing_at_equal_coverage(): void
    {
        $game = Game::firstOrCreate(['slug' => 'equal-game'], ['name' => 'Equal Game']);
        $raw = $this->make(['ai_count' => 2000], $game);
        $reviewed = $this->make(['human_count' => 500, 'validated_count' => 1500], $game);

        $this->assertSame(0.5, $raw->usefulness());
        $this->assertSame(1.0, $reviewed->usefulness());
    }

    public function test_the_page_wide_maximum_is_fetched_once(): void
    {
        $game = Game::firstOrCreate(['slug' => 'hint-game'], ['name' => 'Hint Game']);
        $a = $this->make(['human_count' => 800], $game);
        $b = $this->make(['human_count' => 200], $game);

        $maxes = Translation::maxResolvedLinesByGame([$game->id]);

        $this->assertSame(800, $maxes[$game->id]);
        $this->assertSame(0.25, $b->gameCoverage($maxes[$game->id]));
        $this->assertSame(1.0, $a->gameCoverage($maxes[$game->id]));
    }
}
