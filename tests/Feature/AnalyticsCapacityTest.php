<?php

namespace Tests\Feature;

use App\Models\AnalyticsDaily;
use App\Models\EditSessionToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Concurrency history for live edit sessions.
 *
 * Concurrency is the one thing that cannot be reconstructed after the fact: a
 * page view leaves a row behind, a simultaneous connection leaves nothing once
 * it closes. Hence sampled peaks plus exact counters — and hence these tests,
 * which guard the difference between "nobody was connected" and "we could not
 * tell", the confusion that would make the whole history lie.
 */
class AnalyticsCapacityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function today(): AnalyticsDaily
    {
        return AnalyticsDaily::whereDate('date', now()->toDateString())->firstOrFail();
    }

    public function test_capacity_sampling_keeps_the_high_water_mark(): void
    {
        AnalyticsDaily::recordCapacitySample(3, 2);
        AnalyticsDaily::recordCapacitySample(7, 5);
        AnalyticsDaily::recordCapacitySample(1, 1);

        $row = $this->today();
        $this->assertSame(7, $row->peak_edit_sessions);
        $this->assertSame(5, $row->peak_edit_streams);
        $this->assertNotNull($row->peak_edit_sessions_at);
        $this->assertNotNull($row->peak_edit_streams_at);
    }

    public function test_unknown_stream_count_never_lowers_the_peak(): void
    {
        AnalyticsDaily::recordCapacitySample(4, 9);

        // SSE server unreachable: streams unknown, NOT zero. Recording a zero
        // would read as "nobody was connected" on a day that may have been busy.
        AnalyticsDaily::recordCapacitySample(4, null);

        $this->assertSame(9, $this->today()->peak_edit_streams);
    }

    public function test_stream_refusals_are_kept_as_high_water_marks(): void
    {
        AnalyticsDaily::recordCapacitySample(1, 4, 3, 7);

        // The SSE server counts from its own start, so a Passenger restart puts
        // it back to zero. Keeping the maximum loses history at worst; summing
        // would count the same refusals again on every sample.
        AnalyticsDaily::recordCapacitySample(1, 4, 0, 0);

        $row = $this->today();
        $this->assertSame(3, $row->stream_refusals_capacity);
        $this->assertSame(7, $row->stream_refusals_per_ip);

        AnalyticsDaily::recordCapacitySample(1, 4, 9, 7);
        $this->assertSame(9, $this->today()->stream_refusals_capacity);
    }

    public function test_an_sse_server_that_reports_no_refusals_leaves_them_untouched(): void
    {
        AnalyticsDaily::recordCapacitySample(1, 4, 5, 5);

        // Older SSE server, or unreachable: null is "not reported", never zero
        AnalyticsDaily::recordCapacitySample(1, 4, null, null);

        $row = $this->today();
        $this->assertSame(5, $row->stream_refusals_capacity);
        $this->assertSame(5, $row->stream_refusals_per_ip);
    }

    public function test_started_and_refused_sessions_are_counted_exactly(): void
    {
        // One slot, so the second attempt is turned away — the only figure that
        // proves the cap actually bit, which sampled peaks can miss entirely
        config(['edit_session.max_active' => 1]);

        EditSessionToken::createSession(['Hello' => 'Bonjour'], 'Test Game', 'en', 'fr');

        $this->expectException(\OverflowException::class);

        try {
            EditSessionToken::createSession(['Hello' => 'Bonjour'], 'Test Game', 'en', 'fr');
        } finally {
            $row = $this->today();
            $this->assertSame(1, $row->edit_sessions_started);
            $this->assertSame(1, $row->edit_sessions_refused);
        }
    }

    public function test_analytics_page_shows_peaks_and_refusals(): void
    {
        AnalyticsDaily::recordCapacitySample(12, 8);
        AnalyticsDaily::countEditSession(refused: true);

        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();

        $this->actingAs($admin)
            ->get(route('admin.analytics'))
            ->assertOk()
            ->assertSee('Peak edit sessions')
            ->assertSee('Sessions refused')
            // Spelled out rather than left to the reader to notice a "1"
            ->assertSee('The cap turned users away', false);
    }
}
