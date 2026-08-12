<?php

namespace Tests\Feature\Api;

use App\Models\Game;
use App\Models\Translation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * What someone with NO ACCOUNT is told about the translation they installed.
 *
 * This endpoint is the only one they can reach: searching and downloading need no account, so a
 * player can install a community translation and never sign in. Everything else the mod knows about
 * ownership comes from authenticated calls, which means this response IS their whole picture.
 *
 * Two things are checked, and they pull in opposite directions on purpose:
 *
 *  - the answer must name the uploader, or the mod can only call the work "Website" — it holds the
 *    site id it downloaded from and nothing about whose translation it is;
 *  - the answer must be cacheable, because this runs on a timer for the whole session.
 *
 * The trap is the second one eating the first: an ETag built on file_hash alone (what it used to
 * be) replies 304 while the uploader's name or the vote count has moved, because neither of those
 * touches a translated line. The caller then keeps stale values forever and nothing ever says so.
 */
class PublicCheckTest extends TestCase
{
    use RefreshDatabase;

    private function makePublicTranslation(User $owner): Translation
    {
        $game = Game::firstOrCreate(['slug' => 'public-check-game'], ['name' => 'Public Check Game']);

        $path = 'translations/public-check-' . uniqid() . '.json';
        Storage::disk('local')->put($path, json_encode([
            '_uuid' => 'uuid-public-check',
            'Shop' => ['v' => 'Boutique', 't' => 'H'],
        ], JSON_UNESCAPED_UNICODE));

        $translation = new Translation();
        $translation->forceFill([
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'source_language' => 'English',
            'target_language' => 'French',
            'visibility' => 'public',
            'file_uuid' => 'uuid-public-check',
            'file_path' => $path,
            'file_hash' => 'hash-' . uniqid(),
            'line_count' => 1,
            'vote_count' => 0,
        ])->save();

        return $translation->refresh();
    }

    public function test_an_anonymous_caller_is_told_who_published_the_translation(): void
    {
        $owner = User::factory()->create(['name' => 'someone']);
        $translation = $this->makePublicTranslation($owner);

        $this->getJson("/api/v1/translations/{$translation->id}/check")
            ->assertOk()
            ->assertJsonPath('uploader', 'someone');
    }

    public function test_a_caller_holding_the_current_version_is_told_it_is_unchanged(): void
    {
        $owner = User::factory()->create(['name' => 'someone']);
        $translation = $this->makePublicTranslation($owner);

        $etag = $this->getJson("/api/v1/translations/{$translation->id}/check")
            ->assertOk()
            ->headers->get('ETag');

        $this->assertNotNull($etag, 'Without an ETag every timed check re-downloads the whole answer.');

        $this->withHeaders(['If-None-Match' => $etag])
            ->getJson("/api/v1/translations/{$translation->id}/check")
            ->assertStatus(304);
    }

    /**
     * A vote moves the answer without touching a line of the translation. If the ETag ignored it,
     * the 304 above would keep answering "unchanged" and the count shown in game would be frozen
     * at whatever it was the day the file was installed.
     */
    public function test_a_vote_makes_the_cached_answer_stale(): void
    {
        $owner = User::factory()->create(['name' => 'someone']);
        $translation = $this->makePublicTranslation($owner);

        $etag = $this->getJson("/api/v1/translations/{$translation->id}/check")
            ->headers->get('ETag');

        $translation->forceFill(['vote_count' => 7])->save();

        $this->withHeaders(['If-None-Match' => $etag])
            ->getJson("/api/v1/translations/{$translation->id}/check")
            ->assertOk()
            ->assertJsonPath('vote_count', 7);
    }

    /**
     * Same reasoning for the name: the site lets people rename themselves, and a frozen ETag would
     * leave the mod crediting a name its owner has left behind.
     */
    public function test_a_rename_makes_the_cached_answer_stale(): void
    {
        $owner = User::factory()->create(['name' => 'someone']);
        $translation = $this->makePublicTranslation($owner);

        $etag = $this->getJson("/api/v1/translations/{$translation->id}/check")
            ->headers->get('ETag');

        $owner->forceFill(['name' => 'someone-else'])->save();

        $this->withHeaders(['If-None-Match' => $etag])
            ->getJson("/api/v1/translations/{$translation->id}/check")
            ->assertOk()
            ->assertJsonPath('uploader', 'someone-else');
    }
}
