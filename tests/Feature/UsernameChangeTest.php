<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UsernameChangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_name_change_enforces_cooldown(): void
    {
        $user = User::factory()->create(['name' => 'OldName']);

        $this->actingAs($user)->put('/profile', ['name' => 'NewName'])->assertRedirect();
        $user->refresh();
        $this->assertSame('NewName', $user->name);
        $this->assertNotNull($user->name_changed_at);

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

        // Same name + locale change: no cooldown error
        $this->actingAs($user)->put('/profile', ['name' => 'Stable', 'locale' => 'fr'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    }

    /**
     * 🔴 The name somebody removes must not be kept anywhere.
     *
     * A history of past display names was written on every rename until 2026-08-26, and read by
     * nothing. It held exactly what the rename exists to hide — the prompt offering it says OAuth
     * names sometimes expose real ones — and it survived account deletion.
     *
     * ⚠ Asserted against the SCHEMA, not against a model: the model is gone, so a test calling it
     * would fail to compile rather than fail meaningfully, and a future migration recreating the
     * table would go unnoticed. This fails the day the table comes back, whoever brings it.
     */
    public function test_past_display_names_are_not_kept(): void
    {
        $this->assertFalse(Schema::hasTable('username_history'),
            'Past display names must not be stored: nothing reads them, and they hold the very name a rename removes.');

        $user = User::factory()->create(['name' => 'ExposedRealName']);
        $this->actingAs($user)->put('/profile', ['name' => 'Chosen'])->assertRedirect();

        // Nowhere in the account's own row either
        $this->assertSame('Chosen', $user->fresh()->name);
        $this->assertStringNotContainsString('ExposedRealName', json_encode($user->fresh()->toArray()));
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
