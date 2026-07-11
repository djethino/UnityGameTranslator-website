<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UsernameHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UsernameChangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_name_change_records_history_and_enforces_cooldown(): void
    {
        $user = User::factory()->create(['name' => 'OldName']);

        $this->actingAs($user)->put('/profile', ['name' => 'NewName'])->assertRedirect();
        $user->refresh();
        $this->assertSame('NewName', $user->name);
        $this->assertNotNull($user->name_changed_at);
        $this->assertSame(1, UsernameHistory::where('user_id', $user->id)->where('old_name', 'OldName')->count());

        // Second change within 30 days is refused
        $this->actingAs($user)->put('/profile', ['name' => 'ThirdName'])
            ->assertSessionHasErrors('name');
        $this->assertSame('NewName', $user->fresh()->name);

        // After the cooldown it works again
        $user->forceFill(['name_changed_at' => now()->subDays(31)])->save();
        $this->actingAs($user)->put('/profile', ['name' => 'ThirdName'])->assertRedirect();
        $this->assertSame('ThirdName', $user->fresh()->name);
    }

    public function test_saving_profile_without_name_change_skips_cooldown(): void
    {
        $user = User::factory()->create(['name' => 'Stable']);
        $user->forceFill(['name_changed_at' => now()])->save();

        // Same name + locale change: no cooldown error, no history row
        $this->actingAs($user)->put('/profile', ['name' => 'Stable', 'locale' => 'fr'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->assertSame(0, UsernameHistory::count());
    }

    public function test_one_shot_prompt_flow(): void
    {
        $user = User::factory()->create();
        $this->assertNull($user->username_prompt_seen_at);

        // Overlay is visible on any page
        $this->actingAs($user)->get('/')->assertSee(__('profile.prompt_title'));

        // "Keep" marks it seen and it never comes back
        $this->actingAs($user)->post('/username-prompt-seen', ['action' => 'keep'])->assertRedirect();
        $this->assertNotNull($user->fresh()->username_prompt_seen_at);
        $this->actingAs($user)->get('/')->assertDontSee(__('profile.prompt_title'));
    }

    public function test_local_registration_never_sees_the_prompt(): void
    {
        $this->post('/register', [
            'username' => 'fresh-user',
            'password' => 'super-secret-pass',
            'password_confirmation' => 'super-secret-pass',
        ]);

        $user = User::where('username', 'fresh-user')->first();
        $this->assertNotNull($user->username_prompt_seen_at);
    }
}
