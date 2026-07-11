<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\Translation;
use App\Models\User;
use App\Notifications\BranchMerged;
use App\Notifications\BranchSubmitted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * In-app notifications: grouping of branch submissions, merge notifications
 * to contributors, and the bell endpoints/page.
 */
class NotificationFlowTest extends TestCase
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

    private function makeTranslation(User $user, array $content, array $overrides = []): Translation
    {
        $game = Game::forceCreate(['name' => 'Test Game', 'slug' => 'test-game-' . uniqid()]);

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
            'file_uuid' => (string) Str::uuid(),
            'visibility' => 'public',
            'line_count' => count($content),
        ], $overrides))->save();

        return $translation;
    }

    public function test_branch_submissions_are_grouped_per_lineage(): void
    {
        $owner = User::factory()->create();
        $main = $this->makeTranslation($owner, ['Hello' => ['v' => 'Bonjour', 't' => 'H']]);

        BranchSubmitted::sendGrouped($owner, $main, 'alice');
        BranchSubmitted::sendGrouped($owner, $main, 'bob');

        $this->assertSame(1, $owner->unreadNotifications()->count());
        $data = $owner->unreadNotifications()->first()->data;
        $this->assertSame(2, $data['count']);
        $this->assertSame('bob', $data['last_contributor']);

        // Once read, the next submission creates a fresh notification
        $owner->unreadNotifications->markAsRead();
        BranchSubmitted::sendGrouped($owner, $main, 'carol');
        $this->assertSame(1, $owner->unreadNotifications()->count());
        $this->assertSame(1, $owner->unreadNotifications()->first()->data['count']);
    }

    public function test_merge_apply_notifies_branch_contributors(): void
    {
        $owner = User::factory()->create();
        $contributor = User::factory()->create();

        $main = $this->makeTranslation($owner, [
            'Hello' => ['v' => 'Bonjour', 't' => 'A'],
            'World' => ['v' => 'Monde', 't' => 'A'],
        ]);
        $branch = $this->makeTranslation($contributor, [
            'Hello' => ['v' => 'Salut', 't' => 'H'],
        ], [
            'file_uuid' => $main->file_uuid,
            'visibility' => 'branch',
            'parent_id' => $main->id,
        ]);

        $selections = [
            ['key' => 'Hello', 'value' => 'Salut', 'tag' => 'H', 'source' => 'branch_' . $branch->id],
        ];

        $response = $this->actingAs($owner)->post('/translations/' . $main->file_uuid . '/merge', [
            'selections_json' => json_encode($selections),
        ]);
        $response->assertRedirect();

        $this->assertSame(1, $contributor->unreadNotifications()->count());
        $data = $contributor->unreadNotifications()->first()->data;
        $this->assertSame('branch_merged', $data['type']);
        $this->assertSame(1, $data['merged_count']);
        $this->assertSame($owner->username, $data['owner_username']);

        // The owner merging their own view does not notify themselves
        $this->assertSame(0, $owner->unreadNotifications()->count());
    }

    public function test_bell_endpoints_and_page(): void
    {
        $owner = User::factory()->create();
        $main = $this->makeTranslation($owner, ['Hello' => ['v' => 'Bonjour', 't' => 'H']]);
        BranchSubmitted::sendGrouped($owner, $main, 'alice');

        $this->actingAs($owner)->getJson('/notifications-count')
            ->assertOk()
            ->assertJson(['unread' => 1]);

        $page = $this->actingAs($owner)->get('/notifications');
        $page->assertOk();
        $page->assertSee('alice');

        // Localized variant must work too (language switcher target)
        $this->actingAs($owner)->get('/fr/notifications')->assertOk();

        $id = $owner->notifications()->first()->id;
        $this->actingAs($owner)->post("/notifications/{$id}/read")->assertRedirect();
        $this->assertSame(0, $owner->unreadNotifications()->count());

        // Mark-all
        $contributorNotif = new BranchMerged($main, 3);
        $owner->notify($contributorNotif);
        BranchSubmitted::sendGrouped($owner, $main, 'dave');
        $this->assertSame(2, $owner->unreadNotifications()->count());
        $this->actingAs($owner)->post('/notifications-read-all')->assertRedirect();
        $this->assertSame(0, $owner->unreadNotifications()->count());
    }

    public function test_guests_cannot_access_notifications(): void
    {
        $this->get('/notifications')->assertRedirect();
        $this->getJson('/notifications-count')->assertUnauthorized();
    }

    public function test_mod_api_returns_summary_and_marks_read(): void
    {
        $owner = User::factory()->create();
        $main = $this->makeTranslation($owner, ['Hello' => ['v' => 'Bonjour', 't' => 'H']]);
        BranchSubmitted::sendGrouped($owner, $main, 'alice');
        BranchSubmitted::sendGrouped($owner, $main, 'bob');

        $apiToken = \App\Models\ApiToken::createForUser($owner);
        $headers = ['Authorization' => 'Bearer ' . $apiToken->plain_token];

        $response = $this->getJson('/api/v1/me/notifications', $headers);
        $response->assertOk()
            ->assertJson(['unread' => 1])
            ->assertJsonPath('items.0.type', 'branch_submitted');
        $this->assertStringContainsString('2 contribution(s)', $response->json('items.0.text'));
        $this->assertStringContainsString('/merge', $response->json('items.0.url'));

        $this->postJson('/api/v1/me/notifications/read', [], $headers)->assertOk();
        $this->assertSame(0, $owner->unreadNotifications()->count());

        // Unauthenticated mod calls are rejected
        $this->getJson('/api/v1/me/notifications')->assertUnauthorized();
    }
}
