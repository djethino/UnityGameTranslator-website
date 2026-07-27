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

        $response->assertStatus(303)
            ->assertRedirect(route('edit-session.show'));

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
}
