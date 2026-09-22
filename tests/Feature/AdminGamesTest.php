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
 * ⚠ **Clearing is the only act** (decided 2026-09-22): the value comes from the game's own files,
 * which an admin does not have. Emptying it is what unlocks a game, since nothing overwrites a
 * value that is there; typing one would be guessing.
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
        $game->update(['unity_name' => 'SomeKey']);

        $this->get('/admin/games')->assertRedirect();

        $this->actingAs(User::factory()->create())
            ->delete("/admin/games/{$game->id}/names")
            ->assertStatus(403);

        $this->assertSame('SomeKey', $game->fresh()->unity_name);
    }

    public function test_an_admin_can_clear_a_key_that_was_taken_wrongly(): void
    {
        $game = Game::create(['name' => 'Some Game', 'steam_id' => '990001']);
        $game->update(['unity_name' => 'WrongKey', 'unity_company' => 'Wrong Studio']);

        $this->actingAs($this->admin())
            ->delete("/admin/games/{$game->id}/names")
            ->assertRedirect();

        $game->refresh();

        $this->assertNull($game->unity_name, 'clearing is what unlocks the game');
        $this->assertNull($game->unity_company);
    }

    public function test_no_name_can_be_typed_in_here(): void
    {
        // The value lives in the game's files; an admin writing one would be guessing, and a wrong
        // key files other people's uploads under the wrong card. There is no door for it at all.
        $game = Game::create(['name' => 'Other Game']);

        $this->actingAs($this->admin())
            ->post("/admin/games/{$game->id}/names", ['unity_name' => 'Typed', 'unity_company' => 'Typed'])
            ->assertStatus(405);

        $this->assertNull($game->fresh()->unity_name);
    }

    public function test_the_screen_shows_the_name_and_offers_clear_only_when_there_is_one(): void
    {
        $named = Game::create(['name' => 'Named Game']);
        $named->update(['unity_name' => 'NamedKey', 'unity_company' => 'Named Studio']);
        Game::create(['name' => 'Unnamed Game']);

        $this->actingAs($this->admin())
            ->get(route('admin.games'))
            ->assertOk()
            ->assertSee('NamedKey')
            ->assertSee('Named Studio')
            ->assertSee(route('admin.games.names.clear', $named->id), false)
            ->assertDontSee('name="unity_name"', false);
    }

    private function translationOf(Game $game, string $contentUpdatedAt): void
    {
        $path = 'translations/order-' . uniqid() . '.json';
        \Illuminate\Support\Facades\Storage::disk('local')->put($path, json_encode(['Hi' => ['v' => 'Salut', 't' => 'H']]));

        $translation = new \App\Models\Translation();
        $translation->forceFill([
            'game_id' => $game->id,
            'user_id' => User::factory()->create()->id,
            'source_language' => 'English',
            'target_language' => 'French',
            'visibility' => 'public',
            'file_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'file_path' => $path,
            'file_hash' => 'hash-' . uniqid(),
            'line_count' => 1,
            'human_count' => 1,
        ])->save();

        // ⚠ Written AFTER the save and around the model: Translation's `saving` hook sets
        // content_updated_at to now() whenever file_hash changes — which it does on creation — so a
        // date passed in the fill is overwritten and every row ends up "updated just now".
        \App\Models\Translation::whereKey($translation->id)->update(['content_updated_at' => $contentUpdatedAt]);
    }

    public function test_both_admin_lists_show_what_moved_last_first(): void
    {
        // Asked on 2026-09-23: an admin comes to look at what moved lately. "Updated" is when the
        // CONTENT last changed (content_updated_at), never updated_at, which a vote writes.
        $old = Game::create(['name' => 'Aaa Quiet Game']);
        $fresh = Game::create(['name' => 'Zzz Busy Game']);
        $never = Game::create(['name' => 'Mmm Empty Game']);

        $this->translationOf($old, now()->subMonths(3)->toDateTimeString());
        $this->translationOf($fresh, now()->subHour()->toDateTimeString());

        $this->actingAs($this->admin());

        // Games: by the last change of any of their translations; a game with none comes last.
        $this->get(route('admin.games'))
            ->assertOk()
            ->assertSeeInOrder(['Zzz Busy Game', 'Aaa Quiet Game', 'Mmm Empty Game']);

        // Translations: the same default, on their own "Updated".
        $this->get(route('admin.translations.index'))
            ->assertOk()
            ->assertSeeInOrder(['Zzz Busy Game', 'Aaa Quiet Game']);

        $this->assertNotNull($never->id);
    }

    public function test_clearing_leaves_a_trace_and_moves_no_date(): void
    {
        $game = Game::create(['name' => 'Traced Game']);
        $game->update(['unity_name' => 'TracedKey']);
        $was = $game->fresh()->updated_at;

        $this->travel(2)->days();

        $this->actingAs($this->admin())
            ->delete("/admin/games/{$game->id}/names")
            ->assertRedirect();

        // What a machine resolves by decides where other people's uploads are filed, so a change
        // here is worth a trace — it is invisible everywhere else.
        $this->assertTrue(
            AuditLog::where('action', 'game.names_cleared')->where('entity_id', $game->id)->exists()
        );

        // ⚠ And it must not re-date the catalogue: saveQuietly() silences events and still writes
        // updated_at, so only timestamps = false holds the date. Listings ordered by freshness
        // would otherwise reshuffle on an admin's correction.
        $this->assertEquals($was, $game->fresh()->updated_at);
    }
}
