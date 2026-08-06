<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\Translation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Repairing columns derived from a file must leave the translation's history alone.
 *
 * The command recomputes values FROM the stored file — nobody edited anything. Letting it move
 * the timestamps would say the opposite: the ranking reads updated_at as freshness, and a
 * translation whose content date is unknown falls back to it, so a maintenance run would date
 * every translation to the day it ran.
 */
class BackfillDerivedColumnsTest extends TestCase
{
    use RefreshDatabase;

    private array $createdFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->createdFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    private function makeTranslation(array $content): Translation
    {
        $game = Game::firstOrCreate(['slug' => 'backfill-game'], ['name' => 'Backfill Game']);
        $user = User::factory()->create();

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
            'file_hash' => 'hash-' . uniqid(),
            'line_count' => 1,
        ])->save();

        return $translation->refresh();
    }

    public function test_repairing_columns_does_not_make_a_translation_look_freshly_worked_on(): void
    {
        $translation = $this->makeTranslation([
            'Hello' => ['v' => 'Bonjour', 't' => 'H'],
            'Qapla' => ['v' => 'Qapla\'', 't' => 'S'],
        ]);

        // An old translation nobody has touched in months
        $this->travel(-90)->days();
        $translation->forceFill([
            'updated_at' => now(),
            'content_updated_at' => now(),
            'skipped_count' => 0,
        ])->saveQuietly();
        $translation->refresh();

        $before = $translation->updated_at->timestamp;
        $contentBefore = $translation->content_updated_at->timestamp;

        $this->travelBack();
        $this->artisan('translations:backfill-derived')->assertSuccessful();

        $translation->refresh();

        // The repair DID fix the derived value...
        $this->assertSame(1, $translation->skipped_count);
        // ...without rewriting when the translation was last worked on
        $this->assertSame($before, $translation->updated_at->timestamp);
        $this->assertSame($contentBefore, $translation->content_updated_at->timestamp);
    }
}
