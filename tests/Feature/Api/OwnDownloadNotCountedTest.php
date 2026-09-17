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
 * An author fetching their OWN file is not a download.
 *
 * The mod reads a published copy back to count what changed on the site since the game last
 * synced, and the Manager to compare — both signed in as the file's author. Counted, every such
 * look added a download to the catalogue's figure for a file nobody took, and the figure is one
 * of the things the catalogue is measured on. Anybody else fetching it is a download, signed in
 * or not.
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
}
