<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\Translation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Who gets a compressed answer, and who must never get one.
 *
 * 🔴 **The whole point is the second half.** Every mod published up to 2026-08-20 asks for gzip
 * and cannot inflate it; sending it one kills every call it makes, including the one that would
 * have found an update. So this is not a performance feature with a safety note attached — the
 * safety IS the feature, and these tests are what keeps it true.
 *
 * ⚠ The host strips `Accept-Encoding` before PHP (verified on the live site), so the usual
 * negotiation is unavailable and the decision rests on the User-Agent. That makes the whitelist
 * the only thing standing between a published mod and a body it cannot read.
 */
class CompressJsonResponseTest extends TestCase
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

    /** Same shape as the other suites: a real file on disk, since that is what download serves. */
    private function makeTranslation(User $user, Game $game, array $content): Translation
    {
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
            'file_uuid' => 'uuid-' . uniqid('', true),
            'file_hash' => hash('sha256', json_encode($content)),
            'visibility' => 'public',
            'line_count' => count($content),
        ])->save();

        return $translation;
    }

    /** Enough public translations that the listing is worth compressing. */
    private function seedListing(int $count = 25): void
    {
        $user = User::factory()->create()->refresh();

        for ($i = 0; $i < $count; $i++) {
            $game = Game::forceCreate([
                'name' => "A game with a fairly long name, number $i",
                'slug' => "game-$i-" . uniqid('', true),
            ]);
            $this->makeTranslation($user, $game, ["Line $i" => ['v' => "Ligne $i", 't' => 'V']]);
        }
    }

    private function getAs(string $agent, string $uri = '/api/v1/translations')
    {
        return $this->withHeaders(['User-Agent' => $agent])->get($uri);
    }

    public function test_a_mod_that_cannot_inflate_never_receives_gzip(): void
    {
        $this->seedListing();

        // The literal every published build sends. It announces gzip and dies on it.
        $response = $this->getAs('UnityGameTranslator/1.0');

        $response->assertOk();
        $this->assertNull($response->headers->get('Content-Encoding'),
            'a build that cannot decompress was sent gzip — every call it makes would fail');
        $this->assertStringStartsWith('{', $response->getContent());
    }

    public function test_a_fixed_mod_receives_gzip(): void
    {
        $this->seedListing();

        $response = $this->getAs('UnityGameTranslator/0.11.1 (BepInEx6-IL2CPP)');

        $response->assertOk();
        $this->assertSame('gzip', $response->headers->get('Content-Encoding'));
        $this->assertSame("\x1f\x8b", substr($response->getContent(), 0, 2));
    }

    public function test_the_manager_receives_gzip(): void
    {
        $this->seedListing();

        $this->assertSame('gzip',
            $this->getAs('UnityGameTranslatorManager/0.1.0')->headers->get('Content-Encoding'));
    }

    public function test_a_browser_receives_gzip(): void
    {
        $this->seedListing();

        $this->assertSame('gzip',
            $this->getAs('Mozilla/5.0 (Windows NT 10.0; Win64; x64)')->headers->get('Content-Encoding'));
    }

    /**
     * ⚠ An unknown caller is not assumed to cope. `Accept-Encoding` never reaches PHP here, so
     * there is nothing to ask — and guessing wrong sends binary to something expecting text.
     */
    public function test_an_unknown_caller_is_left_alone(): void
    {
        $this->seedListing();

        foreach (['curl/8.4.0', 'python-requests/2.31', '', 'SomeoneElsesTool/2'] as $agent) {
            $this->assertNull($this->getAs($agent)->headers->get('Content-Encoding'),
                "an unverified caller ($agent) was sent gzip");
        }
    }

    /** 🔴 Lossless, and the reason this was asked for: content must come back byte for byte. */
    public function test_what_comes_back_is_exactly_what_was_sent(): void
    {
        $this->seedListing();

        $plain = $this->getAs('curl/8.4.0')->getContent();
        $packed = $this->getAs('Mozilla/5.0')->getContent();

        $this->assertSame($plain, gzdecode($packed),
            'the compressed answer does not decode back to the original');
    }

    /** Accents, ideograms and emoji survive: gzip works on finished bytes, never on characters. */
    public function test_accented_and_non_latin_content_survives(): void
    {
        $user = User::factory()->create()->refresh();
        $game = Game::forceCreate([
            'name' => 'Jeu accentué — 龙胤立志传 (démo) 🎮',
            'slug' => 'accents-' . uniqid('', true),
        ]);

        $content = [];
        for ($i = 0; $i < 60; $i++) {
            $content["Line $i"] = ['v' => "Une traduction française très accentuée : àéèêëîïôùû n°$i", 't' => 'V'];
        }
        $translation = $this->makeTranslation($user, $game, $content);

        $packed = $this->getAs('UnityGameTranslator/0.11.1 (BepInEx5)',
            "/api/v1/translations/{$translation->id}/download");

        $this->assertSame('gzip', $packed->headers->get('Content-Encoding'));

        $decoded = gzdecode($packed->getContent());
        $this->assertSame($content, json_decode($decoded, true),
            'the downloaded translation did not survive compression intact');
        $this->assertStringContainsString('àéèêëîïôùû', $decoded);
    }

    /** A short answer would grow: gzip carries a header of its own. */
    public function test_a_short_answer_is_not_compressed(): void
    {
        $response = $this->getAs('Mozilla/5.0', '/api/v1/translations?steam_id=000000000');

        $response->assertOk();
        $this->assertLessThan(1024, strlen($response->getContent()));
        $this->assertNull($response->headers->get('Content-Encoding'));
    }

    /** HTML is the host's business and is already compressed by it — never touched here. */
    public function test_html_is_left_to_the_server(): void
    {
        $response = $this->withHeaders(['User-Agent' => 'Mozilla/5.0'])->get('/');

        $response->assertOk();
        $this->assertNull($response->headers->get('Content-Encoding'));
    }

    /**
     * 🔴 The answer depends on who asked, so it must say so — otherwise a cache could hand a
     * compressed body to one of the builds this exists to protect.
     */
    public function test_a_compressed_answer_says_it_varies_on_the_caller(): void
    {
        $this->seedListing();

        $vary = $this->getAs('Mozilla/5.0')->headers->get('Vary');

        $this->assertNotNull($vary);
        $this->assertStringContainsString('User-Agent', $vary);
    }

    /** The download is where the bytes actually are — and where a published mod must stay safe. */
    public function test_a_download_is_plain_for_a_build_that_cannot_inflate(): void
    {
        $user = User::factory()->create()->refresh();
        $game = Game::forceCreate(['name' => 'Downloadable', 'slug' => 'dl-' . uniqid('', true)]);

        $content = [];
        for ($i = 0; $i < 300; $i++) {
            $content["Some game line number $i"] = ['v' => "Une ligne traduite numéro $i", 't' => 'V'];
        }
        $translation = $this->makeTranslation($user, $game, $content);

        $plain = $this->getAs('UnityGameTranslator/1.0',
            "/api/v1/translations/{$translation->id}/download");

        $plain->assertOk();
        $this->assertNull($plain->headers->get('Content-Encoding'));
        $this->assertSame($content, json_decode($plain->getContent(), true));
    }
}
