<?php

namespace Tests\Feature;

use App\Models\EditSessionToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 🔴 **One rate-limit counter for the whole site was shared by every route.**
 *
 * `throttle:10,1` builds its cache key from `resolveRequestSignature()`, which is the signed-in
 * user — or the domain and IP — and NOTHING ELSE. Not the route, not the URI. So every route
 * carrying a bare `throttle:N,1` incremented the SAME counter, while each was compared against its
 * OWN N: the strictest route on the site fell first, fed by traffic it never received.
 *
 * Found on the live editor: the page polls its state every ten seconds (`throttle:30,1`), and the
 * "End session" button (`throttle:10,1`) answered 429 because the shared counter was already past
 * ten. Nothing had hammered anything — the page had merely been open for a minute.
 *
 * ⚠ Worse, and this is what makes it hard to recognise: the expiry belongs to whichever route
 * created the key first. A route with `throttle:5,60` opening the window makes every other route
 * on the site count within an HOUR-long bucket instead of a minute.
 *
 * The cure is the third argument the middleware already takes: a prefix, which lands in the key.
 */
class ThrottleIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    /**
     * The exact sequence a person performs: open the editor, let it poll, press End session.
     */
    public function test_polling_the_editor_does_not_use_up_the_end_button(): void
    {
        $this->postJson('/api/v1/edit-session/init', [
            'content' => ['Hello' => ['v' => 'Bonjour', 't' => 'A']],
            'game_name' => 'A Game',
        ])->assertOk();

        $session = EditSessionToken::first();
        $this->get('/edit-session/' . $session->token);
        $this->withCookie('ugt_edit_session', $session->token)->withCredentials();

        // A minute of an open editor. The page polls every ten seconds; this is twelve of them,
        // which is well inside that route's own allowance of thirty.
        for ($i = 0; $i < 12; $i++) {
            $this->get('/edit-session-state?s=' . $session->id)->assertOk();
        }

        // 🔴 And now the one press. It has its own budget of ten per minute and has been used
        // exactly zero times.
        $this->post('/edit-session-end', ['s' => $session->id])
            ->assertStatus(302);
    }

    /**
     * ⚠ **The other half, and the one that would make the fix worthless if it failed.** Giving each
     * route its own counter must not stop any of them counting: a route still refuses past its own
     * limit, and that limit is the one written beside it.
     */
    public function test_a_route_still_refuses_past_its_own_limit(): void
    {
        $this->postJson('/api/v1/edit-session/init', [
            'content' => ['Hello' => ['v' => 'Bonjour', 't' => 'A']],
            'game_name' => 'A Game',
        ])->assertOk();

        $session = EditSessionToken::first();
        $this->get('/edit-session/' . $session->token);
        $this->withCookie('ugt_edit_session', $session->token)->withCredentials();

        // The state route allows thirty a minute.
        for ($i = 0; $i < 30; $i++) {
            $this->get('/edit-session-state?s=' . $session->id)->assertOk();
        }

        $this->get('/edit-session-state?s=' . $session->id)->assertStatus(429);
    }
}
