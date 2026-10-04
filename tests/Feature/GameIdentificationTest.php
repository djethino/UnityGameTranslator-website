<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\AuditLog;
use App\Models\Game;
use App\Models\Translation;
use App\Models\User;
use App\Services\GameSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Which game a publication is filed under, and what the publish list offers to choose from.
 *
 * 🔴 **The defect these cases stand against** (analyse/identite-des-jeux-parcours.md): the site
 * took the FIRST answer of an IGDB search as "the" game. A title came back as another series'
 * game, and every republication landed on it again — while the game carrying that exact title,
 * with its Steam id, was in the same answer. Each case below was broken on purpose, watched red,
 * and put back.
 *
 * The stores are faked whole and stray requests refused: a case that reached the network would
 * prove nothing about this code.
 */
class GameIdentificationTest extends TestCase
{
    use RefreshDatabase;

    /** IGDB's number for Steam in `external_game_source` (GameSearchService::IgdbSteamSource). */
    private const Steam = 1;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.twitch.client_id' => 'client',
            'services.twitch.client_secret' => 'secret',
            'services.rawg.key' => 'rawg-key',
        ]);
        Cache::forget('twitch_api_token');
    }

    /**
     * What the stores answer. `$igdb` maps a searched title (or `id:N`) to its rows; `$steamApps`
     * maps an app id to its store data; `$steamSearch` and `$rawg` answer every title search.
     */
    private function stores(array $igdb = [], array $steamApps = [], array $steamSearch = [], array $rawg = [], array $rawgGames = []): void
    {
        Http::preventStrayRequests();

        Http::fake([
            // One RAWG game by id or slug (`/api/games/<id>`), before RAWG's search (`/api/games?`).
            'api.rawg.io/api/games/*' => function (Request $request) use ($rawgGames) {
                $asked = basename(parse_url($request->url(), PHP_URL_PATH));

                return isset($rawgGames[$asked]) ? Http::response($rawgGames[$asked]) : Http::response(['detail' => 'Not found.'], 404);
            },
            'id.twitch.tv/*' => Http::response(['access_token' => 'fresh', 'expires_in' => 5_000_000]),
            'api.igdb.com/*' => function (Request $request) use ($igdb) {
                $body = $request->body();

                foreach ($igdb as $asked => $rows) {
                    $matches = match (true) {
                        str_starts_with($asked, 'id:') => str_contains($body, 'where id = ' . substr($asked, 3) . ';'),
                        str_starts_with($asked, 'slug:') => str_contains($body, 'where slug = "' . substr($asked, 5) . '"'),
                        default => str_contains($body, 'search "' . $asked . '"'),
                    };

                    if ($matches) {
                        return Http::response($rows);
                    }
                }

                return Http::response([]);
            },
            'store.steampowered.com/api/storesearch/*' => Http::response(['items' => $steamSearch]),
            'store.steampowered.com/api/appdetails*' => function (Request $request) use ($steamApps) {
                $id = (string) ($request->data()['appids'] ?? '');

                return Http::response(isset($steamApps[$id])
                    ? [$id => ['success' => true, 'data' => $steamApps[$id]]]
                    : [$id => ['success' => false]]);
            },
            'api.rawg.io/*' => Http::response(['results' => $rawg]),
        ]);
    }

    private function igdbGame(int $id, string $name, ?string $steamId = null): array
    {
        return array_filter([
            'id' => $id,
            'name' => $name,
            'external_games' => $steamId ? [['uid' => $steamId, 'external_game_source' => self::Steam]] : null,
        ]);
    }

    private function publish(array $fields, ?User $user = null): \Illuminate\Testing\TestResponse
    {
        $token = ApiToken::createForUser($user ?? User::factory()->create(), 'test')->plain_token;

        return $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/v1/translations', array_merge([
                'source_language' => 'English',
                'target_language' => 'French',
                'content' => json_encode([
                    '_uuid' => (string) Str::uuid(),
                    'Hello ' . uniqid() => ['v' => 'Bonjour', 't' => 'H'],
                ]),
            ], $fields));
    }

    // ── what the upload resolves on its own ─────────────────────────────────────────────────

    public function test_the_first_hit_of_a_search_is_never_taken_for_the_game(): void
    {
        // IGDB ranks a word-swapped title of another series first; the game whose title IS the
        // one sent comes second, with its Steam id.
        $this->stores(['Lost Echo' => [
            $this->igdbGame(11, 'Echo: The Lost Legacy'),
            $this->igdbGame(22, 'Lost Echo', '500'),
        ]]);

        $found = app(GameSearchService::class)->findGame(null, 'Lost Echo');

        $this->assertSame(22, $found['id']);
        $this->assertSame('500', $found['steam_id'], 'the Steam id IGDB links comes with the hit');
    }

    public function test_two_games_of_one_title_are_not_chosen_between(): void
    {
        $this->stores(['Lost Echo' => [
            $this->igdbGame(22, 'Lost Echo', '500'),
            $this->igdbGame(33, 'Lost Echo', '600'),
        ]]);

        $this->assertNull(app(GameSearchService::class)->findGame(null, 'Lost Echo'));

        // IGDB naming two games is an answer, not a silence for RAWG to fill.
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'api.rawg.io'));
    }

    public function test_a_title_in_another_script_is_not_sent_to_igdb_empty(): void
    {
        $this->stores();

        app(GameSearchService::class)->findGame(null, '百花杀尽');

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'api.igdb.com'));
    }

    public function test_a_refused_igdb_token_is_forgotten_and_asked_for_again(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'id.twitch.tv/*' => Http::response(['access_token' => 'fresh', 'expires_in' => 5_000_000]),
            'api.igdb.com/*' => fn (Request $r) => $r->hasHeader('Authorization', 'Bearer stale')
                ? Http::response(['message' => 'invalid access token'], 401)
                : Http::response([$this->igdbGame(22, 'Lost Echo')]),
        ]);

        // A token Twitch revoked before the expiry it announced, still in the cache.
        Cache::put('twitch_api_token', 'stale', 3600);

        $rows = app(GameSearchService::class)->igdb('games', 'search "Lost Echo"; fields id,name;');

        $this->assertSame('Lost Echo', $rows[0]['name'] ?? null);
        $this->assertSame('fresh', Cache::get('twitch_api_token'));
    }

    public function test_an_upload_of_one_title_is_filed_by_the_steam_id_igdb_gives(): void
    {
        $this->stores(
            ['Lost Echo' => [$this->igdbGame(11, 'Echo: The Lost Legacy'), $this->igdbGame(22, 'Lost Echo', '500')]],
            ['500' => ['name' => 'Lost Echo', 'type' => 'game']],
        );

        $this->publish(['game_name' => 'Lost Echo'])->assertSuccessful();

        $card = Translation::latest('id')->first()->game;
        $this->assertSame('Lost Echo', $card->name);
        $this->assertSame('500', $card->steam_id);
        $this->assertSame(22, (int) $card->igdb_id, 'the IGDB id the card was made from is kept');
    }

    public function test_a_shared_title_is_refused_not_guessed(): void
    {
        $this->stores(['Lost Echo' => [
            $this->igdbGame(22, 'Lost Echo', '500'),
            $this->igdbGame(33, 'Lost Echo', '600'),
        ]]);

        // Two games carry the title: which one this is cannot be told, so nothing is created —
        // the person picks it in the list.
        $this->publish(['game_name' => 'Lost Echo'])
            ->assertStatus(422)
            ->assertJsonPath('refused_code', 'game_not_found');

        $this->assertSame(0, Game::count());
        $this->assertSame(0, Translation::count());
    }

    public function test_a_game_nothing_identifies_is_refused_never_created(): void
    {
        $this->stores();

        $this->publish(['game_name' => 'My Unity Project', 'game_read' => ['product_name' => 'My Unity Project']])
            ->assertStatus(422)
            ->assertJsonPath('refused_code', 'game_not_found')
            ->assertJsonPath('error', fn ($error) => str_contains($error, 'could not be identified'));

        $this->assertSame(0, Game::count());
        $this->assertSame(0, Translation::count());
    }

    public function test_a_steam_id_steam_does_not_know_creates_nothing(): void
    {
        // Any Unity project can carry a made-up steam_appid.txt: an id is a game only when the
        // store describes it.
        $this->stores();

        $this->publish(['steam_id' => '500', 'game_name' => 'Lost Echo'])
            ->assertStatus(422)
            ->assertJsonPath('refused_code', 'game_not_found');

        $this->assertSame(0, Game::count());
    }

    public function test_a_steam_id_nobody_holds_never_lands_on_a_homonym_with_another_one(): void
    {
        $homonym = Game::create(['name' => 'Lost Echo', 'steam_id' => '600']);
        $this->stores([], ['500' => ['name' => 'Lost Echo', 'type' => 'game']]);

        $this->publish(['steam_id' => '500', 'game_name' => 'Lost Echo'])->assertSuccessful();

        $card = Translation::latest('id')->first()->game;
        $this->assertNotSame($homonym->id, $card->id);
        $this->assertSame('500', $card->steam_id);

        // Two cards of one title, each with its own address — the first keeps the plain one.
        $this->assertSame('lost-echo', $homonym->fresh()->slug);
        $this->assertSame('lost-echo-500', $card->slug);
    }

    public function test_a_fork_is_filed_under_the_game_of_what_it_was_forked_from(): void
    {
        $this->stores();
        $game = Game::create(['name' => 'Lost Echo', 'steam_id' => '500']);

        $this->publish(['steam_id' => '500', 'game_name' => 'Lost Echo'])->assertSuccessful();
        $source = Translation::latest('id')->first();

        // The forking copy reads another name, and no Steam id: re-resolved, it would go elsewhere.
        $this->publish(['game_name' => 'Something Else Entirely', 'forked_from_id' => $source->id])
            ->assertSuccessful();

        $fork = Translation::latest('id')->first();
        $this->assertSame($source->id, $fork->origin_translation_id);
        $this->assertSame($game->id, $fork->game_id);
    }

    public function test_the_audit_keeps_what_the_client_sent_about_the_game(): void
    {
        $this->stores();
        Game::create(['name' => 'Lost Echo', 'steam_id' => '500']);

        $this->publish(['steam_id' => '500', 'game_name' => 'LOSTECHO', 'game_company' => 'Studio'])
            ->assertSuccessful();

        $entry = AuditLog::where('action', AuditLog::ACTION_TRANSLATION_UPLOAD)->latest('id')->first();
        // A client that sends no choice and no reading: both said absent, not empty.
        $this->assertSame(
            ['steam_id' => '500', 'game_name' => 'LOSTECHO', 'game_company' => 'Studio', 'game_pick' => null, 'game_read' => null],
            $entry->metadata['sent'],
        );
        $this->assertFalse($entry->metadata['is_branch']);
    }

    // ── what a client that sends its choice and what it read gets ───────────────────────────

    public function test_a_picked_card_is_the_game_whatever_the_title_sent(): void
    {
        $this->stores();
        $card = Game::create(['name' => 'Chronicles of the Long Road', 'steam_id' => '700']);

        $this->publish([
            'game_name' => 'Some Other Title',
            'game_pick' => ['source' => 'local', 'id' => $card->id],
        ])->assertSuccessful();

        $row = Translation::latest('id')->first();
        $this->assertSame($card->id, $row->game_id);
        $this->assertSame(['source' => 'local', 'id' => (string) $card->id], $row->game_pick);
    }

    public function test_a_picked_store_game_is_created_from_that_id_and_never_searched_again(): void
    {
        $this->stores(
            [
                'id:22' => [$this->igdbGame(22, 'Lost Echo', '500')],
                // What a new search would answer first — must never be asked.
                'Lost Echo' => [$this->igdbGame(11, 'Echo: The Lost Legacy')],
            ],
            ['500' => ['name' => 'Lost Echo', 'type' => 'game']],
        );

        $this->publish([
            'game_name' => 'Lost Echo',
            'game_pick' => ['source' => 'igdb', 'id' => 22],
            'game_read' => ['product_name' => 'LOSTECHO', 'company_name' => 'Studio'],
        ])->assertSuccessful();

        $card = Translation::latest('id')->first()->game;
        $this->assertSame(22, (int) $card->igdb_id);
        $this->assertSame('500', $card->steam_id);
        $this->assertSame('LOSTECHO', $card->unity_name, 'the key is the name READ, not the title picked');
        Http::assertNotSent(fn (Request $r) => str_contains($r->body(), 'search "Lost Echo"'));
    }

    public function test_a_name_read_unlike_the_title_is_not_the_key_but_still_finds_the_card(): void
    {
        $this->stores();
        $card = Game::create(['name' => 'Chronicles of the Long Road', 'steam_id' => '700']);

        $this->publish([
            'steam_id' => '700',
            'game_name' => 'Chronicles of the Long Road',
            'game_pick' => ['source' => 'local', 'id' => $card->id],
            'game_read' => ['product_name' => 'CLR', 'steam_id' => '700'],
        ])->assertSuccessful();

        // The guard against a declared name taking a Steam card's key still holds…
        $this->assertNull($card->fresh()->unity_name);

        // …and the copies without a Steam id that read "CLR" find the card all the same.
        $this->getJson('/api/v1/translations?q=CLR')
            ->assertOk()
            ->assertJsonPath('translations.0.game.id', $card->id);
    }

    public function test_a_steam_id_read_on_disk_refuses_another_game_and_writes_nothing(): void
    {
        $this->stores();
        $picked = Game::create(['name' => 'Lost Echo', 'steam_id' => '600']);
        $cards = Game::count();

        $this->publish([
            'game_name' => 'Lost Echo',
            'game_pick' => ['source' => 'local', 'id' => $picked->id],
            'game_read' => ['product_name' => 'Lost Echo', 'steam_id' => '500', 'steam_id_from' => 'steam_appid.txt'],
        ])->assertStatus(422)->assertJsonPath('refused_code', 'game_mismatch');

        $this->assertSame(0, Translation::count());
        $this->assertSame($cards, Game::count());
    }

    public function test_a_demo_read_on_disk_is_the_full_game_it_belongs_to(): void
    {
        $this->stores([], [
            '901' => ['name' => 'Lost Echo Demo', 'type' => 'demo', 'fullgame' => ['appid' => '900', 'name' => 'Lost Echo']],
            '900' => ['name' => 'Lost Echo', 'type' => 'game'],
        ]);

        $this->publish([
            'game_name' => 'Lost Echo',
            'game_pick' => ['source' => 'steam', 'id' => '901'],
            'game_read' => ['product_name' => 'Lost Echo', 'steam_id' => '901'],
        ])->assertSuccessful();

        $this->assertSame('900', Translation::latest('id')->first()->game->steam_id);
    }

    // ── an upload into a lineage that exists ────────────────────────────────────────────────

    /** A lineage filed under `$game`: the Main's owner, and the uuid of its file. */
    private function lineageOn(Game $game): array
    {
        $uuid = (string) Str::uuid();
        $owner = User::factory()->create();

        $this->publish([
            'game_name' => $game->name,
            'game_pick' => ['source' => 'local', 'id' => $game->id],
            'content' => json_encode(['_uuid' => $uuid, 'Hello' => ['v' => 'Bonjour', 't' => 'H']]),
        ], $owner)->assertSuccessful();

        return [$owner, $uuid];
    }

    private function contentOf(string $uuid): string
    {
        return json_encode(['_uuid' => $uuid, 'Hello' => ['v' => 'Bonjour', 't' => 'H'], 'Line ' . uniqid() => ['v' => 'Ligne', 't' => 'H']]);
    }

    public function test_an_update_from_another_game_is_refused_with_the_way_out(): void
    {
        $this->stores();
        $wrong = Game::create(['name' => 'Lost Echo', 'steam_id' => '600']);
        [$owner, $uuid] = $this->lineageOn($wrong);
        $hash = Translation::first()->file_hash;

        $this->publish([
            'game_name' => 'Lost Echo',
            'content' => $this->contentOf($uuid),
            'game_read' => ['product_name' => 'Lost Echo', 'steam_id' => '500'],
        ], $owner)->assertStatus(422)
            ->assertJsonPath('refused_code', 'game_mismatch')
            ->assertJsonPath('error', fn ($error) => str_contains($error, 'Change its game on the website'));

        $this->assertSame($hash, Translation::first()->file_hash);
    }

    public function test_a_branch_from_another_game_is_refused_and_names_who_can_act(): void
    {
        $this->stores();
        $wrong = Game::create(['name' => 'Lost Echo', 'steam_id' => '600']);
        [, $uuid] = $this->lineageOn($wrong);
        Translation::first()->update(['accepts_branches' => true]);

        $this->publish([
            'game_name' => 'Lost Echo',
            'content' => $this->contentOf($uuid),
            'game_read' => ['product_name' => 'Lost Echo', 'steam_id' => '500'],
        ])->assertStatus(422)
            ->assertJsonPath('refused_code', 'game_mismatch')
            ->assertJsonPath('error', fn ($error) => str_contains($error, 'Only the owner of the Main'));

        $this->assertSame(1, Translation::count());
    }

    public function test_a_fork_left_behind_by_its_moved_original_is_told_to_follow_it(): void
    {
        $this->stores();
        $wrong = Game::create(['name' => 'Lost Echo', 'steam_id' => '600']);
        $right = Game::create(['name' => 'Lost Echo Reborn', 'steam_id' => '500']);
        [$owner] = $this->lineageOn($wrong);
        $original = Translation::first();

        $forker = User::factory()->create();
        $forkUuid = (string) Str::uuid();
        $this->publish([
            'game_name' => 'Lost Echo',
            'forked_from_id' => $original->id,
            'content' => $this->contentOf($forkUuid),
        ], $forker)->assertSuccessful();
        $this->assertSame($wrong->id, Translation::where('file_uuid', $forkUuid)->first()->game_id);

        app(\App\Services\LineageGame::class)->move($original, $right, $owner, 'test');

        $this->publish([
            'game_name' => 'Lost Echo',
            'content' => $this->contentOf($forkUuid),
            'game_read' => ['product_name' => 'Lost Echo', 'steam_id' => '500'],
        ], $forker)->assertStatus(422)
            ->assertJsonPath('error', fn ($error) => str_contains($error, 'Move it to the game of the translation it was forked from'));
    }

    public function test_an_upload_into_a_lineage_of_the_same_game_goes_through(): void
    {
        $this->stores();
        $game = Game::create(['name' => 'Lost Echo', 'steam_id' => '500']);
        [$owner, $uuid] = $this->lineageOn($game);

        $this->publish([
            'game_name' => 'Lost Echo',
            'content' => $this->contentOf($uuid),
            'game_read' => ['product_name' => 'Lost Echo', 'steam_id' => '500'],
        ], $owner)->assertSuccessful();
    }

    public function test_a_demo_of_the_lineages_game_is_that_game_and_remembered(): void
    {
        $this->stores([], [
            '901' => ['name' => 'Lost Echo Demo', 'type' => 'demo', 'fullgame' => ['appid' => '900', 'name' => 'Lost Echo']],
            '900' => ['name' => 'Lost Echo', 'type' => 'game'],
        ]);
        $game = Game::create(['name' => 'Lost Echo', 'steam_id' => '900']);
        [$owner, $uuid] = $this->lineageOn($game);

        $this->publish([
            'game_name' => 'Lost Echo',
            'content' => $this->contentOf($uuid),
            'game_read' => ['product_name' => 'Lost Echo', 'steam_id' => '901'],
        ], $owner)->assertSuccessful();

        $this->assertTrue(Game::answeringToSteamId('901')->whereKey($game->id)->exists());
    }

    public function test_a_client_that_reads_nothing_updates_as_before(): void
    {
        $this->stores();
        $game = Game::create(['name' => 'Lost Echo', 'steam_id' => '600']);
        [$owner, $uuid] = $this->lineageOn($game);

        $this->publish(['game_name' => 'Lost Echo', 'content' => $this->contentOf($uuid), 'steam_id' => '500'], $owner)->assertSuccessful();
    }

    public function test_an_engine_the_store_names_that_is_not_the_games_refuses_it(): void
    {
        $this->stores(['id:22' => [array_merge($this->igdbGame(22, 'Lost Echo'), ['game_engines' => [['name' => 'Unreal Engine 4']]])]]);

        $this->publish([
            'game_name' => 'Lost Echo',
            'game_pick' => ['source' => 'igdb', 'id' => 22],
            'game_read' => ['product_name' => 'Lost Echo', 'engine' => 'Unity'],
        ])->assertStatus(422)->assertJsonPath('refused_code', 'game_mismatch');

        $this->assertSame(0, Game::count());
    }

    public function test_a_choice_that_names_nothing_is_said_not_guessed(): void
    {
        $this->stores();

        $this->publish([
            'game_name' => 'Lost Echo',
            'game_pick' => ['source' => 'local', 'id' => 999999],
        ])->assertStatus(422)->assertJsonPath('refused_code', 'game_not_found');

        $this->assertSame(0, Game::count());
    }

    public function test_a_picked_id_its_store_does_not_describe_creates_nothing(): void
    {
        // IGDB says nothing of that id (made up, or the store down): nothing vouches for the game,
        // so nothing is created — and the title sent is never searched instead.
        $this->stores();

        $this->publish([
            'game_name' => 'Lost Echo',
            'game_pick' => ['source' => 'igdb', 'id' => 22],
            'game_read' => ['product_name' => 'Lost Echo'],
        ])->assertStatus(422)->assertJsonPath('refused_code', 'game_not_found');

        $this->assertSame(0, Game::count());
        Http::assertNotSent(fn (Request $r) => str_contains($r->body(), 'search "Lost Echo"'));
    }

    public function test_the_adult_question_resolves_the_choice_as_the_upload_will(): void
    {
        $this->stores();
        $card = Game::create(['name' => 'Chronicles of the Long Road', 'steam_id' => '700']);
        $token = ApiToken::createForUser(User::factory()->create(), 'test')->plain_token;

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/v1/games/adult?game_name=Something%20Else&game_pick[source]=local&game_pick[id]=' . $card->id)
            ->assertOk()
            ->assertJsonPath('known', true);
    }

    public function test_a_batch_lookup_finds_a_card_by_a_name_its_translations_read(): void
    {
        $this->stores();
        $card = Game::create(['name' => 'Chronicles of the Long Road', 'steam_id' => '700']);
        $this->publish([
            'steam_id' => '700',
            'game_name' => 'Chronicles of the Long Road',
            'game_read' => ['product_name' => 'CLR', 'steam_id' => '700'],
        ])->assertSuccessful();

        $this->postJson('/api/v1/translations/for-games', ['games' => [['name' => 'CLR']]])
            ->assertOk()
            ->assertJsonPath('results.0.games.0.game.id', $card->id);
    }

    // ── what the publish list offers ────────────────────────────────────────────────────────

    public function test_two_games_of_one_title_are_both_offered(): void
    {
        $this->stores(['Lost Echo' => [
            $this->igdbGame(22, 'Lost Echo', '500'),
            $this->igdbGame(33, 'Lost Echo', '600'),
        ]]);

        $ids = collect(app(GameSearchService::class)->searchFull('Lost Echo'))->pluck('id')->all();

        $this->assertContains(22, $ids);
        $this->assertContains(33, $ids);
    }

    public function test_one_game_reached_through_two_stores_is_offered_once(): void
    {
        $this->stores(
            ['Lost Echo' => [$this->igdbGame(22, 'Lost Echo', '500')]],
            [],
            [['id' => 500, 'name' => 'Lost Echo', 'tiny_image' => 'https://example.test/capsule.jpg']],
        );

        $rows = collect(app(GameSearchService::class)->searchFull('Lost Echo'))->where('steam_id', '500');

        $this->assertCount(1, $rows);
        $this->assertSame('igdb', $rows->first()['source']);

        // The row kept gathers what the other source knew: both ids, both pages to check.
        $this->assertSame(['igdb' => '22', 'steam' => '500'], $rows->first()['ids']);
        $this->assertSame(['igdb', 'steam'], array_keys($rows->first()['pages']));
    }

    public function test_a_store_hit_for_a_card_we_hold_is_that_card(): void
    {
        $card = Game::create(['name' => 'Lost Echo', 'igdb_id' => 22]);
        $this->stores(['Lost Echo' => [$this->igdbGame(22, 'Lost Echo')]]);

        $rows = app(GameSearchService::class)->searchFull('Lost Echo');

        $this->assertSame([['local', $card->id]], array_map(fn ($r) => [$r['source'], $r['id']], $rows));
        $this->assertArrayHasKey('igdb', $rows[0]['pages']);
    }

    public function test_a_steam_page_address_is_searched_as_its_steam_id(): void
    {
        $this->stores([], ['500' => ['name' => 'Lost Echo', 'type' => 'game']]);

        $rows = app(GameSearchService::class)->searchFull('https://store.steampowered.com/app/500/Lost_Echo/');

        $this->assertSame('500', $rows[0]['steam_id'] ?? null);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'storesearch'));
    }

    public function test_a_number_is_asked_of_every_store_and_searched_as_a_title(): void
    {
        // "2048" can be a title, a Steam app, an IGDB game and a RAWG game: no source is THE id.
        $this->stores(
            ['id:2048' => [$this->igdbGame(2048, 'An IGDB Game')], '2048' => [$this->igdbGame(77, '2048')]],
            ['2048' => ['name' => 'A Steam Game', 'type' => 'game']],
            [],
            [],
            ['2048' => ['id' => 2048, 'name' => 'A RAWG Game']],
        );

        $names = collect(app(GameSearchService::class)->searchFull('2048'))->pluck('name')->all();

        $this->assertContains('A Steam Game', $names);
        $this->assertContains('An IGDB Game', $names);
        $this->assertContains('A RAWG Game', $names);
        $this->assertContains('2048', $names, 'and the title too');
    }

    public function test_an_igdb_or_rawg_page_address_names_that_game(): void
    {
        $this->stores(
            ['slug:lost-echo--1' => [$this->igdbGame(22, 'Lost Echo', '500')]],
            [],
            [],
            [],
            ['lost-echo' => ['id' => 5, 'name' => 'Lost Echo From RAWG']],
        );

        $igdb = app(GameSearchService::class)->searchFull('https://www.igdb.com/games/lost-echo--1');
        $this->assertSame([22, '500'], [$igdb[0]['id'] ?? null, $igdb[0]['steam_id'] ?? null]);
        Http::assertSent(fn (Request $r) => str_contains($r->body(), 'where slug = "lost-echo--1"'));

        $rawg = app(GameSearchService::class)->searchFull('https://rawg.io/games/lost-echo');
        $this->assertSame('Lost Echo From RAWG', $rawg[0]['name'] ?? null);
    }

    public function test_the_stores_are_asked_however_many_cards_of_ours_match(): void
    {
        // Three cards of ours share a word with the title; the real game is only in a store.
        foreach (['Legacy One', 'Legacy Two', 'Legacy Three'] as $name) {
            Game::create(['name' => $name]);
        }
        $this->stores(['Legacy' => [$this->igdbGame(22, 'Legacy', '500')]]);

        $names = collect(app(GameSearchService::class)->searchFull('Legacy'))->pluck('name')->all();

        $this->assertContains('Legacy', $names, 'a store answer is never hidden by cards of ours');
        $this->assertCount(4, $names);
    }

    public function test_a_card_is_offered_by_the_name_its_game_states_on_disk(): void
    {
        $card = Game::create(['name' => 'Chronicles of the Long Road', 'unity_name' => 'CLR']);
        $this->stores();

        $rows = app(GameSearchService::class)->searchFull('CLR');

        $this->assertContains($card->id, collect($rows)->where('source', 'local')->pluck('id')->all());
    }

    // ── what the card shows ─────────────────────────────────────────────────────────────────

    public function test_a_card_links_every_source_it_holds_an_id_from(): void
    {
        $card = new Game(['name' => 'Lost Echo', 'steam_id' => '500', 'igdb_id' => 22, 'rawg_id' => 1001328]);

        $this->assertSame([
            'Steam' => 'https://store.steampowered.com/app/500/',
            'IGDB' => 'https://www.igdb.com/g/m',
            'RAWG' => 'https://rawg.io/games/1001328',
        ], $card->storePages());
    }
}
