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

    public function test_the_mod_can_collect_the_result_it_asked_for(): void
    {
        $mainOwner = User::factory()->create()->refresh();
        $contributor = User::factory()->create()->refresh();
        $main = $this->makeTranslation($mainOwner, self::ONLINE);

        $apiToken = ApiToken::createForUser($contributor);
        $token = $this->postJson('/api/v1/merge-preview/init', [
            'translation_id' => $main->id,
            'local_content' => self::LOCAL,
            'destination' => 'local',
        ], ['Authorization' => 'Bearer ' . $apiToken->plain_token])->json('token');

        $this->get("/translations/{$main->id}/merge-preview?token={$token}")->assertStatus(303);
        $this->post(route('translations.merge-preview.apply-local', $main), [
            'selections' => [
                ['key' => 'Hello', 'value' => 'Bonjour du serveur', 'tag' => 'H', 'source' => 'online'],
            ],
        ])->assertRedirect();

        $result = $this->getJson("/api/v1/merge-preview/{$token}/result", [
            'Authorization' => 'Bearer ' . $apiToken->plain_token,
        ]);

        $result->assertOk();
        $this->assertSame('Bonjour du serveur', $result->json('content.Hello.v'));
    }

    public function test_holding_the_token_is_not_enough_to_read_someone_elses_result(): void
    {
        $mainOwner = User::factory()->create()->refresh();
        $contributor = User::factory()->create()->refresh();
        $stranger = User::factory()->create()->refresh();
        $main = $this->makeTranslation($mainOwner, self::ONLINE);

        $token = $this->init($contributor, $main, self::LOCAL)->json('token');

        // The token identifies the comparison, not its owner: a leaked one must not open
        // someone's unpublished file
        $strangerToken = ApiToken::createForUser($stranger);
        $this->getJson("/api/v1/merge-preview/{$token}/result", [
            'Authorization' => 'Bearer ' . $strangerToken->plain_token,
        ])->assertNotFound();
    }

    public function test_a_published_comparison_has_no_result_to_collect(): void
    {
        $owner = User::factory()->create()->refresh();
        $translation = $this->makeTranslation($owner, self::ONLINE);

        $apiToken = ApiToken::createForUser($owner);
        $token = $this->postJson('/api/v1/merge-preview/init', [
            'translation_id' => $translation->id,
            'local_content' => self::LOCAL,
            'destination' => 'server',
        ], ['Authorization' => 'Bearer ' . $apiToken->plain_token])->json('token');

        // It became the online version: reading it back through here would be a second,
        // needless way to the same bytes
        $this->getJson("/api/v1/merge-preview/{$token}/result", [
            'Authorization' => 'Bearer ' . $apiToken->plain_token,
        ])->assertStatus(409);
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
        $page = $this->get(route('translations.merge-preview', $main))
            ->assertOk()
            ->assertSee(__('merge_preview.direction_to_game'))
            ->assertSee(route('translations.merge-preview.apply-local', $main));

        // 🔴 BOTH buttons, not just the page's. The workbench strip carries the same one, and it
        // said "Save" whichever way the comparison ran — the exact wording already fixed once
        // below, left standing in the strip that HIDES the page, so the two could never be read
        // side by side and caught disagreeing.
        $this->assertSame(2, substr_count($page->getContent(), __('merge_preview.send_to_game')),
            'both the page button and the workbench strip should name the direction');
        $this->assertStringNotContainsString(__('merge_preview.save_to_server'), $page->getContent());
    }

    /**
     * 🔴 A page left open for hours has two ways of going stale, and only one of them is its own
     * doing: the online version can be rewritten under it, and the session that authorises writing
     * back expires on its own. Both were discovered by pressing Save, after the work was done.
     *
     * ⚠ The endpoint is deliberately tiny — a hash and an envelope. Asked when the tab comes back
     * into view, never on a timer, so it must cost nothing.
     */
    public function test_a_comparison_can_be_asked_whether_it_is_still_current(): void
    {
        $mainOwner = User::factory()->create()->refresh();
        $contributor = User::factory()->create()->refresh();
        $main = $this->makeTranslation($mainOwner, self::ONLINE);

        $token = $this->init($contributor, $main, self::LOCAL)->json('token');
        $this->get("/translations/{$main->id}/merge-preview?token={$token}")->assertStatus(303);

        $this->get(route('translations.merge-preview.state', $main))
            ->assertOk()
            ->assertJsonPath('file_hash', $main->fresh()->file_hash)
            ->assertJsonPath('session', 'mod');
    }

    /** A dead session answers 410 — the same refusal the data endpoint gives, from the same guard. */
    public function test_an_expired_comparison_says_so_before_anything_is_saved(): void
    {
        $mainOwner = User::factory()->create()->refresh();
        $contributor = User::factory()->create()->refresh();
        $main = $this->makeTranslation($mainOwner, self::ONLINE);

        $token = $this->init($contributor, $main, self::LOCAL)->json('token');
        $this->get("/translations/{$main->id}/merge-preview?token={$token}")->assertStatus(303);

        MergePreviewToken::where('translation_id', $main->id)
            ->update(['expires_at' => now()->subMinute()]);

        $this->get(route('translations.merge-preview.state', $main))->assertStatus(410);
    }

    /** Nobody else's business: the state endpoint is as guarded as the data it describes. */
    public function test_the_state_of_a_comparison_is_not_public(): void
    {
        $owner = User::factory()->create()->refresh();
        $stranger = User::factory()->create()->refresh();
        $main = $this->makeTranslation($owner, self::ONLINE);

        $this->actingAs($stranger)
            ->get(route('translations.merge-preview.state', $main))
            ->assertForbidden();
    }

    public function test_a_publishing_comparison_says_nothing_about_going_to_the_game(): void
    {
        $owner = User::factory()->create()->refresh();
        $translation = $this->makeTranslation($owner, self::ONLINE);

        $token = $this->init($owner, $translation, self::LOCAL, 'server')->json('token');
        $this->get("/translations/{$translation->id}/merge-preview?token={$token}")->assertStatus(303);

        $page = $this->get(route('translations.merge-preview', $translation))
            ->assertOk()
            ->assertDontSee(__('merge_preview.direction_to_game'))
            ->assertSee(route('translations.merge-preview.apply', $translation));

        // And the other way round, on the same two buttons
        $this->assertSame(2, substr_count($page->getContent(), __('merge_preview.save_to_server')));
        $this->assertStringNotContainsString(__('merge_preview.send_to_game'), $page->getContent());
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
