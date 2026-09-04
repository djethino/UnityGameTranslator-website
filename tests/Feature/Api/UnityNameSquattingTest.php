<?php

namespace Tests\Feature\Api;

use App\Models\ApiToken;
use App\Models\Game;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * What an account may make the catalogue believe about a game's name.
 *
 * 🔴 **Every case here was reachable in production on 2026-09-04**, from any account, by publishing
 * one translation. `unity_name` had just become a resolution key read before the loose search; it
 * was filled from whatever the upload declared, on any game whose column was empty. So:
 *
 *   · declare the Steam id of a popular game and any product name, and that name became the key
 *     every other machine resolves with;
 *   · players of the real game — the ones with no Steam id, precisely those the column serves —
 *     were then shown the popular game's translations and offered them for install;
 *   · and their own uploads were filed under it.
 *
 * "Never overwrite" made it permanent instead of protecting anything. The guard is not to overwrite
 * better: it is that a game carrying a Steam id has no use for this column at all, since it is
 * resolved by that id before any name is read.
 */
class UnityNameSquattingTest extends TestCase
{
    use RefreshDatabase;

    private function publish(array $fields): \Illuminate\Testing\TestResponse
    {
        $token = ApiToken::createForUser(User::factory()->create(), 'test')->plain_token;

        return $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/v1/translations', array_merge([
                'source_language' => 'English',
                'target_language' => 'French',
                // ⚠ Unique per upload: the site refuses a file whose content it already holds.
                'content' => json_encode([
                    '_uuid' => (string) Str::uuid(),
                    'Hello ' . uniqid() => ['v' => 'Bonjour', 't' => 'H'],
                ]),
            ], $fields));
    }

    public function test_a_game_with_a_steam_id_never_takes_a_declared_name(): void
    {
        $popular = Game::create(['name' => 'A Popular Game', 'steam_id' => '424242']);

        $this->publish([
            'steam_id' => '424242',
            'game_name' => 'SomeoneElsesGame',
        ])->assertSuccessful();

        // The upload is filed under the game its Steam id names — that part is right and unchanged.
        // What must not happen is the declared string becoming the key others resolve with.
        $this->assertNull($popular->fresh()->unity_name);
    }

    public function test_a_game_with_a_steam_id_still_records_a_form_of_its_own_title(): void
    {
        // 🔴 **The case the column exists for, and refusing outright would have cost it.** A game
        // published from a Steam copy carries an id; a copy of that same game WITHOUT one — a
        // repack, a store that is not Steam — is exactly who needs the product name recorded.
        $game = Game::create(['name' => 'Lonestar: The Game', 'steam_id' => '505050']);

        $this->publish([
            'steam_id' => '505050',
            'game_name' => 'LONESTAR',
        ])->assertSuccessful();

        $this->assertSame('LONESTAR', $game->fresh()->unity_name);
    }

    public function test_a_longer_name_cannot_be_recorded_on_a_shorter_title(): void
    {
        // The direction matters: a product name is the tighter form of a shop title, never the
        // wider one. Accepting "Cattails" on a game called "Cat" is the squat, wearing a disguise.
        $cat = Game::create(['name' => 'Cat', 'steam_id' => '606060']);

        $this->publish([
            'steam_id' => '606060',
            'game_name' => 'Cattails',
        ])->assertSuccessful();

        $this->assertNull($cat->fresh()->unity_name);
    }

    public function test_a_name_another_game_answers_to_cannot_be_claimed(): void
    {
        // ⚠ **Why this case is narrow, and worth holding anyway.** Once a game carrying a Steam id
        // is refused, the only path left that WRITES the column reaches its game by display name —
        // so what gets written equals that display name, and there is nothing to divert. The one
        // remaining opening is two entries sharing a display name: the second would take a key the
        // first already answers to, and every lookup for that name would become ambiguous for good.
        $first = Game::create(['name' => 'Twin Title']);
        $first->update(['unity_name' => 'Twin Title']);

        $second = Game::create(['name' => 'Twin Title', 'slug' => 'twin-title-second']);

        $this->publish(['game_name' => 'Twin Title'])->assertSuccessful();

        $this->assertNull(
            $second->fresh()->unity_name,
            'a name another entry already answers to may not be taken by a second one'
        );
    }

    public function test_the_display_name_is_resolved_before_the_declared_one(): void
    {
        // Two games: one is called X, another merely claims X as its machine name. An upload for X
        // must reach the game actually called X.
        $real = Game::create(['name' => 'Real Game']);
        $claimer = Game::create(['name' => 'Claimer']);
        $claimer->update(['unity_name' => 'Real Game']);

        $this->publish(['game_name' => 'Real Game'])->assertSuccessful();

        $this->assertSame(1, $real->fresh()->translations()->count());
        $this->assertSame(0, $claimer->fresh()->translations()->count());
    }

    public function test_a_declared_name_cannot_hide_the_ordinary_search(): void
    {
        // The search used to answer with the exact match ALONE when there was one, so a claimed
        // name could hide every real candidate behind it.
        $claimer = Game::create(['name' => 'Zzz Claimer']);
        $claimer->update(['unity_name' => 'Adventure']);

        $real = Game::create(['name' => 'Adventure Quest']);

        $response = $this->getJson('/api/v1/translations?q=Adventure')->assertOk();

        $names = collect($response->json('games'))->pluck('game.name');

        $this->assertTrue($names->contains('Adventure Quest'), 'the ordinary match is still there');
        $this->assertSame(2, $response->json('games_total'), 'the claim widens, it never replaces');
    }

    public function test_a_batch_will_not_sweep_the_catalogue_for_one_letter_names(): void
    {
        for ($i = 0; $i < 30; $i++) {
            Game::create(['name' => "Sweepable Game {$i}"]);
        }

        // A hundred one-letter names used to load every matching game into memory, with no SQL
        // limit, twenty times a minute, from anybody.
        $games = [];
        foreach (range('a', 'z') as $letter) {
            $games[] = ['name' => $letter];
        }

        $response = $this->postJson('/api/v1/translations/for-games', ['games' => $games])->assertOk();

        foreach ($response->json('results') as $result) {
            $this->assertSame(0, $result['games_total'], 'a single letter identifies nothing');
        }
    }

    public function test_one_game_is_one_card_whatever_the_copy_came_from(): void
    {
        // 🔴 **The rule, stated by the owner: same game, same card — Steam, GOG, Epic, a disc.**
        //
        // A copy with no Steam id publishes under the product name its folder carries. Nothing in
        // the ordinary lookups can match it to a card created from a Steam copy under the shop
        // title, so the resolution asks IGDB — and creating on THAT answer without looking again is
        // what used to give one game a second entry.
        //
        // ⚠ The external lookup is stubbed, and it has to be: it needs credentials and a network,
        // so a test that let it fail would exercise the fallback and prove nothing about the guard.
        $this->mock(\App\Services\GameSearchService::class, function ($mock) {
            $mock->shouldReceive('findGame')->andReturn([
                'name' => 'Lonestar: The Game',
                'steam_id' => '707070',
                'image_url' => null,
            ]);
        });

        $existing = Game::create(['name' => 'Lonestar: The Game', 'steam_id' => '707070']);
        $before = Game::count();

        // The GOG copy: no id, and the name Unity wrote on disk.
        $this->publish(['game_name' => 'LONESTAR'])->assertSuccessful();

        $this->assertSame($before, Game::count(), 'a second copy of one game makes no second card');
        $this->assertSame(1, $existing->fresh()->translations()->count());

        // And the card learns what that copy could tell it: the name a machine reads.
        $this->assertSame('LONESTAR', $existing->fresh()->unity_name);
    }

    public function test_episodes_of_one_series_stay_apart(): void
    {
        // 🔴 **Measured on real installs, not assumed.** Frog Detective 1 calls itself "The Haunted
        // Island" in app.info, under the shop title "Frog Detective: The Haunted Island". A sequel
        // is a different Unity project with a different product name, so the two never compete for
        // one key — which is what makes a series safe here.
        $first = Game::create([
            'name' => 'Frog Detective: The Haunted Island',
            'steam_id' => '801001',
        ]);

        $second = Game::create([
            'name' => 'Frog Detective 2: The Case of the Invisible Wizard',
            'steam_id' => '801002',
        ]);

        // The first episode publishes: its product name is a form of ITS title, so it is recorded.
        $this->publish(['steam_id' => '801001', 'game_name' => 'The Haunted Island'])
            ->assertSuccessful();

        $this->assertSame('The Haunted Island', $first->fresh()->unity_name);

        // And it cannot land on the sequel: nothing of that name is in the sequel's title.
        $this->publish(['steam_id' => '801002', 'game_name' => 'The Haunted Island'])
            ->assertSuccessful();

        $this->assertNull(
            $second->fresh()->unity_name,
            'one episode may not record itself on another'
        );
    }

    public function test_a_series_name_shared_by_two_episodes_is_taken_only_once(): void
    {
        // ⚠ **The case that stays open, held here so it is known rather than discovered.** A
        // developer CAN reuse one product name across episodes — "Frog Detective" for both — and
        // then nothing on disk tells the two apart either. What the guard does is stop the second
        // entry taking a key the first already answers to.
        $first = Game::create(['name' => 'Some Series', 'steam_id' => '802001']);
        $first->update(['unity_name' => 'Some Series']);

        $second = Game::create(['name' => 'Some Series 2', 'steam_id' => '802002']);

        $this->publish(['steam_id' => '802002', 'game_name' => 'Some Series'])->assertSuccessful();

        $this->assertNull($second->fresh()->unity_name, 'the key belongs to the first that took it');
    }

    public function test_the_company_is_refused_when_it_is_too_long_for_the_column(): void
    {
        // It was read straight into a varchar(255) with no rule: in strict mode, a 500.
        $this->publish([
            'game_name' => 'Some Game',
            'game_company' => str_repeat('a', 300),
        ])->assertStatus(422);
    }
}
