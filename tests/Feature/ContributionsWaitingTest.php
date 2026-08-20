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
     * The answer, written the way a reader of these tests should think about it: not a total, but
     * how many lines of each kind are waiting.
     *
     * ⚠ `lines` is derived rather than given, so a case cannot claim a total its own breakdown
     * contradicts — the property the API relies on when it prints the three side by side.
     */
    private function waiting(int $branches, int $new = 0, int $reworded = 0, int $validated = 0): array
    {
        return [
            'branches' => $branches,
            'lines' => $new + $reworded + $validated,
            'new' => $new,
            'reworded' => $reworded,
            'validated' => $validated,
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
            $this->waiting(1, new: 1),
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

        // ⚠ Counted as VALIDATED, not as a line of text: nothing was written, somebody read what
        // was there and stood behind it. That distinction is the whole reason the answer carries
        // three numbers — a Main weighing an evening of review needs to know which it is.
        $this->assertSame(
            $this->waiting(1, validated: 1),
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

        $this->assertSame(
            $this->waiting(0),
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
        $this->assertSame($this->waiting(1, reworded: 1), $service->contributionsWaiting($main));

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
            $this->waiting(1, reworded: 1),
            $service->contributionsWaiting($main),
            'moved since: it is back in front of the Main'
        );
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
            $this->waiting(2, reworded: 1),
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
        $this->assertSame(1, $asOwner['lines_new'], 'and the endpoint says which is which');
        $this->assertSame(1, $asOwner['lines_validated']);
        $this->assertSame(0, $asOwner['lines_reworded']);
        $this->assertNull($asOwner['lines_offered'], 'a Main offers nothing to itself');

        // The contributor: what they are holding, and nothing about the lineage's other rows.
        $asContributor = $this->asApi($contributor)
            ->getJson('/api/v1/translations/check-uuid?uuid=' . $uuid)
            ->assertOk()
            ->json();

        $this->assertSame(2, $asContributor['lines_offered']);
        $this->assertNull($asContributor['branches_with_work'], 'not their question');
        $this->assertNull($asContributor['lines_available'], 'nor their business');
        $this->assertNull($asContributor['lines_validated'], 'and neither is the breakdown');
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
        $this->assertSame(1, $row['lines_validated']);
        $this->assertSame(0, $row['lines_new']);
        $this->assertSame(0, $row['lines_reworded']);
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
        $this->assertSame($this->waiting(1, validated: 1), $service->contributionsWaiting($main));

        $this->makeTranslation(
            User::factory()->create()->refresh(),
            Game::find($main->game_id),
            $main->file_uuid,
            'branch',
            ['_uuid' => 'x', 'greet' => $this->line('Bonjour', 'V'), 'extra' => $this->line('Plus', 'H')]
        );

        $this->assertSame($this->waiting(2, new: 1, validated: 1), $service->contributionsWaiting($main));
    }

    /**
     * 🔴 One key, one kind — the largest thing that happened to it.
     *
     * Two contributions can offer the same line for different reasons: one retranslates it, another
     * only marks it validated. The total already counts that key once; the breakdown has to file it
     * once too, or the three numbers stop summing to it. And it must not depend on the order the
     * contributions came back — which a plain array union would.
     */
    public function test_one_key_offered_twice_is_filed_under_the_larger_kind(): void
    {
        $main = $this->lineage(
            ['_uuid' => 'x', 'greet' => $this->line('Bonjour', 'A')],
            // Validated only: same words, better tag.
            ['_uuid' => 'x', 'greet' => $this->line('Bonjour', 'V')],
            // Retranslated: the words change too.
            ['_uuid' => 'x', 'greet' => $this->line('Salut', 'H')]
        );

        $this->assertSame(
            $this->waiting(2, reworded: 1),
            app(TranslationService::class)->contributionsWaiting($main),
            'one line to recover, and it is a retranslation'
        );
    }

    /**
     * A retranslation carries a better tag as often as not. Reporting it as "validated" would name
     * the lesser half of what the contributor did.
     */
    public function test_new_words_are_reported_as_reworded_even_when_the_tag_also_rises(): void
    {
        $main = $this->lineage(
            ['_uuid' => 'x', 'greet' => $this->line('Bonjour', 'A')],
            ['_uuid' => 'x', 'greet' => $this->line('Salut', 'H')]
        );

        $this->assertSame(
            $this->waiting(1, reworded: 1),
            app(TranslationService::class)->contributionsWaiting($main)
        );
    }

    /** The three kinds at once, which is what a real contribution looks like. */
    public function test_the_three_kinds_are_counted_apart(): void
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
                // Retranslated.
                'bye' => $this->line('Salut', 'H'),
                // The Main keeps its own: a tie never displaces it.
                'kept' => $this->line('Autre', 'H'),
                // Text nobody else has.
                'extra' => $this->line('Plus', 'H'),
            ]
        );

        $this->assertSame(
            $this->waiting(1, new: 1, reworded: 1, validated: 1),
            app(TranslationService::class)->contributionsWaiting($main),
            'three lines waiting, of three different kinds, and the tie left out'
        );
    }
}
