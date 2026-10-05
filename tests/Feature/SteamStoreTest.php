<?php

namespace Tests\Feature;

use App\Exceptions\StoreUnavailable;
use App\Models\ApiToken;
use App\Models\Game;
use App\Models\User;
use App\Services\AdultRating;
use App\Services\GameSearchService;
use App\Support\SteamStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Steam's store is never pushed past our own ceiling, and a store that could not be asked is never
 * read as an answer (user, 2026-10-05: "il ne faut pas de blocage steam, ou d'entrée erronée en base").
 */
class SteamStoreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.twitch.client_id' => 'c', 'services.twitch.client_secret' => 's']);
        Cache::forget('twitch_api_token');
        Http::preventStrayRequests();
    }

    /** Steam answers `$steamStatus`; IGDB links `$igdbLinks` (steam id => igdb game). */
    private function stores(int $steamStatus, array $igdbLinks = []): void
    {
        Http::fake([
            'store.steampowered.com/*' => fn (Request $r) => $steamStatus === 200
                ? Http::response([(string) ($r->data()['appids'] ?? '') => ['success' => true, 'data' => ['name' => 'From Steam']]])
                : Http::response('', $steamStatus),
            'id.twitch.tv/*' => Http::response(['access_token' => 't', 'expires_in' => 5_000_000]),
            'api.igdb.com/v4/external_games' => function (Request $r) use ($igdbLinks) {
                foreach ($igdbLinks as $steamId => $igdbId) {
                    if (str_contains($r->body(), 'uid = "' . $steamId . '"')) {
                        return Http::response([['game' => $igdbId]]);
                    }
                }

                return Http::response([]);
            },
            'api.igdb.com/v4/games' => fn (Request $r) => Http::response(preg_match('/where id = (\d+)/', $r->body(), $m)
                ? [['id' => (int) $m[1], 'name' => 'From IGDB', 'external_games' => [['uid' => array_search((int) $m[1], $igdbLinks), 'external_game_source' => 1]]]]
                : []),
            'api.rawg.io/*' => Http::response(['results' => []]),
        ]);
    }

    private function publish(array $fields): \Illuminate\Testing\TestResponse
    {
        $token = ApiToken::createForUser(User::factory()->create(), 'test')->plain_token;

        return $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/v1/translations', $fields + [
                'source_language' => 'English',
                'target_language' => 'French',
                'content' => json_encode(['_uuid' => (string) Str::uuid(), 'Hello' => ['v' => 'Bonjour', 't' => 'H']]),
            ]);
    }

    public function test_our_ceiling_is_reached_before_steam_s_and_background_work_has_a_share_of_it(): void
    {
        $this->stores(200);
        $stores = app(GameSearchService::class);

        foreach (range(1, SteamStore::BackgroundShare) as $n) {
            SteamStore::inBackground(fn () => $stores->steamApp((string) $n));
        }
        $this->assertThrows(fn () => SteamStore::inBackground(fn () => $stores->steamApp('9999')), StoreUnavailable::class);

        // A player still has the rest of the ceiling.
        foreach (range(SteamStore::BackgroundShare + 1, SteamStore::Ceiling) as $n) {
            $stores->steamApp((string) $n);
        }
        $this->assertThrows(fn () => $stores->steamApp('99999'), StoreUnavailable::class);
        $this->assertSame(SteamStore::Ceiling, Http::recorded()->count(), 'never past our own ceiling');

        // The next window opens it again.
        $this->travel(6)->minutes();
        $this->assertSame('From Steam', $stores->steamApp('99999')['name']);
    }

    public function test_a_publication_steam_cannot_identify_now_is_refused_with_try_again_and_writes_nothing(): void
    {
        $this->stores(500);

        $this->publish(['game_pick' => ['source' => 'steam', 'id' => '4242'], 'game_name' => 'Somewhere'])
            ->assertStatus(503)
            ->assertJsonPath('refused_code', 'store_unavailable')
            ->assertJsonPath('error', StoreUnavailable::Sentence);

        $this->assertSame(0, Game::count(), 'no card made up');
    }

    public function test_when_igdb_is_sure_of_the_steam_id_the_publication_goes_through_without_steam(): void
    {
        $this->stores(500, ['4242' => 777]);

        $this->publish(['game_pick' => ['source' => 'steam', 'id' => '4242'], 'game_name' => 'Somewhere'])
            ->assertSuccessful();

        $game = Game::sole();
        $this->assertSame('4242', (string) $game->steam_id);
        $this->assertSame(777, (int) $game->igdb_id);
    }

    public function test_steam_not_asked_is_never_written_as_nothing_found(): void
    {
        $this->stores(500);
        $game = Game::create(['name' => 'Some game', 'steam_id' => '4242']);
        $game->forceFill(['adult_checked_at' => null])->saveQuietly();

        app(AdultRating::class)->rate($game);

        $this->assertNull($game->refresh()->adult_checked_at, 'still due: nothing was asked');
    }

    public function test_a_refusal_is_written_down_and_a_success_ends_it(): void
    {
        $status = 429;
        Http::fake(['store.steampowered.com/*' => function (Request $r) use (&$status) {
            return $status === 200
                ? Http::response([(string) ($r->data()['appids'] ?? '') => ['success' => true, 'data' => ['name' => 'Back']]])
                : Http::response('', $status);
        }]);

        $this->assertThrows(fn () => app(GameSearchService::class)->steamApp('1'), StoreUnavailable::class);
        $this->assertTrue(SteamStore::refusing());

        $status = 200;
        $this->travel(6)->minutes();
        $this->assertSame('Back', app(GameSearchService::class)->steamApp('2')['name']);
        $this->assertFalse(SteamStore::refusing());
    }
}
