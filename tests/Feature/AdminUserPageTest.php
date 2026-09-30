<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\Translation;
use App\Models\User;
use App\Support\StoreLinks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * An account's page in the admin — its "My translations" read by an admin — and the two orderings
 * of the games screen that used to read as broken.
 */
class AdminUserPageTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['is_admin' => true])->save();

        return $user;
    }

    private function translation(User $user, Game $game, string $visibility = 'public', ?string $uuid = null): Translation
    {
        return Translation::create([
            'user_id' => $user->id,
            'game_id' => $game->id,
            'title' => 'A translation',
            'source_language' => 'English',
            'target_language' => 'French',
            'file_path' => 'translations/' . uniqid() . '.json',
            'file_hash' => hash('sha256', uniqid()),
            'file_uuid' => $uuid ?? (string) Str::uuid(),
            'visibility' => $visibility,
        ]);
    }

    public function test_the_page_is_closed_to_everybody_else(): void
    {
        $user = User::factory()->create();

        $this->get(route('admin.users.show', $user))->assertRedirect();
        $this->actingAs(User::factory()->create())
            ->get(route('admin.users.show', $user))
            ->assertStatus(403);
    }

    public function test_it_lists_every_role_the_account_holds_and_nobody_elses(): void
    {
        $author = User::factory()->create(['name' => 'Author Person']);
        $owner = User::factory()->create();

        $mainGame = Game::create(['name' => 'Main Game']);
        $branchGame = Game::create(['name' => 'Branch Game']);
        $otherGame = Game::create(['name' => 'Somebody Else Game']);

        $this->translation($author, $mainGame);
        $theirMain = $this->translation($owner, $branchGame);
        // A branch is not public: the admin page shows it anyway, as every admin translation screen does.
        $this->translation($author, $branchGame, 'branch', $theirMain->file_uuid);
        $this->translation($owner, $otherGame);

        $this->actingAs($this->admin())
            ->get(route('admin.users.show', $author))
            ->assertOk()
            ->assertSee('Main Game')
            ->assertSee('Branch Game')
            ->assertDontSee('Somebody Else Game')
            ->assertSee('1 Main')
            ->assertSee('1 Branch');
    }

    public function test_every_sort_the_author_has_renders_here_too(): void
    {
        $author = User::factory()->create();
        $this->translation($author, Game::create(['name' => 'One']));
        $this->translation($author, Game::create(['name' => 'Two']));
        $admin = $this->admin();

        foreach (['updated', 'new', 'game', 'downloads', 'review', 'not-a-sort'] as $sort) {
            $this->actingAs($admin)
                ->get(route('admin.users.show', ['user' => $author, 'sort' => $sort]))
                ->assertOk();
        }
    }

    public function test_deleting_from_the_page_comes_back_to_it(): void
    {
        $author = User::factory()->create();
        $translation = $this->translation($author, Game::create(['name' => 'Gone Game']));

        $this->actingAs($this->admin())
            ->delete(route('admin.translations.destroy', $translation), ['return' => 'user'])
            ->assertRedirect(route('admin.users.show', $author));

        $this->assertNull(Translation::find($translation->id));
    }

    public function test_the_translations_list_leads_to_the_page(): void
    {
        $author = User::factory()->create();
        $this->translation($author, Game::create(['name' => 'Listed Game']));

        $this->actingAs($this->admin())
            ->get(route('admin.translations.index'))
            ->assertOk()
            ->assertSee(route('admin.users.show', $author), false);
    }

    public function test_the_adults_only_column_sorts_on_what_it_shows(): void
    {
        // Checked MORE recently than the marked one: sorting on the check date alone, which the
        // column never displays, put it first.
        $plain = Game::create(['name' => 'Plain Game']);
        $plain->forceFill(['adult_checked_at' => now()])->save();

        $marked = Game::create(['name' => 'Marked Game']);
        $marked->forceFill(['adult_override' => true, 'adult_checked_at' => now()->subYear()])->save();

        $this->actingAs($this->admin())
            ->get(route('admin.games', ['sort' => 'adult_checked_at', 'dir' => 'desc']))
            ->assertOk()
            ->assertSeeInOrder(['Marked Game', 'Plain Game']);
    }

    public function test_an_igdb_id_opens_the_games_igdb_page(): void
    {
        // IGDB's short address is the id in base 36 under /g/ — checked by hand: 1942 lands on
        // /games/the-witcher-3-wild-hunt.
        $this->assertSame('https://www.igdb.com/g/1hy', StoreLinks::igdbId('1942'));
        $this->assertNull(StoreLinks::igdbId(null));
        $this->assertNull(StoreLinks::igdbId('0'));
        $this->assertNull(StoreLinks::igdbId('12a'));
    }

    public function test_the_public_game_page_links_the_stores_it_knows(): void
    {
        $game = Game::create(['name' => 'Store Game', 'steam_id' => '2056210', 'igdb_id' => 1942]);
        $this->translation(User::factory()->create(), $game);

        $this->get(route('games.show', $game))
            ->assertOk()
            ->assertSee('https://store.steampowered.com/app/2056210/', false)
            ->assertSee('https://www.igdb.com/g/1hy', false);

        $bare = Game::create(['name' => 'Bare Game']);
        $this->translation(User::factory()->create(), $bare);

        $this->get(route('games.show', $bare))
            ->assertOk()
            ->assertDontSee('store.steampowered.com/app/', false)
            ->assertDontSee('igdb.com/g/', false);
    }
}
