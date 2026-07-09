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

    protected function tearDown(): void
    {
        $disk = Storage::disk('local');
        foreach ($disk->files(EditSessionToken::CONTENT_DIR) as $file) {
            $disk->delete($file);
        }
        parent::tearDown();
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
}
