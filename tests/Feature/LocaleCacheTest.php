<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A page whose language comes from the session must not be stored by any cache.
 *
 * 🔴 Reported on 2026-08-16: switch the interface to Spanish on /games, click Docs, and the site
 * comes back in English. Nothing was wrong in the translation — the browser served its own copy of
 * /docs from under a minute earlier, because every page was announced as `public, max-age=60,
 * s-maxage=300`.
 *
 * ⚠ The middleware set `Vary: Cookie, Accept-Language`, which reads like the answer and cannot be
 * one: the language lives in the SESSION, so the cookie is identical before and after switching.
 * Two identical requests, two different bodies — no Vary can separate them. The only fix is to
 * stop calling those URLs cacheable.
 */
class LocaleCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_url_without_a_locale_prefix_is_never_cached(): void
    {
        $response = $this->get('/docs')->assertOk();

        $cacheControl = $response->headers->get('Cache-Control');

        $this->assertStringContainsString('no-cache', $cacheControl);
        $this->assertStringNotContainsString('public', $cacheControl);
        $this->assertStringNotContainsString('s-maxage', $cacheControl);
    }

    public function test_a_prefixed_url_keeps_its_public_cache(): void
    {
        // ⚠ The guard must not turn into "never cache anything". These are the URLs the layout
        // advertises through hreflang and the ones crawlers index; their language is in the path,
        // so a shared cache can hold them safely.
        $response = $this->get('/es/docs')->assertOk();

        $this->assertStringContainsString('public', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('Cookie', $response->headers->get('Vary'));
    }

    public function test_the_session_language_survives_a_page_change(): void
    {
        // The user's exact sequence, minus the browser cache the test harness does not have:
        // it proves the server side is right, which is what the header change protects.
        $this->get('/locale/es')->assertRedirect();

        $this->get('/docs')->assertOk()->assertSee('lang="es"', escape: false);
    }
}
