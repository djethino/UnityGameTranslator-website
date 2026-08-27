<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Cutting an access one no longer recognises.
 *
 * The gap this closes: `DELETE /api/v1/auth/token` only ever revoked the token presented in the
 * Bearer header, so the one credential somebody would actually want to cut — a stolen one — was
 * the only one out of reach. Everything here is about somebody acting from their own session on a
 * credential they are not holding.
 */
class LinkedDevicesTest extends TestCase
{
    use RefreshDatabase;

    private function linkFrom(string $userAgent, ?string $gameId, ?string $gameName, User $user, ?string $device = null): void
    {
        $init = $this->withHeader('User-Agent', $userAgent)
            ->postJson('/api/v1/auth/device', array_filter([
                'game_id' => $gameId,
                'game_name' => $gameName,
            ]));

        $init->assertOk();

        $this->actingAs($user)->post('/link', [
            'code' => $init->json('user_code'),
            'device_label' => $device,
        ])->assertRedirect();
    }

    public function test_the_screen_shows_only_this_accounts_accesses(): void
    {
        $mine = User::factory()->create();
        $theirs = User::factory()->create();

        ApiToken::createForUser($mine, null, ['device_label' => 'Living room PC']);
        ApiToken::createForUser($theirs, null, ['device_label' => 'Somebody else PC']);

        $this->actingAs($mine)->get('/profile/connections')
            ->assertOk()
            ->assertSee('Living room PC')
            ->assertDontSee('Somebody else PC');
    }

    /**
     * 🔴 The ids are sequential, so route-model binding here would hand out every account's rows.
     * A miss must be a 404 and never a 403: a 403 would confirm the row exists.
     */
    public function test_another_accounts_access_cannot_be_reached(): void
    {
        $mine = User::factory()->create();
        $theirs = User::factory()->create();
        $target = ApiToken::createForUser($theirs);

        $this->actingAs($mine)
            ->delete("/profile/connections/{$target->id}")
            ->assertNotFound();

        $this->actingAs($mine)
            ->patch("/profile/connections/{$target->id}", ['device_label' => 'stolen'])
            ->assertNotFound();

        $this->assertDatabaseHas('api_tokens', ['id' => $target->id, 'device_label' => null]);
    }

    /**
     * The public code names a line out loud; it must never be a way in. Feeding it where an id is
     * expected has to miss, or a six-character value becomes an enumeration surface.
     */
    public function test_the_public_code_is_not_an_identifier(): void
    {
        $user = User::factory()->create();
        $token = ApiToken::createForUser($user);

        $this->assertNotNull($token->public_code);

        $this->actingAs($user)
            ->delete("/profile/connections/{$token->public_code}")
            ->assertNotFound();

        $this->assertDatabaseHas('api_tokens', ['id' => $token->id]);
    }

    public function test_revoking_cuts_one_line_a_device_or_everything(): void
    {
        $user = User::factory()->create();

        $salon = ApiToken::createForUser($user, null, ['device_label' => 'Living room PC']);
        $salonToo = ApiToken::createForUser($user, null, ['device_label' => 'Living room PC']);
        $laptop = ApiToken::createForUser($user, null, ['device_label' => 'Laptop']);

        $this->actingAs($user)->delete("/profile/connections/{$salon->id}")->assertRedirect();
        $this->assertDatabaseMissing('api_tokens', ['id' => $salon->id]);
        $this->assertDatabaseHas('api_tokens', ['id' => $salonToo->id]);

        $this->actingAs($user)->delete('/profile/connections', [
            'scope' => 'device',
            'device_label' => 'Living room PC',
        ])->assertRedirect();
        $this->assertDatabaseMissing('api_tokens', ['id' => $salonToo->id]);
        $this->assertDatabaseHas('api_tokens', ['id' => $laptop->id]);

        $this->actingAs($user)->delete('/profile/connections', ['scope' => 'all'])->assertRedirect();
        $this->assertSame(0, $user->apiTokens()->count());
    }

    /**
     * Unnamed rows are a group of their own — everything issued before a name was asked for. They
     * must be reachable, or the whole legacy parc would be revocable one line at a time only.
     */
    public function test_the_unnamed_group_can_be_revoked_as_one(): void
    {
        $user = User::factory()->create();

        ApiToken::createForUser($user);
        ApiToken::createForUser($user);
        $named = ApiToken::createForUser($user, null, ['device_label' => 'Laptop']);

        $this->actingAs($user)->delete('/profile/connections', [
            'scope' => 'device',
            'device_label' => '',
        ])->assertRedirect();

        $this->assertSame(1, $user->apiTokens()->count());
        $this->assertDatabaseHas('api_tokens', ['id' => $named->id]);
    }

    /**
     * The shared-machine case: a session left open lets whoever sits down next link a game, and the
     * access they get outlives the session. Cutting the browsers is half the remedy and has to work
     * for accounts that have no password at all — which is why it is not logoutOtherDevices().
     */
    public function test_other_browsers_are_signed_out_and_this_one_is_not(): void
    {
        $user = User::factory()->create(['password' => null]);

        $this->actingAs($user)->get('/profile/connections')->assertOk();
        $mine = session()->getId();

        DB::table('sessions')->insert([
            ['id' => 'other-browser-1', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()],
            ['id' => 'other-browser-2', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()],
        ]);

        $this->actingAs($user)->delete('/profile/browsers')->assertRedirect();

        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->where('id', '!=', $mine)->count());
    }

    /**
     * 🔴 Deleting the session rows is not signing a browser out.
     *
     * Both sign-in paths call `Auth::login($user, remember: true)` with no condition, so every
     * browser also holds a recaller cookie and Laravel rebuilds the session from it on the next
     * request — no sign-in screen, nothing typed. Observed on a real machine: the other browser
     * came back on its own and this page counted it again.
     */
    public function test_a_signed_out_browser_cannot_come_back_on_its_remember_cookie(): void
    {
        $user = User::factory()->create();
        $before = $user->getRememberToken();

        $this->actingAs($user)->get('/profile/connections')->assertOk();

        DB::table('sessions')->insert([
            ['id' => 'the-other-browser', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()],
        ]);

        $this->actingAs($user)->delete('/profile/browsers')->assertRedirect();

        // The recaller the other browser is holding no longer matches anything.
        $this->assertNotSame($before, $user->fresh()->getRememberToken());
        $this->assertSame(0, DB::table('sessions')->where('id', 'the-other-browser')->count());
    }

    /**
     * ⚠ The rotation invalidates this browser's own recaller too, so it has to be handed a fresh
     * one — otherwise the button quietly signs out the person pressing it as soon as they close
     * the window, which is the opposite of what it says.
     */
    public function test_the_browser_doing_the_signing_out_keeps_its_own_place(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/profile/connections')->assertOk();
        $this->actingAs($user)->delete('/profile/browsers')->assertRedirect();

        $this->actingAs($user)->get('/profile/connections')->assertOk();
        $this->assertAuthenticatedAs($user);
    }

    /**
     * One game holds one access per program. Linking again replaces rather than leaving a line
     * nobody can identify behind — which is the whole reason orphans accumulate today.
     */
    public function test_linking_the_same_game_again_replaces_its_access(): void
    {
        $user = User::factory()->create();

        $this->linkFrom('UnityGameTranslator/0.12.0 (BepInEx6-Mono)', '367520', 'A Game', $user, 'Living room PC');
        $this->assertSame(1, $user->apiTokens()->count());
        $first = $user->apiTokens()->first();

        $this->linkFrom('UnityGameTranslator/0.12.0 (BepInEx6-Mono)', '367520', 'A Game', $user, 'Living room PC');

        $this->assertSame(1, $user->apiTokens()->count());
        $this->assertDatabaseMissing('api_tokens', ['id' => $first->id]);
    }

    /**
     * 🔴 The same game on two machines is a mainstream setup — a desktop and a Steam Deck — and
     * both accesses have to live. Keyed on the game alone the cap cut across machines: linking on
     * one signed the other out, and back again on the next switch.
     */
    public function test_the_same_game_on_another_device_keeps_its_own_access(): void
    {
        $user = User::factory()->create();

        $this->linkFrom('UnityGameTranslator/0.12.0 (BepInEx6-Mono)', '367520', 'A Game', $user, 'Living room PC');
        $this->linkFrom('UnityGameTranslator/0.12.0 (BepInEx6-Mono)', '367520', 'A Game', $user, 'Steam Deck');

        $this->assertSame(2, $user->apiTokens()->count());
        $this->assertSame(
            ['Living room PC', 'Steam Deck'],
            $user->apiTokens()->pluck('device_label')->sort()->values()->all()
        );
    }

    /**
     * ⚠ No device name, no cap — an unnamed line is an unanswered question, not a machine, and
     * cutting on an absence is how two unrelated installs end up sharing one access.
     */
    public function test_an_unnamed_link_never_cuts_another_unnamed_one(): void
    {
        $user = User::factory()->create();

        $this->linkFrom('UnityGameTranslator/0.12.0 (BepInEx6-Mono)', '367520', 'A Game', $user);
        $this->linkFrom('UnityGameTranslator/0.12.0 (BepInEx6-Mono)', '367520', 'A Game', $user);

        $this->assertSame(2, $user->apiTokens()->count());
    }

    /**
     * The Manager is a different program on the same game, so it keeps its own access: the cap is
     * one per game AND per program, not one per game.
     */
    public function test_the_manager_does_not_replace_the_mods_access(): void
    {
        $user = User::factory()->create();

        // ⚠ Both named, and the same name on purpose: with no device the cap does not run at all,
        // and this would pass without proving anything about telling two programs apart.
        $this->linkFrom('UnityGameTranslator/0.12.0 (BepInEx6-Mono)', '367520', 'A Game', $user, 'Living room PC');
        $this->linkFrom('UnityGameTranslatorManager/0.1.1', '367520', 'A Game', $user, 'Living room PC');

        $this->assertSame(2, $user->apiTokens()->count());
        $this->assertSame(
            ['manager', 'mod'],
            $user->apiTokens()->pluck('client_kind')->sort()->values()->all()
        );
    }

    /**
     * 🔴 No Steam id, no cap. A game recognised only through `Application.productName` cannot be
     * told apart from another carrying the same one, and two different games silently cutting each
     * other off is worse than not capping at all.
     */
    public function test_a_game_without_a_steam_id_is_never_capped(): void
    {
        $user = User::factory()->create();

        $this->linkFrom('UnityGameTranslator/0.12.0 (BepInEx5)', null, 'Some Unity Game', $user);
        $this->linkFrom('UnityGameTranslator/0.12.0 (BepInEx5)', null, 'Some Unity Game', $user);

        $this->assertSame(2, $user->apiTokens()->count());
        $this->assertSame([null, null], $user->apiTokens()->pluck('game_slot')->all());
    }

    /**
     * The same game held by two accounts must not produce the same value, or anybody reading the
     * table could group accounts by what they play.
     */
    public function test_the_game_slot_is_not_shared_between_accounts(): void
    {
        $one = User::factory()->create();
        $two = User::factory()->create();

        $this->assertNotSame(
            ApiToken::gameSlotFor($one, '367520'),
            ApiToken::gameSlotFor($two, '367520')
        );
    }

    /**
     * 🔴 The mods already installed post NOTHING at all — no body, no content type — and none of
     * them will ever be updated. An empty JSON object is not the same request, so the guarantee is
     * tested as it actually arrives.
     */
    public function test_a_request_with_no_body_at_all_still_starts_a_link(): void
    {
        $this->call('POST', '/api/v1/auth/device')
            ->assertOk()
            ->assertJsonStructure(['device_code', 'user_code', 'verification_uri']);
    }

    /**
     * A program that declares nothing still gets a working token — every mod already installed
     * calls this with an empty body and none of them will ever be updated.
     */
    public function test_a_client_that_declares_nothing_still_links(): void
    {
        $user = User::factory()->create();

        $this->linkFrom('SomeOtherProgram/1.0', null, null, $user);

        $token = $user->apiTokens()->first();
        $this->assertNotNull($token);
        $this->assertSame('other', $token->client_kind);
        $this->assertNull($token->game_slot);
    }

    /**
     * What the program said travels with the link and is displayed: the mod's own loader is the
     * most recognisable thing a line can carry when no game name is known.
     */
    public function test_the_program_and_its_loader_are_recorded(): void
    {
        $user = User::factory()->create();

        $this->linkFrom('UnityGameTranslator/0.12.0 (MelonLoader-IL2CPP)', '367520', 'A Game', $user, 'Laptop');

        $token = $user->apiTokens()->first();
        $this->assertSame('mod', $token->client_kind);
        $this->assertSame('0.12', $token->client_version);
        $this->assertSame('MelonLoader-IL2CPP', $token->client_variant);
        $this->assertSame('A Game', $token->gameName());
    }

    /**
     * The game's name is readable by the application and unreadable in an export of the database
     * alone — which is the only thing this protection claims.
     */
    public function test_the_game_name_is_not_stored_in_the_clear(): void
    {
        $user = User::factory()->create();

        $this->linkFrom('UnityGameTranslator/0.12.0 (BepInEx5)', '367520', 'A Very Distinctive Name', $user);

        $stored = DB::table('api_tokens')->where('user_id', $user->id)->value('game_ref');

        $this->assertNotNull($stored);
        $this->assertStringNotContainsString('A Very Distinctive Name', $stored);
        $this->assertSame('A Very Distinctive Name', $user->apiTokens()->first()->gameName());
    }

    /**
     * Defect C: a banned account could no longer hand back its own credential — the one action
     * nobody has a reason to deny, refused to the people most likely to want it.
     */
    public function test_a_banned_account_can_still_hand_back_its_own_token(): void
    {
        $user = User::factory()->create();
        $token = ApiToken::createForUser($user);

        // ban() cuts every token, so the row has to be put back to test the endpoint itself.
        $user->banned_at = now();
        $user->save();

        $this->withHeader('Authorization', 'Bearer ' . $token->plain_token)
            ->deleteJson('/api/v1/auth/token')
            ->assertOk();

        $this->assertDatabaseMissing('api_tokens', ['id' => $token->id]);
    }

    /**
     * Defect D: tokens issued before expiry existed were treated as valid for ever, and they are
     * the oldest and least identifiable rows on the table.
     */
    public function test_no_token_is_immortal(): void
    {
        $user = User::factory()->create();
        $token = ApiToken::createForUser($user);

        $this->assertNotNull($token->expires_at);
    }

    /**
     * An ordinary call names the game an old access never declared.
     *
     * Reported from production on 2026-08-27: every line read "Mod" and nothing else. The game was
     * declared at the link and nowhere afterwards, so an access created before that existed stayed
     * nameless for ever while calling us several times an hour with the game right there.
     */
    public function test_an_ordinary_call_fills_in_a_game_the_link_never_declared(): void
    {
        $user = User::factory()->create();
        $token = ApiToken::createForUser($user);

        $this->assertNull($token->game_slot);

        $declaration = base64_encode(json_encode(['game_id' => '367520', 'game_name' => 'Hollow Knight']));

        $this->withHeaders([
            'Authorization' => 'Bearer ' . $token->plain_token,
            'X-UGT-Game' => $declaration,
        ])->getJson('/api/v1/me')->assertOk();

        $token->refresh();

        $this->assertSame(ApiToken::gameSlotFor($user, '367520'), $token->game_slot);
        $this->assertSame('Hollow Knight', $token->gameName());
    }

    /**
     * 🔴 And it never corrects one. `game_slot` is what the one-access-per-game cap is applied to,
     * so overwriting it would move an existing access under a different game — and the next link
     * would cut the wrong line.
     */
    public function test_a_declared_game_is_never_overwritten_by_a_later_call(): void
    {
        $user = User::factory()->create();

        $token = ApiToken::createForUser($user, null, [
            'game_slot' => ApiToken::gameSlotFor($user, '367520'),
            'game_ref' => 'Hollow Knight',
        ]);

        $slot = $token->game_slot;
        $this->assertNotNull($slot);

        // A second game claiming the same access — what a mod would send if it were ever pointed at
        // the wrong config, and what a tampered client could send on purpose.
        $this->withHeaders([
            'Authorization' => 'Bearer ' . $token->plain_token,
            'X-UGT-Game' => base64_encode(json_encode(['game_id' => '999999', 'game_name' => 'Another Game'])),
        ])->getJson('/api/v1/me')->assertOk();

        $token->refresh();

        $this->assertSame($slot, $token->game_slot);
        $this->assertSame('Hollow Knight', $token->gameName());
    }

    /**
     * A header nobody can read must never be able to break the access it describes.
     *
     * It is parsed inside authentication, on every authenticated call: turning a malformed value
     * into a 500 would let anybody take their own access down by writing one bad byte.
     */
    public function test_a_malformed_declaration_is_ignored_rather_than_fatal(): void
    {
        $user = User::factory()->create();
        $token = ApiToken::createForUser($user);

        foreach (['not-base64!!', base64_encode('{'), base64_encode(json_encode(['game_id' => 'abc'])), ''] as $bad) {
            $this->withHeaders([
                'Authorization' => 'Bearer ' . $token->plain_token,
                'X-UGT-Game' => $bad,
            ])->getJson('/api/v1/me')->assertOk();
        }

        $token->refresh();
        $this->assertNull($token->game_slot);
    }

    /**
     * What the line says about a deadline, and when it says nothing.
     *
     * 🔴 Both halves were wrong, and both were reported from production on 2026-08-27:
     *
     *  - the idle deadline SLIDES — six months from the last exchange — so printing a fixed date
     *    beside "exchange today" stated something false about tomorrow. Worse, on the day the rule
     *    shipped the grace floor put every line on one date, which reads as a broken screen;
     *  - the six-month rule was applied and stated nowhere, so the date arrived out of the blue.
     */
    public function test_the_cut_date_is_shown_on_a_quiet_line_and_not_on_a_busy_one(): void
    {
        $user = User::factory()->create();

        $busy = ApiToken::createForUser($user, null, ['device_label' => 'Desk']);
        DB::table('api_tokens')->where('id', $busy->id)->update(['last_used_at' => now()]);

        $page = $this->actingAs($user)->get('/profile/connections');

        $page->assertOk()
            ->assertSee(__('connections.exchange_today'))
            ->assertDontSee(__('connections.cut_on', ['date' => '']), false)
            // The rule itself, said once for the whole page rather than implied by a date.
            ->assertSee(__('connections.idle_rule'));

        DB::table('api_tokens')->where('id', $busy->id)
            ->update(['last_used_at' => now()->subMonths(8)]);

        $this->actingAs($user)->get('/profile/connections')
            ->assertOk()
            ->assertSee(__('connections.cut_on', [
                'date' => \App\Console\Commands\PurgeIdleTokens::deadlineFor($busy->fresh())
                    ->translatedFormat('j F Y'),
            ]));
    }

    /**
     * A mod access with no game says so, instead of repeating the word the icon already carries.
     */
    public function test_a_mod_access_with_no_game_says_the_game_was_not_recorded(): void
    {
        $user = User::factory()->create();
        ApiToken::createForUser($user, null, ['device_label' => 'Desk', 'client_kind' => 'mod']);

        $this->actingAs($user)->get('/profile/connections')
            ->assertOk()
            ->assertSee(__('connections.game_not_recorded'));
    }

    /**
     * The code the screen names each line by, returned to the program holding that line.
     *
     * Without it the screen is an impasse: it offers to rename a machine nothing lets somebody
     * identify. Retroactive by construction — the code is already stored against the token.
     */
    public function test_the_holder_of_a_token_is_told_its_access_code(): void
    {
        $user = User::factory()->create();
        $token = ApiToken::createForUser($user);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plain_token)
            ->getJson('/api/v1/me');

        $response->assertOk()->assertJsonPath('access_code', $token->public_code);
    }
}
