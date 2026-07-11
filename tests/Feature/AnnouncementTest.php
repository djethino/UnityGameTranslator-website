<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnnouncementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['is_admin' => true])->save();

        return $user;
    }

    public function test_admin_can_publish_and_all_users_are_notified(): void
    {
        $admin = $this->admin();
        $alice = User::factory()->create();
        $banned = User::factory()->create();
        $banned->forceFill(['banned_at' => now()])->save();

        $response = $this->actingAs($admin)->post('/admin/announcements', [
            'title' => 'Big news',
            'body' => 'The mod has a new release.',
            'link' => 'https://example.com/release',
            'show_banner' => 1,
        ]);
        $response->assertRedirect();

        // Every non-banned user got the in-app notification (admin included)
        $this->assertSame(1, $alice->unreadNotifications()->count());
        $this->assertSame(1, $admin->unreadNotifications()->count());
        $this->assertSame(0, $banned->unreadNotifications()->count());

        $data = $alice->unreadNotifications()->first()->data;
        $this->assertSame('announcement', $data['type']);
        $this->assertSame('Big news', $data['title']);

        // Banner is live for guests
        Announcement::clearBannerCache();
        $this->get('/')->assertOk()->assertSee('Big news');
    }

    public function test_expired_banner_disappears(): void
    {
        $admin = $this->admin();
        $announcement = Announcement::create([
            'title' => 'Old news',
            'body' => 'Past announcement',
            'show_banner' => true,
            'created_by' => $admin->id,
            'published_at' => now()->subDay(),
        ]);

        $this->actingAs($admin)->post("/admin/announcements/{$announcement->id}/expire")->assertRedirect();

        Announcement::clearBannerCache();
        $this->get('/')->assertOk()->assertDontSee('Old news');
    }

    public function test_non_admin_cannot_publish(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post('/admin/announcements', [
            'title' => 'Nope',
            'body' => 'Nope',
        ])->assertForbidden();
    }
}
