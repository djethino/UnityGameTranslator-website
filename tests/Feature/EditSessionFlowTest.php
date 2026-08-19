<?php

namespace Tests\Feature;

use App\Models\EditSessionToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * End-to-end tests of the anonymous mod → browser live-edit flow.
 *
 * The whole flow is unauthenticated by design (no account required): the
 * one-time browser token opens the page, the session carries it afterwards,
 * and the mod key authenticates the mod-side content download.
 */
class EditSessionFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Session content files must never touch the real storage disk:
        // without the fake, test files land in storage/app/private and any
        // cleanup sweep would also delete LIVE dev sessions.
        Storage::fake('local');
    }

    private const CONTENT = [
        '_uuid' => 'test-uuid-123',
        '_game' => ['name' => 'Test Game'],
        'Hello' => ['v' => 'Bonjour', 't' => 'A'],
        'Play' => ['v' => 'Jouer', 't' => 'H'],
    ];

    private const SESSION_COOKIE = 'ugt_edit_session';

    /**
     * Open the session in the "browser" and keep its cookie for the requests
     * that follow: the test client does not carry response cookies over on its
     * own. The value goes in as PLAIN TEXT — withCookie() encrypts it the way
     * the middleware expects, so replaying the encrypted value from the
     * response would encrypt it twice and never decrypt back to the token.
     */
    private function openInBrowser(EditSessionToken $session): void
    {
        $this->get('/edit-session/' . $session->token);
        $this->withCookie(self::SESSION_COOKIE, $session->token);
        // postJson() sends NO cookie unless credentials are enabled — it mimics
        // a fetch() without them. Real browsers send same-origin cookies by
        // default, which is what the page relies on.
        $this->withCredentials();
    }

    private function initSession(array $content = self::CONTENT)
    {
        return $this->postJson('/api/v1/edit-session/init', [
            'content' => $content,
            'game_name' => 'Test Game',
            'source_language' => 'English',
            'target_language' => 'French',
        ]);
    }

    public function test_init_requires_no_authentication_and_returns_credentials(): void
    {
        $response = $this->initSession();

        $response->assertOk()
            ->assertJsonStructure(['mod_key', 'url', 'expires_at']);

        $session = EditSessionToken::first();
        $this->assertNotNull($session);
        $this->assertNotEquals($session->token, $session->mod_key);
        $this->assertStringContainsString($session->token, $response->json('url'));
        $this->assertStringNotContainsString($session->mod_key, $response->json('url'));
    }

    public function test_init_stores_content_in_file_with_metadata(): void
    {
        $this->initSession()->assertOk();

        $session = EditSessionToken::first();
        $path = $session->getContentFilePath();
        $this->assertNotNull($path);

        $stored = json_decode(file_get_contents($path), true);
        // Metadata keys must survive the round trip (the file replaces
        // translations.json verbatim on the mod side)
        $this->assertSame('test-uuid-123', $stored['_uuid']);
        $this->assertSame('Bonjour', $stored['Hello']['v']);
    }

    public function test_open_consumes_token_and_redirects_without_token(): void
    {
        $this->initSession();
        $session = EditSessionToken::first();

        $response = $this->get('/edit-session/' . $session->token);

        // The id pins the tab to ITS session, whatever the browser opens next
        $response->assertStatus(303)
            ->assertRedirect(route('edit-session.show', ['s' => $session->id]));

        $session->refresh();
        $this->assertNotNull($session->consumed_at);

        // Consumed token can no longer authenticate a new browser
        $this->get('/edit-session/' . $session->token)->assertStatus(403);
    }

    public function test_open_rejects_invalid_token(): void
    {
        $this->get('/edit-session/' . str_repeat('x', 64))->assertStatus(403);
        $this->get('/edit-session/short')->assertStatus(403);
    }

    public function test_show_without_session_renders_expired_view(): void
    {
        $this->get('/edit-session')
            ->assertOk()
            ->assertViewIs('edit-session.expired');
    }

    public function test_data_streams_session_content(): void
    {
        $this->initSession();
        $session = EditSessionToken::first();

        $this->openInBrowser($session);
        $response = $this->get('/edit-session-data');

        $response->assertOk();
        $payload = json_decode($response->streamedContent(), true);
        $this->assertSame('Bonjour', $payload['content']['Hello']['v']);
    }

    public function test_settings_endpoint_lists_what_the_file_carries(): void
    {
        $this->initSession([
            '_uuid' => 'session-uuid',
            '_fonts' => ['Title' => ['enabled' => true, 'fallback' => 'NotoSans']],
            '_exclusions' => ["Qapla'"],
            'Hello' => ['v' => 'Bonjour', 't' => 'H'],
        ]);
        $session = EditSessionToken::first();
        $this->openInBrowser($session);

        $response = $this->getJson('/edit-session-settings');

        $response->assertOk();
        // One source, nothing to arbitrate: the point is only to KNOW what the file carries
        $this->assertStringContainsString('NotoSans', $response->json('settings.fonts:Title.value'));
        $this->assertArrayHasKey("exclusions:Qapla'", $response->json('settings'));
    }

    public function test_settings_endpoint_refuses_without_a_session(): void
    {
        $this->getJson('/edit-session-settings')->assertStatus(410);
    }

    public function test_save_applies_selections_preserves_metadata_and_extends_expiry(): void
    {
        $this->initSession();
        $session = EditSessionToken::first();
        $this->openInBrowser($session);

        $expiryAfterConsume = $session->fresh()->expires_at;
        $this->travel(5)->minutes();

        $response = $this->postJson('/edit-session-save', [
            'selections' => [
                ['key' => 'Hello', 'value' => 'Salut', 'tag' => 'A', 'source' => 'manual'],
                ['key' => 'Play', 'value' => 'Jouer', 'tag' => 'S', 'source' => 'local'],
            ],
        ]);

        $response->assertOk()->assertJson(['saved' => 2]);

        $stored = json_decode(file_get_contents($session->getContentFilePath()), true);
        // Manual edit → H (tag rule), explicit Skip preserved
        $this->assertSame(['v' => 'Salut', 't' => 'H'], $stored['Hello']);
        $this->assertSame(['v' => 'Jouer', 't' => 'S'], $stored['Play']);
        // Explicit dropdown gestures (source 'local') are written as-is:
        // Validate must stick, Invalidate must not be undone
        $this->postJson('/edit-session-save', [
            'selections' => [
                ['key' => 'Hello', 'value' => 'Salut', 'tag' => 'V', 'source' => 'local'],
                ['key' => 'Play', 'value' => 'Jouer', 'tag' => 'A', 'source' => 'local'],
            ],
        ])->assertOk();
        $stored = json_decode(file_get_contents($session->getContentFilePath()), true);
        $this->assertSame(['v' => 'Salut', 't' => 'V'], $stored['Hello']);
        $this->assertSame(['v' => 'Jouer', 't' => 'A'], $stored['Play']);
        // Metadata untouched
        $this->assertSame('test-uuid-123', $stored['_uuid']);
        // Sliding TTL
        $this->assertTrue($session->fresh()->expires_at->gt($expiryAfterConsume));
    }

    public function test_mod_downloads_updated_content_with_mod_key(): void
    {
        $this->initSession();
        $session = EditSessionToken::first();
        $this->openInBrowser($session);
        $this->postJson('/edit-session-save', [
            'selections' => [
                ['key' => 'Hello', 'value' => 'Salut', 'tag' => 'A', 'source' => 'manual'],
            ],
        ])->assertOk();

        $response = $this->get('/api/v1/edit-session/' . $session->mod_key . '/content');

        $response->assertOk();
        $payload = json_decode($response->streamedContent(), true);
        $this->assertSame('Salut', $payload['Hello']['v']);
        $this->assertSame('test-uuid-123', $payload['_uuid']);
    }

    public function test_content_rejects_browser_token_and_unknown_keys(): void
    {
        $this->initSession();
        $session = EditSessionToken::first();

        // The browser token must not work as a mod key
        $this->get('/api/v1/edit-session/' . $session->token . '/content')->assertStatus(404);
        $this->get('/api/v1/edit-session/' . str_repeat('x', 64) . '/content')->assertStatus(404);
    }

    public function test_end_deletes_session_and_file(): void
    {
        $this->initSession();
        $session = EditSessionToken::first();
        $this->openInBrowser($session);
        $filePath = $session->getContentFilePath();

        $this->post('/edit-session-end')->assertRedirect(route('home'));

        $this->assertDatabaseCount('edit_session_tokens', 0);
        $this->assertFileDoesNotExist($filePath);
    }

    public function test_expired_session_rejected_everywhere(): void
    {
        $this->initSession();
        $session = EditSessionToken::first();
        $this->openInBrowser($session);

        // Past the inactivity window with no sign of life from either side
        $this->travel(25)->hours();

        $this->get('/edit-session-data')->assertStatus(410);
        $this->postJson('/edit-session-save', [
            'selections' => [['key' => 'Hello', 'value' => 'X', 'tag' => 'A', 'source' => 'manual']],
        ])->assertStatus(410);
        $this->get('/api/v1/edit-session/' . $session->mod_key . '/content')->assertStatus(404);
    }

    public function test_init_validates_content(): void
    {
        $this->postJson('/api/v1/edit-session/init', ['game_name' => 'X'])
            ->assertStatus(422);
    }

    /**
     * These fields carry a language NAME, not an ISO code. The bound was 16 — a code's length —
     * so the three catalogue entries whose name is longer could never open a browser editor at
     * all, and the refusal read as "the source language field must not be greater than 16
     * characters", which sounds like a rule rather than a defect.
     *
     * The names are written out rather than read from the catalogue on purpose: the point is that
     * THESE strings must pass, and a test that fetched them would keep passing if they changed.
     */
    public function test_init_accepts_the_longest_language_names(): void
    {
        $this->postJson('/api/v1/edit-session/init', [
            'content' => self::CONTENT,
            'game_name' => 'Test Game',
            'source_language' => 'Simplified Chinese',   // 18
            'target_language' => 'Traditional Chinese',  // 19
        ])->assertOk();

        $this->postJson('/api/v1/edit-session/init', [
            'content' => self::CONTENT,
            'source_language' => 'Norwegian Nynorsk',    // 17
        ])->assertOk();
    }

    public function test_save_ignores_metadata_keys(): void
    {
        $this->initSession();
        $session = EditSessionToken::first();
        $this->openInBrowser($session);

        $this->postJson('/edit-session-save', [
            'selections' => [
                ['key' => '_uuid', 'value' => 'forged', 'tag' => 'H', 'source' => 'manual'],
                ['key' => 'Hello', 'value' => 'Salut', 'tag' => 'A', 'source' => 'manual'],
            ],
        ])->assertOk()->assertJson(['saved' => 1]);

        $stored = json_decode(file_get_contents($session->getContentFilePath()), true);
        // The forged metadata write was ignored, the real edit applied
        $this->assertSame('test-uuid-123', $stored['_uuid']);
        $this->assertSame('Salut', $stored['Hello']['v']);
    }

    public function test_mod_update_replaces_content_and_reports_presence(): void
    {
        $this->initSession();
        $session = EditSessionToken::first();
        $this->openInBrowser($session);
        // Browser heartbeat
        $this->get('/edit-session-state')->assertOk();

        $newContent = self::CONTENT;
        $newContent['NewKey'] = ['v' => 'Nouvelle', 't' => 'A'];

        $response = $this->postJson('/api/v1/edit-session/' . $session->mod_key . '/update', [
            'content' => $newContent,
        ]);

        $response->assertOk()
            ->assertJsonStructure(['content_hash', 'browser_seen_seconds_ago', 'browser_left'])
            ->assertJson(['browser_left' => false]);
        $this->assertNotNull($response->json('browser_seen_seconds_ago'));

        $stored = json_decode(file_get_contents($session->getContentFilePath()), true);
        $this->assertSame('Nouvelle', $stored['NewKey']['v']);
        // The state poll now reports the new hash
        $this->assertSame($response->json('content_hash'), $this->get('/edit-session-state')->json('content_hash'));
    }

    public function test_state_updates_browser_presence_and_returns_hash(): void
    {
        $this->initSession();
        $session = EditSessionToken::first();
        $this->openInBrowser($session);

        $response = $this->get('/edit-session-state');

        $response->assertOk();
        $this->assertSame($session->fresh()->content_hash, $response->json('content_hash'));
        $this->assertNotNull($session->fresh()->browser_last_seen_at);
    }

    public function test_leave_beacon_marks_browser_away_and_state_rejoins(): void
    {
        $this->initSession();
        $session = EditSessionToken::first();
        $this->openInBrowser($session);

        // sendBeacon carries no CSRF token — the route is exempt
        $this->post('/edit-session-leave')->assertNoContent();
        $this->assertNotNull($session->fresh()->browser_left_at);

        // The mod's update push sees the browser as away
        $this->postJson('/api/v1/edit-session/' . $session->mod_key . '/update', [
            'content' => self::CONTENT,
        ])->assertOk()->assertJson(['browser_left' => true]);

        // Next state poll (page reopened / refresh finished) rejoins
        $this->get('/edit-session-state')->assertOk();
        $this->assertNull($session->fresh()->browser_left_at);
    }

    public function test_keepalive_extends_expiry_while_game_runs(): void
    {
        $this->initSession();
        $session = EditSessionToken::first();
        $this->openInBrowser($session);
        $expiryBefore = $session->fresh()->expires_at;

        $this->travel(30)->minutes();

        $this->postJson('/api/v1/edit-session/' . $session->mod_key . '/keepalive')
            ->assertOk()
            ->assertJson(['browser_left' => false]);

        $this->assertTrue($session->fresh()->expires_at->gt($expiryBefore));

        // Unknown key → 404 so the mod stops cleanly
        $this->postJson('/api/v1/edit-session/' . str_repeat('x', 64) . '/keepalive')
            ->assertStatus(404);
    }

    public function test_language_switch_from_edit_session_page_does_not_404(): void
    {
        $this->initSession();
        $session = EditSessionToken::first();
        $this->openInBrowser($session);

        // The language switcher redirects back with the new locale prefix:
        // every BROWSED page must exist in prefixed form for EVERY supported
        // locale (the rule that was missed when these pages were added)
        foreach (array_keys(config('locales.supported')) as $locale) {
            $this->get('/locale/' . $locale, ['referer' => url('/edit-session')])
                ->assertRedirect(url('/' . $locale . '/edit-session'));

            $this->get('/' . $locale . '/edit-session')
                ->assertOk()
                ->assertViewIs('edit-session.show');
        }
    }

    public function test_retranslate_relays_to_mod_when_ai_available(): void
    {
        // Session advertising the mod's AI backend
        $this->postJson('/api/v1/edit-session/init', [
            'content' => self::CONTENT,
            'game_name' => 'Test Game',
            'ai_available' => true,
            'ai_model' => 'llama3',
        ])->assertOk();
        $session = EditSessionToken::first();
        $this->assertTrue($session->ai_available);
        $this->assertSame('llama3', $session->ai_model);

        $this->openInBrowser($session);

        // The state poll exposes the flag to the page
        $this->get('/edit-session-state')->assertOk()->assertJson(['ai_available' => true]);

        // Valid request: accepted (the SSE publish itself is fire-and-forget)
        $this->postJson('/edit-session-retranslate', ['key' => 'Hello', 'id' => 'req-1'])
            ->assertOk()->assertJson(['requested' => true]);

        // The id is required (browser retries reuse it, the mod dedupes on it)
        $this->postJson('/edit-session-retranslate', ['key' => 'Hello'])->assertStatus(422);

        // Metadata keys are never relayed
        $this->postJson('/edit-session-retranslate', ['key' => '_uuid', 'id' => 'req-2'])->assertStatus(422);
    }

    public function test_mod_side_state_reports_saves_without_moving_the_file(): void
    {
        $this->initSession();
        $session = EditSessionToken::first();
        $this->openInBrowser($session);

        $before = $this->getJson('/api/v1/edit-session/' . $session->mod_key . '/state')
            ->assertOk()
            ->assertJson(['pending_changes' => 0, 'browser_left' => false]);

        $hashBefore = $before->json('content_hash');
        $this->assertNotEmpty($hashBefore, 'the state must carry the identity of the file');

        // A browser save moves the hash — which is the whole point: a client can tell there is
        // something to fetch WITHOUT streaming the entire translation to find out.
        $this->postJson('/edit-session-save', [
            'selections' => [
                ['key' => 'Hello', 'value' => 'Salut', 'tag' => 'A', 'source' => 'manual'],
            ],
        ])->assertOk();

        $after = $this->getJson('/api/v1/edit-session/' . $session->mod_key . '/state')->assertOk();
        $this->assertNotSame($hashBefore, $after->json('content_hash'));
        $this->assertSame(1, $after->json('pending_changes'));

        // ⚠ Asking is not being present: polling must not keep a session alive on behalf of a
        // window nobody is looking at. Only keepalive says "still here".
        $seenBefore = $session->fresh()->game_last_seen_at;
        $this->getJson('/api/v1/edit-session/' . $session->mod_key . '/state')->assertOk();
        $this->assertEquals($seenBefore, $session->fresh()->game_last_seen_at);

        // Unknown key: refused, and never with the shape of an answer.
        $this->getJson('/api/v1/edit-session/' . str_repeat('a', 64) . '/state')->assertStatus(404);
    }

    public function test_retranslation_answer_reaches_the_page_without_writing_the_file(): void
    {
        $this->postJson('/api/v1/edit-session/init', [
            'content' => self::CONTENT,
            'game_name' => 'Test Game',
            'ai_available' => true,
        ])->assertOk();
        $session = EditSessionToken::first();
        $this->openInBrowser($session);

        $hashBefore = $session->fresh()->content_hash;

        // The mod answers a retranslation. This is a PROPOSAL: the whole point is
        // that it travels WITHOUT the file being rewritten, so the browser's Save
        // stays the only thing that writes.
        $this->postJson('/api/v1/edit-session/' . $session->mod_key . '/retranslation', [
            'id' => 'req-1',
            'key' => 'Hello',
            'value' => 'Salut',
            'outcome' => 'replaced',
        ])->assertOk()->assertJson(['received' => true]);

        $this->assertSame($hashBefore, $session->fresh()->content_hash, 'a proposal must not touch the file');

        $expected = ['id' => 'req-1', 'key' => 'Hello', 'value' => 'Salut', 'outcome' => 'replaced'];

        $this->get('/edit-session-state')->assertOk()->assertJson(['retranslations' => [$expected]]);

        // Read again: still there. Reading must not consume, or a second tab open
        // on the same session would swallow a proposal the first never learns about.
        $this->get('/edit-session-state')->assertOk()->assertJson(['retranslations' => [$expected]]);

        // The browser re-emits a pending request every 30s, always with the same
        // id — the answer must not pile up as several proposals for one line.
        $this->postJson('/api/v1/edit-session/' . $session->mod_key . '/retranslation', [
            'id' => 'req-1',
            'key' => 'Hello',
            'value' => 'Salut',
            'outcome' => 'replaced',
        ])->assertOk();

        $response = $this->get('/edit-session-state')->assertOk();
        $this->assertCount(1, $response->json('retranslations'));
    }

    public function test_retranslation_answer_refused_for_an_unknown_session(): void
    {
        $this->initSession();

        // 64 valid-format characters that belong to no session
        $this->postJson('/api/v1/edit-session/' . str_repeat('a', 64) . '/retranslation', [
            'id' => 'req-1',
            'key' => 'Hello',
            'value' => 'Salut',
            'outcome' => 'replaced',
        ])->assertStatus(404);

        $session = EditSessionToken::first();

        // An outcome the page would not know how to read is refused rather than stored
        $this->postJson('/api/v1/edit-session/' . $session->mod_key . '/retranslation', [
            'id' => 'req-1',
            'key' => 'Hello',
            'outcome' => 'whatever',
        ])->assertStatus(422);
    }

    public function test_retranslate_rejected_without_ai_backend(): void
    {
        $this->initSession();
        $session = EditSessionToken::first();
        $this->openInBrowser($session);

        $this->postJson('/edit-session-retranslate', ['key' => 'Hello', 'id' => 'req-3'])->assertStatus(422);
    }

    public function test_mod_update_can_toggle_ai_availability(): void
    {
        $this->initSession();
        $session = EditSessionToken::first();
        $this->assertFalse($session->ai_available);

        $this->postJson('/api/v1/edit-session/' . $session->mod_key . '/update', [
            'content' => self::CONTENT,
            'ai_available' => true,
            'ai_model' => 'qwen2',
        ])->assertOk();

        $session->refresh();
        $this->assertTrue($session->ai_available);
        $this->assertSame('qwen2', $session->ai_model);
    }

    public function test_mod_can_end_session_with_mod_key(): void
    {
        $this->initSession();
        $session = EditSessionToken::first();
        $filePath = $session->getContentFilePath();

        $this->deleteJson('/api/v1/edit-session/' . $session->mod_key)
            ->assertOk()->assertJson(['ended' => true]);

        $this->assertDatabaseCount('edit_session_tokens', 0);
        $this->assertFileDoesNotExist($filePath);

        // Idempotent on an already-gone session
        $this->deleteJson('/api/v1/edit-session/' . $session->mod_key)
            ->assertOk()->assertJson(['ended' => true]);
    }

    public function test_browser_alone_keeps_the_session_alive_past_the_old_window(): void
    {
        // The bug this replaces: the expiry was tied to SESSION_LIFETIME
        // (120 min) and only the game's keepalive pushed it back, so an open
        // editor with the game closed died on the server's clock mid-edit.
        $this->initSession();
        $session = EditSessionToken::first();
        $this->openInBrowser($session);

        // No keepalive from the mod at any point — only the page heartbeat
        $this->travel(3)->hours();
        $this->get('/edit-session-state')->assertOk();

        $this->travel(3)->hours();
        $this->get('/edit-session-state')->assertOk();
        $this->get('/edit-session-data')->assertOk();

        // Six hours in, with the game silent throughout: still fully usable
        $this->postJson('/edit-session-save', [
            'selections' => [['key' => 'Hello', 'value' => 'Salut', 'tag' => 'A', 'source' => 'manual']],
        ])->assertOk();
    }

    public function test_browser_keeps_renewing_the_window_while_the_game_answers(): void
    {
        $this->initSession();
        $session = EditSessionToken::first();
        $this->openInBrowser($session);

        // Game and browser both alive: each heartbeat restarts the whole window
        $this->travel(3)->hours();
        $this->postJson('/api/v1/edit-session/' . $session->mod_key . '/keepalive')->assertOk();
        $this->get('/edit-session-state')->assertOk();

        $this->assertTrue($session->fresh()->expires_at->gt(now()->addHours(23)));
    }

    public function test_a_forgotten_tab_stops_renewing_a_session_whose_game_is_gone(): void
    {
        // A crashed or killed game never sends its DELETE. The browser must not
        // renew such a session for ever: it holds one of the few concurrent
        // slots the host allows, and every save it accepts goes nowhere.
        $this->initSession();
        $session = EditSessionToken::first();
        $this->openInBrowser($session);

        // The tab polls faithfully for a full day; the game never calls back
        foreach ([6, 6, 6, 7] as $hours) {
            $this->travel($hours)->hours();
            $this->get('/edit-session-state');
        }

        // Collected on the window counted from the GAME's last sign of life
        $this->get('/edit-session-state')->assertStatus(410);
        $this->get('/edit-session-data')->assertStatus(410);
    }

    public function test_state_reports_the_game_going_silent_and_coming_back(): void
    {
        $this->initSession();
        $session = EditSessionToken::first();
        $this->openInBrowser($session);

        $this->get('/edit-session-state')->assertOk()->assertJson(['game_responding' => true]);

        // Past the tolerance (three missed keepalives), the page says so
        $this->travel(EditSessionToken::GAME_SILENT_AFTER_MINUTES + 1)->minutes();
        $response = $this->get('/edit-session-state')->assertOk();
        $this->assertFalse($response->json('game_responding'));
        $this->assertGreaterThanOrEqual(
            EditSessionToken::GAME_SILENT_AFTER_MINUTES * 60,
            $response->json('game_seen_seconds_ago')
        );

        // One keepalive — the player switched back to the game — clears it
        $this->postJson('/api/v1/edit-session/' . $session->mod_key . '/keepalive')->assertOk();
        $this->get('/edit-session-state')->assertOk()->assertJson(['game_responding' => true]);
    }

    public function test_unfetched_edits_survive_the_early_collection(): void
    {
        // Saving in the browser does NOT put the work on the player's machine.
        // Until the mod fetches the file, this session is the only place it
        // exists — collecting it an hour later would destroy work the user was
        // told was saved.
        $this->initSession();
        $session = EditSessionToken::first();
        $this->openInBrowser($session);

        $this->postJson('/edit-session-save', [
            'selections' => [['key' => 'Hello', 'value' => 'Salut', 'tag' => 'A', 'source' => 'manual']],
        ])->assertOk();
        $this->assertSame(1, $session->fresh()->pending_changes);

        $this->post('/edit-session-leave')->assertNoContent();
        $this->travel(EditSessionToken::ABANDONED_TTL_MINUTES + 1)->minutes();
        EditSessionToken::cleanupExpired();

        $this->assertDatabaseCount('edit_session_tokens', 1);

        // The game comes back and reads the file: nothing is owed any more, and
        // only now does the session become disposable
        $this->get('/api/v1/edit-session/' . $session->mod_key . '/content')->assertOk();
        $this->assertSame(0, $session->fresh()->pending_changes);

        $this->post('/edit-session-leave')->assertNoContent();
        $this->travel(EditSessionToken::ABANDONED_TTL_MINUTES + 1)->minutes();
        EditSessionToken::cleanupExpired();

        $this->assertDatabaseCount('edit_session_tokens', 0);
    }

    public function test_a_session_both_sides_left_is_collected_early(): void
    {
        $this->initSession();
        $session = EditSessionToken::first();
        $this->openInBrowser($session);
        $filePath = $session->getContentFilePath();

        // Both sides say goodbye: the game stops calling in, the page fires its
        // pagehide beacon. Nobody is coming back for this one, and it would
        // otherwise hold a concurrency slot and a multi-MB file for a full day.
        $this->post('/edit-session-leave')->assertNoContent();

        $this->travel(EditSessionToken::ABANDONED_TTL_MINUTES + 1)->minutes();
        EditSessionToken::cleanupExpired();

        $this->assertDatabaseCount('edit_session_tokens', 0);
        $this->assertFileDoesNotExist($filePath);
    }

    public function test_a_session_is_kept_while_either_side_may_return(): void
    {
        // Browser gone, but the game is still calling in: someone is playing
        // and will save again. Nothing may be collected here.
        $this->initSession();
        $session = EditSessionToken::first();
        $this->openInBrowser($session);
        $this->post('/edit-session-leave')->assertNoContent();

        $this->travel(EditSessionToken::ABANDONED_TTL_MINUTES + 1)->minutes();
        $this->postJson('/api/v1/edit-session/' . $session->mod_key . '/keepalive')->assertOk();

        EditSessionToken::cleanupExpired();
        $this->assertDatabaseCount('edit_session_tokens', 1);

        // And the mirror case: the game is long gone, but the tab never sent a
        // beacon (browser crash, killed tab). Silence is not a goodbye — the
        // full window applies, because the work may still be wanted.
        $session->fresh()->update(['browser_left_at' => null]);
        $this->travel(EditSessionToken::ABANDONED_TTL_MINUTES + 1)->minutes();

        EditSessionToken::cleanupExpired();
        $this->assertDatabaseCount('edit_session_tokens', 1);
    }

    public function test_connection_labels_are_rendered_as_readable_text(): void
    {
        $this->initSession();
        $session = EditSessionToken::first();
        $this->openInBrowser($session);

        $html = $this->get('/fr/edit-session')->assertOk()->getContent();

        // Rendered by Blade, not handed to an Alpine expression: this project
        // runs the CSP build of Alpine, whose evaluator returns string literals
        // verbatim — a @js-escaped accent reached the screen as "du00e9connectu00e9".
        // Present as readable text in the markup itself. Put them back into an
        // Alpine expression and they leave the HTML altogether, so these two
        // assertions are exactly the guard against the bug coming back.
        $this->assertStringContainsString('Jeu déconnecté', $html);
        $this->assertStringContainsString('Jeu connecté', $html);
    }

    public function test_state_exposes_the_game_connection_state(): void
    {
        $this->initSession();
        $session = EditSessionToken::first();
        $this->openInBrowser($session);

        $state = $this->get('/edit-session-state')->assertOk()->json();

        // Presence comes from the mod's open stream, which only the SSE server
        // knows about. There is none here — and no Redis either — so the field
        // must be present and null: the page shows nothing rather than
        // declaring a running game dead because the infrastructure is missing.
        $this->assertArrayHasKey('game_connected', $state);
        $this->assertNull($state['game_connected']);

        // ...and the timestamp fallback still answers, which is what the page
        // falls back on in exactly this situation
        $this->assertTrue($state['game_responding']);
    }

    public function test_mod_content_download_counts_as_game_presence(): void
    {
        $this->initSession();
        $session = EditSessionToken::first();
        $this->openInBrowser($session);

        $this->travel(EditSessionToken::GAME_SILENT_AFTER_MINUTES + 1)->minutes();
        $this->assertFalse($session->fresh()->isGameResponding());

        // Fetching the file is the game applying a save: it is alive
        $this->get('/api/v1/edit-session/' . $session->mod_key . '/content')->assertOk();

        $this->assertTrue($session->fresh()->isGameResponding());
    }

    public function test_session_survives_the_loss_of_the_web_session(): void
    {
        // The token no longer lives in the web session: a sleeping machine or
        // a break past SESSION_LIFETIME must not cost the user pending edits,
        // which are keyed on the session id and cannot be restored elsewhere.
        $this->initSession();
        $session = EditSessionToken::first();
        $this->openInBrowser($session);

        $this->flushSession();

        $this->get('/edit-session-state')->assertOk();
        $this->get('/edit-session')->assertOk()->assertViewIs('edit-session.show');
        $this->postJson('/edit-session-save', [
            'selections' => [['key' => 'Hello', 'value' => 'Salut', 'tag' => 'A', 'source' => 'manual']],
        ])->assertOk();
    }

    public function test_session_cookie_is_http_only_and_cleared_on_end(): void
    {
        $this->initSession();
        $session = EditSessionToken::first();

        $opened = $this->get('/edit-session/' . $session->token);
        $cookie = collect($opened->headers->getCookies())
            ->first(fn ($c) => $c->getName() === self::SESSION_COOKIE);

        $this->assertNotNull($cookie, 'The session cookie must be set when the link is opened.');
        // Unreadable from JS: the token is the only credential the page holds
        $this->assertTrue($cookie->isHttpOnly());
        // Outlives the web session cookie — that is the whole point
        $this->assertGreaterThan(
            now()->addMinutes((int) config('session.lifetime'))->getTimestamp(),
            $cookie->getExpiresTime()
        );

        $this->withCookie(self::SESSION_COOKIE, $session->token);
        $ended = $this->post('/edit-session-end');

        $cleared = collect($ended->headers->getCookies())
            ->first(fn ($c) => $c->getName() === self::SESSION_COOKIE);
        $this->assertNotNull($cleared, 'Ending the session must clear its cookie.');
        $this->assertLessThan(now()->getTimestamp(), $cleared->getExpiresTime());
    }

    // ── Several games edited at once, in one browser ──────────────────────
    // One cookie held one token, so opening a second game's editor rebound
    // every open tab to it: the older tab loaded the other game's file and
    // its next save wrote into that game's session. The cookie now holds a
    // list and each page states which session it means.

    /**
     * Put both sessions in the browser, as two consecutive "open" clicks do.
     * The plain-text value mirrors what the controller writes (a JSON list);
     * withCookie() encrypts it the way the middleware expects.
     */
    private function openBothInBrowser(EditSessionToken $first, EditSessionToken $second): void
    {
        $this->get('/edit-session/' . $first->token);
        $this->get('/edit-session/' . $second->token);
        $this->withCookie(self::SESSION_COOKIE, json_encode([$second->token, $first->token]));
        $this->withCredentials();
    }

    /** Two live sessions, distinguishable by their content. */
    private function initTwoSessions(): array
    {
        $this->initSession(['Hello' => ['v' => 'Bonjour', 't' => 'A']]);
        $first = EditSessionToken::latest('id')->first();

        $this->initSession(['Ciao' => ['v' => 'Salut', 't' => 'A']]);
        $second = EditSessionToken::latest('id')->first();

        return [$first, $second];
    }

    public function test_two_sessions_in_one_browser_read_their_own_content(): void
    {
        [$first, $second] = $this->initTwoSessions();
        $this->openBothInBrowser($first, $second);

        $firstPayload = json_decode(
            $this->get('/edit-session-data?s=' . $first->id)->streamedContent(),
            true
        );
        $secondPayload = json_decode(
            $this->get('/edit-session-data?s=' . $second->id)->streamedContent(),
            true
        );

        $this->assertArrayHasKey('Hello', $firstPayload['content']);
        $this->assertArrayNotHasKey('Ciao', $firstPayload['content']);
        $this->assertArrayHasKey('Ciao', $secondPayload['content']);
        $this->assertArrayNotHasKey('Hello', $secondPayload['content']);
    }

    public function test_a_save_never_lands_in_the_other_session(): void
    {
        [$first, $second] = $this->initTwoSessions();
        $this->openBothInBrowser($first, $second);

        $this->postJson('/edit-session-save?s=' . $first->id, [
            'selections' => [
                ['key' => 'Hello', 'value' => 'Salutations', 'tag' => 'M', 'source' => 'manual'],
            ],
        ])->assertOk();

        $firstContent = json_decode(file_get_contents($first->getContentFilePath()), true);
        $secondContent = json_decode(file_get_contents($second->getContentFilePath()), true);

        $this->assertSame('Salutations', $firstContent['Hello']['v']);
        // The other game's file must not have gained a key it never had
        $this->assertArrayNotHasKey('Hello', $secondContent);
        $this->assertSame('Salut', $secondContent['Ciao']['v']);
    }

    public function test_an_ambiguous_request_is_refused_rather_than_guessed(): void
    {
        [$first, $second] = $this->initTwoSessions();
        $this->openBothInBrowser($first, $second);

        // A tab from before the fix: no id, and two sessions to mean
        $this->getJson('/edit-session-state')->assertStatus(410);

        // A single remembered session stays unambiguous — old tabs keep working
        $this->withCookie(self::SESSION_COOKIE, $first->token);
        $this->getJson('/edit-session-state')->assertOk();
    }

    public function test_ending_one_session_leaves_the_other_usable(): void
    {
        [$first, $second] = $this->initTwoSessions();
        $this->openBothInBrowser($first, $second);

        $this->post('/edit-session-end?s=' . $first->id)->assertRedirect();

        $this->assertNull(EditSessionToken::find($first->id));
        $this->getJson('/edit-session-state?s=' . $second->id)->assertOk();
    }

    public function test_ending_a_session_the_page_cannot_name_says_so(): void
    {
        [$first, $second] = $this->initTwoSessions();
        $this->openBothInBrowser($first, $second);

        // A tab opened before the multi-session cookie: it posts no id, and with
        // two sessions held there is nothing to guess. Ending NOTHING while
        // announcing success is the worst outcome — the session stays alive and
        // the user believes it is closed.
        $this->post('/edit-session-end')->assertSessionHasErrors('error');

        $this->assertNotNull(EditSessionToken::find($first->id));
        $this->assertNotNull(EditSessionToken::find($second->id));
    }

    public function test_the_bare_page_url_lands_on_the_latest_session(): void
    {
        [$first, $second] = $this->initTwoSessions();
        $this->openBothInBrowser($first, $second);

        // Bookmark or old tab: never "expired" while a session is alive
        $this->get('/edit-session')
            ->assertStatus(303)
            ->assertRedirect(route('edit-session.show', ['s' => $second->id]));
    }
}
