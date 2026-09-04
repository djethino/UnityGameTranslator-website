<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Game;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The one screen that can repair what a machine declared about a game.
 *
 * 🔴 **Written because nothing could.** `unity_name` is sent by whoever publishes and decides which
 * game other machines resolve to. Every guard around it refuses a bad value at the door — an id
 * that is not the game's, a name another entry answers to, a substring too banal to name anything
 * — and none of them could correct a value already stored. "Never overwrite" made a key taken by
 * mistake, or on purpose, final short of raw SQL.
 *
 * ⚠ Clearing is the main act here, not an afterthought: emptying the field is what unlocks a game,
 * since nothing overwrites a value that is there.
 */
class AdminGamesTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    public function test_the_screen_is_closed_to_everybody_else(): void
    {
        $game = Game::create(['name' => 'Some Game']);

        $this->get('/admin/games')->assertRedirect();

        $this->actingAs(User::factory()->create())
            ->post("/admin/games/{$game->id}/names", ['unity_name' => 'Whatever'])
            ->assertStatus(403);

        $this->assertNull($game->fresh()->unity_name);
    }

    public function test_an_admin_can_clear_a_key_that_was_taken_wrongly(): void
    {
        $game = Game::create(['name' => 'Some Game', 'steam_id' => '990001']);
        $game->update(['unity_name' => 'WrongKey', 'unity_company' => 'Wrong Studio']);

        $this->actingAs($this->admin())
            ->post("/admin/games/{$game->id}/names", ['unity_name' => '', 'unity_company' => ''])
            ->assertRedirect();

        $game->refresh();

        $this->assertNull($game->unity_name, 'clearing is what unlocks the game');
        $this->assertNull($game->unity_company);
    }

    public function test_an_admin_cannot_break_another_game_by_repairing_one(): void
    {
        $held = Game::create(['name' => 'Held Title', 'steam_id' => '990002']);
        $held->update(['unity_name' => 'HeldKey']);

        $other = Game::create(['name' => 'Other Game']);

        $this->actingAs($this->admin())
            ->post("/admin/games/{$other->id}/names", ['unity_name' => 'HeldKey'])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertNull($other->fresh()->unity_name);
        $this->assertSame('HeldKey', $held->fresh()->unity_name, 'the holder keeps it');
    }

    public function test_repairing_a_game_leaves_a_trace_and_moves_no_date(): void
    {
        $game = Game::create(['name' => 'Traced Game']);
        $was = $game->updated_at;

        $this->travel(2)->days();

        $this->actingAs($this->admin())
            ->post("/admin/games/{$game->id}/names", ['unity_name' => 'TracedKey'])
            ->assertRedirect();

        // What a machine resolves by decides where other people's uploads are filed, so a change
        // here is worth a trace — it is invisible everywhere else.
        $this->assertTrue(
            AuditLog::where('action', 'game.names_updated')->where('entity_id', $game->id)->exists()
        );

        // ⚠ And it must not re-date the catalogue: saveQuietly() silences events and still writes
        // updated_at, so only timestamps = false holds the date. Listings ordered by freshness
        // would otherwise reshuffle on an admin's correction.
        $this->assertEquals($was, $game->fresh()->updated_at);
    }
}
