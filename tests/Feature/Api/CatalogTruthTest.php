<?php

namespace Tests\Feature\Api;

use App\Models\Game;
use App\Models\Translation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * What the catalogue endpoints are allowed to leave out — and what they must never let a caller
 * conclude from it.
 *
 * 🔴 **Every case here is a lie the API used to be able to tell.** `search` loaded 300 rows with no
 * `orderBy`, ranked them in PHP and returned 50, with no total and no pagination. Past those
 * numbers:
 *
 *  · the Manager said "50 translations are published for this game" — it had asked for all of them;
 *  · it listed a truncated set of languages, so "None of them is in French" was reachable with five
 *    French ones sitting in the part that was cut;
 *  · and it OFFERED ONE FOR INSTALL out of that sample, which writes a file into a game.
 *
 * The rule these tests hold: **a limit bounds the transport, never the truth.** Whatever is capped,
 * the counts beside it are measured, so nothing a caller concludes can be wrong.
 *
 * ⚠ The flat `translations` array is deliberately still capped and still first in the payload:
 * every mod already published reads it and nothing else. Its behaviour must not move.
 */
class CatalogTruthTest extends TestCase
{
    use RefreshDatabase;

    private function makeGame(string $name, ?string $steamId = null): Game
    {
        return Game::create([
            'name' => $name,
            'slug' => Str::slug($name) . '-' . uniqid(),
            'steam_id' => $steamId,
        ]);
    }

    private function makeTranslation(Game $game, array $attributes = []): Translation
    {
        $path = 'translations/catalog-truth-' . uniqid() . '.json';
        Storage::disk('local')->put($path, json_encode(['Hello' => ['v' => 'Bonjour', 't' => 'H']]));

        $translation = new Translation();
        $translation->forceFill(array_merge([
            'game_id' => $game->id,
            'user_id' => User::factory()->create()->id,
            'source_language' => 'English',
            'target_language' => 'French',
            'visibility' => 'public',
            'file_uuid' => (string) Str::uuid(),
            'file_path' => $path,
            'file_hash' => 'hash-' . uniqid(),
            'line_count' => 10,
            'human_count' => 10,
            'capture_count' => 0,
            'vote_count' => 0,
        ], $attributes))->save();

        return $translation->refresh();
    }

    // =====================================================================================
    // search
    // =====================================================================================

    public function test_a_game_with_more_translations_than_the_flat_array_still_reports_the_true_count(): void
    {
        $game = $this->makeGame('Crowded Game', '999001');

        // One more than the flat array carries, so the two answers cannot agree by accident.
        for ($i = 0; $i < 55; $i++) {
            $this->makeTranslation($game, ['target_language' => 'French']);
        }

        $response = $this->getJson('/api/v1/translations?steam_id=999001')->assertOk();

        // The legacy view is untouched — a deployed mod sees exactly what it saw before.
        $this->assertSame(50, $response->json('count'));
        $this->assertCount(50, $response->json('translations'));

        // And the truth is beside it, complete.
        $this->assertSame(1, $response->json('games_total'));
        $this->assertSame(55, $response->json('games.0.total'));
        $this->assertCount(55, $response->json('games.0.translations'));
    }

    public function test_the_language_tally_describes_the_game_even_under_a_language_filter(): void
    {
        $game = $this->makeGame('Multilingual Game', '999002');

        $this->makeTranslation($game, ['target_language' => 'French']);
        $this->makeTranslation($game, ['target_language' => 'French']);
        $this->makeTranslation($game, ['target_language' => 'German']);

        // Asking for French must not make the answer claim the game only exists in French: that is
        // exactly how "None of them is in <your language>" became sayable about a game that had one.
        $response = $this->getJson('/api/v1/translations?steam_id=999002&lang=French')->assertOk();

        $this->assertSame(2, $response->json('games.0.total'), 'total answers the request');
        $this->assertSame(
            ['French' => 2, 'German' => 1],
            $response->json('games.0.languages'),
            'languages answers about the game'
        );
    }

    public function test_a_fuzzy_name_search_keeps_each_game_apart(): void
    {
        $cat = $this->makeGame('Cat');
        $cattails = $this->makeGame('Cattails');

        $this->makeTranslation($cat, ['target_language' => 'French']);
        $this->makeTranslation($cattails, ['target_language' => 'German']);
        $this->makeTranslation($cattails, ['target_language' => 'Spanish']);

        $response = $this->getJson('/api/v1/translations?q=Cat')->assertOk();

        // 🔴 The defect this shape exists for: `name LIKE %Cat%` spans two games, and the answer
        // used to be one flat pile. A client attributed all of it to the game it had asked about —
        // so a translation of Cattails could be OFFERED FOR INSTALL into Cat.
        $this->assertSame(2, $response->json('games_total'));

        $groups = collect($response->json('games'))->keyBy('game.name');

        $this->assertSame(1, $groups['Cat']['total']);
        $this->assertSame(2, $groups['Cattails']['total']);
        $this->assertSame('French', $groups['Cat']['translations'][0]['target_language']);
    }

    public function test_the_answer_stays_the_shape_a_published_mod_reads(): void
    {
        $game = $this->makeGame('Shape Game', '999003');
        $this->makeTranslation($game);

        $response = $this->getJson('/api/v1/translations?steam_id=999003')->assertOk();

        // Additive only: whatever else is added, these two are the whole world to a deployed mod.
        $response->assertJsonStructure([
            'count',
            'translations' => [['id', 'game' => ['name', 'steam_id'], 'uploader', 'file_uuid', 'file_hash']],
        ]);
    }

    public function test_without_a_game_filter_nothing_claims_to_be_complete(): void
    {
        $game = $this->makeGame('Lonely Game', '999004');
        $this->makeTranslation($game);

        $response = $this->getJson('/api/v1/translations')->assertOk();

        // There is no "per game" to be exhaustive about here, so the grouped shape is absent
        // rather than present and partial. A caller must never be handed a promise we cannot keep.
        $this->assertNull($response->json('games'));
        $this->assertNull($response->json('games_total'));
        $this->assertNotNull($response->json('translations'));
    }

    // =====================================================================================
    // for-games (the batch)
    // =====================================================================================

    public function test_a_batch_answers_every_entry_asked_in_order(): void
    {
        $first = $this->makeGame('Batch One', '999101');
        $second = $this->makeGame('Batch Two');

        $this->makeTranslation($first, ['target_language' => 'French']);
        $this->makeTranslation($second, ['target_language' => 'German']);

        $response = $this->postJson('/api/v1/translations/for-games', [
            'games' => [
                ['steam_id' => '999101'],
                ['name' => 'Batch Two'],
                ['name' => 'Nothing Here At All'],
            ],
        ])->assertOk();

        $results = $response->json('results');
        $this->assertCount(3, $results, 'one result per entry asked');

        $this->assertSame('999101', $results[0]['key']['steam_id']);
        $this->assertSame(1, $results[0]['games'][0]['total']);

        $this->assertSame('Batch Two', $results[1]['key']['name']);
        $this->assertSame('German', $results[1]['games'][0]['translations'][0]['target_language']);

        // ⚠ Present and empty, never missing: a caller cannot tell "nothing published" from "not
        // asked about" when the key simply is not there.
        $this->assertSame([], $results[2]['games']);
        $this->assertSame(0, $results[2]['games_total']);
    }

    public function test_a_batch_matches_a_name_whatever_its_case(): void
    {
        $game = $this->makeGame('Case Game');
        $this->makeTranslation($game, ['target_language' => 'French']);

        // 🔴 **This leans on the column's collation, so it is held by a test.** `games.name` is
        // indexed and the table collates utf8mb4_unicode_ci, which ignores case — so the lookup is
        // a plain `whereIn` that USES the index. Wrapping the column in LOWER() would have matched
        // just as well and forced a full scan of the table instead. If the collation ever changes,
        // this fails here rather than silently sending every library lookup through a scan.
        $response = $this->postJson('/api/v1/translations/for-games', [
            'games' => [['name' => 'cASE gAME']],
        ])->assertOk();

        $this->assertSame(1, $response->json('results.0.games_total'));
        $this->assertSame('Case Game', $response->json('results.0.games.0.game.name'));
    }

    public function test_a_batch_finds_the_lineage_a_game_runs_even_when_it_left_the_catalogue(): void
    {
        $game = $this->makeGame('Delisted Game', '999102');

        // Published, nothing translated, past the grace period: out of every listing — and still
        // the translation this game is running.
        $delisted = $this->makeTranslation($game, [
            'human_count' => 0,
            'validated_count' => 0,
            'ai_count' => 0,
            'capture_count' => 400,
            'created_at' => now()->subDays(Translation::EMPTY_GRACE_DAYS + 10),
        ]);

        $response = $this->postJson('/api/v1/translations/for-games', [
            'games' => [['steam_id' => '999102', 'uuid' => $delisted->file_uuid]],
        ])->assertOk();

        $result = $response->json('results.0');

        // Out of the catalogue…
        $this->assertSame(0, $result['games'][0]['total']);

        // …and still resolvable, which is what keeps the author, the sync verdict and the votes on
        // the card of the file in front of the reader. The rule is scopePubliclyListed's own:
        // "never where it resolves one".
        $this->assertNotNull($result['matching']);
        $this->assertSame($delisted->id, $result['matching']['id']);
    }

    public function test_a_batch_never_hands_back_somebody_elses_branch(): void
    {
        $game = $this->makeGame('Branch Game', '999103');
        $shared = (string) Str::uuid();

        $this->makeTranslation($game, ['file_uuid' => $shared, 'visibility' => 'public']);
        $branch = $this->makeTranslation($game, ['file_uuid' => $shared, 'visibility' => 'branch']);

        $response = $this->postJson('/api/v1/translations/for-games', [
            'games' => [['steam_id' => '999103', 'uuid' => $shared]],
        ])->assertOk();

        $result = $response->json('results.0');

        // A lineage carries a Main and its branches under one uuid. Only the Main may come back:
        // a branch is a private contribution, readable by its author and the Main owner, and this
        // endpoint has no business deciding that — /me/translations answers roles.
        $this->assertNotSame($branch->id, $result['matching']['id']);
        $this->assertSame('public', Translation::find($result['matching']['id'])->visibility);

        foreach ($result['games'][0]['translations'] as $listed) {
            $this->assertNotSame($branch->id, $listed['id']);
        }
    }

    public function test_a_batch_refuses_more_than_it_promises_to_answer_completely(): void
    {
        $games = [];
        for ($i = 0; $i < 101; $i++) {
            $games[] = ['steam_id' => (string) (900000 + $i)];
        }

        // Refused outright rather than silently truncated: the whole point of the batch is that
        // every entry it accepts is answered in full.
        $this->postJson('/api/v1/translations/for-games', ['games' => $games])
            ->assertStatus(422);
    }
}
