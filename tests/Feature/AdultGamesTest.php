<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\Translation;
use App\Models\User;
use App\Services\AdultRating;
use App\Services\GameSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Games for adults only: who decides, and who is shown them.
 *
 * 🔴 **Two rules hold the whole design, and each has its cases here.**
 *
 * ① **Only an admin can say "no".** Detection and a contributor's declaration can merely raise the
 * flag, so two contributors can never contradict each other — and a declaration can rescue a game
 * the stores said nothing about, which is not a hypothetical: a game whose adult content ships as
 * a separate free DLC carries no descriptor of its own (measured on Steam app 3149980, 2026-09-22).
 *
 * ② **Discovery is filtered, access never is.** A listing leaves these games out; a game's own
 * page, and every lookup by app id — which is the only way the mod and the Manager ask — answers
 * exactly as before. Hiding a translation from somebody already playing the game would be absurd.
 */
class AdultGamesTest extends TestCase
{
    use RefreshDatabase;

    private function game(array $attributes = []): Game
    {
        $game = Game::create(array_merge([
            'name' => 'Ordinary Game ' . uniqid(),
        ], array_diff_key($attributes, array_flip(['adult_override', 'adult_detected', 'adult_detected_source']))));

        // The adult columns are out of $fillable on purpose — nothing must be able to set them
        // through a mass assignment — so a test states them the way the application does.
        foreach (['adult_override', 'adult_detected', 'adult_detected_source'] as $column) {
            if (array_key_exists($column, $attributes)) {
                $game->{$column} = $attributes[$column];
            }
        }

        $game->save();

        return $game->refresh();
    }

    /**
     * A game holding one published translation, so it has a place in the catalogue at all.
     */
    private function listedGame(array $attributes = [], ?User $author = null): Game
    {
        $game = $this->game($attributes);

        $path = 'translations/test-' . uniqid() . '.json';
        Storage::disk('local')->put($path, json_encode(['Hello' => ['v' => 'Bonjour', 't' => 'H']]));

        (new Translation())->forceFill([
            'game_id' => $game->id,
            'user_id' => ($author ?? User::factory()->create())->id,
            'source_language' => 'English',
            'target_language' => 'French',
            'visibility' => 'public',
            'file_uuid' => (string) Str::uuid(),
            'file_path' => $path,
            'file_hash' => 'hash-' . uniqid(),
            'line_count' => 10,
            'human_count' => 10,
            'status' => 'complete',
        ])->save();

        return $game;
    }

    // ---------------------------------------------------------------- the verdict

    public function test_a_declaration_marks_a_game_the_stores_said_nothing_about(): void
    {
        $game = $this->game(['adult_detected' => false]);
        $this->assertFalse($game->adult);

        $game->adult_declared_by = User::factory()->create()->id;
        $game->adult_declared_at = now();
        $game->save();

        $this->assertTrue($game->refresh()->adult);
        $this->assertSame('contributor', $game->adultSource());
    }

    public function test_only_the_admin_can_take_a_game_out_of_the_mark(): void
    {
        $game = $this->game(['adult_detected' => true, 'adult_detected_source' => 'steam']);
        $this->assertTrue($game->adult);

        // A later declaration cannot contradict it — and cannot lower it either.
        $game->adult_declared_by = User::factory()->create()->id;
        $game->adult_declared_at = now();
        $game->save();
        $this->assertTrue($game->refresh()->adult);

        $game->adult_override = false;
        $game->save();
        $this->assertFalse($game->refresh()->adult, 'the admin is the only word that can say no');

        // And clearing the override hands the game back to what the sources say, rather than
        // pinning the answer for ever.
        $game->adult_override = null;
        $game->save();
        $this->assertTrue($game->refresh()->adult);
    }

    public function test_an_admin_unmark_survives_every_later_detection(): void
    {
        // Asked on 2026-09-22: "si j'ai déjà changé une fois après une modération, il ne faut pas
        // que ça me le repropose à chaque fois". Detection keeps running — nightly, and on every
        // new Steam id — and the store keeps saying "adults only". The admin's word stands.
        Http::fake([
            'store.steampowered.com/*' => Http::response([
                '777' => ['success' => true, 'data' => [
                    'name' => 'A Game',
                    'content_descriptors' => ['ids' => [1, 3, 4, 5]],
                ]],
            ]),
        ]);

        $game = $this->game(['name' => 'A Game', 'steam_id' => '777', 'adult_override' => false]);

        foreach ([false, true, false] as $quiet) {
            app(AdultRating::class)->rate($game, quiet: $quiet);
            $game->refresh();

            $this->assertTrue($game->adult_detected, 'detection still reads the store');
            $this->assertFalse($game->adult, 'and never overrules the admin');
        }
    }

    public function test_the_mark_cites_the_store_rather_than_the_admin_who_agreed_with_it(): void
    {
        $game = $this->game([
            'adult_detected' => true,
            'adult_detected_source' => 'steam_dlc',
            'adult_override' => true,
        ]);

        $this->assertSame('steam_dlc', $game->adultSource(), 'the admin screen keeps the detail');
        $this->assertSame('steam', $game->adultCitation(), 'a reader is told the store said it');
    }

    // ---------------------------------------------------------------- detection

    public function test_an_adult_only_descriptor_on_the_game_itself_marks_it(): void
    {
        Http::fake([
            'store.steampowered.com/*' => Http::response([
                '111' => ['success' => true, 'data' => [
                    'name' => 'A Game',
                    'content_descriptors' => ['ids' => [1, 3, 4, 5]],
                ]],
            ]),
        ]);

        $this->assertSame('steam', app(AdultRating::class)->judge('111', null, 'A Game'));
    }

    public function test_an_adult_only_descriptor_carried_by_a_dlc_marks_the_game(): void
    {
        // 🔴 The case the whole DLC loop exists for, and it is real: Steam app 3149980 answers an
        // EMPTY descriptor list while its free 18+ DLC carries [1,3,4,5]. Reading the base app
        // alone declares that game all-ages.
        Http::fake([
            'store.steampowered.com/api/appdetails?appids=222*' => Http::response([
                '222' => ['success' => true, 'data' => [
                    'name' => 'Clean Looking Game',
                    'content_descriptors' => ['ids' => []],
                    // Repeated on purpose: Steam really does repeat an id in that list.
                    'dlc' => [333, 333],
                ]],
            ]),
            'store.steampowered.com/api/appdetails?appids=333*' => Http::response([
                '333' => ['success' => true, 'data' => [
                    'name' => 'Clean Looking Game - Free Adult Content (18+)',
                    'content_descriptors' => ['ids' => [1, 3, 4, 5]],
                ]],
            ]),
        ]);

        $this->assertSame('steam_dlc', app(AdultRating::class)->judge('222', null, 'Clean Looking Game'));
    }

    public function test_the_descriptors_mainstream_games_carry_mark_nothing(): void
    {
        // Measured on 2026-09-22: The Witcher 3 answers [1,5] and GTA V [5]. Marking on either
        // would hide them, which is not the question anybody asked.
        foreach ([[1, 5], [5], [2, 5], [1, 2, 5]] as $descriptors) {
            Http::fake([
                'store.steampowered.com/*' => Http::response([
                    '444' => ['success' => true, 'data' => [
                        'name' => 'Mainstream Game',
                        'content_descriptors' => ['ids' => $descriptors],
                    ]],
                ]),
            ]);

            $this->assertNull(
                app(AdultRating::class)->judge('444', null, 'Mainstream Game'),
                'descriptors ' . implode(',', $descriptors) . ' must not mark a game'
            );
        }
    }

    public function test_igdb_is_asked_only_when_the_store_could_not_answer(): void
    {
        $answered = ['success' => true, 'data' => ['name' => 'A Game', 'content_descriptors' => ['ids' => []]]];

        // The store answered and said nothing: its answer stands, IGDB is never asked — otherwise
        // a game sold censored on Steam would be marked on its uncensored release elsewhere.
        $this->mock(GameSearchService::class, function ($mock) use ($answered) {
            $mock->shouldReceive('steamApp')->once()->andReturn($answered['data']);
            $mock->shouldNotReceive('igdb');
        });

        $this->assertNull(app(AdultRating::class)->judge('555', null, 'A Game'));

        // The store could not answer (a delisted app): IGDB is the fallback, and theme 42 marks it.
        $this->mock(GameSearchService::class, function ($mock) {
            $mock->shouldReceive('steamApp')->andReturnNull();
            $mock->shouldReceive('igdb')->andReturn([['name' => 'A Game', 'themes' => [42]]]);
        });

        $this->assertSame('igdb', app(AdultRating::class)->judge('555', null, 'A Game'));
    }

    public function test_a_loose_igdb_hit_on_another_game_marks_nothing(): void
    {
        // IGDB's search answers with neighbours. Accepting one would mark a game on somebody
        // else's classification — the one failure this design exists to avoid.
        $this->mock(GameSearchService::class, function ($mock) {
            $mock->shouldReceive('igdb')->andReturn([
                ['name' => 'A Game - Adult Add-On', 'themes' => [42]],
            ]);
        });

        $this->assertNull(app(AdultRating::class)->judge(null, null, 'A Game'));
    }

    public function test_a_title_spelled_differently_is_still_the_same_game(): void
    {
        $this->mock(GameSearchService::class, function ($mock) {
            $mock->shouldReceive('igdb')->andReturn([
                ['name' => 'Love n Life: Happy Student', 'themes' => [42]],
            ]);
        });

        $this->assertSame('igdb', app(AdultRating::class)->judge(null, null, 'Love N Life: Happy Student'));
    }

    // ---------------------------------------------------------------- the catalogue

    public function test_the_catalogue_leaves_them_out_and_brings_them_back_when_asked(): void
    {
        $ordinary = $this->listedGame(['name' => 'An Ordinary Game']);
        $marked = $this->listedGame(['name' => 'A Marked Game', 'adult_override' => true]);

        $this->get(route('games.index'))
            ->assertOk()
            ->assertSee('An Ordinary Game')
            ->assertDontSee('A Marked Game');

        // Ticking the box shows them, and the choice follows the browsing session: the next page
        // carries no parameter and still shows them.
        $this->get(route('games.index', ['adult' => 1]))
            ->assertOk()
            ->assertSee('A Marked Game');

        $this->get(route('games.index'))->assertSee('A Marked Game');

        // And unticking puts them back out.
        $this->get(route('games.index', ['adult' => 0]))->assertDontSee('A Marked Game');
        $this->get(route('games.index'))->assertDontSee('A Marked Game');

        $this->assertNotNull($ordinary->id . $marked->id);
    }

    public function test_the_box_is_not_drawn_when_no_such_game_is_listed(): void
    {
        $this->listedGame(['name' => 'An Ordinary Game']);

        $this->get(route('games.index'))
            ->assertOk()
            ->assertDontSee(__('games.filter.adult'));

        $this->listedGame(['name' => 'A Marked Game', 'adult_override' => true]);

        $this->get(route('games.index'))
            ->assertOk()
            ->assertSee(__('games.filter.adult'));
    }

    public function test_an_account_that_asked_for_them_needs_no_box(): void
    {
        $this->listedGame(['name' => 'A Marked Game', 'adult_override' => true]);

        $reader = User::factory()->create();
        $reader->forceFill(['show_adult_games' => true])->save();

        $this->actingAs($reader)->get(route('games.index'))->assertSee('A Marked Game');
    }

    public function test_the_games_own_page_is_never_filtered_and_says_who_marked_it(): void
    {
        $game = $this->listedGame(['name' => 'A Marked Game', 'adult_detected' => true, 'adult_detected_source' => 'steam']);

        $this->get(route('games.show', $game))
            ->assertOk()
            ->assertSee('A Marked Game')
            ->assertSee(__('games.adult.mark'))
            ->assertSee(__('games.adult.source_steam'));
    }

    public function test_the_front_page_lists_leave_them_out(): void
    {
        $this->listedGame(['name' => 'A Marked Game', 'adult_override' => true]);

        $this->get('/')->assertOk()->assertDontSee('A Marked Game');
    }

    // ---------------------------------------------------------------- the API

    public function test_the_api_browse_leaves_them_out_and_a_lookup_by_app_id_does_not(): void
    {
        $marked = $this->listedGame([
            'name' => 'A Marked Game',
            'steam_id' => '909090',
            'adult_override' => true,
        ]);

        $this->getJson('/api/v1/games')
            ->assertOk()
            ->assertJsonMissing(['name' => 'A Marked Game']);

        $this->getJson('/api/v1/games?include_adult=1')
            ->assertOk()
            ->assertJsonFragment(['name' => 'A Marked Game']);

        // 🔴 The mod and the Manager only ever ask this way, about the game being played.
        $this->getJson('/api/v1/games?steam_id=909090')
            ->assertOk()
            ->assertJsonFragment(['name' => 'A Marked Game']);

        $this->getJson('/api/v1/games/' . $marked->slug)
            ->assertOk()
            ->assertJsonFragment(['name' => 'A Marked Game']);
    }

    public function test_the_translation_search_filters_a_browse_and_not_a_lookup(): void
    {
        $this->listedGame([
            'name' => 'A Marked Game',
            'steam_id' => '808080',
            'adult_override' => true,
        ]);

        $this->getJson('/api/v1/translations')
            ->assertOk()
            ->assertJsonMissing(['name' => 'A Marked Game']);

        $this->getJson('/api/v1/translations?steam_id=808080')
            ->assertOk()
            ->assertJsonFragment(['name' => 'A Marked Game']);
    }

    // ---------------------------------------------------------------- declaring

    /** Publish from a client, as the mod and the Manager do, under this account. */
    private function publishAs(User $user, array $fields): \Illuminate\Testing\TestResponse
    {
        $token = \App\Models\ApiToken::createForUser($user, 'test')->plain_token;

        return $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/v1/translations', array_merge([
                'source_language' => 'English',
                'target_language' => 'French',
                'content' => json_encode([
                    '_uuid' => (string) \Illuminate\Support\Str::uuid(),
                    'Hello ' . uniqid() => ['v' => 'Bonjour', 't' => 'H'],
                ]),
            ], $fields));
    }

    private function storesKnowNothing(): void
    {
        $this->mock(\App\Services\GameSearchService::class, function ($mock) {
            $this->storesSayNothingAboutAdultContent($mock);
            $mock->shouldReceive('findGame')->andReturnNull();
        });
    }

    public function test_the_first_publisher_declares_when_their_upload_creates_the_game(): void
    {
        $this->storesKnowNothing();
        $first = User::factory()->create();

        $this->publishAs($first, ['game_name' => 'A Game Nobody Knows', 'adult_declared' => true])
            ->assertSuccessful();

        $game = Game::where('name', 'A Game Nobody Knows')->firstOrFail();
        $this->assertTrue($game->adult);
        $this->assertSame($first->id, $game->adult_declared_by);
        $this->assertSame('contributor', $game->adultCitation());
    }

    public function test_a_later_translator_cannot_declare_a_game_that_already_exists(): void
    {
        // 🔴 The rule is about the GAME, and many people translate one game: the say belongs to
        // the first publisher alone. Sent for a game that exists, the field is ignored.
        $this->storesKnowNothing();
        $game = $this->listedGame(['name' => 'An Existing Game']);

        $this->publishAs(User::factory()->create(), ['game_name' => 'An Existing Game', 'adult_declared' => true])
            ->assertSuccessful();

        $this->assertFalse($game->refresh()->adult);
        $this->assertNull($game->adult_declared_at);
    }

    public function test_nobody_declares_from_the_site_any_more(): void
    {
        $author = User::factory()->create();
        $game = $this->listedGame(['name' => 'A Game'], $author);

        $this->actingAs($author)->post('/games/' . $game->slug . '/adult')->assertStatus(405);
        $this->assertFalse($game->refresh()->adult);
    }

    public function test_only_the_declarer_may_take_it_back(): void
    {
        $this->storesKnowNothing();
        $first = User::factory()->create();
        $this->publishAs($first, ['game_name' => 'A Declared Game', 'adult_declared' => true])->assertSuccessful();
        $game = Game::where('name', 'A Declared Game')->firstOrFail();

        // Another translator of the same game, and a passer-by: not theirs to undo.
        $other = User::factory()->create();
        $this->actingAs($other)->delete(route('games.adult.withdraw', $game))->assertForbidden();
        $this->assertTrue($game->refresh()->adult);

        $this->actingAs($first)->delete(route('games.adult.withdraw', $game))->assertRedirect();
        $this->assertFalse($game->refresh()->adult);
        $this->assertNull($game->adult_declared_by);
    }

    public function test_the_declarer_cannot_lower_a_mark_the_stores_gave(): void
    {
        // Withdrawing takes back one's OWN word; the store's stays.
        $first = User::factory()->create();
        $game = $this->listedGame(['name' => 'A Store Game', 'adult_detected' => true, 'adult_detected_source' => 'steam']);
        $game->declareAdultBy($first->id);

        $this->actingAs($first)->delete(route('games.adult.withdraw', $game))->assertRedirect();
        $this->assertTrue($game->refresh()->adult, 'Steam still says so');
    }

    public function test_the_publish_screen_is_told_whether_it_may_ask(): void
    {
        $this->storesKnowNothing();
        $token = \App\Models\ApiToken::createForUser(User::factory()->create(), 'test')->plain_token;
        $asked = fn (array $q) => $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/v1/games/adult?' . http_build_query($q));

        // A game nobody has published, and the stores find nothing: the box is offered.
        $asked(['game_name' => 'Brand New Game'])->assertOk()
            ->assertExactJson(['known' => false, 'adult' => false, 'source' => null, 'declarable' => true]);

        // A game already on the site: its state, and no box.
        $this->listedGame(['name' => 'Already Here', 'adult_override' => true]);
        $asked(['game_name' => 'Already Here'])->assertOk()
            ->assertExactJson(['known' => true, 'adult' => true, 'source' => 'admin', 'declarable' => false]);

        // Nobody signed in: not asked at all — the question costs the stores' quota.
        $this->withHeaders(['Authorization' => ''])->getJson('/api/v1/games/adult?game_name=X')->assertUnauthorized();
    }

    public function test_the_publish_screen_hears_what_the_stores_say_before_the_game_exists(): void
    {
        $this->mock(\App\Services\GameSearchService::class, function ($mock) {
            $mock->shouldReceive('findGame')->andReturn(['name' => 'A Store Title', 'steam_id' => '3149980']);
            $mock->shouldReceive('steamApp')->with('3149980')->andReturn(['content_descriptors' => ['ids' => [1, 3]]]);
            $mock->shouldReceive('igdb')->andReturn([]);
        });
        $token = \App\Models\ApiToken::createForUser(User::factory()->create(), 'test')->plain_token;

        $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/v1/games/adult?game_name=StoreTitle&steam_id=3149980')
            ->assertOk()
            ->assertExactJson(['known' => false, 'adult' => true, 'source' => 'steam', 'declarable' => false]);

        $this->assertSame(0, Game::count(), 'asking creates nothing');
    }

    public function test_the_admin_screen_says_which_source_decided(): void
    {
        $this->game(['name' => 'A Marked Game', 'adult_detected' => true, 'adult_detected_source' => 'steam_dlc']);
        $this->game(['name' => 'An Unasked Game']);

        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();

        $this->actingAs($admin)->get(route('admin.games'))
            ->assertOk()
            ->assertSee('steam_dlc')
            ->assertSee('never checked')
            ->assertSee('Unmark')
            ->assertSee('Clear');
    }

    public function test_the_admin_screen_filters_and_sorts(): void
    {
        $this->game(['name' => 'A Marked Game', 'adult_override' => true]);
        $this->game(['name' => 'An Ordinary Game', 'unity_name' => 'OrdinaryGame']);

        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $this->actingAs($admin);

        $this->get(route('admin.games', ['adult' => 'yes']))
            ->assertSee('A Marked Game')->assertDontSee('An Ordinary Game');

        $this->get(route('admin.games', ['adult' => 'no']))
            ->assertSee('An Ordinary Game')->assertDontSee('A Marked Game');

        // The pair this screen exists to repair.
        $this->get(route('admin.games', ['naming' => 'missing']))
            ->assertSee('A Marked Game')->assertDontSee('An Ordinary Game');

        $this->get(route('admin.games', ['naming' => 'set']))
            ->assertSee('An Ordinary Game')->assertDontSee('A Marked Game');

        // A sort the headers offer, and one they do not: an unknown column must fall back to the
        // screen's own order rather than reach the database.
        $this->get(route('admin.games', ['sort' => 'name', 'dir' => 'asc']))->assertOk();
        $this->get(route('admin.games', ['sort' => 'adult_checked_at', 'dir' => 'desc']))->assertOk();
        $this->get(route('admin.games', ['sort' => 'id; drop table games', 'dir' => 'asc']))->assertOk();
    }

    public function test_an_admin_can_take_the_mark_off_and_put_it_back(): void
    {
        $author = User::factory()->create();
        $game = $this->listedGame(['name' => 'A Game'], $author);
        $game->declareAdultBy($author->id);
        $this->assertTrue($game->refresh()->adult);

        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();

        $this->actingAs($admin)->post(route('admin.games.adult', $game->id), ['adult' => 'no']);
        $this->assertFalse($game->refresh()->adult);

        $this->actingAs($admin)->post(route('admin.games.adult', $game->id), ['adult' => 'clear']);
        $this->assertTrue($game->refresh()->adult, 'clearing hands the game back to the declaration');
    }
}
