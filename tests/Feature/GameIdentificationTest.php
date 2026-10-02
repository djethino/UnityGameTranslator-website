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
    private function stores(array $igdb = [], array $steamApps = [], array $steamSearch = [], array $rawg = []): void
    {
        Http::preventStrayRequests();

        Http::fake([
            'id.twitch.tv/*' => Http::response(['access_token' => 'fresh', 'expires_in' => 5_000_000]),
            'api.igdb.com/*' => function (Request $request) use ($igdb) {
                $body = $request->body();

                foreach ($igdb as $asked => $rows) {
                    $matches = str_starts_with($asked, 'id:')
                        ? str_contains($body, 'where id = ' . substr($asked, 3) . ';')
                        : str_contains($body, 'search "' . $asked . '"');

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

    public function test_a_shared_title_is_filed_under_the_name_sent_not_a_guess(): void
    {
        $this->stores(['Lost Echo' => [
            $this->igdbGame(22, 'Lost Echo', '500'),
            $this->igdbGame(33, 'Lost Echo', '600'),
        ]]);

        $this->publish(['game_name' => 'Lost Echo'])->assertSuccessful();

        $card = Translation::latest('id')->first()->game;
        $this->assertNull($card->steam_id);
        $this->assertSame('Lost Echo', $card->unity_name, 'findable again by the name the mod reads');
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
        $this->assertSame(
            ['steam_id' => '500', 'game_name' => 'LOSTECHO', 'game_company' => 'Studio'],
            $entry->metadata['sent'],
        );
        $this->assertFalse($entry->metadata['is_branch']);
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
    }

    public function test_a_store_hit_for_a_card_we_hold_is_that_card(): void
    {
        $card = Game::create(['name' => 'Lost Echo', 'igdb_id' => 22]);
        $this->stores(['Lost Echo' => [$this->igdbGame(22, 'Lost Echo')]]);

        $rows = app(GameSearchService::class)->searchFull('Lost Echo');

        $this->assertSame([['local', $card->id]], array_map(fn ($r) => [$r['source'], $r['id']], $rows));
    }

    public function test_a_steam_page_address_is_searched_as_its_steam_id(): void
    {
        $this->stores([], ['500' => ['name' => 'Lost Echo', 'type' => 'game']]);

        $rows = app(GameSearchService::class)->searchFull('https://store.steampowered.com/app/500/Lost_Echo/');

        $this->assertSame('500', $rows[0]['steam_id'] ?? null);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'storesearch'));
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
