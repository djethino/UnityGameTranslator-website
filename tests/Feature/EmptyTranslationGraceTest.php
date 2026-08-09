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

    /**
     * The other end of the same problem: not publishing it in the first place.
     *
     * Refusing outright would be wrong — capture mode is legitimate work and its author may have
     * reasons — so the upload asks once. Both halves are tested here because a warning nobody can
     * get past is as broken as no warning at all.
     */
    public function test_uploading_a_file_with_nothing_translated_asks_first(): void
    {
        $user = User::factory()->create();
        $payload = [
            'game_name' => 'Asked Game',
            'game_source' => 'igdb',
            'game_external_id' => 4242,
            'source_language' => 'English',
            'target_language' => 'French',
            'status' => 'in_progress',
        ];
        $file = fn () => \Illuminate\Http\UploadedFile::fake()->createWithContent(
            'translations.json',
            json_encode(['_uuid' => (string) \Illuminate\Support\Str::uuid(), 'Hello' => ['v' => '', 't' => 'H']])
        );

        $this->actingAs($user)
            ->post(route('translations.store'), $payload + ['file' => $file()])
            ->assertSessionHasErrors('file')
            ->assertSessionHas('confirm_empty');

        $this->assertDatabaseCount('translations', 0);

        // Ticked, it goes through: the author has been told, and it remains their call.
        $this->actingAs($user)
            ->post(route('translations.store'), $payload + ['file' => $file(), 'publish_empty' => '1'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('translations', 1);
    }

    /**
     * The two states a contributor could not see, and the reason they are kept apart.
     *
     * A DELISTED Main is still there and can still merge the work. An ORPHANED branch has no Main
     * at all — nobody can ever merge it. Same symptom from the outside (nothing visible), two
     * different answers, so the site says two different things.
     */
    public function test_a_branch_knows_whether_its_main_is_hidden_or_gone(): void
    {
        $main = $this->makeTranslation();
        $contributor = User::factory()->create();
        $branch = $this->makeTranslation([
            'user_id' => $contributor->id,
            'visibility' => 'branch',
            'file_uuid' => $main->file_uuid,
            'human_count' => 1200,
            'capture_count' => 400,
        ]);

        $this->assertTrue($branch->mainIsDelisted(), 'The Main is delisted, not gone.');
        $this->assertFalse($branch->isOrphanBranch());

        $this->actingAs($contributor)->get(route('translations.mine'))
            ->assertOk()
            ->assertSee(__('my_translations.main_delisted_title'))
            ->assertDontSee(__('my_translations.orphan_title'));

        $main->delete();
        $branch->refresh();

        $this->assertTrue($branch->isOrphanBranch(), 'With no Main left, the branch is orphaned.');
        $this->assertFalse($branch->mainIsDelisted());

        $this->actingAs($contributor)->get(route('translations.mine'))
            ->assertOk()
            ->assertSee(__('my_translations.orphan_title'));
    }

    /**
     * And the contributor is told at the moment it happens.
     *
     * From their side nothing changes visibly — the file still opens, still translates, still
     * saves — so this has to be pushed rather than waited for. Deleting an ACCOUNT does not come
     * through here: accounts are anonymised and their translations kept, which is why the wording
     * speaks of a deleted translation rather than a departed author.
     */
    public function test_deleting_a_main_notifies_the_contributors_it_strands(): void
    {
        $main = $this->makeTranslation();
        $contributor = User::factory()->create();
        $this->makeTranslation([
            'user_id' => $contributor->id,
            'visibility' => 'branch',
            'file_uuid' => $main->file_uuid,
            'human_count' => 5,
        ]);

        $main->delete();

        $this->assertSame(1, $contributor->notifications()->count());
        $this->assertSame('branch_orphaned', $contributor->notifications()->first()->data['type']);
    }

    /** A lineage that still has a head strands nobody, so nobody is told. */
    public function test_deleting_one_of_two_public_translations_notifies_nobody(): void
    {
        $main = $this->makeTranslation();
        $fork = $this->makeTranslation(['file_uuid' => $main->file_uuid]);
        $contributor = User::factory()->create();
        $this->makeTranslation([
            'user_id' => $contributor->id,
            'visibility' => 'branch',
            'file_uuid' => $main->file_uuid,
            'human_count' => 5,
        ]);

        $fork->delete();

        $this->assertSame(0, $contributor->notifications()->count());
    }

    /**
     * The scheduled command, and the only reason one exists here.
     *
     * The STATE is computed on every query — nothing to store, nothing to reconcile. What cannot
     * happen on its own is the moment: no code runs on the thirtieth day. Two audiences, two
     * messages, because the author can act and the contributors cannot.
     */
    public function test_the_daily_command_tells_the_author_and_the_contributors(): void
    {
        $main = $this->makeTranslation();
        $contributor = User::factory()->create();
        $this->makeTranslation([
            'user_id' => $contributor->id,
            'visibility' => 'branch',
            'file_uuid' => $main->file_uuid,
            'human_count' => 40,
            'file_hash' => 'unreviewed-hash',
        ]);

        $this->artisan('translations:notify-delisted')->assertSuccessful();

        $authorNotification = $main->user->notifications()->first();
        $this->assertSame('translation_delisted', $authorNotification->data['type']);
        $this->assertSame(1, $authorNotification->data['waiting_branches'], 'The author needs the count they cannot see.');

        $this->assertSame('main_delisted', $contributor->notifications()->first()->data['type']);

        // Run again the next night: nobody is told the same thing twice.
        $this->artisan('translations:notify-delisted')->assertSuccessful();
        $this->assertSame(1, $main->user->notifications()->count());
        $this->assertSame(1, $contributor->notifications()->count());
    }

    /** Inside the grace period the command says nothing at all. */
    public function test_the_daily_command_leaves_a_fresh_translation_alone(): void
    {
        $translation = $this->makeTranslation(['created_at' => now()->subDay()]);

        $this->artisan('translations:notify-delisted')->assertSuccessful();

        $this->assertSame(0, $translation->user->notifications()->count());
    }

    /** And the mod is told, so it stops calling itself a branch of nothing. */
    public function test_check_uuid_tells_the_mod_when_the_main_is_gone(): void
    {
        $main = $this->makeTranslation();
        $contributor = User::factory()->create();
        $branch = $this->makeTranslation([
            'user_id' => $contributor->id,
            'visibility' => 'branch',
            'file_uuid' => $main->file_uuid,
            'human_count' => 5,
        ]);

        $token = \App\Models\ApiToken::createForUser($contributor, 'test');
        $headers = ['Authorization' => 'Bearer ' . $token->plain_token];

        $this->getJson('/api/v1/translations/check-uuid?uuid=' . $branch->file_uuid, $headers)
            ->assertOk()
            ->assertJsonPath('role', 'branch')
            ->assertJsonPath('main_missing', false);

        $main->delete();

        $this->getJson('/api/v1/translations/check-uuid?uuid=' . $branch->file_uuid, $headers)
            ->assertOk()
            ->assertJsonPath('main_missing', true);
    }

    /**
     * And whatever happens to the Main, its author can still read their own branch.
     *
     * "Private" was being read as "belongs to the Main": the download button and the eye on this
     * very page answered 403 to the person who wrote the file, and once the Main was deleted the
     * branch became unreadable to everyone alive.
     */
    public function test_a_contributor_can_always_read_their_own_branch(): void
    {
        $main = $this->makeTranslation();
        $contributor = User::factory()->create();
        $branch = $this->makeTranslation([
            'user_id' => $contributor->id,
            'visibility' => 'branch',
            'file_uuid' => $main->file_uuid,
            'human_count' => 5,
        ]);

        $this->actingAs($contributor)->get(route('translations.download', $branch))->assertOk();

        $main->delete();
        $branch->refresh();

        $this->actingAs($contributor)->get(route('translations.download', $branch))->assertOk();
        $this->actingAs($contributor)->get(route('translations.view', $branch))->assertOk();

        // Still nobody else's business
        $this->actingAs(User::factory()->create())
            ->get(route('translations.download', $branch))
            ->assertForbidden();
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
