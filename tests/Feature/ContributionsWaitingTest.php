<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\Game;
use App\Models\Translation;
use App\Models\User;
use App\Services\TranslationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * What a Main's contributions are actually holding for it — and what one contribution holds.
 *
 * 🔴 **The number must match the rows behind the button.** It is computed with the same rule the
 * merge screen pre-selects with (`contributionWins`), because a count that promises work the screen
 * will not offer sends somebody to review emptiness. Asked for on 2026-07-25: *"le nombre de
 * branches avec des écarts positifs (pas un mec dans une ancienne version)"*.
 */
class ContributionsWaitingTest extends TestCase
{
    use RefreshDatabase;

    private array $createdFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->createdFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    private function makeTranslation(User $user, Game $game, string $uuid, string $visibility, array $content): Translation
    {
        $dir = storage_path('app/private/translations');
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $relativePath = 'translations/test_' . uniqid('', true) . '.json';
        $fullPath = storage_path('app/private/' . $relativePath);
        file_put_contents($fullPath, json_encode($content, JSON_UNESCAPED_UNICODE));
        $this->createdFiles[] = $fullPath;

        $translation = new Translation();
        $translation->forceFill([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'source_language' => 'English',
            'target_language' => 'French',
            'file_path' => $relativePath,
            'file_uuid' => $uuid,
            'file_hash' => hash('sha256', json_encode($content)),
            'visibility' => $visibility,
            'line_count' => count($content),
        ])->save();

        return $translation;
    }

    /** A request carrying this user's bearer token, the way the mod and the Manager call. */
    private function asApi(User $user)
    {
        return $this->withHeader(
            'Authorization',
            'Bearer ' . ApiToken::createForUser($user, 'test')->plain_token
        );
    }

    private function line(string $value, string $tag): array
    {
        return ['v' => $value, 't' => $tag];
    }

    /**
     * The answer, written the way a reader of these tests should think about it: two measures that
     * do not follow from each other, each broken down by the contribution's tag.
     *
     * @param  int  $take  what would be taken — the figure a published mod prints
     * @param  array<string,int>  $new        lines the Main does not hold, by tag
     * @param  array<string,int>  $differing  lines both hold differently, by tag
     */
    private function waiting(int $branches, int $take = 0, array $new = [], array $differing = []): array
    {
        return [
            'branches' => $branches,
            'lines' => $take,
            'review' => array_sum($new) + array_sum($differing),
            'new' => $new,
            'differing' => $differing,
        ];
    }

    /** A Main, and whatever contributions the test needs attached to its lineage. */
    private function lineage(array $mainContent, array ...$branchContents): Translation
    {
        $game = Game::forceCreate(['name' => 'Test Game', 'slug' => 'test-game-' . uniqid('', true)]);
        $owner = User::factory()->create()->refresh();
        $uuid = 'uuid-' . uniqid('', true);

        $main = $this->makeTranslation($owner, $game, $uuid, 'public', $mainContent);

        foreach ($branchContents as $content) {
            $this->makeTranslation(
                User::factory()->create()->refresh(),
                $game,
                $uuid,
                'branch',
                $content
            );
        }

        Cache::flush();

        return $main;
    }

    public function test_a_contribution_holding_nothing_new_is_not_counted(): void
    {
        // The case the whole thing exists for: somebody who took the file and never came back.
        $main = $this->lineage(
            ['_uuid' => 'x', 'greet' => $this->line('Bonjour', 'H'), 'bye' => $this->line('Au revoir', 'H')],
            ['_uuid' => 'x', 'greet' => $this->line('Bonjour', 'H')]
        );

        $this->assertSame(
            $this->waiting(0),
            app(TranslationService::class)->contributionsWaiting($main)
        );
    }

    public function test_a_line_the_main_does_not_have_is_counted(): void
    {
        $main = $this->lineage(
            ['_uuid' => 'x', 'greet' => $this->line('Bonjour', 'H')],
            ['_uuid' => 'x', 'greet' => $this->line('Bonjour', 'H'), 'bye' => $this->line('Au revoir', 'A')]
        );

        $this->assertSame(
            $this->waiting(1, take: 1, new: ['A' => 1]),
            app(TranslationService::class)->contributionsWaiting($main)
        );
    }

    public function test_a_review_of_the_mains_machine_line_is_counted(): void
    {
        // No word changes: the contributor read the machine's line and marked it correct. It is
        // the work this site asks for, and comparing values alone loses every one of them.
        $main = $this->lineage(
            ['_uuid' => 'x', 'greet' => $this->line('Bonjour', 'A')],
            ['_uuid' => 'x', 'greet' => $this->line('Bonjour', 'V')]
        );

        // ⚠ Reported under the contribution's TAG — `V`, not the letter the Main holds. Nothing was
        // written here: somebody read what was there and stood behind it, and that is the work this
        // site asks for. A Main weighing an evening needs to see it apart from new prose.
        $this->assertSame(
            $this->waiting(1, take: 1, differing: ['V' => 1]),
            app(TranslationService::class)->contributionsWaiting($main)
        );
    }

    public function test_a_contribution_never_displaces_the_mains_own_decision(): void
    {
        // H against H, H against S, S against H: all ties, all kept by the Main.
        $main = $this->lineage(
            [
                '_uuid' => 'x',
                'greet' => $this->line('Bonjour', 'H'),
                'skip' => $this->line('', 'S'),
                'other' => $this->line('Autre', 'H'),
            ],
            [
                '_uuid' => 'x',
                'greet' => $this->line('Salut', 'H'),
                'skip' => $this->line('Passer', 'H'),
                'other' => $this->line('', 'S'),
            ]
        );

        // 🔴 **Nothing to take, three rows to look at** — and that is the pair of measures this
        // answer exists to keep apart. The old shape could only say "0", which told the Main there
        // was nothing here at all; there are three lines a contributor changed, and the Main is the
        // one who decides they stay as they are.
        $this->assertSame(
            $this->waiting(0, take: 0, differing: ['H' => 2, 'S' => 1]),
            app(TranslationService::class)->contributionsWaiting($main)
        );
    }

    /**
     * 🔴 The second filter: a contribution already gone through stops asking.
     *
     * Counting work the Main has arbitrated and refused would bring it back every time, and a
     * number that never falls to zero is a number nobody reads. It comes back the moment its
     * author pushes something new — which is exactly when it should.
     */
    public function test_a_contribution_already_reviewed_stops_asking(): void
    {
        $main = $this->lineage(
            ['_uuid' => 'x', 'greet' => $this->line('Bonjour', 'A')],
            ['_uuid' => 'x', 'greet' => $this->line('Salut', 'H')]
        );

        $service = app(TranslationService::class);
        $this->assertSame(
            $this->waiting(1, take: 1, differing: ['H' => 1]),
            $service->contributionsWaiting($main)
        );

        $branch = Translation::where('file_uuid', $main->file_uuid)->branches()->first();
        $branch->forceFill(['reviewed_hash' => $branch->file_hash])->save();

        $this->assertSame(
            $this->waiting(0),
            $service->contributionsWaiting($main),
            'gone through: it stops asking'
        );

        // The author pushes something new: it asks again, without anybody clearing a flag.
        $branch->forceFill(['file_hash' => 'moved-' . uniqid('', true)])->save();

        $this->assertSame(
            $this->waiting(1, take: 1, differing: ['H' => 1]),
            $service->contributionsWaiting($main),
            'moved since: it is back in front of the Main'
        );
    }

    /**
     * 🔴 **Taking nothing from a contribution is a decision, and it had no way to be recorded.**
     *
     * `reviewed_hash` had one writer: the 1-to-5 mark. So a Main who went through a contribution
     * and kept none of it could only stop it coming back by GRADING its author — a private
     * judgement about a person over time, asked in place of a fact about one state of one file.
     * The contribution returned for ever, which is the counter that cries wolf.
     *
     * ⚠ Marked when the merge is SAVED, not when the screen is opened: opening two thousand lines
     * is not reading them, and the two mistakes do not cost the same — marking read by accident
     * takes somebody's work out of the queue in silence, marking unread costs a reminder.
     *
     * 🔴 **And a save covers only half the ground.** `apply` refuses an empty submission ("No
     * changes to apply"), so a Main who keeps nothing from anybody has nothing to save and this
     * path never runs for them. That case belongs to the read/unread control, which is why that
     * control is not a convenience but the only road for it — see the test below.
     *
     * What a save does settle is the ordinary case: several contributions on screen, lines taken
     * from one of them. The others were arbitrated too, and stop asking.
     */
    public function test_saving_a_merge_marks_every_contribution_on_screen_read(): void
    {
        $main = $this->lineage(
            ['_uuid' => 'x', 'greet' => $this->line('Bonjour', 'A')],
            ['_uuid' => 'x', 'greet' => $this->line('Salut', 'H')],
            ['_uuid' => 'x', 'greet' => $this->line('Coucou', 'H')]
        );

        $service = app(TranslationService::class);
        $this->assertSame(2, $service->contributionsWaiting($main)['branches']);

        $branches = Translation::where('file_uuid', $main->file_uuid)->branches()->get();

        // A line taken from the FIRST one only. The second was read all the same.
        $this->actingAs($main->user)
            ->post(route('translations.merge.apply', $main->file_uuid), [
                'mode' => 'merge',
                'branches' => [$branches[0]->id, $branches[1]->id],
                'selections_json' => json_encode([[
                    'key' => 'greet', 'value' => 'Salut', 'tag' => 'H',
                    'source' => 'branch_' . $branches[0]->id, 'auto' => false, 'base' => 'Bonjour',
                ]]),
                'deletions_json' => '[]',
            ])->assertSessionHasNoErrors();

        foreach ($branches as $branch) {
            $this->assertSame($branch->file_hash, $branch->fresh()->reviewed_hash,
                'every contribution on screen was arbitrated');
            $this->assertNull($branch->fresh()->main_rating,
                'and none of it judged its author');
        }

        $this->assertSame(0, $service->contributionsWaiting($main->fresh())['branches']);
    }

    /** Hiding a contribution is closing it, so it was not arbitrated and keeps its place. */
    public function test_a_contribution_left_off_the_screen_is_not_marked_read(): void
    {
        $main = $this->lineage(
            ['_uuid' => 'x', 'greet' => $this->line('Bonjour', 'A')],
            ['_uuid' => 'x', 'greet' => $this->line('Salut', 'H')],
            ['_uuid' => 'x', 'bye' => $this->line('Ciao', 'H')]
        );

        $branches = Translation::where('file_uuid', $main->file_uuid)->branches()->get();

        $this->actingAs($main->user)
            ->post(route('translations.merge.apply', $main->file_uuid), [
                'mode' => 'merge',
                'branches' => [$branches[0]->id],
                'selections_json' => json_encode([[
                    'key' => 'greet', 'value' => 'Salut', 'tag' => 'H',
                    'source' => 'branch_' . $branches[0]->id, 'auto' => false, 'base' => 'Bonjour',
                ]]),
                'deletions_json' => '[]',
            ])->assertSessionHasNoErrors();

        $this->assertSame($branches[0]->file_hash, $branches[0]->fresh()->reviewed_hash);
        $this->assertNull($branches[1]->fresh()->reviewed_hash, 'never on screen, never read');
    }

    /**
     * 🔴 **The case the whole thing exists for, and a save cannot reach it.**
     *
     * Keeping nothing from anybody leaves nothing to submit — `apply` refuses an empty one — so
     * the only way to say "I went through this" is the read control. Without it the contribution
     * would come back for ever, and the Main's only exit would be to grade its author.
     */
    public function test_a_main_who_keeps_nothing_can_still_close_the_review(): void
    {
        $main = $this->lineage(
            ['_uuid' => 'x', 'greet' => $this->line('Bonjour', 'A')],
            ['_uuid' => 'x', 'greet' => $this->line('Salut', 'H')]
        );

        $service = app(TranslationService::class);
        $branch = Translation::where('file_uuid', $main->file_uuid)->branches()->first();
        $this->assertSame(1, $service->contributionsWaiting($main)['branches']);

        // Nothing to save: the server refuses an empty merge, which is why the control exists.
        $this->actingAs($main->user)
            ->post(route('translations.merge.apply', $main->file_uuid), [
                'mode' => 'merge',
                'branches' => [$branch->id],
                'selections_json' => '[]',
                'deletions_json' => '[]',
            ])->assertSessionHasErrors();

        $this->actingAs($main->user)
            ->post(route('translations.read-branch', $branch), ['read' => true])->assertOk();

        $this->assertSame(0, $service->contributionsWaiting($main->fresh())['branches']);
        $this->assertNull($branch->fresh()->main_rating, 'closed without grading anybody');
    }

    /**
     * The way back, and the reason the automatic mark is not a trap: a Main interrupted mid-review
     * puts the contribution back in the queue. Nothing here judges anybody.
     */
    public function test_a_contribution_can_be_put_back_in_the_queue_by_hand(): void
    {
        $main = $this->lineage(
            ['_uuid' => 'x', 'greet' => $this->line('Bonjour', 'A')],
            ['_uuid' => 'x', 'greet' => $this->line('Salut', 'H')]
        );

        $branch = Translation::where('file_uuid', $main->file_uuid)->branches()->first();

        $this->actingAs($main->user)
            ->post(route('translations.read-branch', $branch), ['read' => true])
            ->assertOk();
        $this->assertSame($branch->file_hash, $branch->fresh()->reviewed_hash);

        $this->actingAs($main->user)
            ->post(route('translations.read-branch', $branch), ['read' => false])
            ->assertOk();
        $this->assertNull($branch->fresh()->reviewed_hash);

        // And it is the Main's alone: a contributor cannot mark their own work read.
        $this->actingAs($branch->user)
            ->post(route('translations.read-branch', $branch), ['read' => true])
            ->assertForbidden();
    }

    /**
     * 🔴 The mark judges a CONTRIBUTOR over time; the review states that one file was looked at.
     * Written as one ternary, taking a mark back also un-read the contribution.
     */
    public function test_taking_back_a_mark_does_not_unread_the_contribution(): void
    {
        $main = $this->lineage(
            ['_uuid' => 'x', 'greet' => $this->line('Bonjour', 'A')],
            ['_uuid' => 'x', 'greet' => $this->line('Salut', 'H')]
        );

        $branch = Translation::where('file_uuid', $main->file_uuid)->branches()->first();

        $this->actingAs($main->user)
            ->post(route('translations.rate-branch', $branch), ['rating' => 4])->assertOk();
        $this->assertSame($branch->file_hash, $branch->fresh()->reviewed_hash);

        $this->actingAs($main->user)
            ->post(route('translations.rate-branch', $branch), ['rating' => null])->assertOk();

        $this->assertNull($branch->fresh()->main_rating);
        $this->assertSame($branch->file_hash, $branch->fresh()->reviewed_hash,
            'what was seen stays seen');
    }

    public function test_the_same_line_from_two_contributions_is_one_line_to_recover(): void
    {
        // Adding their counts would promise twice the work that exists.
        $main = $this->lineage(
            ['_uuid' => 'x', 'greet' => $this->line('Bonjour', 'A')],
            ['_uuid' => 'x', 'greet' => $this->line('Salut', 'H')],
            ['_uuid' => 'x', 'greet' => $this->line('Coucou', 'H')]
        );

        $this->assertSame(
            $this->waiting(2, take: 1, differing: ['H' => 1]),
            app(TranslationService::class)->contributionsWaiting($main)
        );
    }

    public function test_the_mods_own_interface_is_never_counted(): void
    {
        $main = $this->lineage(
            ['_uuid' => 'x', 'greet' => $this->line('Bonjour', 'H')],
            ['_uuid' => 'x', 'greet' => $this->line('Bonjour', 'H'), 'ui.close' => $this->line('Fermer', 'M')]
        );

        $this->assertSame(
            $this->waiting(0),
            app(TranslationService::class)->contributionsWaiting($main)
        );
    }

    public function test_metadata_is_not_a_translated_line(): void
    {
        $main = $this->lineage(
            ['_uuid' => 'x', 'greet' => $this->line('Bonjour', 'H')],
            ['_uuid' => 'x', '_game' => ['name' => 'Whatever'], 'greet' => $this->line('Bonjour', 'H')]
        );

        $this->assertSame(
            $this->waiting(0),
            app(TranslationService::class)->contributionsWaiting($main)
        );
    }

    public function test_a_main_with_no_contribution_asks_nothing_of_the_disk(): void
    {
        $main = $this->lineage(['_uuid' => 'x', 'greet' => $this->line('Bonjour', 'H')]);

        $this->assertSame(
            $this->waiting(0),
            app(TranslationService::class)->contributionsWaiting($main)
        );
    }

    /** The other direction: what one contributor is holding, which is their own business alone. */
    public function test_a_contribution_knows_what_it_offers_its_main(): void
    {
        $game = Game::forceCreate(['name' => 'Test Game', 'slug' => 'test-game-' . uniqid('', true)]);
        $uuid = 'uuid-' . uniqid('', true);

        $main = $this->makeTranslation(
            User::factory()->create()->refresh(),
            $game,
            $uuid,
            'public',
            ['_uuid' => 'x', 'greet' => $this->line('Bonjour', 'A'), 'bye' => $this->line('Au revoir', 'H')]
        );

        $branch = $this->makeTranslation(
            User::factory()->create()->refresh(),
            $game,
            $uuid,
            'branch',
            [
                '_uuid' => 'x',
                'greet' => $this->line('Bonjour', 'V'),   // a review: counted
                'bye' => $this->line('Salut', 'H'),       // a tie: the Main keeps its own
                'new' => $this->line('Nouveau', 'A'),     // a key the Main lacks: counted
            ]
        );

        Cache::flush();

        $this->assertSame(2, app(TranslationService::class)->linesOfferedToMain($branch, $main));
    }

    /**
     * 🔴 Each side is told its own question and no more.
     *
     * A Main learns what its contributions hold; a contributor learns what THEIRS holds. What the
     * other contributions are doing is none of their business — `isReadableBy` already keeps their
     * content out of reach, and a count would leak the shape of it.
     */
    public function test_each_side_is_told_its_own_question_and_no_other(): void
    {
        $game = Game::forceCreate(['name' => 'Test Game', 'slug' => 'test-game-' . uniqid('', true)]);
        $uuid = 'uuid-' . uniqid('', true);

        $owner = User::factory()->create()->refresh();
        $contributor = User::factory()->create()->refresh();

        $main = $this->makeTranslation($owner, $game, $uuid, 'public', [
            '_uuid' => $uuid,
            'greet' => $this->line('Bonjour', 'A'),
        ]);

        $branch = $this->makeTranslation($contributor, $game, $uuid, 'branch', [
            '_uuid' => $uuid,
            'greet' => $this->line('Bonjour', 'V'),
            'new' => $this->line('Nouveau', 'H'),
        ]);

        Cache::flush();

        // The Main: how many contributions hold something, and how many lines that is.
        $asOwner = $this->asApi($owner)
            ->getJson('/api/v1/translations/check-uuid?uuid=' . $uuid)
            ->assertOk()
            ->json();

        $this->assertSame(1, $asOwner['branches_count'], 'one person contributes');
        $this->assertSame(1, $asOwner['branches_with_work'], 'and they are holding something');
        $this->assertSame(2, $asOwner['lines_available'], 'a review and a new line');

        // And the endpoint says what they are, on both axes.
        $this->assertSame(2, $asOwner['lines_waiting']['review']);
        $this->assertSame(2, $asOwner['lines_waiting']['take']);
        $this->assertSame(['H' => 1], $asOwner['lines_waiting']['new']);
        $this->assertSame(['V' => 1], $asOwner['lines_waiting']['differing']);
        $this->assertNull($asOwner['lines_offered'], 'a Main offers nothing to itself');

        // The contributor: what they are holding, and nothing about the lineage's other rows.
        $asContributor = $this->asApi($contributor)
            ->getJson('/api/v1/translations/check-uuid?uuid=' . $uuid)
            ->assertOk()
            ->json();

        $this->assertSame(2, $asContributor['lines_offered']);
        $this->assertNull($asContributor['branches_with_work'], 'not their question');
        $this->assertNull($asContributor['lines_available'], 'nor their business');
        $this->assertNull($asContributor['lines_waiting'], 'and neither is the breakdown');
    }

    public function test_a_listing_carries_the_same_answer_as_the_single_check(): void
    {
        $game = Game::forceCreate(['name' => 'Test Game', 'slug' => 'test-game-' . uniqid('', true)]);
        $uuid = 'uuid-' . uniqid('', true);
        $owner = User::factory()->create()->refresh();

        $this->makeTranslation($owner, $game, $uuid, 'public', [
            '_uuid' => $uuid,
            'greet' => $this->line('Bonjour', 'A'),
        ]);

        $this->makeTranslation(
            User::factory()->create()->refresh(),
            $game,
            $uuid,
            'branch',
            ['_uuid' => $uuid, 'greet' => $this->line('Bonjour', 'V')]
        );

        Cache::flush();

        $row = collect(
            $this->asApi($owner)
                ->getJson('/api/v1/me/translations')
                ->assertOk()
                ->json('translations')
        )->firstWhere('file_uuid', $uuid);

        // Two screens describing one lineage must not report different numbers about it — the
        // breakdown included, which is the half a listing is most tempted to compute differently.
        $this->assertSame(1, $row['branches_with_work']);
        $this->assertSame(1, $row['lines_available']);
        $this->assertSame(1, $row['lines_waiting']['review']);
        $this->assertSame(['V' => 1], $row['lines_waiting']['differing']);
        $this->assertSame([], $row['lines_waiting']['new']);
    }

    public function test_an_orphaned_contribution_offers_nothing_rather_than_failing(): void
    {
        $game = Game::forceCreate(['name' => 'Test Game', 'slug' => 'test-game-' . uniqid('', true)]);

        $branch = $this->makeTranslation(
            User::factory()->create()->refresh(),
            $game,
            'uuid-orphan',
            'branch',
            ['_uuid' => 'x', 'greet' => $this->line('Bonjour', 'H')]
        );

        // Its Main is gone. A count is not the place to discover it, and never a crash.
        $this->assertSame(0, app(TranslationService::class)->linesOfferedToMain($branch, null));
    }

    public function test_the_count_follows_the_files_without_being_told(): void
    {
        // 🔴 The cache key carries every hash, so a new contribution changes the answer with no
        // invalidation hook to remember. A hook is exactly what goes missing when a file is
        // written from a path nobody thought of.
        $main = $this->lineage(
            ['_uuid' => 'x', 'greet' => $this->line('Bonjour', 'A')],
            ['_uuid' => 'x', 'greet' => $this->line('Bonjour', 'V')]
        );

        $service = app(TranslationService::class);
        $this->assertSame(
            $this->waiting(1, take: 1, differing: ['V' => 1]),
            $service->contributionsWaiting($main)
        );

        $this->makeTranslation(
            User::factory()->create()->refresh(),
            Game::find($main->game_id),
            $main->file_uuid,
            'branch',
            ['_uuid' => 'x', 'greet' => $this->line('Bonjour', 'V'), 'extra' => $this->line('Plus', 'H')]
        );

        $this->assertSame(
            $this->waiting(2, take: 2, new: ['H' => 1], differing: ['V' => 1]),
            $service->contributionsWaiting($main)
        );
    }

    /**
     * 🔴 One key, one row — under the best tag any contribution offers for it.
     *
     * Two contributions can offer the same line at different qualities. "Two contributions offering
     * the same line are one line to recover" has to hold for the breakdown as well, or the two
     * halves stop summing to the total — and which tag is reported must not depend on the order the
     * files came back, which a plain array union would decide.
     */
    public function test_one_key_offered_twice_is_filed_once_under_its_best_tag(): void
    {
        $main = $this->lineage(
            ['_uuid' => 'x', 'greet' => $this->line('Bonjour', 'A')],
            // Read and stood behind: same words, better tag.
            ['_uuid' => 'x', 'greet' => $this->line('Bonjour', 'V')],
            // Written by hand, which outranks it.
            ['_uuid' => 'x', 'greet' => $this->line('Salut', 'H')]
        );

        $this->assertSame(
            $this->waiting(2, take: 1, differing: ['H' => 1]),
            app(TranslationService::class)->contributionsWaiting($main),
            'one line to look at, reported at the best quality offered for it'
        );
    }

    /**
     * 🔴 A captured line is an `H` with nothing in it, and it sits at the FLOOR of the ladder.
     *
     * Reading the letter alone would report it as hand-written and rank it above every real
     * translation — which is why the rank is kept beside the tag rather than recomputed from it.
     */
    public function test_a_capture_is_not_reported_as_hand_written(): void
    {
        $main = $this->lineage(
            ['_uuid' => 'x', 'greet' => $this->line('Bonjour', 'A')],
            // A capture, and a real translation of the same line from somebody else.
            ['_uuid' => 'x', 'greet' => $this->line('', 'H')],
            ['_uuid' => 'x', 'greet' => $this->line('Salut', 'V')]
        );

        $this->assertSame(
            $this->waiting(1, take: 1, differing: ['V' => 1]),
            app(TranslationService::class)->contributionsWaiting($main),
            'the V outranks the empty H, and only one contribution is holding anything'
        );
    }

    /** The two axes at once, which is what a real contribution looks like. */
    public function test_the_two_axes_are_counted_apart(): void
    {
        $main = $this->lineage(
            [
                '_uuid' => 'x',
                'greet' => $this->line('Bonjour', 'A'),
                'bye' => $this->line('Au revoir', 'A'),
                'kept' => $this->line('Gardé', 'H'),
            ],
            [
                '_uuid' => 'x',
                // Read and stood behind: no word changes.
                'greet' => $this->line('Bonjour', 'V'),
                // Retranslated by hand.
                'bye' => $this->line('Salut', 'H'),
                // A tie: the Main keeps its own, but the row still needs looking at.
                'kept' => $this->line('Autre', 'H'),
                // Text nobody else has.
                'extra' => $this->line('Plus', 'H'),
            ]
        );

        // 🔴 Four rows to review, three worth taking — the pair of measures a single total cannot
        // carry. And the tags say what they are: one new line written by hand, one validation, two
        // hand-written rewordings of which one the Main will keep.
        $this->assertSame(
            $this->waiting(1, take: 3, new: ['H' => 1], differing: ['V' => 1, 'H' => 2]),
            app(TranslationService::class)->contributionsWaiting($main)
        );
    }
}
