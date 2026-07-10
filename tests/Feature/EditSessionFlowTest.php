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

        $this->get('/edit-session/' . $session->token);
        $response = $this->get('/edit-session-data');

        $response->assertOk();
        $payload = json_decode($response->streamedContent(), true);
        $this->assertSame('Bonjour', $payload['content']['Hello']['v']);
    }

    public function test_save_applies_selections_preserves_metadata_and_extends_expiry(): void
    {
        $this->initSession();
        $session = EditSessionToken::first();
        $this->get('/edit-session/' . $session->token);

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
        $this->get('/edit-session/' . $session->token);
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
        $this->get('/edit-session/' . $session->token);
        $filePath = $session->getContentFilePath();

        $this->post('/edit-session-end')->assertRedirect(route('home'));

        $this->assertDatabaseCount('edit_session_tokens', 0);
        $this->assertFileDoesNotExist($filePath);
    }

    public function test_expired_session_rejected_everywhere(): void
    {
        $this->initSession();
        $session = EditSessionToken::first();
        $this->get('/edit-session/' . $session->token);

        $this->travel(3)->hours();

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
        $this->get('/edit-session/' . $session->token);

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
        $this->get('/edit-session/' . $session->token);
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
        $this->get('/edit-session/' . $session->token);

        $response = $this->get('/edit-session-state');

        $response->assertOk();
        $this->assertSame($session->fresh()->content_hash, $response->json('content_hash'));
        $this->assertNotNull($session->fresh()->browser_last_seen_at);
    }

    public function test_leave_beacon_marks_browser_away_and_state_rejoins(): void
    {
        $this->initSession();
        $session = EditSessionToken::first();
        $this->get('/edit-session/' . $session->token);

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
        $this->get('/edit-session/' . $session->token);
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
        $this->get('/edit-session/' . $session->token);

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

        $this->get('/edit-session/' . $session->token);

        // The state poll exposes the flag to the page
        $this->get('/edit-session-state')->assertOk()->assertJson(['ai_available' => true]);

        // Valid request: accepted (the SSE publish itself is fire-and-forget)
        $this->postJson('/edit-session-retranslate', ['key' => 'Hello'])
            ->assertOk()->assertJson(['requested' => true]);

        // Metadata keys are never relayed
        $this->postJson('/edit-session-retranslate', ['key' => '_uuid'])->assertStatus(422);
    }

    public function test_retranslate_rejected_without_ai_backend(): void
    {
        $this->initSession();
        $session = EditSessionToken::first();
        $this->get('/edit-session/' . $session->token);

        $this->postJson('/edit-session-retranslate', ['key' => 'Hello'])->assertStatus(422);
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
}
