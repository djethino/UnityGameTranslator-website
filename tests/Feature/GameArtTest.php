<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\Game;
use App\Models\User;
use App\Services\GameArt;
use App\Services\GameSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A game card's picture is chosen by shape, from its own ids — the same choice at its creation, in
 * the publish list and on every stores check (user, 2026-10-06, analyse/images-des-jeux.md).
 */
class GameArtTest extends TestCase
{
    use RefreshDatabase;

    private const Capsule = 'https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/4242/0123456789abcdef0123456789abcdef01234567/library_600x900.jpg?t=1';
    private const Header = 'https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/4242/0123456789abcdef0123456789abcdef01234567/header.jpg?t=1';
    private const AppHeader = 'https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/4242/header.jpg';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.twitch.client_id' => 'c', 'services.twitch.client_secret' => 's', 'services.rawg.key' => 'k']);
        Cache::flush();
        Http::preventStrayRequests();
    }

    /**
     * Steam knows app 4242 with a header; `$capsule` says whether its item list has a library
     * capsule. `$items` adds other apps to the item list (`appid => [name, type, parent]`), and
     * `$search` is what Steam's title search answers.
     */
    private function stores(bool $capsule, array $igdbSearch = [], array $items = [], array $search = []): void
    {
        $item = fn (int $id, string $name, int $type, ?int $parent, bool $withCapsule) => array_filter([
            'appid' => $id, 'success' => 1, 'name' => $name, 'type' => $type,
            'related_items' => $parent ? ['parent_appid' => $parent] : null,
            'assets' => array_filter([
                'asset_url_format' => "steam/apps/{$id}/\${FILENAME}?t=1",
                'library_capsule' => $withCapsule ? '0123456789abcdef0123456789abcdef01234567/library_600x900.jpg' : null,
                'header' => '0123456789abcdef0123456789abcdef01234567/header.jpg',
            ]),
        ], fn ($v) => $v !== null);

        Http::fake([
            'store.steampowered.com/api/appdetails*' => Http::response(['4242' => ['success' => true, 'data' => [
                'name' => 'Lost Echo', 'type' => 'game', 'header_image' => self::AppHeader,
            ]]]),
            'store.steampowered.com/api/storesearch/*' => Http::response(['items' => $search]),
            // The item list answers the ids it is asked for, as Steam does.
            'api.steampowered.com/*' => function (Request $r) use ($item, $capsule, $items) {
                $asked = array_column(json_decode($r->data()['input_json'] ?? '{}', true)['ids'] ?? [], 'appid');
                $known = [4242 => $item(4242, 'Lost Echo', 0, null, $capsule)];
                foreach ($items as $id => [$name, $type, $parent]) {
                    $known[$id] = $item($id, $name, $type, $parent, true);
                }

                return Http::response(['response' => ['store_items' => array_values(array_intersect_key($known, array_flip($asked)))]]);
            },
            'id.twitch.tv/*' => Http::response(['access_token' => 't', 'expires_in' => 5_000_000]),
            'api.igdb.com/*' => fn (Request $r) => Http::response(str_contains($r->body(), 'search "') ? $igdbSearch : []),
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

    public function test_a_card_created_from_a_steam_pick_shows_the_capsule_and_keeps_the_header_as_banner(): void
    {
        $this->stores(capsule: true);

        $this->publish(['game_pick' => ['source' => 'steam', 'id' => '4242'], 'game_name' => 'Lost Echo'])->assertSuccessful();

        $game = Game::sole();
        $this->assertSame(self::Capsule, $game->image_url, 'the portrait, not the header the pick carried');
        $this->assertSame(self::Header, $game->banner_url);
    }

    public function test_without_a_capsule_the_card_keeps_a_wide_picture(): void
    {
        $this->stores(capsule: false);

        $this->publish(['game_pick' => ['source' => 'steam', 'id' => '4242'], 'game_name' => 'Lost Echo'])->assertSuccessful();

        // The same kind of picture from the same store at its current address: taken.
        $this->assertSame(self::Header, Game::sole()->image_url);
    }

    public function test_the_publish_list_shows_the_igdb_cover_folded_into_a_steam_row(): void
    {
        $this->stores(capsule: false, igdbSearch: [[
            'id' => 7, 'name' => 'Lost Echo',
            'cover' => ['url' => '//images.igdb.com/igdb/image/upload/t_thumb/co1.jpg', 'width' => 600, 'height' => 800],
            'external_games' => [['uid' => '4242', 'external_game_source' => 1]],
        ]]);

        $rows = app(GameSearchService::class)->searchFull('Lost Echo', '4242');

        $this->assertCount(1, $rows, 'one game, folded by its Steam id');
        $this->assertSame('steam', $rows[0]['source'], 'the row kept is still the one picked');
        $this->assertSame('https://images.igdb.com/igdb/image/upload/t_cover_big/co1.jpg', $rows[0]['image_url']);
        $this->assertArrayNotHasKey('_shape', $rows[0], 'internal, never handed out');
    }

    public function test_a_wide_igdb_cover_does_not_replace_a_steam_header_in_the_list(): void
    {
        $this->stores(capsule: false, igdbSearch: [[
            'id' => 7, 'name' => 'Lost Echo',
            'cover' => ['url' => '//images.igdb.com/igdb/image/upload/t_thumb/co1.jpg', 'width' => 800, 'height' => 450],
            'external_games' => [['uid' => '4242', 'external_game_source' => 1]],
        ]]);

        $rows = app(GameSearchService::class)->searchFull('Lost Echo', '4242');

        $this->assertSame(self::AppHeader, $rows[0]['image_url'], 'a sideways move is no better');
    }

    public function test_a_games_artbook_soundtrack_and_demo_are_the_game_in_the_list(): void
    {
        // Measured on Foretales (2026-10-06): three Steam apps of their own, each naming the game.
        $this->stores(capsule: true, items: [
            2080350 => ['Lost Echo - Artbook', 4, 4242],
            2080330 => ['Lost Echo - Soundtrack', 11, 4242],
            2012150 => ['Lost Echo Demo', 1, 4242],
        ], search: [
            ['id' => 2080350, 'name' => 'Lost Echo - Artbook', 'tiny_image' => 'https://shared.akamai.steamstatic.com/a.jpg'],
            ['id' => 2080330, 'name' => 'Lost Echo - Soundtrack', 'tiny_image' => 'https://shared.akamai.steamstatic.com/b.jpg'],
            ['id' => 2012150, 'name' => 'Lost Echo Demo', 'tiny_image' => 'https://shared.akamai.steamstatic.com/c.jpg'],
        ]);

        $rows = app(GameSearchService::class)->searchFull('Lost Echo');

        $this->assertCount(1, $rows, 'one game, whatever Steam sells beside it');
        $this->assertSame('4242', (string) $rows[0]['steam_id']);
        $this->assertSame('Lost Echo', $rows[0]['name']);
        $this->assertSame(self::Capsule, $rows[0]['image_url'], 'with its portrait capsule');
    }

    public function test_the_same_name_outranks_an_add_on_that_contains_it(): void
    {
        // The site's order follows the mod's and the Manager's (common GameCandidates.Confidence).
        $this->stores(capsule: false, igdbSearch: [[
            'id' => 7, 'name' => 'Lost Echo',
            'external_games' => [['uid' => '4242', 'external_game_source' => 1]],
        ]], search: [
            ['id' => 9999, 'name' => 'Lost Echo - Artbook', 'tiny_image' => 'https://shared.akamai.steamstatic.com/a.jpg'],
        ]);

        $rows = app(GameSearchService::class)->searchFull('Lost Echo');

        $this->assertSame('Lost Echo', $rows[0]['name']);
    }

    public function test_only_a_better_picture_replaces_the_one_shown(): void
    {
        $steamCover = ['url' => self::Capsule, 'source' => 'steam', 'shape' => 'portrait', 'rank' => GameArt::SteamCover];
        $banner = ['url' => self::Header, 'source' => 'steam', 'shape' => 'wide', 'rank' => GameArt::SteamBanner];
        $igdb = 'https://images.igdb.com/igdb/image/upload/t_cover_big/co1.jpg';

        $this->assertTrue(GameArt::shouldReplace(null, $banner, [$banner]), 'nothing shown');
        $this->assertTrue(GameArt::shouldReplace('https://media.rawg.io/media/screenshots/a.jpg', $banner, [$banner]), 'a RAWG screenshot');
        $this->assertTrue(GameArt::shouldReplace($igdb, $steamCover, [$steamCover, $banner]), 'the Steam capsule ranks first');
        $this->assertFalse(GameArt::shouldReplace($igdb, $banner, [$banner]), 'a cover never gives way to a banner');
        $this->assertTrue(GameArt::shouldReplace(self::AppHeader, $banner, [$banner]), 'the same banner at its new address');
        $this->assertFalse(GameArt::shouldReplace(self::Header, $banner, [$banner]), 'already shown');
        $this->assertFalse(GameArt::shouldReplace($igdb, null, []), 'nothing found keeps what is there');
    }
}
