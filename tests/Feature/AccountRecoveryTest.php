<?php

namespace Tests\Feature;

use App\Models\RecoveryCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Getting back into a local account with a one-time code — and who may not.
 *
 * 🔴 **Recovery was a door around the ban.** login() refused a banned account after checking its
 * password; recover() checked nothing of the kind, so the same account could type a recovery code,
 * set a new password and be signed in, until the next request threw it out. The ban is now tested
 * in the same place and the same way: after the credential, so only its holder learns of it.
 */
class AccountRecoveryTest extends TestCase
{
    use RefreshDatabase;

    /** A local account holding a fresh set of codes; returns the account and one plain code. */
    private function localAccount(array $attributes = []): array
    {
        $user = User::factory()->create(array_merge([
            'username' => 'someone',
            'provider' => 'local',
            'password' => Hash::make('old-password-1234'),
        ], $attributes));

        $codes = RecoveryCode::generateFor($user);

        return [$user, $codes[0]];
    }

    private function recover(string $code, string $username = 'someone'): \Illuminate\Testing\TestResponse
    {
        return $this->post(route('local.recover.post'), [
            'username' => $username,
            'recovery_code' => $code,
            'password' => 'new-password-1234',
            'password_confirmation' => 'new-password-1234',
        ]);
    }

    public function test_a_valid_code_recovers_the_account_and_is_burnt(): void
    {
        [$user, $code] = $this->localAccount();

        $this->recover($code)->assertRedirect(route('home'));

        $this->assertAuthenticatedAs($user);
        $this->assertTrue(Hash::check('new-password-1234', $user->fresh()->password));
        $this->assertNull(RecoveryCode::find($user, $code), 'a code opens the door once');
    }

    public function test_a_banned_account_cannot_recover_and_keeps_its_code(): void
    {
        [$user, $code] = $this->localAccount(['banned_at' => now()]);

        $this->recover($code)->assertSessionHasErrors('username');

        $this->assertGuest();
        $this->assertTrue(Hash::check('old-password-1234', $user->fresh()->password), 'nothing was changed');

        // ⚠ Not burnt: refused for a reason of the account's, not of the code's. The day the ban is
        // lifted, the code still works.
        $this->assertNotNull(RecoveryCode::find($user, $code));
    }

    public function test_a_wrong_code_says_nothing_about_the_account(): void
    {
        [$user] = $this->localAccount(['banned_at' => now()]);

        // A banned account and a wrong code answer exactly as an unknown account does: the ban is
        // only ever disclosed to somebody holding a valid credential.
        $this->recover('WRONG-WRONG-WRNG')->assertSessionHasErrors('recovery_code');
        $this->recover('WRONG-WRONG-WRNG', 'nobody')->assertSessionHasErrors('recovery_code');

        $this->assertGuest();
    }
}
