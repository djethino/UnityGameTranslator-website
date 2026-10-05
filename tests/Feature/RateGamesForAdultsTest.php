<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\User;
use App\Services\GameSearchService;
use App\Services\StoreChanges;
use App\Support\SteamStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The adult check asks only about games DUE — new, changed in a store, or asked again — within a
 * small budget, and stops at the store's first refusal (2026-10-05).
 */
class RateGamesForAdultsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.steam.client_secret' => 'key']);
        Http::preventStrayRequests();
    }

    /** Store pages: none adult; `$dlcOf` maps a DLC to its game. */
    private function store(array $changedGames = [], array $changedDlc = [], array $dlcOf = [], int $status = 200): void
    {
        Http::fake([
            // What changed is told once, as Steam does: asked again since the moment it was told,
            // the list is empty.
            'api.steampowered.com/IStoreService/GetAppList/*' => function (Request $request) use (&$changedGames, &$changedDlc) {
                if ($request->data()['include_games'] === 'true') {
                    [$apps, $changedGames] = [$changedGames, []];
                } else {
                    [$apps, $changedDlc] = [$changedDlc, []];
                }

                return Http::response(['response' => ['apps' => array_map(fn ($id) => ['appid' => (int) $id, 'last_modified' => 1], $apps)]]);
            },
            'store.steampowered.com/api/appdetails*' => function (Request $request) use ($dlcOf, $status) {
                if ($status !== 200) {
                    return Http::response('', $status);
                }
                $id = (string) ($request->data()['appids'] ?? '');

                return Http::response([$id => ['success' => true, 'data' => array_filter([
                    'content_descriptors' => ['ids' => []],
                    'fullgame' => isset($dlcOf[$id]) ? ['appid' => $dlcOf[$id]] : null,
                ])]]);
            },
            'id.twitch.tv/*' => Http::response(['access_token' => 't', 'expires_in' => 5_000_000]),
            'api.igdb.com/*' => Http::response([]),
        ]);
    }

    private function games(int $count): void
    {
        foreach (range(1, $count) as $n) {
            Game::create(['name' => "Game {$n}", 'steam_id' => (string) (1000 + $n)]);
        }
        Game::query()->update(['adult_checked_at' => now()->subDay()]);
    }

    public function test_only_games_a_store_changed_are_asked_again_their_dlc_included(): void
    {
        $this->games(5);
        $this->store(changedGames: ['1001', '777'], changedDlc: ['90003'], dlcOf: ['90003' => '1003']);

        // The first pass only starts counting changes.
        $this->artisan('games:rate-adult')->assertSuccessful();
        $this->assertSame(0, Game::whereNull('adult_checked_at')->count());

        // The second finds what changed, the third asks about it — due games go first in a pass.
        $this->artisan('games:rate-adult')->assertSuccessful();
        $this->assertSame(2, Game::whereNull('adult_checked_at')->count());
        $this->artisan('games:rate-adult')->assertSuccessful();

        // 1001 changed itself; 1003 through its DLC; 777 is not ours. All due games were asked.
        $asked = Game::where('adult_checked_at', '>=', now()->subMinute())->pluck('steam_id')->sort()->values()->all();
        $this->assertSame(['1001', '1003'], $asked);
    }

    public function test_the_first_refusal_stops_the_pass_and_a_success_ends_it(): void
    {
        $this->games(5);
        Game::query()->update(['adult_checked_at' => null]);
        $status = 429;
        $asked = 0;
        Http::fake([
            // Steam's change list says nothing changed: only the due games are asked about.
            'api.steampowered.com/IStoreService/GetAppList/*' => Http::response(['response' => ['apps' => []]]),
            'store.steampowered.com/api/appdetails*' => function (Request $request) use (&$status, &$asked) {
            $asked++;
            $id = (string) ($request->data()['appids'] ?? '');

            return $status === 200
                ? Http::response([$id => ['success' => true, 'data' => ['content_descriptors' => ['ids' => []]]]])
                : Http::response('', $status);
            },
        ]);

        $this->artisan('games:rate-adult', ['--budget' => 100])->assertSuccessful();

        $this->assertTrue(SteamStore::refusing());
        $this->assertSame(1, $asked, 'one refusal, then nothing more is asked');

        // While it refuses, a pass asks once — the probe — and stops.
        $this->artisan('games:rate-adult', ['--budget' => 100])->assertSuccessful();
        $this->assertSame(2, $asked);

        // The store answers again: the probe clears the refusal, and the next pass goes on.
        $status = 200;
        $this->artisan('games:rate-adult', ['--budget' => 100])->assertSuccessful();
        $this->assertFalse(SteamStore::refusing());
        $this->artisan('games:rate-adult', ['--budget' => 100])->assertSuccessful();
        $this->assertSame(0, Game::whereNull('adult_checked_at')->count());
    }

    public function test_a_store_page_is_asked_once_a_day_not_at_every_call(): void
    {
        $this->store();
        $stores = app(GameSearchService::class);

        $stores->steamApp('1001');
        $stores->steamApp('1001');

        $this->assertSame(1, Http::recorded()->count());
    }

    public function test_check_again_asks_now(): void
    {
        $this->store();
        $game = Game::create(['name' => 'Some game', 'steam_id' => '1001']);
        $game->forceFill(['adult_checked_at' => now()->subYear()])->saveQuietly();
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->post(route('admin.games.adult.check', $game->id))
            ->assertSessionHas('success', 'Asked the stores about Some game: nothing found.');
        $this->assertTrue($game->refresh()->adult_checked_at->isToday());
    }
}
