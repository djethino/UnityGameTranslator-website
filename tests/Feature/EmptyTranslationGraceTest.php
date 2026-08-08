<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\Translation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A published file with no translated line, once its grace period is over.
 *
 * The banner on "my translations" tells its author, in as many words, that it leaves the public
 * catalogue after EMPTY_GRACE_DAYS. Until this, isEmptyPastGrace() was written and called
 * nowhere: a file published in March was still counted as a game's one translation five months
 * later, and a player downloading it got the game's own text back unchanged.
 *
 * The tests below hold both halves of the promise — it goes, and it comes back the moment one
 * line is written, with nothing to clear and no second delay to wait out.
 */
class EmptyTranslationGraceTest extends TestCase
{
    use RefreshDatabase;

    private function makeTranslation(array $attributes = []): Translation
    {
        $game = Game::firstOrCreate(['slug' => 'grace-game'], ['name' => 'Grace Game']);

        $path = 'translations/test-' . uniqid() . '.json';
        Storage::disk('local')->put($path, json_encode(['Hello' => ['v' => '', 't' => 'H']]));

        $translation = new Translation();
        $translation->forceFill(array_merge([
            'game_id' => $game->id,
            'user_id' => User::factory()->create()->id,
            'source_language' => 'English',
            'target_language' => 'French',
            'visibility' => 'public',
            'file_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'file_path' => $path,
            'file_hash' => 'hash-' . uniqid(),
            'line_count' => 400,
            'capture_count' => 400,
            'created_at' => now()->subDays(Translation::EMPTY_GRACE_DAYS + 1),
        ], $attributes))->save();

        return $translation->refresh();
    }

    public function test_an_empty_translation_leaves_the_catalogue_once_the_grace_is_over(): void
    {
        $this->makeTranslation();

        // The game itself goes with it: a catalogue card promising a translation that changes
        // nothing is the thing being fixed.
        $this->get(route('games.index'))->assertOk()->assertDontSee('Grace Game');
    }

    public function test_it_is_gone_from_the_game_page_and_from_the_mod_s_search(): void
    {
        $translation = $this->makeTranslation();

        $html = $this->get(route('games.show', $translation->game))->assertOk()->getContent();
        $this->assertStringNotContainsString($translation->user->name, $html);

        $this->getJson('/api/v1/translations?game=grace-game')
            ->assertOk()
            ->assertJsonPath('count', 0);
    }

    /**
     * The branches go with it, and this is the sharp edge of the whole change.
     *
     * A branch has no public standing of its own: the game page shows that it exists, under the
     * Main it contributes to, and its content is private. When the delisted Main was simply
     * removed from the list, the grouping promoted the branch to the group's primary — the game
     * page then offered somebody's private contribution as its translation, download button and
     * all, on a route that answers 403.
     */
    public function test_a_branch_never_takes_the_place_of_a_delisted_main(): void
    {
        $main = $this->makeTranslation();
        $branch = $this->makeTranslation([
            'visibility' => 'branch',
            'file_uuid' => $main->file_uuid,
            'human_count' => 1200,
            'capture_count' => 400,
            'created_at' => now()->subDay(),
        ]);

        $html = $this->get(route('games.show', $main->game))->assertOk()->getContent();

        $this->assertStringNotContainsString($branch->user->name, $html);
        $this->assertStringNotContainsString(route('translations.download', $branch), $html);
    }

    /** Inside the grace period nothing happens: the author is being warned, not punished. */
    public function test_a_fresh_empty_translation_is_left_alone(): void
    {
        $this->makeTranslation(['created_at' => now()->subDay()]);

        $this->get(route('games.index'))->assertOk()->assertSee('Grace Game');
    }

    /**
     * One translated line brings it straight back. No flag to clear, no second delay: the rule
     * reads the file's own counters, so writing a line IS the way back.
     */
    public function test_one_translated_line_brings_it_back(): void
    {
        $translation = $this->makeTranslation();
        $translation->forceFill(['human_count' => 1, 'capture_count' => 399])->save();

        $this->get(route('games.index'))->assertOk()->assertSee('Grace Game');
    }

    /** Delisted is not deleted: the file, and its owner's access to it, are untouched. */
    public function test_its_author_keeps_it(): void
    {
        $translation = $this->makeTranslation();

        $this->actingAs($translation->user)
            ->get(route('translations.mine'))
            ->assertOk()
            ->assertSee('Grace Game');

        $this->assertDatabaseHas('translations', ['id' => $translation->id]);
    }

    /**
     * A delisted Main is still the Main of its lineage. The rule is about listings; resolving a
     * lineage is another job entirely, and a mod syncing against it must still find it.
     */
    public function test_the_lineage_still_resolves(): void
    {
        $translation = $this->makeTranslation();

        $this->assertNotNull($translation->getMain());
        $this->assertSame($translation->id, $translation->getMain()->id);
    }
}
