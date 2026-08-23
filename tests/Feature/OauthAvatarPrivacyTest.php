<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Tests\TestCase;

/**
 * Signing in with a provider must not publish which account it was.
 *
 * 🔴 The provider's avatar URL carries the provider's own user id —
 * `avatars.githubusercontent.com/u/12345` — and GitHub turns that id into a login through a
 * public, unauthenticated endpoint. Rendered on a game page it tied a site pseudonym to a real
 * GitHub account for anyone reading the HTML, on a site whose sign-up is advertised as anonymous.
 *
 * ⚠ These tests guard the DEFAULT, not the choice: profile.avatar still lets somebody ask for
 * their platform avatar. What must never come back is that choice being made for them by
 * inaction — which is what a missing avatar_seed amounts to.
 */
class OauthAvatarPrivacyTest extends TestCase
{
    use RefreshDatabase;

    private function signInWith(string $provider, string $id, string $avatar): void
    {
        $socialUser = $this->createMock(SocialiteUser::class);
        $socialUser->method('getId')->willReturn($id);
        $socialUser->method('getName')->willReturn('Someone');
        $socialUser->method('getNickname')->willReturn('someone');
        $socialUser->method('getEmail')->willReturn($id . '@example.test');
        $socialUser->method('getAvatar')->willReturn($avatar);

        Socialite::shouldReceive('driver')->with($provider)->andReturnSelf();
        Socialite::shouldReceive('user')->andReturn($socialUser);

        $this->get("/auth/{$provider}/callback");
    }

    public function test_a_new_oauth_account_gets_a_generated_avatar(): void
    {
        $this->signInWith('github', '12345', 'https://avatars.githubusercontent.com/u/12345?v=4');

        $user = User::where('provider_id', '12345')->firstOrFail();

        $this->assertNotNull(
            $user->avatar_seed,
            'A new OAuth account must start on a generated avatar: without a seed the '
            . 'x-avatar component falls back to the provider URL, which carries the provider id.'
        );
    }

    public function test_the_provider_url_is_kept_so_the_choice_stays_available(): void
    {
        $url = 'https://avatars.githubusercontent.com/u/12345?v=4';
        $this->signInWith('github', '12345', $url);

        $user = User::where('provider_id', '12345')->firstOrFail();

        // Storing it is what makes "use my platform avatar" possible at all. The seed is what
        // decides whether it is ever rendered.
        $this->assertSame($url, $user->avatar);
    }

    public function test_a_seeded_user_renders_no_provider_url(): void
    {
        $user = User::factory()->create([
            'provider' => 'github',
            'avatar' => 'https://avatars.githubusercontent.com/u/12345?v=4',
        ]);
        $user->forceFill(['avatar_seed' => 'abcdefghij'])->save();

        $html = view('components.avatar', ['user' => $user, 'size' => 32])->render();

        $this->assertStringNotContainsString('githubusercontent.com', $html);
        $this->assertStringContainsString('data-dicebear-seed', $html);
    }
}
