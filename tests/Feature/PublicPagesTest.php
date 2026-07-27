<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Smoke tests for the pages any visitor can reach without an account.
 *
 * Replaces Laravel's generated ExampleTest, which had been failing since the
 * initial commit: it called the home page without RefreshDatabase, so the
 * tables it reads did not exist and it answered 500. A test that always fails
 * teaches nothing and hides real regressions in the noise.
 */
class PublicPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_page_renders(): void
    {
        $this->get('/')->assertOk();
    }

    public function test_legal_pages_render(): void
    {
        $this->get('/legal')->assertOk();
        $this->get('/terms')->assertOk();
        $this->get('/privacy')->assertOk();
    }

    public function test_privacy_page_lists_every_cookie_the_site_sets(): void
    {
        // The page names cookies one by one, so setting one without declaring
        // it here would make it false. Anyone adding a cookie must add a line.
        $this->get('/privacy')
            ->assertOk()
            ->assertSee('laravel_session')
            ->assertSee('XSRF-TOKEN')
            ->assertSee('ugt_edit_session');
    }
}
