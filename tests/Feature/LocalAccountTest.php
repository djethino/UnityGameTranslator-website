<?php

namespace Tests\Feature;

use App\Models\RecoveryCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Local (platform-less) accounts: registration without email, login,
 * one-time recovery codes.
 */
class LocalAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_creates_account_without_email_and_shows_codes_once(): void
    {
        $response = $this->post('/register', [
            'username' => 'Moon-Translator',
            'password' => 'super-secret-pass',
            'password_confirmation' => 'super-secret-pass',
        ]);

        $response->assertRedirect(route('local.recovery-codes'));
        $this->assertAuthenticated();

        $user = User::where('username', 'moon-translator')->first();
        $this->assertNotNull($user);
        $this->assertNull($user->email);
        $this->assertSame('local', $user->provider);
        $this->assertSame('Moon-Translator', $user->name);
        $this->assertSame(8, RecoveryCode::where('user_id', $user->id)->count());

        // Codes page renders once, then refuses without the flash
        $page = $this->get('/recovery-codes');
        $page->assertOk();
        $this->get('/recovery-codes')->assertRedirect(route('home'));
    }

    public function test_username_uniqueness_is_case_insensitive(): void
    {
        $this->post('/register', [
            'username' => 'duplicate',
            'password' => 'super-secret-pass',
            'password_confirmation' => 'super-secret-pass',
        ]);
        auth()->logout();

        $response = $this->post('/register', [
            'username' => 'DUPLICATE',
            'password' => 'super-secret-pass',
            'password_confirmation' => 'super-secret-pass',
        ]);
        $response->assertSessionHasErrors('username');
    }

    public function test_login_and_wrong_password(): void
    {
        $user = new User();
        $user->forceFill([
            'name' => 'tester',
            'username' => 'tester',
            'email' => null,
            'password' => Hash::make('super-secret-pass'),
            'provider' => 'local',
        ])->save();

        // Wrong password: generic error, still guest
        $this->post('/login-local', [
            'username' => 'tester',
            'password' => 'wrong-password!',
        ])->assertSessionHasErrors('username');
        $this->assertGuest();

        // Correct password
        $this->post('/login-local', [
            'username' => 'TESTER',
            'password' => 'super-secret-pass',
        ])->assertRedirect();
        $this->assertAuthenticatedAs($user);
    }

    public function test_recovery_code_resets_password_once(): void
    {
        $user = new User();
        $user->forceFill([
            'name' => 'lost-soul',
            'username' => 'lost-soul',
            'email' => null,
            'password' => Hash::make('forgotten-password'),
            'provider' => 'local',
        ])->save();
        $codes = RecoveryCode::generateFor($user);

        $response = $this->post('/account-recovery', [
            'username' => 'lost-soul',
            'recovery_code' => $codes[0],
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ]);
        $response->assertRedirect(route('home'));
        $this->assertAuthenticatedAs($user);
        $this->assertTrue(Hash::check('brand-new-password', $user->fresh()->password));

        // The code is burned: reusing it fails
        auth()->logout();
        $this->post('/account-recovery', [
            'username' => 'lost-soul',
            'recovery_code' => $codes[0],
            'password' => 'another-password!',
            'password_confirmation' => 'another-password!',
        ])->assertSessionHasErrors('recovery_code');
        $this->assertGuest();
    }

    public function test_oauth_accounts_cannot_use_local_login(): void
    {
        $oauth = User::factory()->create(); // provider null, has email
        $oauth->forceFill(['username' => null, 'password' => Hash::make('some-password-123')])->save();

        $this->post('/login-local', [
            'username' => $oauth->name,
            'password' => 'some-password-123',
        ])->assertSessionHasErrors('username');
        $this->assertGuest();
    }
}
