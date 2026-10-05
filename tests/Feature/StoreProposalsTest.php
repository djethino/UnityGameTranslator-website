<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\GameProposal;
use App\Models\User;
use App\Services\GameSearchService;
use App\Services\StoreProposals;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What the stores may propose for a game card, and what an admin's answer holds.
 *
 * 🔴 **A title is a guess, an id is a fact.** Nothing found by title reaches a card without being
 * ticked on /admin/games, and a decision taken there — a Reject — is never put to the admin again
 * (asked on 2026-09-22: "il ne faut pas que ça me le repropose à chaque fois").
 */
class StoreProposalsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();

        return $admin;
    }

    /**
     * Steam answers this search with the game AND its add-ons — the shape it really has (measured
     * on 2026-09-22 for "Love n Life Happy Student").
     */
    private function storesKnow(array $steamHits, array $igdbRows = [], ?array $steamApp = null,
                                ?array $steamAssets = null, ?array $igdbCover = null, ?array $igdbBySteamId = null): void
    {
        $this->mock(GameSearchService::class, function ($mock) use ($steamHits, $igdbRows, $steamApp, $steamAssets, $igdbCover, $igdbBySteamId) {
            $mock->shouldReceive('steamSearch')->andReturn($steamHits);
            $mock->shouldReceive('igdb')->andReturn($igdbRows);
            $mock->shouldReceive('steamApp')->andReturn($steamApp);
            $mock->shouldReceive('steamAssets')->andReturn($steamAssets);
            $mock->shouldReceive('igdbCover')->andReturn($igdbCover);
            $mock->shouldReceive('getGameFromIgdbBySteamId')->andReturn($igdbBySteamId);
        });
    }

    private const SteamCover = 'https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/2503770/abc/library_600x900.jpg';
    private const SteamHeader = 'https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/2503770/abc/header.jpg';
    private const IgdbCover = 'https://images.igdb.com/igdb/image/upload/t_cover_big/co748v.jpg';

    public function test_an_exact_title_is_proposed_and_nothing_is_written(): void
    {
        $game = Game::create(['name' => 'LoneStar']);

        $this->storesKnow([
            ['id' => '2056210', 'name' => 'LONESTAR'],
            ['id' => '9999999', 'name' => 'LONESTAR - Soundtrack'],
        ]);

        app(StoreProposals::class)->check($game);

        $this->assertNull($game->refresh()->steam_id, 'a title match is never written directly');
        $this->assertSame(['2056210'], GameProposal::pending()->where('field', 'steam_id')->pluck('value')->all(),
            'only the EXACT title is proposed — the soundtrack is a neighbour');
    }

    public function test_every_proposal_carries_the_page_to_check_it_on(): void
    {
        $game = Game::create(['name' => 'Aviassembly']);

        $this->storesKnow(
            [['id' => '2660460', 'name' => 'Aviassembly']],
            [
                ['id' => 291217, 'name' => 'Aviassembly', 'url' => 'https://www.igdb.com/games/aviassembly'],
                // Same title, an address that is not IGDB's: the proposal stays, the link does not.
                ['id' => 999, 'name' => 'Aviassembly', 'url' => 'javascript:alert(1)'],
            ]
        );

        app(StoreProposals::class)->check($game);

        $this->assertSame('https://store.steampowered.com/app/2660460/',
            GameProposal::where('field', 'steam_id')->value('link'));
        $this->assertSame('https://www.igdb.com/games/aviassembly',
            GameProposal::where('field', 'igdb_id')->where('value', '291217')->value('link'));
        $this->assertNull(GameProposal::where('field', 'igdb_id')->where('value', '999')->value('link'),
            'a link a store answered is held to the shape it must have before it reaches an href');
    }

    public function test_a_value_already_on_the_card_is_never_asked_about(): void
    {
        $game = Game::create(['name' => 'A Game', 'steam_id' => '111', 'igdb_id' => 222, 'image_url' => 'https://images.igdb.com/cover.jpg']);

        // Its pictures are still read from its ids — that is not asking about a value.
        $this->mock(GameSearchService::class, function ($mock) {
            $mock->shouldNotReceive('steamSearch');
            $mock->shouldNotReceive('igdb');
            $mock->shouldNotReceive('steamApp');
            $mock->shouldNotReceive('getGameFromIgdbBySteamId');
            $mock->shouldReceive('steamAssets')->andReturnNull();
            $mock->shouldReceive('igdbCover')->andReturnNull();
        });

        $this->assertSame(0, app(StoreProposals::class)->check($game));
    }

    public function test_a_rejected_value_is_never_proposed_again(): void
    {
        $game = Game::create(['name' => 'LoneStar']);
        $this->storesKnow([['id' => '2056210', 'name' => 'LONESTAR']]);

        app(StoreProposals::class)->check($game);
        $proposal = GameProposal::first();

        $this->actingAs($this->admin())
            ->post(route('admin.games.proposals.reject', $proposal))
            ->assertRedirect();

        // The store keeps giving the same answer. It must not come back.
        $this->assertSame(0, app(StoreProposals::class)->check($game->refresh()));
        $this->assertSame(0, GameProposal::pending()->count());
        $this->assertSame(GameProposal::Rejected, $proposal->refresh()->state);

        // A DIFFERENT answer is new information, and is put to the admin.
        $this->storesKnow([['id' => '3000000', 'name' => 'LoneStar']]);
        $this->assertSame(1, app(StoreProposals::class)->check($game));
    }

    public function test_applying_writes_the_id_and_reads_the_adult_mark_from_it(): void
    {
        $game = Game::create(['name' => 'Love N Life: Happy Student']);

        $this->storesKnow(
            [['id' => '3149980', 'name' => 'Love n Life: Happy Student']],
            [],
            ['name' => 'Love n Life: Happy Student', 'content_descriptors' => ['ids' => [1, 3, 4, 5]]]
        );

        app(StoreProposals::class)->check($game);
        $proposal = GameProposal::where('field', 'steam_id')->first();

        $this->actingAs($this->admin())
            ->post(route('admin.games.proposals.apply'), ['proposals' => [$proposal->id]])
            ->assertRedirect()
            ->assertSessionHas('success');

        $game->refresh();
        $this->assertSame('3149980', $game->steam_id);
        $this->assertTrue($game->adult, 'the id is what the classification is read from');
        $this->assertSame(GameProposal::Applied, $proposal->refresh()->state);
    }

    public function test_a_value_another_card_carries_is_a_merge_and_cannot_be_applied(): void
    {
        Game::create(['name' => 'The Other Card', 'steam_id' => '2056210']);
        $game = Game::create(['name' => 'LoneStar']);
        $this->storesKnow([['id' => '2056210', 'name' => 'LONESTAR']]);

        app(StoreProposals::class)->check($game);
        $proposal = GameProposal::first();

        $this->assertFalse($proposal->isApplicable());

        $this->actingAs($this->admin())
            ->post(route('admin.games.proposals.apply'), ['proposals' => [$proposal->id]])
            ->assertSessionHas('error');

        $this->assertNull($game->refresh()->steam_id);
    }

    public function test_two_values_for_the_same_field_are_refused_whole(): void
    {
        $game = Game::create(['name' => 'Twin Title']);
        $this->storesKnow([
            ['id' => '100', 'name' => 'Twin Title'],
            ['id' => '200', 'name' => 'Twin Title'],
        ]);

        app(StoreProposals::class)->check($game);
        $ids = GameProposal::pluck('id')->all();
        $this->assertCount(2, $ids);

        $this->actingAs($this->admin())
            ->post(route('admin.games.proposals.apply'), ['proposals' => $ids])
            ->assertSessionHas('error');

        $this->assertNull($game->refresh()->steam_id, 'nothing is written when the selection is refused');
        $this->assertSame(2, GameProposal::pending()->count());
    }

    public function test_the_other_candidates_go_once_one_is_taken(): void
    {
        $game = Game::create(['name' => 'Twin Title']);
        $this->storesKnow([
            ['id' => '100', 'name' => 'Twin Title'],
            ['id' => '200', 'name' => 'Twin Title'],
        ]);

        app(StoreProposals::class)->check($game);

        $this->actingAs($this->admin())
            ->post(route('admin.games.proposals.apply'), ['proposals' => [GameProposal::where('value', '100')->value('id')]]);

        $this->assertSame('100', $game->refresh()->steam_id);
        $this->assertSame(0, GameProposal::pending()->count());
    }

    // ── the card's picture, by shape, from its own ids (2026-10-06) ─────────────────────────

    public function test_the_best_picture_of_the_cards_ids_is_written_with_its_banner(): void
    {
        // A RAWG in-game screenshot: the Steam portrait capsule replaces it, the header becomes
        // the banner. Written, not proposed: both are read from the card's own Steam id.
        $game = Game::create([
            'name' => 'House of Legacy', 'steam_id' => '2503770', 'igdb_id' => 1,
            'image_url' => 'https://media.rawg.io/media/screenshots/36d/36d0fad027bec526f9274e5a14f8c43d.jpg',
        ]);
        Game::whereKey($game->id)->update(['updated_at' => now()->subYear()]);
        $before = $game->refresh()->updated_at;

        $this->storesKnow([], [], null, ['cover' => self::SteamCover, 'banner' => self::SteamHeader],
            ['url' => self::IgdbCover, 'width' => 264, 'height' => 352]);

        $this->assertSame(0, app(StoreProposals::class)->checkOne($game));

        $game->refresh();
        $this->assertSame(self::SteamCover, $game->image_url);
        $this->assertSame(self::SteamHeader, $game->banner_url);
        $this->assertEquals($before, $game->updated_at, 'reading a store is not a change somebody made');
        $this->assertSame(0, GameProposal::count(), 'the batch writes the best and proposes nothing');
    }

    public function test_a_wide_picture_gives_way_to_a_cover_but_a_cover_never_to_a_banner(): void
    {
        // The Steam header a Steam pick created the card with: the IGDB cover replaces it.
        $wide = Game::create(['name' => 'Little Kitty', 'steam_id' => '1177980', 'igdb_id' => 145937, 'image_url' => self::SteamHeader]);
        $this->storesKnow([], [], null, ['cover' => null, 'banner' => self::SteamHeader],
            ['url' => self::IgdbCover, 'width' => 264, 'height' => 352]);
        app(StoreProposals::class)->checkOne($wide);
        $this->assertSame(self::IgdbCover, $wide->refresh()->image_url);

        // IGDB silent this time: the header found is NOT taken over the cover already there — a
        // store not answering must not flip a card sideways.
        $this->storesKnow([], [], null, ['cover' => null, 'banner' => self::SteamHeader], null);
        $wide->update(['name' => 'Little Kitty, Big City']);
        app(StoreProposals::class)->checkOne($wide->refresh());
        $this->assertSame(self::IgdbCover, $wide->refresh()->image_url);
    }

    public function test_a_picture_an_admin_chose_is_never_moved(): void
    {
        $game = Game::create(['name' => 'A Game', 'steam_id' => '111', 'igdb_id' => 1, 'image_url' => self::SteamHeader]);
        $game->forceFill(['image_chosen_at' => now()])->save();

        $this->storesKnow([], [], null, ['cover' => self::SteamCover, 'banner' => self::SteamHeader]);
        app(StoreProposals::class)->checkOne($game->refresh());

        $this->assertSame(self::SteamHeader, $game->refresh()->image_url);
    }

    public function test_check_again_lays_out_the_variants_unticked_and_applying_one_pins_it(): void
    {
        $game = Game::create(['name' => 'A Game', 'steam_id' => '2503770', 'igdb_id' => 1]);
        $this->storesKnow([], [], null, ['cover' => self::SteamCover, 'banner' => self::SteamHeader],
            ['url' => self::IgdbCover, 'width' => 264, 'height' => 352]);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.games.check-stores.one', $game->id))->assertRedirect();

        $this->assertSame(self::SteamCover, $game->refresh()->image_url, 'the best is written');
        $variants = GameProposal::pending()->where('field', 'image_url')->pluck('value')->sort()->values()->all();
        $this->assertSame([self::IgdbCover, self::SteamHeader], $variants, 'every OTHER picture is proposed');

        // Never ticked: an Apply meant for an id must not change the picture on the way.
        $html = $this->get(route('admin.games'))->assertOk()->getContent();
        foreach (GameProposal::pending()->where('field', 'image_url')->pluck('id') as $id) {
            $this->assertSame(1, preg_match('~<input[^>]*name="proposals\[\]" value="' . $id . '"[^>]*>~', $html, $tag));
            $this->assertStringNotContainsString('checked', $tag[0]);
        }

        $this->post(route('admin.games.proposals.apply'), ['proposals' => [GameProposal::where('value', self::IgdbCover)->value('id')]])
            ->assertSessionHas('success');
        $game->refresh();
        $this->assertSame(self::IgdbCover, $game->image_url);
        $this->assertNotNull($game->image_chosen_at);

        // The next check keeps the admin's choice.
        app(StoreProposals::class)->checkOne($game);
        $this->assertSame(self::IgdbCover, $game->refresh()->image_url);
    }

    public function test_a_card_without_ids_is_given_no_picture_from_a_proposed_one(): void
    {
        // A picture from a PROPOSED id could be accepted while the id is rejected — the picture of
        // the wrong game. It waits for the id to be applied.
        $game = Game::create(['name' => 'LoneStar']);
        $this->mock(GameSearchService::class, function ($mock) {
            $mock->shouldReceive('steamSearch')->andReturn([['id' => '2056210', 'name' => 'LONESTAR']]);
            $mock->shouldReceive('igdb')->andReturn([]);
            $mock->shouldNotReceive('steamAssets');
            $mock->shouldNotReceive('igdbCover');
        });

        app(StoreProposals::class)->check($game);
        $this->assertNull($game->refresh()->image_url);
    }

    // ── the IGDB id read from the Steam id (2026-10-06) ─────────────────────────────────────

    public function test_the_igdb_id_of_a_steam_card_is_written_when_igdb_links_exactly_one_game(): void
    {
        $game = Game::create(['name' => 'Aviassembly', 'steam_id' => '2660460']);
        $this->storesKnow([], [], null, null, null, ['id' => 291217, 'name' => 'Aviassembly']);

        app(StoreProposals::class)->checkOne($game);

        $this->assertSame('291217', (string) $game->refresh()->igdb_id);
        $this->assertSame(0, GameProposal::where('field', 'igdb_id')->count(), 'read by id: nothing to propose by title');
    }

    public function test_an_igdb_id_another_card_holds_is_never_written(): void
    {
        Game::create(['name' => 'The Other Card', 'igdb_id' => 291217]);
        $game = Game::create(['name' => 'Aviassembly', 'steam_id' => '2660460']);
        $this->storesKnow([], [], null, null, null, ['id' => 291217, 'name' => 'Aviassembly']);

        app(StoreProposals::class)->checkOne($game);

        $this->assertNull($game->refresh()->igdb_id, 'one game two cards would be a merge');
    }

    public function test_a_title_in_another_script_is_asked_of_igdb_whole(): void
    {
        // 🔴 Guarded against the query language, never against a language (2026-10-05): until then
        // this title escaped to nothing and its card never got IGDB's proposals.
        $game = Game::create(['name' => '轮回修仙路', 'steam_id' => '1993150']);

        $this->mock(GameSearchService::class, function ($mock) {
            $mock->shouldReceive('igdb')->once()
                 ->with('games', \Mockery::on(fn ($body) => str_contains($body, 'search "轮回修仙路"')))
                 ->andReturn([]);
            $mock->shouldReceive('steamApp')->andReturn(null);
            $mock->shouldReceive('getGameFromIgdbBySteamId')->andReturnNull();
            $mock->shouldReceive('steamAssets')->andReturnNull();
        });

        $this->assertSame(0, app(StoreProposals::class)->check($game));
    }

    public function test_a_title_that_escapes_to_nothing_is_not_sent_to_igdb_empty(): void
    {
        // An empty search would answer with whatever IGDB likes.
        $game = Game::create(['name' => '"*;|', 'steam_id' => '1993150']);

        $this->mock(GameSearchService::class, function ($mock) {
            $mock->shouldNotReceive('igdb');
            $mock->shouldReceive('steamApp')->andReturn(null);
            $mock->shouldReceive('getGameFromIgdbBySteamId')->andReturnNull();
            $mock->shouldReceive('steamAssets')->andReturnNull();
        });

        $this->assertSame(0, app(StoreProposals::class)->check($game));
    }

    public function test_asking_the_stores_does_not_touch_the_cards_dates(): void
    {
        $game = Game::create(['name' => 'LoneStar']);
        Game::whereKey($game->id)->update(['updated_at' => now()->subYear()]);
        $before = $game->refresh()->updated_at;

        $this->storesKnow([['id' => '2056210', 'name' => 'LONESTAR']]);

        $this->actingAs($this->admin())->post(route('admin.games.check-stores'))->assertRedirect();

        $game->refresh();
        $this->assertNotNull($game->stores_checked_at);
        $this->assertEquals($before, $game->updated_at, 'a check is not a change to the card');
    }

    public function test_the_screen_shows_what_would_change_and_filters_on_it(): void
    {
        $proposed = Game::create(['name' => 'LoneStar']);
        Game::create(['name' => 'Nothing To Propose', 'steam_id' => '1', 'igdb_id' => 1, 'image_url' => 'https://images.igdb.com/x.jpg']);

        $this->storesKnow([['id' => '2056210', 'name' => 'LONESTAR']]);
        app(StoreProposals::class)->check($proposed);

        $this->actingAs($this->admin());

        // The value, what the store calls the game, and the page to check it on — an id nobody can
        // open is an id nobody should accept.
        $this->get(route('admin.games'))
            ->assertOk()
            ->assertSee('2056210')
            ->assertSee('LONESTAR')
            ->assertSee('https://store.steampowered.com/app/2056210/', false);

        $this->get(route('admin.games', ['proposals' => 'pending']))
            ->assertSee('LoneStar')
            ->assertDontSee('Nothing To Propose');
    }

    public function test_checking_the_stores_lands_on_the_cards_with_something_to_decide(): void
    {
        // With thirty games a page, the proposals landed wherever the admin had been — often on a
        // page nobody was looking at (asked on 2026-09-30).
        Game::create(['name' => 'LoneStar']);
        $this->storesKnow([['id' => '2056210', 'name' => 'LONESTAR']]);

        $from = route('admin.games', ['search' => 'Lone', 'sort' => 'name', 'dir' => 'asc', 'page' => 3]);

        $this->actingAs($this->admin())
            ->from($from)
            ->post(route('admin.games.check-stores'))
            ->assertRedirect(route('admin.games', ['search' => 'Lone', 'sort' => 'name', 'dir' => 'asc', 'proposals' => 'pending']));

        // And the filtered list says so, with the way back to every game — the rest kept.
        $this->get(route('admin.games', ['search' => 'Lone', 'proposals' => 'pending']))
            ->assertSee('Only games with pending proposals')
            ->assertSee(route('admin.games', ['search' => 'Lone']), false);
    }

    public function test_a_second_check_says_what_is_still_waiting_not_only_what_is_new(): void
    {
        // "0 new" alone read as "the stores found nothing" beside proposals waiting on screen (asked
        // 2026-09-30). Since 2026-10-05 the second click asks nothing — the card has not changed —
        // and says so, with what still waits.
        $game = Game::create(['name' => 'LoneStar']);
        $this->storesKnow([['id' => '2056210', 'name' => 'LONESTAR']]);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.games.check-stores'))
            ->assertSessionHas('success', 'Asked the stores about 1 game: 1 new proposal. 1 proposal waiting on 1 game.');

        $this->actingAs($admin)->post(route('admin.games.check-stores'))
            ->assertSessionHas('success', 'No game has a new question for the stores. Use Check again on a game to ask about it anyway. 1 proposal waiting on 1 game.');

        // Asked again on purpose: the same value found again, nothing new, the waiting one said.
        $this->actingAs($admin)->post(route('admin.games.check-stores.one', $game->id))
            ->assertSessionHas('success', 'Asked the stores about LoneStar: nothing new.');
    }

    public function test_with_nothing_proposed_the_admin_stays_where_they_were(): void
    {
        Game::create(['name' => 'LoneStar']);
        $this->storesKnow([]);

        $from = route('admin.games', ['page' => 2]);

        $this->actingAs($this->admin())
            ->from($from)
            ->post(route('admin.games.check-stores'))
            ->assertRedirect($from);
    }

    // ── when the stores are asked again (2026-10-05) ────────────────────────────────────────

    public function test_a_card_asked_once_is_not_asked_again_until_it_changes(): void
    {
        $unknown = Game::create(['name' => 'A title no store knows']);
        $calls = 0;
        $this->mock(GameSearchService::class, function ($mock) use (&$calls) {
            $mock->shouldReceive('steamSearch')->andReturnUsing(function () use (&$calls) { $calls++; return []; });
            $mock->shouldReceive('igdb')->andReturnUsing(function () use (&$calls) { $calls++; return []; });
            $mock->shouldReceive('steamApp')->andReturn(null);
        });
        $proposals = app(StoreProposals::class);

        $this->assertSame(1, $proposals->checkDue()['checked']);
        $asked = $calls;
        $this->assertGreaterThan(0, $asked);

        // The same question, the same empty answer: not asked again.
        $result = $proposals->checkDue();
        $this->assertSame([0, 0], [$result['checked'], $result['left']]);
        $this->assertSame($asked, $calls, 'no store asked twice the same question');

        // Renamed: a new question, so due again.
        $unknown->refresh()->update(['name' => 'Its real title']);
        $this->assertNull($unknown->refresh()->stores_checked_at);
        $this->assertSame(1, $proposals->checkDue()['checked']);
    }

    public function test_check_again_asks_now_and_is_offered_on_every_card_asked_once(): void
    {
        // On a complete card too, since 2026-10-06: it lays out the pictures to choose another.
        $open = Game::create(['name' => 'Open card']);
        $complete = Game::create(['name' => 'Complete card', 'steam_id' => '100', 'igdb_id' => 200,
            'image_url' => 'https://cdn.example/cover.jpg']);
        $this->storesKnow([['id' => '555', 'name' => 'Open card']]);
        app(StoreProposals::class)->checkDue();

        $page = $this->actingAs($this->admin())->get(route('admin.games'))->assertOk();
        $page->assertSee(route('admin.games.check-stores.one', $open->id), false);
        $page->assertSee(route('admin.games.check-stores.one', $complete->id), false);

        GameProposal::query()->delete();
        $this->post(route('admin.games.check-stores.one', $open->id))->assertRedirect();
        $this->assertSame(['555'], GameProposal::pending()->where('game_id', $open->id)->pluck('value')->all());
    }
}
