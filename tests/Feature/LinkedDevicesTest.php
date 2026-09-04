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

    private function linkFrom(
        string $userAgent,
        ?string $gameId,
        ?string $gameName,
        User $user,
        ?string $device = null,
        ?string $machine = null
    ): void {
        // 🔴 **withHeaders persists for the whole test**, it does not apply to one request. Without
        // this, a link made with no machine still carried the previous one's header, the cap fired
        // against a machine that was not there, and the count came out right for the wrong reason.
        $this->flushHeaders();

        $headers = array_filter([
            'User-Agent' => $userAgent,
            // The machine rides the header, not the body: /auth/device is unauthenticated, so the
            // middleware that reads it elsewhere never runs — the header is on the request anyway.
            'X-UGT-Device' => $machine,
        ]);

        $init = $this->withHeaders($headers)
            ->postJson('/api/v1/auth/device', array_filter([
                'game_id' => $gameId,
                'game_name' => $gameName,
            ]));

        $init->assertOk();

        // 🔴 assertSessionHasNoErrors, not assertRedirect alone: a link that fails validation or a
        // code that is not found redirects too, so the weaker assertion passes while nothing is
        // created — and every count downstream is then wrong for a reason nothing names.
        // Two steps, as the page now does them: the code first, which only shows what it stands
        // for, then the same code confirmed — the POST that actually links.
        $this->actingAs($user)->post('/link', [
            'code' => $init->json('user_code'),
        ])->assertSessionHasNoErrors()->assertRedirect(route('link'));

        $this->actingAs($user)->post('/link', [
            'code' => $init->json('user_code'),
            'confirm' => 1,
        ])->assertSessionHasNoErrors()->assertRedirect();

        // ⚠ The link screen no longer asks for a name, so a test that wants one names it where a
        // user now would: on the Linked devices screen, after the fact. Posting `device_label` to
        // /link would prove nothing — the field is not read there any more.
        if ($device !== null) {
            $latest = $user->apiTokens()->orderByDesc('id')->first();
            $this->actingAs($user)
                ->patch("/profile/connections/{$latest->id}", ['device_label' => $device])
                ->assertSessionHasNoErrors();
        }
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

        // ⚠ One of the group's own lines, not the typed name: a machine can now group without
        // anybody typing, so two groups may share the same absent name.
        //
        // 🔴 assertSessionHasNoErrors, not assertRedirect alone. A validation failure IS a redirect,
        // so the weaker assertion passes while nothing happens — which is exactly how a rule
        // demanding a string swallowed an integer id here, in silence.
        $this->actingAs($user)->delete('/profile/connections', [
            'scope' => 'device',
            'token' => $salonToo->id,
        ])->assertSessionHasNoErrors()->assertRedirect();
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

        $unnamed = ApiToken::createForUser($user);
        ApiToken::createForUser($user);
        $named = ApiToken::createForUser($user, null, ['device_label' => 'Laptop']);

        $this->actingAs($user)->delete('/profile/connections', [
            'scope' => 'device',
            'token' => $unnamed->id,
        ])->assertSessionHasNoErrors()->assertRedirect();

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

        $this->linkFrom('UnityGameTranslator/0.12.0 (BepInEx6-Mono)', '111111', 'A Game', $user, null, 'a1b2c3d4e5f60718293a4b5c6d7e8f90');
        $this->assertSame(1, $user->apiTokens()->count());
        $first = $user->apiTokens()->first();

        $this->linkFrom('UnityGameTranslator/0.12.0 (BepInEx6-Mono)', '111111', 'A Game', $user, null, 'a1b2c3d4e5f60718293a4b5c6d7e8f90');

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

        $this->linkFrom('UnityGameTranslator/0.12.0 (BepInEx6-Mono)', '111111', 'A Game', $user, 'Living room PC', 'a1b2c3d4e5f60718293a4b5c6d7e8f90');
        $this->linkFrom('UnityGameTranslator/0.12.0 (BepInEx6-Mono)', '111111', 'A Game', $user, 'Steam Deck', 'ffffffffffffffffffffffffffffffff');

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

        $this->linkFrom('UnityGameTranslator/0.12.0 (BepInEx6-Mono)', '111111', 'A Game', $user);
        $this->linkFrom('UnityGameTranslator/0.12.0 (BepInEx6-Mono)', '111111', 'A Game', $user);

        $this->assertSame(2, $user->apiTokens()->count());
    }

    /**
     * The Manager is a different program on the same game, so it keeps its own access: the cap is
     * one per game AND per program, not one per game.
     */
    public function test_the_manager_does_not_replace_the_mods_access(): void
    {
        $user = User::factory()->create();

        // ⚠ The SAME machine on purpose: without one the cap does not run at all, and this would
        // pass without proving anything about telling two programs apart.
        $this->linkFrom('UnityGameTranslator/0.12.0 (BepInEx6-Mono)', '111111', 'A Game', $user, null, 'a1b2c3d4e5f60718293a4b5c6d7e8f90');
        $this->linkFrom('UnityGameTranslatorManager/0.1.1', '111111', 'A Game', $user, null, 'a1b2c3d4e5f60718293a4b5c6d7e8f90');

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
            ApiToken::gameSlotFor($one, '111111'),
            ApiToken::gameSlotFor($two, '111111')
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

        $this->linkFrom('UnityGameTranslator/0.12.0 (MelonLoader-IL2CPP)', '111111', 'A Game', $user, 'Laptop');

        $token = $user->apiTokens()->first();
        $this->assertSame('mod', $token->client_kind);
        // ⚠ The full version, not `0.12`. It used to be truncated to major.minor by a second
        // User-Agent parser that no longer exists — nothing justified it, and it hid exactly the
        // distinction this page is for: 0.12.0 and 0.12.1 are not the same build.
        $this->assertSame('0.12.0', $token->client_version);
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

        $this->linkFrom('UnityGameTranslator/0.12.0 (BepInEx5)', '111111', 'A Very Distinctive Name', $user);

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

        $declaration = base64_encode(json_encode(['game_id' => '111111', 'game_name' => 'Declared Game']));

        $this->withHeaders([
            'Authorization' => 'Bearer ' . $token->plain_token,
            'X-UGT-Game' => $declaration,
        ])->getJson('/api/v1/me')->assertOk();

        $token->refresh();

        $this->assertSame(ApiToken::gameSlotFor($user, '111111'), $token->game_slot);
        $this->assertSame('Declared Game', $token->gameName());
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
            'game_slot' => ApiToken::gameSlotFor($user, '111111'),
            'game_ref' => 'Declared Game',
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
        $this->assertSame('Declared Game', $token->gameName());
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
     * The pile of accesses from before anything was recorded explains itself.
     *
     * 🔴 Somebody opening this page for the first time finds credentials they cannot recognise,
     * some of them months old, and has exactly one question: am I in trouble? The page answered a
     * different one — "linked before a name was asked for" — and gave them nothing to do.
     *
     * ⚠ Shown on what the line SAYS, never on a date: an access that has spoken since names its own
     * program, so what is left is exactly what was created and never used again.
     */
    public function test_accesses_from_before_anything_was_recorded_explain_themselves(): void
    {
        $user = User::factory()->create();

        $legacy = ApiToken::createForUser($user);
        DB::table('api_tokens')->where('id', $legacy->id)->update(['client_kind' => null]);

        $this->actingAs($user)->get('/profile/connections')
            ->assertOk()
            ->assertSee(__('connections.legacy_live'))
            ->assertSee(__('connections.legacy_revoke'))
            ->assertDontSee(__('connections.group_unnamed_hint'));

        // An access that has said which program it is gets no such speech: it is not from before.
        DB::table('api_tokens')->where('id', $legacy->id)->update(['client_kind' => 'mod']);

        $this->actingAs($user)->get('/profile/connections')
            ->assertOk()
            ->assertDontSee(__('connections.legacy_live'))
            ->assertSee(__('connections.group_unnamed_hint'));
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
     * Re-linking a game from the same machine replaces its access, with nobody typing a name.
     *
     * 🔴 **This is what built the pile.** The cap needed a Steam id AND a device name somebody had
     * typed — and nobody types one. So a reinstall, a wiped config, or "revoke everything" followed
     * by signing in again left the previous access behind, every time: thirty-six of them on one
     * account, measured in production on 2026-08-27.
     */
    public function test_relinking_from_the_same_machine_replaces_the_access_with_no_name_typed(): void
    {
        $user = User::factory()->create();
        $machine = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';

        $this->linkFrom('UnityGameTranslator/0.12.1 (BepInEx5)', '111111', 'A Game', $user, null, $machine);
        $this->linkFrom('UnityGameTranslator/0.12.1 (BepInEx5)', '111111', 'A Game', $user, null, $machine);

        $this->assertSame(1, $user->apiTokens()->count());

        // Another machine keeps its own: the cap must never reach across them.
        $this->linkFrom('UnityGameTranslator/0.12.1 (BepInEx5)', '111111', 'A Game', $user, null,
            'ffffffffffffffffffffffffffffffff');

        $this->assertSame(2, $user->apiTokens()->count());

        // And a client that says nothing about its machine still accumulates — deliberately: the
        // cap cannot cut on an absence without risking somebody else's game.
        $this->linkFrom('UnityGameTranslator/0.11.0 (BepInEx5)', '111111', 'A Game', $user);
        $this->assertSame(3, $user->apiTokens()->count(), 'un client sans machine doit ajouter');

        $this->linkFrom('UnityGameTranslator/0.11.0 (BepInEx5)', '111111', 'A Game', $user);
        $this->assertSame(4, $user->apiTokens()->count(), 'et ne jamais remplacer');
    }

    /**
     * A game linked on a machine already filed somewhere joins that group straight away.
     */
    public function test_a_newly_linked_game_joins_the_group_its_machine_is_in(): void
    {
        $user = User::factory()->create();
        $machine = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';

        $this->linkFrom('UnityGameTranslator/0.12.1 (BepInEx5)', '111111', 'A Game', $user, 'Living room PC', $machine);
        $this->linkFrom('UnityGameTranslator/0.12.1 (BepInEx5)', '999999', 'Another', $user, null, $machine);

        $fresh = $user->apiTokens()->orderByDesc('id')->first();

        $this->assertSame('Living room PC', $fresh->device_label);
    }

    /**
     * A machine that says who it is groups its own games, and one name covers them all.
     *
     * 🔴 Measured in production on 2026-08-27: thirty-six accesses on one account, thirty-five in a
     * single "not named" heap, because the only grouping key was a name nobody types when linking.
     */
    public function test_a_machine_groups_its_accesses_and_one_name_covers_them(): void
    {
        $user = User::factory()->create();
        $device = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';

        $first = ApiToken::createForUser($user);
        $second = ApiToken::createForUser($user);
        $elsewhere = ApiToken::createForUser($user);

        foreach ([$first, $second] as $token) {
            $this->withHeaders([
                'Authorization' => 'Bearer ' . $token->plain_token,
                'X-UGT-Device' => $device,
            ])->getJson('/api/v1/me')->assertOk();
        }

        $first->refresh();
        $second->refresh();

        $this->assertSame(ApiToken::deviceSlotFor($user, $device), $first->device_slot);
        $this->assertSame($first->device_slot, $second->device_slot);
        $this->assertNull($elsewhere->fresh()->device_slot);

        // Naming the machine from one of its lines names every line on it — and nothing else.
        $this->actingAs($user)->patch("/profile/connections/{$first->id}", [
            'device_label' => 'Living room PC',
        ])->assertRedirect();

        $this->assertSame('Living room PC', $second->fresh()->device_label);
        $this->assertNull($elsewhere->fresh()->device_label);
    }

    /**
     * A group is the owner's decision; the machine only supplies the default arrangement.
     *
     * 🔴 And the point of the whole thing: a line filed by hand stays where it was put. Renaming
     * the pile it came from must not drag it back, and a new access on that machine must not land
     * on top of it.
     */
    public function test_a_line_filed_by_hand_is_not_dragged_back_by_its_machine(): void
    {
        $user = User::factory()->create();
        $device = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';

        $stays = ApiToken::createForUser($user);
        $moved = ApiToken::createForUser($user);

        foreach ([$stays, $moved] as $token) {
            $this->withHeaders([
                'Authorization' => 'Bearer ' . $token->plain_token,
                'X-UGT-Device' => $device,
            ])->getJson('/api/v1/me')->assertOk();
        }

        // Filed somewhere of its own — by kind of game, not by machine.
        $this->actingAs($user)->patch("/profile/connections/{$moved->id}/group", [
            'new_group' => 'RPGs',
        ])->assertSessionHasNoErrors()->assertRedirect();

        // And back in, by pointing at a line of the destination rather than retyping its name —
        // which is the whole point: a group nobody has named is still a group you can aim at.
        $this->actingAs($user)->patch("/profile/connections/{$moved->id}/group", [
            'into' => $stays->id,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertNull($moved->fresh()->device_label);
        $this->assertSame($stays->fresh()->device_slot, $moved->fresh()->device_slot);

        // Out again, so the rest of the test reads as before.
        $this->actingAs($user)->patch("/profile/connections/{$moved->id}/group", [
            'new_group' => 'RPGs',
        ])->assertSessionHasNoErrors()->assertRedirect();

        // Naming the machine's group touches what is still in it, and nothing else.
        $this->actingAs($user)->patch("/profile/connections/{$stays->id}", [
            'device_label' => 'Living room PC',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame('Living room PC', $stays->fresh()->device_label);
        $this->assertSame('RPGs', $moved->fresh()->device_label);

        // And what it says about the machine is untouched underneath, so putting the line back
        // takes it home rather than into a group called "".
        $this->assertNotNull($moved->fresh()->device_slot);

        $this->actingAs($user)->patch("/profile/connections/{$moved->id}/group", [
            'device_label' => '',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertNull($moved->fresh()->device_label);
    }

    /**
     * A line from the old unplaced heap can be dropped onto a machine group nobody has named.
     *
     * 🔴 The destinations used to be the NAMED groups only, so with two unnamed boxes on screen the
     * control could do nothing but invent a third — "move to another group" that cannot reach the
     * groups you are looking at. A group is pointed at through one of its lines, name or no name.
     */
    public function test_an_unplaced_line_can_join_a_machine_group_that_has_no_name(): void
    {
        $user = User::factory()->create();
        $device = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';

        $onTheMachine = ApiToken::createForUser($user, null, [
            'device_slot' => ApiToken::deviceSlotFor($user, $device),
        ]);
        $legacy = ApiToken::createForUser($user);

        $this->assertNull($legacy->device_slot);

        $this->actingAs($user)->patch("/profile/connections/{$legacy->id}/group", [
            'into' => $onTheMachine->id,
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame($onTheMachine->device_slot, $legacy->fresh()->device_slot);
        $this->assertNull($legacy->fresh()->device_label);
    }

    /**
     * A new access on a machine joins where that machine is already filed, instead of appearing on
     * its own — otherwise naming a machine would hold until the next install and no longer.
     *
     * ⚠ Only when the machine agrees with itself: once its accesses sit under several names there
     * is no "the group of this machine" left, and choosing one would be a guess shown as a fact.
     */
    public function test_a_new_access_joins_the_group_its_machine_is_already_in(): void
    {
        $user = User::factory()->create();
        $device = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';

        $known = ApiToken::createForUser($user, null, [
            'device_label' => 'Living room PC',
            'device_slot' => ApiToken::deviceSlotFor($user, $device),
        ]);

        $fresh = ApiToken::createForUser($user);

        $this->withHeaders([
            'Authorization' => 'Bearer ' . $fresh->plain_token,
            'X-UGT-Device' => $device,
        ])->getJson('/api/v1/me')->assertOk();

        $this->assertSame('Living room PC', $fresh->fresh()->device_label);

        // Split the machine across two groups, and the next arrival stays unfiled rather than
        // being dropped into whichever name happened to come first.
        //
        // ⚠ Through the query builder: createForUser hangs the plain token on the model as a
        // dynamic attribute, so save() would try to persist a column that does not exist.
        DB::table('api_tokens')->where('id', $known->id)->update(['device_label' => 'RPGs']);

        $later = ApiToken::createForUser($user);
        $this->withHeaders([
            'Authorization' => 'Bearer ' . $later->plain_token,
            'X-UGT-Device' => $device,
        ])->getJson('/api/v1/me')->assertOk();

        $this->assertNull($later->fresh()->device_label);
    }

    /**
     * 🔴 The raw identifier is the same under every account on that machine, so what is STORED must
     * not be. Otherwise anybody reading the table could tie two accounts together — the rule a
     * computer carrying several people's games exists to protect.
     */
    public function test_one_machine_seen_by_two_accounts_leaves_no_common_trace(): void
    {
        $mine = User::factory()->create();
        $theirs = User::factory()->create();
        $device = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';

        $ours = ApiToken::createForUser($mine);
        $others = ApiToken::createForUser($theirs);

        foreach ([$ours, $others] as $token) {
            $this->withHeaders([
                'Authorization' => 'Bearer ' . $token->plain_token,
                'X-UGT-Device' => $device,
            ])->getJson('/api/v1/me')->assertOk();
        }

        $this->assertNotNull($ours->fresh()->device_slot);
        $this->assertNotSame($ours->fresh()->device_slot, $others->fresh()->device_slot);
    }

    /**
     * The code the screen names each line by, returned to the program holding that line.
     *
     * Without it the screen is an impasse: it offers to rename a machine nothing lets somebody
     * identify. Retroactive by construction — the code is already stored against the token.
     */
    /**
     * 🔴 The link screen asks for the code and nothing else.
     *
     * It used to ask "Which device is this?" — jargon, on a field somebody meets while holding a
     * code from their game, with the list of already-named machines necessarily EMPTY the one time
     * it would have helped: the first link. It was optional and never said so. And it had stopped
     * being useful the day the machine began saying which it is on its own.
     */
    public function test_the_link_screen_asks_for_the_code_and_nothing_else(): void
    {
        $user = User::factory()->create();

        $page = $this->actingAs($user)->get('/link')->assertOk();

        $page->assertDontSee('device_label', false);
        $page->assertSee('name="code"', false);

        // Naming is offered, not demanded — and only where the thing being named is on screen.
        $page->assertSee(route('profile.connections'), false);
    }

    /**
     * ⚠ And a name is not accepted through the back door either: the field is gone from the page,
     * so a hand-crafted request must not be able to set one where no screen asks for it.
     */
    public function test_a_name_posted_by_hand_to_the_link_screen_is_ignored(): void
    {
        $user = User::factory()->create();

        $init = $this->withHeaders(['User-Agent' => 'UnityGameTranslator/0.12.1 (BepInEx5)'])
            ->postJson('/api/v1/auth/device', ['game_id' => '111111', 'game_name' => 'A Game']);

        // Both steps, with the injected name on each: neither reads it.
        $this->actingAs($user)->post('/link', [
            'code' => $init->json('user_code'),
            'device_label' => 'Injected',
        ])->assertSessionHasNoErrors()->assertRedirect(route('link'));

        $this->actingAs($user)->post('/link', [
            'code' => $init->json('user_code'),
            'confirm' => 1,
            'device_label' => 'Injected',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertNull($user->apiTokens()->first()->device_label);
    }

    public function test_the_holder_of_a_token_is_told_its_access_code(): void
    {
        $user = User::factory()->create();
        $token = ApiToken::createForUser($user);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plain_token)
            ->getJson('/api/v1/me');

        $response->assertOk()->assertJsonPath('access_code', $token->public_code);
    }
}
