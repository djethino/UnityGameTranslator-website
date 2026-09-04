<?php

namespace Tests\Feature;

use App\Models\DeviceCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The link page shows what a code stands for before it links anything.
 *
 * 🔴 **A valid code used to link on the spot.** That is the shape of the phishing every device
 * flow has met: "enter ABCD-1234 on the site to unlock X" — and the access went to whoever's
 * program displayed that code. Now the first POST only shows which program, which game and how
 * long ago, and a second POST is what links. A person handed a code sees a game they do not own
 * and has a way out.
 */
class LinkConfirmationTest extends TestCase
{
    use RefreshDatabase;

    private function issue(array $declared = []): DeviceCode
    {
        $init = $this->withHeaders(['User-Agent' => 'UnityGameTranslator/0.12.1 (BepInEx5)'])
            ->postJson('/api/v1/auth/device', $declared)
            ->assertOk();

        return DeviceCode::where('user_code', $init->json('user_code'))->firstOrFail();
    }

    public function test_the_first_post_shows_the_code_and_links_nothing(): void
    {
        $user = User::factory()->create();
        $code = $this->issue(['game_id' => '367520', 'game_name' => 'Some Game']);

        $this->actingAs($user)->post('/link', ['code' => $code->user_code])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('link'));

        $this->assertSame(0, $user->apiTokens()->count(), 'nothing is linked before it is confirmed');
        $this->assertNull($code->fresh()->user_id);

        $page = $this->actingAs($user)->get(route('link'))->assertOk();
        $page->assertSee('Some Game');
        $page->assertSee('0.12.1');
        $page->assertSee(__('connections.client_mod'));
        $page->assertSee('name="confirm"', false);
    }

    public function test_the_confirmation_is_what_links(): void
    {
        $user = User::factory()->create();
        $code = $this->issue(['game_id' => '367520', 'game_name' => 'Some Game']);

        $this->actingAs($user)->post('/link', ['code' => $code->user_code]);
        $this->actingAs($user)->post('/link', ['code' => $code->user_code, 'confirm' => 1])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame(1, $user->apiTokens()->count());
        // The code is consumed by the link: it cannot be entered a second time.
        $this->assertNull(DeviceCode::findByUserCode($code->user_code));
    }

    public function test_cancelling_forgets_the_code_and_shows_the_form_again(): void
    {
        $user = User::factory()->create();
        $code = $this->issue();

        $this->actingAs($user)->post('/link', ['code' => $code->user_code]);
        $page = $this->actingAs($user)->get(route('link', ['cancel' => 1]))->assertOk();

        $page->assertDontSee('name="confirm"', false);
        $page->assertSee('name="code"', false);
        $this->assertSame(0, $user->apiTokens()->count());
    }

    public function test_a_code_that_expired_while_on_screen_cannot_be_confirmed(): void
    {
        $user = User::factory()->create();
        $code = $this->issue();

        $this->actingAs($user)->post('/link', ['code' => $code->user_code]);

        $code->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->actingAs($user)->get(route('link'))->assertOk()->assertSee(__('link.invalid_code'));

        $this->actingAs($user)->post('/link', ['code' => $code->user_code, 'confirm' => 1])
            ->assertSessionHasErrors('code');

        $this->assertSame(0, $user->apiTokens()->count());
    }

    public function test_a_program_that_declared_nothing_is_still_described(): void
    {
        $user = User::factory()->create();
        $code = $this->issue();

        $this->actingAs($user)->post('/link', ['code' => $code->user_code]);

        $this->actingAs($user)->get(route('link'))->assertOk()
            ->assertSee(__('connections.game_not_recorded'));
    }
}
