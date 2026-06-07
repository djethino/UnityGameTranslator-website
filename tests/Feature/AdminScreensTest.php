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
}
