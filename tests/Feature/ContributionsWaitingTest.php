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
            ['branches' => 0, 'lines' => 0],
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
            ['branches' => 1, 'lines' => 1],
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

        $this->assertSame(
            ['branches' => 1, 'lines' => 1],
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
            ['branches' => 0, 'lines' => 0],
            app(TranslationService::class)->contributionsWaiting($main)
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
            ['branches' => 2, 'lines' => 1],
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
            ['branches' => 0, 'lines' => 0],
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
            ['branches' => 0, 'lines' => 0],
            app(TranslationService::class)->contributionsWaiting($main)
        );
    }

    public function test_a_main_with_no_contribution_asks_nothing_of_the_disk(): void
    {
        $main = $this->lineage(['_uuid' => 'x', 'greet' => $this->line('Bonjour', 'H')]);

        $this->assertSame(
            ['branches' => 0, 'lines' => 0],
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
        $this->assertNull($asOwner['lines_offered'], 'a Main offers nothing to itself');

        // The contributor: what they are holding, and nothing about the lineage's other rows.
        $asContributor = $this->asApi($contributor)
            ->getJson('/api/v1/translations/check-uuid?uuid=' . $uuid)
            ->assertOk()
            ->json();

        $this->assertSame(2, $asContributor['lines_offered']);
        $this->assertNull($asContributor['branches_with_work'], 'not their question');
        $this->assertNull($asContributor['lines_available'], 'nor their business');
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

        // Two screens describing one lineage must not report different numbers about it.
        $this->assertSame(1, $row['branches_with_work']);
        $this->assertSame(1, $row['lines_available']);
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
        $this->assertSame(['branches' => 1, 'lines' => 1], $service->contributionsWaiting($main));

        $this->makeTranslation(
            User::factory()->create()->refresh(),
            Game::find($main->game_id),
            $main->file_uuid,
            'branch',
            ['_uuid' => 'x', 'greet' => $this->line('Bonjour', 'V'), 'extra' => $this->line('Plus', 'H')]
        );

        $this->assertSame(['branches' => 2, 'lines' => 2], $service->contributionsWaiting($main));
    }
}
