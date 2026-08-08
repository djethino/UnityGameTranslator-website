<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\Game;
use App\Models\Translation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Forking used to erase the credit.
 *
 * The mod severs its sync with the original — it has to, or it would keep offering to merge from
 * a lineage it just left — and severed the provenance with it. The fork reached the site as a
 * brand-new translation, and whoever wrote the first thousands of lines lost every trace.
 *
 * The pointer is verified server-side; the counts are declared, because how much the original
 * held at the instant of the fork cannot be recomputed afterwards.
 */
class ForkOriginTest extends TestCase
{
    use RefreshDatabase;

    private function upload(User $user, array $extra = []): \Illuminate\Testing\TestResponse
    {
        $token = ApiToken::createForUser($user, 'test')->plain_token;

        return $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/v1/translations', array_merge([
                'game_name' => 'Origin Game',
                'source_language' => 'English',
                'target_language' => 'French',
                'status' => 'in_progress',
                'content' => json_encode([
                    '_uuid' => 'uuid-' . uniqid(),
                    'Hello' => ['v' => 'Bonjour', 't' => 'H'],
                ]),
            ], $extra));
    }

    private function original(User $author): Translation
    {
        $game = Game::firstOrCreate(['slug' => 'origin-game'], ['name' => 'Origin Game']);
        $t = new Translation();
        $t->forceFill([
            'game_id' => $game->id,
            'user_id' => $author->id,
            'source_language' => 'English',
            'target_language' => 'French',
            'file_path' => 'translations/not-read-by-these-tests.json',
            'file_uuid' => 'origin-uuid',
            'visibility' => 'public',
            'file_hash' => 'origin-hash',
            'human_count' => 3000,
        ])->save();

        return $t->refresh();
    }

    public function test_a_fork_records_who_it_came_from(): void
    {
        $author = User::factory()->create();
        $source = $this->original($author);

        $this->upload(User::factory()->create(), [
            'forked_from_id' => $source->id,
            'forked_from_hash' => 'origin-hash',
            'forked_from_lines' => 3000,
        ])->assertSuccessful();

        $fork = Translation::latest('id')->first();
        $this->assertSame($source->id, $fork->origin_translation_id);
        $this->assertSame($author->id, $fork->origin_user_id);
        $this->assertSame(3000, $fork->origin_resolved_lines);
        $this->assertTrue($fork->hasOrigin());
        $this->assertTrue($source->publicForks()->where('id', $fork->id)->exists());
    }

    public function test_an_origin_pointing_at_nothing_is_dropped_whole(): void
    {
        $this->upload(User::factory()->create(), [
            'forked_from_id' => 999999,
            'forked_from_lines' => 3000,
        ])->assertSuccessful();

        $fork = Translation::latest('id')->first();
        $this->assertNull($fork->origin_translation_id, 'a pointer to nothing credits nobody');
        $this->assertNull($fork->origin_resolved_lines, 'and leaves no number behind either');
        $this->assertFalse($fork->hasOrigin());
    }

    public function test_an_upload_without_these_fields_behaves_as_before(): void
    {
        // Every released version of the mod: the columns simply stay empty.
        $this->upload(User::factory()->create())->assertSuccessful();

        $translation = Translation::latest('id')->first();
        $this->assertNull($translation->origin_translation_id);
        $this->assertFalse($translation->hasOrigin());
    }
}
