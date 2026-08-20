<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Every way of signing in, offered wherever signing in is offered.
 *
 * 🔴 **Written because the list existed three times.** When local accounts arrived — a username and
 * a password, no platform, no email — they were added to /login and nowhere else. /link, the page
 * the MOD sends people to, went on offering five providers and no way in for anybody holding one;
 * so did the mobile menu. Nothing could report it: three copies of a list are three lists.
 *
 * These tests fix the shape of the answer, not the markup: whatever the page, if it offers a way to
 * sign in, it offers ALL of them.
 */
class SignInMethodsTest extends TestCase
{
    use RefreshDatabase;

    private const PROVIDERS = ['steam', 'discord', 'github', 'google', 'twitch'];

    private function assertOffersEveryMethod(string $html, string $where): void
    {
        foreach (self::PROVIDERS as $provider) {
            $this->assertStringContainsString(
                'auth/' . $provider,
                $html,
                "$where does not offer $provider"
            );
        }

        $this->assertStringContainsString('name="username"', $html, "$where has no username field");
        $this->assertStringContainsString('name="password"', $html, "$where has no password field");
        $this->assertStringContainsString(route('local.register'), $html, "$where cannot create an account");
        $this->assertStringContainsString(route('local.recover'), $html, "$where cannot recover one");
    }

    public function test_the_sign_in_page_offers_every_method(): void
    {
        $this->assertOffersEveryMethod(
            $this->get(route('login'))->assertOk()->getContent(),
            '/login'
        );
    }

    /** The page the mod opens. It offered five providers and no local account at all. */
    public function test_the_link_page_offers_every_method(): void
    {
        $this->assertOffersEveryMethod(
            $this->get(route('link'))->assertOk()->getContent(),
            '/link'
        );
    }

    /**
     * ⚠ And it comes back HERE: somebody is holding a code shown in their game, so landing on the
     * home page means finding this page again by themselves.
     */
    public function test_the_link_page_asks_to_be_returned_to(): void
    {
        $html = $this->get(route('link'))->assertOk()->getContent();

        $this->assertStringContainsString(
            urlencode(route('link')),
            $html,
            '/link does not ask to be returned to after signing in'
        );
    }

    /** 🔴 The whole point of the return address: it has to survive the sign-in. */
    public function test_a_local_sign_in_returns_to_where_it_started(): void
    {
        User::factory()->create([
            'username' => 'moon-translator',
            'provider' => 'local',
            'password' => Hash::make('a-long-enough-password'),
        ]);

        $this->post(route('local.login'), [
            'username' => 'moon-translator',
            'password' => 'a-long-enough-password',
            'redirect' => route('link'),
        ])->assertRedirect(route('link'));

        $this->assertAuthenticated();
    }

    /**
     * 🔴 Where somebody lands after signing in must never be an address of their choosing: that is
     * an open redirect, and our own sign-in page would be the one sending them there.
     */
    public function test_a_local_sign_in_refuses_to_return_to_another_site(): void
    {
        User::factory()->create([
            'username' => 'moon-translator',
            'provider' => 'local',
            'password' => Hash::make('a-long-enough-password'),
        ]);

        $response = $this->post(route('local.login'), [
            'username' => 'moon-translator',
            'password' => 'a-long-enough-password',
            'redirect' => 'https://evil.example/steal',
        ]);

        $response->assertRedirect(route('home'));
        $this->assertAuthenticated();
    }

    /** Protocol-relative addresses are another host too, and look relative at a glance. */
    public function test_a_protocol_relative_address_is_refused_as_well(): void
    {
        User::factory()->create([
            'username' => 'moon-translator',
            'provider' => 'local',
            'password' => Hash::make('a-long-enough-password'),
        ]);

        $this->post(route('local.login'), [
            'username' => 'moon-translator',
            'password' => 'a-long-enough-password',
            'redirect' => '//evil.example/steal',
        ])->assertRedirect(route('home'));
    }
}
