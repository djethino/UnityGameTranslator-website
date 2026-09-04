<?php

namespace Tests\Feature\Api;

use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * What a `_uuid` may look like, given where it goes.
 *
 * 🔴 **It was accepted as any string.** The uuid names the stored file, fills a char(36) column and
 * keys the Redis channels of a live session — so a path chose a folder, anything past 36
 * characters was a 500 after the file had been written, and an EMPTY uuid made whoever published
 * it the Main of the lineage "" with every later publisher its branch. The mod writes a GUID and
 * refuses anything else on download; the site now refuses at the door what the mod could never
 * read back.
 */
class UploadUuidShapeTest extends TestCase
{
    use RefreshDatabase;

    private function publish(string $uuid): \Illuminate\Testing\TestResponse
    {
        $token = ApiToken::createForUser(User::factory()->create(), 'test')->plain_token;

        return $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/v1/translations', [
                'game_name' => 'Shape Game',
                'source_language' => 'English',
                'target_language' => 'French',
                'content' => json_encode([
                    '_uuid' => $uuid,
                    'Hello ' . uniqid() => ['v' => 'Bonjour', 't' => 'H'],
                ]),
            ]);
    }

    public static function refused(): array
    {
        return [
            'empty' => [''],
            'a path' => ['../../merge-previews/x'],
            'a slash' => ['a/b'],
            'longer than the column' => [str_repeat('a', 37)],
            'a space' => ['not an id'],
            'a dot' => ['x.json'],
        ];
    }

    #[DataProvider('refused')]
    public function test_a_uuid_that_cannot_be_stored_is_refused_before_anything_is_written(string $uuid): void
    {
        $this->publish($uuid)->assertStatus(422);
    }

    public function test_the_guid_every_mod_writes_passes(): void
    {
        $this->publish((string) Str::uuid())->assertSuccessful();
    }
}
