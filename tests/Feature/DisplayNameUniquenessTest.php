<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Nobody may wear somebody else's name.
 *
 * 🔴 **`name` is what the site shows, and nothing made it unique.** `username` carries the unique
 * index but is displayed nowhere and is null on every account created through a provider — so the
 * one field readers actually see could be taken, exactly, in three clicks. The 30-day delay and the
 * ASCII charset guarded the subtle version of impersonation while the blunt one walked in.
 *
 * ⚠ Existing duplicates are left alone on purpose: this is a ratchet, not a purge. It stops new
 * ones without renaming anybody who is already here.
 */
class DisplayNameUniquenessTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_rename_cannot_take_a_name_somebody_else_holds(): void
    {
        User::factory()->create(['name' => 'Established']);
        $other = User::factory()->create(['name' => 'Newcomer']);

        $this->actingAs($other)->put('/profile', ['name' => 'Established'])
            ->assertSessionHasErrors('name');

        $this->assertSame('Newcomer', $other->fresh()->name);
    }

    /** Capitals are not a difference to whoever is reading the page. */
    public function test_case_alone_is_not_a_different_name(): void
    {
        User::factory()->create(['name' => 'Established']);
        $other = User::factory()->create(['name' => 'Newcomer']);

        $this->actingAs($other)->put('/profile', ['name' => 'eStAbLiShEd'])
            ->assertSessionHasErrors('name');
    }

    /** Without this, nobody could save their own profile, nor fix their own capitalisation. */
    public function test_keeping_or_recasing_your_own_name_is_allowed(): void
    {
        $user = User::factory()->create(['name' => 'Mine', 'locale' => 'en']);

        $this->actingAs($user)->put('/profile', ['name' => 'Mine', 'locale' => 'en'])
            ->assertSessionHasNoErrors();
    }

    /**
     * A refusal with nothing to do next is where people give up — and it is why so many addresses
     * end in a number nobody chose on purpose.
     */
    public function test_the_refusal_offers_free_variants(): void
    {
        User::factory()->create(['name' => 'Taken']);
        User::factory()->create(['name' => 'Taken2']);
        $other = User::factory()->create(['name' => 'Someone']);

        $response = $this->actingAs($other)->put('/profile', ['name' => 'Taken']);

        $error = session('errors')->first('name');
        $this->assertStringContainsString('Taken3', $error);
        $this->assertStringNotContainsString('Taken2', $error, 'Taken2 exists and must not be offered');
    }

    /**
     * 🔴 The front door. A local sign-up checked the unique index and nothing else, so it could
     * take the displayed name of a provider account — which has no username to collide with.
     */
    public function test_local_signup_cannot_take_a_provider_accounts_displayed_name(): void
    {
        User::factory()->create(['name' => 'ProviderUser', 'username' => null, 'provider' => 'google']);

        $this->post('/register', [
            'username' => 'provideruser',
            'password' => 'super-secret-pass',
            'password_confirmation' => 'super-secret-pass',
        ])->assertSessionHasErrors('username');

        $this->assertSame(1, User::whereRaw('LOWER(name) = ?', ['provideruser'])->count());
    }

    public function test_suggestions_skip_names_already_taken(): void
    {
        User::factory()->create(['name' => 'Busy']);
        User::factory()->create(['name' => 'Busy2']);
        User::factory()->create(['name' => 'Busy3']);

        $this->assertSame(['Busy4', 'Busy5', 'Busy6'], User::suggestDisplayNames('Busy'));
    }
}
