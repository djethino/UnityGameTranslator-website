<?php

namespace Tests\Feature\Api;

use App\Models\ApiToken;
use App\Models\Translation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A translation is published under two settled languages, or not at all.
 *
 * A published translation without a real source and target is unusable by whoever only wants to
 * play: a game written in French offered a file "into Chinese" from nobody knows what cannot
 * work, and the catalogue cannot list it anywhere anybody would look. The mod asks the source at
 * the first upload and fixes the target with the first line; the Manager, for a release, did
 * neither — so the question was whether the site would have taken "auto" from it. It would not,
 * and these cases keep it that way.
 *
 * ⚠ Three barriers refuse, in this order, and a case sits on each: the validator (`required`
 * plus `in:` the catalogue's names, which never include "auto"), and
 * TranslationService::resolveLanguages, which refuses "auto" and an identical pair on a NEW
 * translation. On an UPDATE or a BRANCH the request's languages are ignored and the lineage's
 * kept — the last case pins that too, since a client relying on it exists (the Manager).
 */
class UploadLanguagesTest extends TestCase
{
    use RefreshDatabase;

    private function upload(User $user, array $fields): \Illuminate\Testing\TestResponse
    {
        $token = ApiToken::createForUser($user, 'test')->plain_token;

        return $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->postJson('/api/v1/translations', array_merge([
                'game_name' => 'Languages Test Game',
                'source_language' => 'English',
                'target_language' => 'French',
                'content' => json_encode([
                    '_uuid' => 'uuid-' . uniqid(),
                    '_game' => ['name' => 'Languages Test Game', 'steam_id' => '1'],
                    'Hello' => ['v' => 'Bonjour', 't' => 'H'],
                ]),
            ], $fields));
    }

    public function test_auto_is_not_a_language(): void
    {
        $user = User::factory()->create();

        $this->upload($user, ['source_language' => 'auto'])->assertStatus(422);
        $this->upload($user, ['target_language' => 'auto'])->assertStatus(422);
        $this->upload($user, ['source_language' => 'Auto', 'target_language' => 'AUTO'])->assertStatus(422);

        $this->assertSame(0, Translation::count(), 'nothing may be stored under "auto"');
    }

    public function test_a_missing_language_is_refused(): void
    {
        $user = User::factory()->create();

        $this->upload($user, ['source_language' => null])->assertStatus(422);
        $this->upload($user, ['target_language' => ''])->assertStatus(422);

        $this->assertSame(0, Translation::count());
    }

    public function test_a_language_the_catalogue_does_not_carry_is_refused(): void
    {
        $user = User::factory()->create();

        // A code rather than a name: the contract is the catalogue's NAME, and a client sending
        // codes must be told so rather than published under a language nobody searches by.
        $this->upload($user, ['source_language' => 'en'])->assertStatus(422);
        $this->upload($user, ['target_language' => 'Klingon'])->assertStatus(422);

        $this->assertSame(0, Translation::count());
    }

    public function test_the_same_language_both_ways_is_refused(): void
    {
        $response = $this->upload(User::factory()->create(), [
            'source_language' => 'French',
            'target_language' => 'French',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('different', $response->json('error') ?? '');
        $this->assertSame(0, Translation::count());
    }

    public function test_a_settled_pair_is_accepted_and_kept_on_update(): void
    {
        $user = User::factory()->create();
        $uuid = 'uuid-' . uniqid();

        $content = json_encode([
            '_uuid' => $uuid,
            '_game' => ['name' => 'Languages Test Game', 'steam_id' => '1'],
            'Hello' => ['v' => 'Bonjour', 't' => 'H'],
        ]);

        $this->upload($user, ['content' => $content])->assertSuccessful();

        $translation = Translation::sole();
        $this->assertSame('English', $translation->source_language);
        $this->assertSame('French', $translation->target_language);

        // An UPDATE sends whatever it holds — even a different pair — and the lineage keeps its
        // own. This is what lets a client send the pair back without deciding anything.
        $this->upload($user, [
            'content' => json_encode([
                '_uuid' => $uuid,
                '_game' => ['name' => 'Languages Test Game', 'steam_id' => '1'],
                'Hello' => ['v' => 'Bonjour', 't' => 'H'],
                'Bye' => ['v' => 'Au revoir', 't' => 'H'],
            ]),
            'source_language' => 'German',
            'target_language' => 'Thai',
        ])->assertSuccessful();

        $translation->refresh();
        $this->assertSame('English', $translation->source_language, 'an update never moves the pair');
        $this->assertSame('French', $translation->target_language);
        $this->assertSame(1, Translation::count());
    }
}
