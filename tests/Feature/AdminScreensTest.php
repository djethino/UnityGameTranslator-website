<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Smoke tests for the admin list screens: ensures the views render (HTTP 200)
 * with sorting + filters active, including the aggregate columns
 * (downloads_sum, last_mod_activity) and the x-admin.sortable-th component.
 */
class AdminScreensTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['is_admin' => true])->save();

        return $user;
    }

    public function test_translations_index_renders_with_sort_and_filters(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.translations.index', [
                'sort' => 'download_count',
                'dir' => 'asc',
                'status' => 'complete',
                'visibility' => 'public',
            ]))
            ->assertOk();
    }

    public function test_users_index_renders_with_sort_and_filters(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.users', [
                'sort' => 'last_mod_activity',
                'dir' => 'desc',
                'provider' => 'steam',
            ]))
            ->assertOk();
    }

    public function test_translations_index_renders_default(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.translations.index'))
            ->assertOk();
    }

    public function test_users_index_renders_default(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.users'))
            ->assertOk();
    }

    public function test_analytics_renders_with_live_capacity(): void
    {
        // The live-edit capacity panel reaches out to the SSE server for its
        // stream count. That server is absent here, exactly as it can be in
        // production — the page must render anyway rather than 500 on a figure
        // that is only nice to have.
        config(['edit_session.sse_health_url' => 'http://127.0.0.1:9/health']);

        $this->actingAs($this->admin())
            ->get(route('admin.analytics'))
            ->assertOk()
            ->assertSee('Live edit sessions');
    }

    public function test_analytics_renders_without_sse_health_url(): void
    {
        config(['edit_session.sse_health_url' => null]);

        $this->actingAs($this->admin())
            ->get(route('admin.analytics'))
            ->assertOk();
    }
}
