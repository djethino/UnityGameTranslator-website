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
 * Comparing WITHOUT publishing: the result goes back to the mod, the server file is untouched.
 *
 * This is what lets a branch measure itself against its Main. Publishing there is forbidden, so
 * the comparison itself used to be refused upfront — ownership was checked before anyone asked
 * what the comparison was for.
 *
 * The security properties are the subject of most of these tests, because the whole point is
 * that a wider door was opened for READING while the writing door stayed exactly as narrow:
 * a token meant for the mod cannot publish, a token meant to publish cannot end here, and a
 * translation nobody may read stays unreachable either way.
 */
class MergePreviewLocalFlowTest extends TestCase
{
    use RefreshDatabase;

    private array $createdFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        foreach ($this->createdFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    private function makeTranslation(User $user, array $content, array $attributes = []): Translation
    {
        $game = Game::firstOrCreate(['slug' => 'local-merge-game'], ['name' => 'Local Merge Game']);

        $dir = storage_path('app/private/translations');
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $relativePath = 'translations/test_' . uniqid('', true) . '.json';
        $fullPath = storage_path('app/private/' . $relativePath);
        file_put_contents($fullPath, json_encode($content, JSON_UNESCAPED_UNICODE));
        $this->createdFiles[] = $fullPath;

        $translation = new Translation();
        $translation->forceFill(array_merge([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'source_language' => 'English',
            'target_language' => 'French',
            'file_path' => $relativePath,
            'file_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'visibility' => 'public',
            'line_count' => count($content),
        ], $attributes))->save();

        return $translation;
    }

    private function init(User $user, Translation $translation, array $localContent, string $destination = 'local')
    {
        $apiToken = ApiToken::createForUser($user);

        return $this->postJson('/api/v1/merge-preview/init', [
            'translation_id' => $translation->id,
            'local_content' => $localContent,
            'destination' => $destination,
        ], ['Authorization' => 'Bearer ' . $apiToken->plain_token]);
    }

    private const ONLINE = [
        '_uuid' => 'shared-uuid',
        'Hello' => ['v' => 'Bonjour du serveur', 't' => 'H'],
    ];

    private const LOCAL = [
        '_uuid' => 'shared-uuid',
        'Hello' => ['v' => 'Bonjour local', 't' => 'H'],
    ];

    public function test_a_branch_may_compare_against_a_translation_it_does_not_own(): void
    {
        $mainOwner = User::factory()->create()->refresh();
        $contributor = User::factory()->create()->refresh();
        $main = $this->makeTranslation($mainOwner, self::ONLINE);

        // Ownership gates publishing, not reading: this is exactly the case that was impossible
        $this->init($contributor, $main, self::LOCAL)->assertOk();
    }

    public function test_publishing_to_someone_elses_translation_is_still_refused(): void
    {
        $owner = User::factory()->create()->refresh();
        $stranger = User::factory()->create()->refresh();
        $translation = $this->makeTranslation($owner, self::ONLINE);

        $this->init($stranger, $translation, self::LOCAL, 'server')->assertForbidden();
    }

    public function test_a_private_branch_cannot_be_compared_against(): void
    {
        $mainOwner = User::factory()->create()->refresh();
        $stranger = User::factory()->create()->refresh();
        $main = $this->makeTranslation($mainOwner, self::ONLINE);
        $branch = $this->makeTranslation($mainOwner, self::ONLINE, [
            'visibility' => 'branch',
            'parent_id' => $main->id,
            'file_uuid' => $main->file_uuid,
        ]);

        // A branch is someone's unpublished contribution, not a public version
        $this->init($stranger, $branch, self::LOCAL)->assertForbidden();
    }

    public function test_the_result_goes_to_the_mod_and_the_server_file_is_untouched(): void
    {
        $mainOwner = User::factory()->create()->refresh();
        $contributor = User::factory()->create()->refresh();
        $main = $this->makeTranslation($mainOwner, self::ONLINE);

        $token = $this->init($contributor, $main, self::LOCAL)->json('token');
        $this->get("/translations/{$main->id}/merge-preview?token={$token}")->assertStatus(303);

        $this->post(route('translations.merge-preview.apply-local', $main), [
            'selections' => [
                ['key' => 'Hello', 'value' => 'Bonjour du serveur', 'tag' => 'H', 'source' => 'online'],
            ],
        ])->assertRedirect();

        // The Main's own file must not have moved by one byte
        $onDisk = json_decode(file_get_contents(storage_path('app/private/' . $main->file_path)), true);
        $this->assertSame(self::ONLINE, $onDisk);

        // ...while the mod's file now holds the arbitrated result
        $result = json_decode(Storage::disk('local')->get(MergePreviewToken::CONTENT_DIR . '/' . $token . '.json'), true);
        $this->assertSame('Bonjour du serveur', $result['Hello']['v']);
    }

    public function test_the_local_result_keeps_the_players_own_metadata(): void
    {
        $mainOwner = User::factory()->create()->refresh();
        $contributor = User::factory()->create()->refresh();
        $main = $this->makeTranslation($mainOwner, self::ONLINE + ['_source' => ['hash' => 'theirs']]);

        $token = $this->init($contributor, $main, self::LOCAL + ['_source' => ['hash' => 'mine']])->json('token');
        $this->get("/translations/{$main->id}/merge-preview?token={$token}")->assertStatus(303);

        $this->post(route('translations.merge-preview.apply-local', $main), [
            'selections' => [
                ['key' => 'Hello', 'value' => 'Bonjour du serveur', 'tag' => 'H', 'source' => 'online'],
            ],
        ])->assertRedirect();

        $result = json_decode(Storage::disk('local')->get(MergePreviewToken::CONTENT_DIR . '/' . $token . '.json'), true);

        // This is the player's file coming back to them: taking the other side's sync state
        // would make the mod believe it is in step with a version it never had
        $this->assertSame(['hash' => 'mine'], $result['_source']);
    }

    public function test_the_page_states_which_way_the_comparison_runs(): void
    {
        $mainOwner = User::factory()->create()->refresh();
        $contributor = User::factory()->create()->refresh();
        $main = $this->makeTranslation($mainOwner, self::ONLINE);

        $token = $this->init($contributor, $main, self::LOCAL)->json('token');
        $this->get("/translations/{$main->id}/merge-preview?token={$token}")->assertStatus(303);

        // Delete means the opposite here than when publishing; leaving that unsaid is how
        // someone erases their own work believing they are tidying up the Main
        $this->get(route('translations.merge-preview', $main))
            ->assertOk()
            ->assertSee(__('merge_preview.direction_to_game'))
            ->assertSee(route('translations.merge-preview.apply-local', $main));
    }

    public function test_a_publishing_comparison_says_nothing_about_going_to_the_game(): void
    {
        $owner = User::factory()->create()->refresh();
        $translation = $this->makeTranslation($owner, self::ONLINE);

        $token = $this->init($owner, $translation, self::LOCAL, 'server')->json('token');
        $this->get("/translations/{$translation->id}/merge-preview?token={$token}")->assertStatus(303);

        $this->get(route('translations.merge-preview', $translation))
            ->assertOk()
            ->assertDontSee(__('merge_preview.direction_to_game'))
            ->assertSee(route('translations.merge-preview.apply', $translation));
    }

    public function test_a_token_meant_for_the_mod_cannot_publish(): void
    {
        $owner = User::factory()->create()->refresh();
        $translation = $this->makeTranslation($owner, self::ONLINE);

        // Even the OWNER, whose ownership check passes, must not end a local comparison by
        // publishing: the mod asked for something else
        $token = $this->init($owner, $translation, self::LOCAL)->json('token');
        $this->get("/translations/{$translation->id}/merge-preview?token={$token}")->assertStatus(303);

        $this->post(route('translations.merge-preview.apply', $translation), [
            'selections' => [
                ['key' => 'Hello', 'value' => 'Bonjour local', 'tag' => 'H', 'source' => 'local'],
            ],
        ])->assertForbidden();
    }

    public function test_a_token_meant_to_publish_cannot_end_in_the_mod(): void
    {
        $owner = User::factory()->create()->refresh();
        $translation = $this->makeTranslation($owner, self::ONLINE);

        $token = $this->init($owner, $translation, self::LOCAL, 'server')->json('token');
        $this->get("/translations/{$translation->id}/merge-preview?token={$token}")->assertStatus(303);

        $this->post(route('translations.merge-preview.apply-local', $translation), [
            'selections' => [
                ['key' => 'Hello', 'value' => 'Bonjour local', 'tag' => 'H', 'source' => 'local'],
            ],
        ])->assertForbidden();
    }

    public function test_settings_are_pulled_from_the_online_side_when_chosen(): void
    {
        $mainOwner = User::factory()->create()->refresh();
        $contributor = User::factory()->create()->refresh();
        $main = $this->makeTranslation($mainOwner, self::ONLINE + [
            '_fonts' => ['Title' => ['enabled' => true, 'fallback' => 'NotoSans', 'type' => 'TMP']],
        ]);

        $token = $this->init($contributor, $main, self::LOCAL + [
            '_fonts' => ['Title' => ['enabled' => true, 'fallback' => 'Roboto']],
        ])->json('token');
        $this->get("/translations/{$main->id}/merge-preview?token={$token}")->assertStatus(303);

        $this->post(route('translations.merge-preview.apply-local', $main), [
            'settings' => ['fonts:Title' => 'online'],
        ])->assertRedirect();

        $result = json_decode(Storage::disk('local')->get(MergePreviewToken::CONTENT_DIR . '/' . $token . '.json'), true);

        // Copied whole from the Main, including what the comparison never displayed
        $this->assertSame('NotoSans', $result['_fonts']['Title']['fallback']);
        $this->assertSame('TMP', $result['_fonts']['Title']['type']);
    }

    public function test_keeping_ones_own_setting_leaves_it_alone(): void
    {
        $mainOwner = User::factory()->create()->refresh();
        $contributor = User::factory()->create()->refresh();
        $main = $this->makeTranslation($mainOwner, self::ONLINE + [
            '_fonts' => ['Title' => ['enabled' => true, 'fallback' => 'NotoSans']],
        ]);

        $token = $this->init($contributor, $main, self::LOCAL + [
            '_fonts' => ['Title' => ['enabled' => true, 'fallback' => 'Roboto']],
        ])->json('token');
        $this->get("/translations/{$main->id}/merge-preview?token={$token}")->assertStatus(303);

        $this->post(route('translations.merge-preview.apply-local', $main), [
            'settings' => ['fonts:Title' => 'local'],
        ])->assertRedirect();

        $result = json_decode(Storage::disk('local')->get(MergePreviewToken::CONTENT_DIR . '/' . $token . '.json'), true);
        $this->assertSame('Roboto', $result['_fonts']['Title']['fallback']);
    }
}
