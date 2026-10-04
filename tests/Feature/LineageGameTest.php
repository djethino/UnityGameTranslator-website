<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Game;
use App\Models\Translation;
use App\Models\User;
use App\Services\GameSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Changing the game a translation is filed under — the way out the person who published under the
 * wrong game never had (analyse/identite-des-jeux-parcours.md, T22).
 *
 * Who may (App\Services\LineageGame): the owner of a Main, its contributions following; the owner
 * of a fork, only towards the game of its original; a branch author, never; an admin, from /admin.
 */
class LineageGameTest extends TestCase
{
    use RefreshDatabase;

    private function row(Game $game, User $user, string $uuid, string $visibility = 'public', ?int $parent = null, ?int $origin = null): Translation
    {
        $t = new Translation();
        $t->forceFill([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'parent_id' => $parent,
            'origin_translation_id' => $origin,
            'source_language' => 'English',
            'target_language' => 'French',
            'file_path' => 'translations/not-read.json',
            'file_uuid' => $uuid,
            'visibility' => $visibility,
            'file_hash' => str_repeat('a', 64),
        ])->save();

        return $t->refresh();
    }

    private function storesQuiet(): void
    {
        $this->mock(GameSearchService::class, fn ($mock) => $this->storesSayNothingAboutAdultContent($mock));
    }

    public function test_the_owner_of_a_main_moves_it_with_its_contributions_and_no_date_moves(): void
    {
        $this->storesQuiet();
        $wrong = Game::create(['name' => 'Wrong Game']);
        $right = Game::create(['name' => 'Right Game', 'steam_id' => '500']);
        $owner = User::factory()->create();
        $main = $this->row($wrong, $owner, 'lineage-a');
        $branch = $this->row($wrong, User::factory()->create(), 'lineage-a', 'branch', $main->id);
        $updatedAt = $main->updated_at->toIso8601String();

        $this->actingAs($owner)
            ->post(route('translations.game', $main), ['game_pick' => ['source' => 'local', 'id' => $right->id]])
            ->assertRedirect(route('translations.edit', $main));

        $this->assertSame($right->id, $main->fresh()->game_id);
        $this->assertSame($right->id, $branch->fresh()->game_id, 'a lineage is never split across two cards');
        $this->assertSame($updatedAt, $main->fresh()->updated_at->toIso8601String());
        $this->assertTrue(AuditLog::where('action', 'translation.game_changed')->exists());
    }

    public function test_a_move_its_own_uploads_contradict_is_warned_then_confirmed(): void
    {
        // Published from Steam app 500 (said by the uploading machine); moved towards a card that is
        // Steam app 600. Warned — editions and wrong steam_appid.txt exist — never refused.
        $this->storesQuiet();
        $from = Game::create(['name' => 'Lost Echo', 'steam_id' => '500']);
        $to = Game::create(['name' => 'Crystal Dragon', 'steam_id' => '600']);
        $owner = User::factory()->create();
        $main = $this->row($from, $owner, 'lineage-w');
        $main->forceFill(['game_read' => ['product_name' => 'Lost Echo', 'steam_id' => '500']])->save();
        $asked = ['game_pick' => ['source' => 'local', 'id' => $to->id], 'game_name' => 'Crystal Dragon'];

        $this->actingAs($owner)->from(route('translations.edit', $main))
            ->post(route('translations.game', $main), $asked)
            ->assertRedirect(route('translations.edit', $main))
            ->assertSessionHas('game_contradiction', fn ($c) => $c['read'] === '500' && $c['game'] === 'Crystal Dragon');
        $this->assertSame($from->id, $main->fresh()->game_id, 'nothing moves before it is confirmed');

        $this->actingAs($owner)
            ->post(route('translations.game', $main), $asked + ['confirmed' => 1])
            ->assertRedirect(route('translations.edit', $main));
        $this->assertSame($to->id, $main->fresh()->game_id);
    }

    public function test_a_branch_author_cannot_move_a_lineage(): void
    {
        $game = Game::create(['name' => 'A Game']);
        $other = Game::create(['name' => 'Other Game']);
        $main = $this->row($game, User::factory()->create(), 'lineage-b');
        $author = User::factory()->create();
        $branch = $this->row($game, $author, 'lineage-b', 'branch', $main->id);

        $this->actingAs($author)
            ->post(route('translations.game', $branch), ['game_pick' => ['source' => 'local', 'id' => $other->id]])
            ->assertForbidden();

        $this->assertSame($game->id, $main->fresh()->game_id);
    }

    public function test_somebody_else_cannot_move_a_main(): void
    {
        $game = Game::create(['name' => 'A Game']);
        $other = Game::create(['name' => 'Other Game']);
        $main = $this->row($game, User::factory()->create(), 'lineage-c');

        $this->actingAs(User::factory()->create())
            ->post(route('translations.game', $main), ['game_pick' => ['source' => 'local', 'id' => $other->id]])
            ->assertForbidden();
    }

    public function test_a_fork_only_follows_the_game_of_its_original(): void
    {
        $this->storesQuiet();
        $old = Game::create(['name' => 'Old Card']);
        $moved = Game::create(['name' => 'Where The Original Went']);
        $elsewhere = Game::create(['name' => 'Anywhere Else']);
        $original = $this->row($moved, User::factory()->create(), 'lineage-d');
        $forker = User::factory()->create();
        $fork = $this->row($old, $forker, 'lineage-e', 'public', null, $original->id);

        // A pick of any other game is not what a fork may do: it goes where its original is.
        $this->actingAs($forker)
            ->post(route('translations.game', $fork), ['game_pick' => ['source' => 'local', 'id' => $elsewhere->id]])
            ->assertRedirect();

        $this->assertSame($moved->id, $fork->fresh()->game_id);
    }

    public function test_the_card_draws_only_the_act_that_can_succeed(): void
    {
        $game = Game::create(['name' => 'A Game']);
        $owner = User::factory()->create();
        $main = $this->row($game, $owner, 'lineage-f');
        $author = User::factory()->create();
        $branch = $this->row($game, $author, 'lineage-f', 'branch', $main->id);

        $this->actingAs($owner)->get(route('translations.edit', $main))
            ->assertOk()->assertSee('change_game_search', false);

        $this->actingAs($author)->get(route('translations.edit', $branch))
            ->assertOk()->assertDontSee('change_game_search', false);
    }

    public function test_an_admin_moves_any_lineage_from_admin(): void
    {
        $this->storesQuiet();
        $game = Game::create(['name' => 'A Game']);
        $other = Game::create(['name' => 'Other Game']);
        $main = $this->row($game, User::factory()->create(), 'lineage-g');
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->post(route('admin.translations.game', $main), ['game_pick' => ['source' => 'local', 'id' => $other->id]])
            ->assertRedirect(route('admin.translations.show', $main));

        $this->assertSame($other->id, $main->fresh()->game_id);
    }

    public function test_an_admin_removes_a_card_only_when_nothing_is_filed_under_it(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $empty = Game::create(['name' => 'Empty Card']);
        $held = Game::create(['name' => 'Held Card']);
        $main = $this->row($held, User::factory()->create(), 'lineage-h');
        $this->row($held, User::factory()->create(), 'lineage-h', 'branch', $main->id);

        $this->actingAs($admin)->delete(route('admin.games.destroy', $empty->id))->assertRedirect();
        $this->assertNull(Game::find($empty->id));

        $this->actingAs($admin)->delete(route('admin.games.destroy', $held->id))->assertRedirect();
        $this->assertNotNull(Game::find($held->id), 'the cascade would take its translations with it');
        $this->assertSame(2, Translation::where('game_id', $held->id)->count());
    }
}
