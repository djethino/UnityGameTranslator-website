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
    private function storesKnow(array $steamHits, array $igdbRows = [], ?array $steamApp = null): void
    {
        $this->mock(GameSearchService::class, function ($mock) use ($steamHits, $igdbRows, $steamApp) {
            $mock->shouldReceive('steamSearch')->andReturn($steamHits);
            $mock->shouldReceive('igdb')->andReturn($igdbRows);
            $mock->shouldReceive('steamApp')->andReturn($steamApp);
        });
    }

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

        $this->mock(GameSearchService::class, function ($mock) {
            $mock->shouldNotReceive('steamSearch');
            $mock->shouldNotReceive('igdb');
            $mock->shouldNotReceive('steamApp');
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

    public function test_a_cover_comes_only_from_an_id_the_card_already_has(): void
    {
        $header = 'https://shared.akamai.steamstatic.com/store_item_assets/steam/apps/2503770/header.jpg';

        // A RAWG in-game screenshot is not store art: replaceable.
        $game = Game::create([
            'name' => 'House of Legacy',
            'steam_id' => '2503770',
            'igdb_id' => 1,
            'image_url' => 'https://media.rawg.io/media/screenshots/36d/36d0fad027bec526f9274e5a14f8c43d.jpg',
        ]);
        $this->storesKnow([], [], ['header_image' => $header]);

        app(StoreProposals::class)->check($game);
        $this->assertSame($header, GameProposal::where('field', 'image_url')->value('value'));

        // A card with no Steam id gets no cover proposed, even when its id is being proposed:
        // accepting the cover and rejecting the id would leave the cover of the wrong game.
        $other = Game::create(['name' => 'LoneStar', 'igdb_id' => 2]);
        $this->storesKnow([['id' => '2056210', 'name' => 'LONESTAR']], [], ['header_image' => $header]);

        app(StoreProposals::class)->check($other);
        $this->assertSame(0, GameProposal::where('game_id', $other->id)->where('field', 'image_url')->count());
    }

    public function test_a_store_cover_already_there_is_kept(): void
    {
        $game = Game::create([
            'name' => 'A Game', 'steam_id' => '111', 'igdb_id' => 1,
            'image_url' => 'https://images.igdb.com/igdb/image/upload/t_cover_big/co748v.jpg',
        ]);

        $this->mock(GameSearchService::class, fn ($mock) => $mock->shouldNotReceive('steamApp'));

        $this->assertSame(0, app(StoreProposals::class)->check($game));
    }

    public function test_a_title_in_another_script_is_not_sent_to_igdb_empty(): void
    {
        // IGDB's search only takes latin characters here; escaped, this title is nothing, and an
        // empty search would answer with whatever it likes.
        $game = Game::create(['name' => '轮回修仙路', 'steam_id' => '1993150']);

        $this->mock(GameSearchService::class, function ($mock) {
            $mock->shouldNotReceive('igdb');
            $mock->shouldReceive('steamApp')->andReturn(null);
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
}
