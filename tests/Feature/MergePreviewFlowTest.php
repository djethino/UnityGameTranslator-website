<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\Game;
use App\Models\MergePreviewToken;
use App\Models\Translation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * End-to-end tests of the mod → browser merge-preview flow.
 *
 * The local content must never transit through the database column or the
 * session payload (both silently drop large payloads on shared-hosting
 * MySQL): it lives as a file on the private disk, referenced by a one-time
 * token, and is streamed to the page by the data endpoint.
 */
class MergePreviewFlowTest extends TestCase
{
    use RefreshDatabase;

    private array $createdFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Token content files must never touch the real storage disk:
        // without the fake, test files land in storage/app/private and any
        // cleanup sweep would also delete LIVE dev merge sessions.
        // (Translation files still use storage_path() directly and are
        // tracked/deleted per-test via $createdFiles.)
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        foreach ($this->createdFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    /**
     * Create a translation with a real JSON file in the private storage disk.
     */
    private function makeTranslation(User $user, array $content): Translation
    {
        $game = Game::forceCreate(['name' => 'Test Game', 'slug' => 'test-game-' . uniqid()]);

        $dir = storage_path('app/private/translations');
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $relativePath = 'translations/test_' . uniqid('', true) . '.json';
        $fullPath = storage_path('app/private/' . $relativePath);
        file_put_contents($fullPath, json_encode($content, JSON_UNESCAPED_UNICODE));
        $this->createdFiles[] = $fullPath;

        $translation = new Translation();
        $translation->forceFill([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'source_language' => 'English',
            'target_language' => 'French',
            'file_path' => $relativePath,
            'file_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'visibility' => 'public',
            'line_count' => count($content),
        ])->save();

        return $translation;
    }

    /**
     * Call the API init endpoint as the mod would, returns the response.
     */
    private function initMergePreview(User $user, Translation $translation, array $localContent)
    {
        $apiToken = ApiToken::createForUser($user);

        return $this->postJson('/api/v1/merge-preview/init', [
            'translation_id' => $translation->id,
            'local_content' => $localContent,
        ], ['Authorization' => 'Bearer ' . $apiToken->plain_token]);
    }

    private const ONLINE_CONTENT = [
        '_uuid' => 'test-uuid-123',
        'Shared' => ['v' => 'Online value', 't' => 'H'],
        'OnlineOnly' => ['v' => 'Server only', 't' => 'A'],
    ];

    private const LOCAL_CONTENT = [
        'Shared' => ['v' => 'Local value', 't' => 'H'],
        'LocalOnly' => ['v' => 'Local only', 't' => 'A'],
    ];

    public function test_init_stores_content_in_file_not_in_database(): void
    {
        $user = User::factory()->create()->refresh();
        $translation = $this->makeTranslation($user, self::ONLINE_CONTENT);

        $response = $this->initMergePreview($user, $translation, self::LOCAL_CONTENT);

        $response->assertOk()->assertJsonStructure(['token', 'url', 'expires_at']);
        $token = $response->json('token');

        $row = MergePreviewToken::where('token', $token)->first();
        $this->assertNotNull($row);
        $this->assertNull($row->consumed_at);

        $file = MergePreviewToken::CONTENT_DIR . '/' . $token . '.json';
        Storage::disk('local')->assertExists($file);
        $this->assertSame(
            self::LOCAL_CONTENT,
            json_decode(Storage::disk('local')->get($file), true)
        );
    }

    public function test_full_mod_flow_end_to_end(): void
    {
        $user = User::factory()->create()->refresh();
        $translation = $this->makeTranslation($user, self::ONLINE_CONTENT);

        $token = $this->initMergePreview($user, $translation, self::LOCAL_CONTENT)->json('token');

        // Browser opens the tokenized URL: consumed + redirected token-less
        $response = $this->get("/translations/{$translation->id}/merge-preview?token={$token}");
        $response->assertStatus(303)
            ->assertRedirect(route('translations.merge-preview', $translation));

        $row = MergePreviewToken::where('token', $token)->first();
        $this->assertNotNull($row, 'Token row must survive consumption (SSE publish needs it)');
        $this->assertNotNull($row->consumed_at);
        $this->assertSame($token, session('merge_preview_token'));

        // Post-redirect page load: renders without inlining any content
        $page = $this->get(route('translations.merge-preview', $translation));
        $page->assertOk();
        $this->assertStringNotContainsString('Local value', $page->getContent());
        $this->assertStringNotContainsString('Online value', $page->getContent());

        // Data endpoint streams both sides
        $data = $this->get(route('translations.merge-preview.data', $translation));
        $data->assertOk()->assertHeader('Content-Type', 'application/json; charset=utf-8');
        $json = json_decode($data->streamedContent(), true);
        $this->assertSame(self::LOCAL_CONTENT, $json['local']);
        $this->assertSame(self::ONLINE_CONTENT, $json['online']);
    }

    public function test_consumed_token_cannot_authenticate_again(): void
    {
        $user = User::factory()->create()->refresh();
        $translation = $this->makeTranslation($user, self::ONLINE_CONTENT);

        $token = $this->initMergePreview($user, $translation, self::LOCAL_CONTENT)->json('token');

        $this->get("/translations/{$translation->id}/merge-preview?token={$token}")->assertStatus(303);

        // A different browser replaying the URL (e.g. from history/logs)
        $this->flushSession();
        auth()->logout();
        $this->get("/translations/{$translation->id}/merge-preview?token={$token}")->assertForbidden();
    }

    public function test_expired_token_rejected(): void
    {
        $user = User::factory()->create()->refresh();
        $translation = $this->makeTranslation($user, self::ONLINE_CONTENT);

        $token = $this->initMergePreview($user, $translation, self::LOCAL_CONTENT)->json('token');
        MergePreviewToken::where('token', $token)->update(['expires_at' => now()->subMinute()]);

        $this->get("/translations/{$translation->id}/merge-preview?token={$token}")->assertForbidden();
    }

    public function test_scoped_session_recovery_fails_loudly_when_file_missing(): void
    {
        $user = User::factory()->create()->refresh();
        $translation = $this->makeTranslation($user, self::ONLINE_CONTENT);

        $token = $this->initMergePreview($user, $translation, self::LOCAL_CONTENT)->json('token');
        $this->get("/translations/{$translation->id}/merge-preview?token={$token}")->assertStatus(303);

        Storage::disk('local')->delete(MergePreviewToken::CONTENT_DIR . '/' . $token . '.json');

        // Scoped session (token login): closed entirely, explicit error
        $response = $this->get(route('translations.merge-preview', $translation));
        $response->assertRedirect(route('home'))
            ->assertSessionHas('error', __('merge_preview.error_session_expired'));
        $this->assertGuest('web');
    }

    public function test_regular_session_recovery_shows_explicit_error_when_file_missing(): void
    {
        $user = User::factory()->create()->refresh();
        $translation = $this->makeTranslation($user, self::ONLINE_CONTENT);

        // User already logged in on the web before opening the mod link
        $this->actingAs($user);

        $token = $this->initMergePreview($user, $translation, self::LOCAL_CONTENT)->json('token');
        $this->get("/translations/{$translation->id}/merge-preview?token={$token}")->assertStatus(303);

        Storage::disk('local')->delete(MergePreviewToken::CONTENT_DIR . '/' . $token . '.json');

        // Web session survives, page renders the explicit expiration error
        $response = $this->get(route('translations.merge-preview', $translation));
        $response->assertOk()->assertSee(__('merge_preview.error_session_expired'));
        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_data_endpoint_requires_auth(): void
    {
        $user = User::factory()->create()->refresh();
        $translation = $this->makeTranslation($user, self::ONLINE_CONTENT);

        $this->get(route('translations.merge-preview.data', $translation))->assertUnauthorized();
    }

    public function test_data_endpoint_forbidden_for_non_owner(): void
    {
        $owner = User::factory()->create()->refresh();
        $other = User::factory()->create()->refresh();
        $translation = $this->makeTranslation($owner, self::ONLINE_CONTENT);

        $this->actingAs($other)
            ->get(route('translations.merge-preview.data', $translation))
            ->assertForbidden();
    }

    public function test_web_flow_data_endpoint_returns_null_local(): void
    {
        $user = User::factory()->create()->refresh();
        $translation = $this->makeTranslation($user, self::ONLINE_CONTENT);

        $data = $this->actingAs($user)->get(route('translations.merge-preview.data', $translation));
        $data->assertOk();
        $json = json_decode($data->streamedContent(), true);
        $this->assertNull($json['local']);
        $this->assertSame(self::ONLINE_CONTENT, $json['online']);
    }

    public function test_apply_deletes_tokens_and_files_and_updates_translation(): void
    {
        $user = User::factory()->create()->refresh();
        $translation = $this->makeTranslation($user, self::ONLINE_CONTENT);

        $token = $this->initMergePreview($user, $translation, self::LOCAL_CONTENT)->json('token');
        $this->get("/translations/{$translation->id}/merge-preview?token={$token}")->assertStatus(303);

        $response = $this->post(route('translations.merge-preview.apply', $translation), [
            'selections' => [
                ['key' => 'LocalOnly', 'value' => 'Local only', 'tag' => 'A', 'source' => 'local'],
            ],
        ]);
        $response->assertRedirect(route('home')); // scoped session → logged out to home

        $this->assertSame(0, MergePreviewToken::count());
        Storage::disk('local')->assertMissing(MergePreviewToken::CONTENT_DIR . '/' . $token . '.json');

        $saved = json_decode(file_get_contents(storage_path('app/private/' . $translation->file_path)), true);
        // Tag A selected by a human becomes V
        $this->assertSame(['v' => 'Local only', 't' => 'V'], $saved['LocalOnly']);
    }

    public function test_apply_writes_explicit_tag_changes_as_is(): void
    {
        $user = User::factory()->create()->refresh();
        $translation = $this->makeTranslation($user, self::ONLINE_CONTENT);

        $token = $this->initMergePreview($user, $translation, self::LOCAL_CONTENT)->json('token');
        $this->get("/translations/{$translation->id}/merge-preview?token={$token}")->assertStatus(303);

        // Explicit dropdown gestures (source 'tagchange') bypass the A→V
        // promotion: without this, Invalidate (A) would be undone by the save
        $response = $this->post(route('translations.merge-preview.apply', $translation), [
            'selections' => [
                ['key' => 'Shared', 'value' => 'Online value', 'tag' => 'A', 'source' => 'tagchange'],
                ['key' => 'OnlineOnly', 'value' => 'Server only', 'tag' => 'V', 'source' => 'tagchange'],
            ],
        ]);
        $response->assertRedirect(route('home'));

        $saved = json_decode(file_get_contents(storage_path('app/private/' . $translation->file_path)), true);
        $this->assertSame(['v' => 'Online value', 't' => 'A'], $saved['Shared']);
        $this->assertSame(['v' => 'Server only', 't' => 'V'], $saved['OnlineOnly']);
    }

    public function test_new_init_replaces_previous_token_and_file(): void
    {
        $user = User::factory()->create()->refresh();
        $translation = $this->makeTranslation($user, self::ONLINE_CONTENT);

        $first = $this->initMergePreview($user, $translation, self::LOCAL_CONTENT)->json('token');
        $second = $this->initMergePreview($user, $translation, self::LOCAL_CONTENT)->json('token');

        $this->assertSame(1, MergePreviewToken::count());
        Storage::disk('local')->assertMissing(MergePreviewToken::CONTENT_DIR . '/' . $first . '.json');
        Storage::disk('local')->assertExists(MergePreviewToken::CONTENT_DIR . '/' . $second . '.json');
    }

    public function test_large_content_round_trip(): void
    {
        $user = User::factory()->create()->refresh();
        $translation = $this->makeTranslation($user, self::ONLINE_CONTENT);

        // ~2 MB: the size class that silently broke the DB/session-based flow
        $largeContent = [];
        for ($i = 0; $i < 8000; $i++) {
            $largeContent["Key {$i}"] = ['v' => str_repeat('Lorem ipsum dolor sit amet ', 8) . $i, 't' => 'H'];
        }

        $token = $this->initMergePreview($user, $translation, $largeContent)->json('token');
        $this->assertNotNull($token);

        $this->get("/translations/{$translation->id}/merge-preview?token={$token}")->assertStatus(303);
        $this->get(route('translations.merge-preview', $translation))->assertOk();

        $data = $this->get(route('translations.merge-preview.data', $translation));
        $data->assertOk();
        $json = json_decode($data->streamedContent(), true);
        $this->assertCount(8000, $json['local']);
        $this->assertSame($largeContent['Key 7999'], $json['local']['Key 7999']);
    }
}
