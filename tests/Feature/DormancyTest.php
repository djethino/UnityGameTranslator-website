<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\Translation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * When silence starts to mean abandonment.
 *
 * The tolerance is the span the file was actually worked on, not a number chosen for everyone and
 * not the share of work already settled. The previous rule measured PROGRESS where the question
 * is about COMMITMENT, and got it backwards on real data: a translation worked on for seven
 * months with everything still to review was declared abandoned after three weeks, while one
 * finished in twelve days was given six months.
 *
 * What is really under test is that the site never says "abandoned" about somebody who is
 * plainly still there, and never asks a contributor to wait in front of a dead file.
 */
class DormancyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * content_updated_at is applied AFTER the first save on purpose: the model stamps it with
     * now() whenever file_hash changes, which is exactly what it should do in production and
     * exactly what would flatten every date this test class is about.
     */
    private function makeTranslation(array $attributes = []): Translation
    {
        $game = Game::firstOrCreate(['slug' => 'dormancy-game'], ['name' => 'Dormancy Game']);

        $path = 'translations/test-' . uniqid() . '.json';
        Storage::disk('local')->put($path, json_encode(['Hello' => ['v' => 'Bonjour', 't' => 'H']]));

        $contentUpdatedAt = $attributes['content_updated_at'] ?? null;
        unset($attributes['content_updated_at']);

        $translation = new Translation();
        $translation->forceFill(array_merge([
            'game_id' => $game->id,
            'user_id' => User::factory()->create()->id,
            'source_language' => 'English',
            'target_language' => 'French',
            'visibility' => 'public',
            'status' => 'in_progress',
            'file_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'file_path' => $path,
            'file_hash' => 'hash-' . uniqid(),
            'line_count' => 100,
            'human_count' => 100,
        ], $attributes))->save();

        if ($contentUpdatedAt) {
            $translation->forceFill(['content_updated_at' => $contentUpdatedAt])->save();
        }

        return $translation->refresh();
    }

    /** Worked on for months, quiet for weeks: a pause in a habit, not the end of one. */
    public function test_a_long_running_translation_is_given_the_time_it_earned(): void
    {
        $translation = $this->makeTranslation([
            'created_at' => now()->subDays(220),
            'content_updated_at' => now()->subDays(40),
        ]);

        $this->assertSame(90, $translation->dormantAfterDays(), 'Capped at three months.');
        $this->assertFalse($translation->isDormant(), 'Forty days of silence after seven months of work is a holiday.');
    }

    /**
     * The case the old rule got wrong: seven months of work, nothing reviewed yet. Under
     * "tolerance scales with settled work" this was abandoned after 21 days.
     */
    public function test_work_left_to_do_no_longer_shortens_the_patience(): void
    {
        $translation = $this->makeTranslation([
            'human_count' => 0,
            'ai_count' => 100,
            'capture_count' => 400,
            'created_at' => now()->subDays(220),
            'content_updated_at' => now()->subDays(30),
        ]);

        $this->assertSame(90, $translation->dormantAfterDays());
        $this->assertFalse($translation->isDormant());
    }

    /** Ten days of work, three weeks of silence: on a game nobody replays, that is over. */
    public function test_a_short_burst_of_work_is_over_quickly(): void
    {
        $translation = $this->makeTranslation([
            'created_at' => now()->subDays(31),
            'content_updated_at' => now()->subDays(21),
        ]);

        $this->assertSame(14, $translation->dormantAfterDays(), 'Ten days of work falls to the floor.');
        $this->assertTrue($translation->isDormant());
    }

    /** Uploaded once and never touched: nothing about it suggests a habit to be patient with. */
    public function test_a_single_upload_gets_the_floor_and_no_more(): void
    {
        $translation = $this->makeTranslation([
            'created_at' => now()->subDays(20),
            'content_updated_at' => now()->subDays(20),
        ]);

        $this->assertSame(14, $translation->dormantAfterDays());
        $this->assertTrue($translation->isDormant());
    }

    /**
     * A finished translation is out of the calendar entirely. It will not change again, and that
     * is the point of finishing; what can push it aside is a rival covering more of the game.
     */
    public function test_a_finished_translation_is_never_dormant_by_the_clock(): void
    {
        $translation = $this->makeTranslation([
            'status' => 'complete',
            'created_at' => now()->subDays(400),
            'content_updated_at' => now()->subDays(365),
        ]);

        $this->assertFalse($translation->isDormant(), 'A year of silence on finished work is health, not decay.');
    }

    /** Except when it holds no translation at all — then no date can save it. */
    public function test_an_empty_file_is_dormant_whatever_its_dates(): void
    {
        $translation = $this->makeTranslation([
            'human_count' => 0,
            'capture_count' => 400,
            'status' => 'complete',
            'created_at' => now()->subDay(),
            'content_updated_at' => now(),
        ]);

        $this->assertTrue($translation->isDormant());
    }

    /**
     * Level one warns; level two offers a way out. The hint has a floor of its own, or on the
     * shortest tolerance it would fire after five days and warn about somebody's weekend.
     */
    public function test_the_hint_comes_before_the_offer_and_never_on_a_weekend(): void
    {
        $shortest = $this->makeTranslation([
            'created_at' => now()->subDays(60),
            'content_updated_at' => now()->subDays(60),
        ]);
        $this->assertSame(14, $shortest->dormantAfterDays());
        $this->assertSame(7, $shortest->dormantHintAfterDays(), 'A third of 14 would be five days.');

        $longest = $this->makeTranslation([
            'created_at' => now()->subDays(220),
            'content_updated_at' => now()->subDays(10),
        ]);
        $this->assertSame(90, $longest->dormantAfterDays());
        $this->assertSame(30, $longest->dormantHintAfterDays());
    }

    /** And a contributor sees them in that order, never the offer first. */
    public function test_a_contributor_is_told_before_being_offered_the_way_out(): void
    {
        // Worked on for 20 days: tolerance 20, hint at 7. Silent for 10.
        $main = $this->makeTranslation([
            'created_at' => now()->subDays(30),
            'content_updated_at' => now()->subDays(10),
        ]);
        $branch = $this->makeTranslation([
            'visibility' => 'branch',
            'file_uuid' => $main->file_uuid,
        ]);

        $this->assertSame(20, $main->dormantAfterDays());
        $this->assertSame(7, $main->dormantHintAfterDays());
        $this->assertTrue($branch->mainIsDormant(), 'Level one: past the hint, say it.');
        $this->assertFalse($branch->shouldOfferFork(), 'Level two: short of the offer.');

        $main->forceFill(['content_updated_at' => now()->subDays(25)])->save();
        $branch->refresh();

        $this->assertTrue($branch->shouldOfferFork(), 'Silent longer than it was ever worked on.');
    }

    /** The ranking reads the same notion, so "abandoned" cannot mean two things on two screens. */
    public function test_the_fork_bonus_follows_the_same_rule(): void
    {
        $parent = $this->makeTranslation([
            'created_at' => now()->subDays(40),
            'content_updated_at' => now()->subDays(20),
        ]);
        $fork = $this->makeTranslation([
            'parent_id' => $parent->id,
            'content_updated_at' => now()->subDay(),
        ]);

        $this->assertTrue($parent->isDormant());
        $this->assertSame(1.2, round($fork->fork_bonus, 2));
    }
}
