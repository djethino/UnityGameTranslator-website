<?php

namespace Tests\Feature;

use App\Models\AnalyticsDaily;
use App\Models\User;
use App\Support\Span;
use App\Support\TranslationFlows;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Two dates on the admin screens that read over a span (2026-10-05: a past month could not be read
 * on its own — every span ended today).
 */
class SpanTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_dates_are_kept_inside_what_is_stored_and_what_has_happened(): void
    {
        $this->travelTo('2026-10-05 12:00');

        $span = Span::fromRequest(new Request(['from' => '2026-03-01', 'to' => '2026-03-31']), 400);
        $this->assertTrue($span->isRange());
        $this->assertFalse($span->includesToday());
        $this->assertSame('2026-03-01 → 2026-03-31', $span->label());
        $this->assertSame(31, $span->dayCount());

        // Turned round, brought inside the stored days and today.
        $span = Span::fromRequest(new Request(['from' => '2027-01-01', 'to' => '2020-01-01']), 10);
        $this->assertSame(['2026-09-26', '2026-10-05'], [$span->from->toDateString(), $span->to->toDateString()]);
        $this->assertTrue($span->includesToday());

        // Not a date: the offer is read instead.
        $span = Span::fromRequest(new Request(['from' => 'yesterday', 'to' => '2026-10-05', 'period' => 7]), 400);
        $this->assertSame(7, $span->days);
        $this->assertSame('Last 7 days', $span->label());
    }

    public function test_the_analytics_screen_reads_a_past_month_without_today(): void
    {
        $this->travelTo('2026-10-05 12:00');
        $admin = User::factory()->create(['is_admin' => true]);

        AnalyticsDaily::create(['date' => '2026-03-10', 'page_views' => 111, 'unique_visitors' => 5]);
        AnalyticsDaily::create(['date' => '2026-04-10', 'page_views' => 222, 'unique_visitors' => 5]);
        AnalyticsDaily::create(['date' => '2026-10-01', 'page_views' => 333, 'unique_visitors' => 5]);

        $page = $this->actingAs($admin)->get(route('admin.analytics', ['from' => '2026-03-01', 'to' => '2026-03-31']))
            ->assertOk()
            // The first stored day is 2026-03-10: the range starts there, and says so.
            ->assertSee('2026-03-10 → 2026-03-31')
            ->assertDontSee('today included, counted live')
            ->assertDontSee('text-xs text-green-400">+', false);

        $this->assertSame([111], $page->viewData('dailyStats')->pluck('page_views')->all());
        $this->assertSame(111, $page->viewData('totals')['page_views']);
    }

    public function test_the_flows_screen_reads_a_past_range(): void
    {
        $this->travelTo('2026-10-05 12:00');
        $admin = User::factory()->create(['is_admin' => true]);

        $inside = \App\Models\AuditLog::create(['action' => TranslationFlows::DELETED, 'entity_type' => 'Translation', 'entity_id' => 5,
            'metadata' => ['how' => 'admin'], 'created_at' => '2026-09-01 10:00:00']);
        \App\Models\AuditLog::create(['action' => TranslationFlows::DELETED, 'entity_type' => 'Translation', 'entity_id' => 6,
            'metadata' => ['how' => 'author'], 'created_at' => '2026-10-04 10:00:00']);

        $this->actingAs($admin)->get(route('admin.flows', ['from' => '2026-09-01', 'to' => '2026-09-02']))
            ->assertOk()
            ->assertSee('Deleted by an admin')
            ->assertDontSee('Deleted by its author')
            ->assertSee('Per hour');
    }
}
