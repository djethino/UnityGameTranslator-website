<?php

namespace Tests\Feature\Api;

use App\Models\ApiToken;
use App\Models\Translation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A file with no line in it never becomes a translation.
 *
 * A game set up a minute ago holds exactly `{"_uuid": …, "_game": …}`, and both the mod and the
 * Manager offered Publish on it. Everything about that file was valid — JSON, a uuid, and
 * validateEntries skips every key starting with an underscore — so it produced a catalogue row, a
 * stored file and a lineage for content that does not exist.
 *
 * ⚠ The refusal lives in TranslationService::parseAndValidate, which is what BOTH upload paths go
 * through: guarding the buttons in one client leaves the door open to the other and to curl.
 *
 * ⚠ And it must not swallow the capture case. A file whose lines are all untranslated is a real
 * starting point — the web upload asks its author about it, and the grace period removes it later
 * if nothing comes. Those lines exist; these do not.
 */
class EmptyUploadTest extends TestCase
{
    use RefreshDatabase;

    private function upload(User $user, array $content): \Illuminate\Testing\TestResponse
    {
        $token = ApiToken::createForUser($user, 'test')->plain_token;

        return $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/v1/translations', [
                'game_name' => 'Empty Upload Game',
                'source_language' => 'English',
                'target_language' => 'French',
                'content' => json_encode($content),
            ]);
    }

    public function test_a_file_with_no_line_is_refused(): void
    {
        $response = $this->upload(User::factory()->create(), [
            '_uuid' => 'uuid-' . uniqid(),
            '_game' => ['name' => 'Empty Upload Game', 'steam_id' => '1'],
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('nothing to publish', $response->json('error') ?? '');
        $this->assertSame(0, Translation::count(), 'nothing may be stored for an empty file');
    }

    public function test_a_capture_only_file_is_still_accepted(): void
    {
        // Untranslated, on purpose: the author collects the game's own text first. That is a
        // decision they are asked about elsewhere, not something this guard may refuse.
        $response = $this->upload(User::factory()->create(), [
            '_uuid' => 'uuid-' . uniqid(),
            '_game' => ['name' => 'Empty Upload Game', 'steam_id' => '1'],
            'Hello' => ['v' => null, 't' => 'S'],
        ]);

        $response->assertSuccessful();
        $this->assertSame(1, Translation::count());
    }
}
