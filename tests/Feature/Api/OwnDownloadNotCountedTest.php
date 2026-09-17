<?php

namespace Tests\Feature\Api;

use App\Models\ApiToken;
use App\Models\Game;
use App\Models\Translation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * "Downloads" counts takes, not fetches.
 *
 * Two fetches are not takes. An author reading their OWN file back — the mod counts what changed
 * on the site since the game last synced, the Manager compares — and a client refreshing a lineage
 * it already holds, which says so with `update=1`. Counted, every such fetch added a download to
 * the catalogue's figure for a file nobody took, and that figure is one of the things the
 * catalogue is ranked on. Anybody else fetching it for the first time is a download, signed in or
 * not; a client that predates the flag is counted as before.
 */
class OwnDownloadNotCountedTest extends TestCase
{
    use RefreshDatabase;

    private function makeTranslation(User $owner): Translation
    {
        $game = Game::firstOrCreate(['slug' => 'own-download-game'], ['name' => 'Own Download Game']);

        $path = 'translations/own-download-' . uniqid() . '.json';
        Storage::disk('local')->put($path, json_encode([
            '_uuid' => 'uuid-own-download',
            'Shop' => ['v' => 'Boutique', 't' => 'H'],
        ], JSON_UNESCAPED_UNICODE));

        $translation = new Translation();
        $translation->forceFill([
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'source_language' => 'English',
            'target_language' => 'French',
            'visibility' => 'public',
            'file_uuid' => 'uuid-own-download',
            'file_path' => $path,
            'file_hash' => 'hash-' . uniqid(),
            'human_count' => 1,
            'download_count' => 0,
        ])->save();

        return $translation->refresh();
    }

    private function headers(User $user): array
    {
        return ['Authorization' => 'Bearer ' . ApiToken::createForUser($user, 'own download test')->plain_token];
    }

    public function test_the_author_fetching_their_own_file_is_not_counted(): void
    {
        $author = User::factory()->create();
        $translation = $this->makeTranslation($author);

        $this->getJson("/api/v1/translations/{$translation->id}/download", $this->headers($author))
            ->assertOk();

        $this->assertSame(0, $translation->fresh()->download_count);
    }

    public function test_anybody_else_fetching_it_is_a_download(): void
    {
        $author = User::factory()->create();
        $reader = User::factory()->create();
        $translation = $this->makeTranslation($author);

        $this->getJson("/api/v1/translations/{$translation->id}/download", $this->headers($reader))
            ->assertOk();
        $this->getJson("/api/v1/translations/{$translation->id}/download")
            ->assertOk();

        $this->assertSame(2, $translation->fresh()->download_count);
    }

    public function test_refreshing_a_held_copy_is_not_counted(): void
    {
        $author = User::factory()->create();
        $reader = User::factory()->create();
        $translation = $this->makeTranslation($author);

        $this->getJson("/api/v1/translations/{$translation->id}/download?update=1", $this->headers($reader))
            ->assertOk();
        $this->getJson("/api/v1/translations/{$translation->id}/download?update=1")
            ->assertOk();

        $this->assertSame(0, $translation->fresh()->download_count);
    }
}
