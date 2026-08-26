<?php

namespace Tests\Feature;

use App\Models\AnalyticsDaily;
use App\Models\AnalyticsEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The visitor fingerprint answers one question, on one day, and then stops existing.
 *
 * It exists so that "how many people came today" is not "how many pages were opened today". It has
 * never followed anybody: the date is part of it, so the same visitor is a different fingerprint
 * tomorrow. What it lacked was an end — the salt was the application key, constant, so anybody
 * holding the database and the .env could confirm an IP address had visited, for the ninety days
 * the rows lived.
 */
class VisitorFingerprintTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_same_visitor_is_one_fingerprint_within_a_day(): void
    {
        $a = AnalyticsEvent::generateVisitorHash('203.0.113.4', 'Firefox', '2026-08-27');
        $b = AnalyticsEvent::generateVisitorHash('203.0.113.4', 'Firefox', '2026-08-27');

        $this->assertSame($a, $b, 'Unique visitors could not be counted otherwise');
        $this->assertSame(32, strlen($a), 'The column is 32 wide');
    }

    public function test_two_visitors_are_two_fingerprints(): void
    {
        $a = AnalyticsEvent::generateVisitorHash('203.0.113.4', 'Firefox', '2026-08-27');
        $b = AnalyticsEvent::generateVisitorHash('198.51.100.7', 'Firefox', '2026-08-27');

        $this->assertNotSame($a, $b);
    }

    /**
     * 🔴 The salt is per day and thrown away, so nobody can confirm a visit afterwards.
     *
     * Simulated by clearing the cache, which is what expiry does two days on. The point is that the
     * SAME inputs no longer produce the same answer — the check itself becomes impossible.
     */
    public function test_once_the_salt_is_gone_the_fingerprint_cannot_be_recomputed(): void
    {
        $before = AnalyticsEvent::generateVisitorHash('203.0.113.4', 'Firefox', '2026-08-27');

        Cache::flush();

        $after = AnalyticsEvent::generateVisitorHash('203.0.113.4', 'Firefox', '2026-08-27');

        $this->assertNotSame($before, $after,
            'A salt that survives its day would let anybody confirm an IP address had visited');
    }

    /** ⚠ And it must not be derivable from the application key, as it was. */
    public function test_the_fingerprint_does_not_come_from_the_application_key(): void
    {
        $hash = AnalyticsEvent::generateVisitorHash('203.0.113.4', 'Firefox', '2026-08-27');

        $this->assertNotSame(
            md5('203.0.113.4|Firefox|2026-08-27|' . config('app.key')),
            $hash
        );
    }

    /**
     * The counting happens, then the fingerprints go — rows and figures both intact.
     */
    public function test_aggregation_counts_the_visitors_then_forgets_them(): void
    {
        $date = now()->subDay()->toDateString();

        foreach ([['203.0.113.4', 'Firefox'], ['203.0.113.4', 'Firefox'], ['198.51.100.7', 'Chrome']] as [$ip, $ua]) {
            AnalyticsEvent::create([
                'route' => 'games.show',
                'country' => 'FR',
                'device' => 'desktop',
                'browser' => $ua,
                'visitor_hash' => AnalyticsEvent::generateVisitorHash($ip, $ua, $date),
                'created_at' => $date . ' 12:00:00',
            ]);
        }

        $this->artisan('analytics:aggregate', ['--date' => $date])->assertSuccessful();

        // Two people, three views — the figure survives its source.
        $this->assertSame(2, AnalyticsDaily::whereDate('date', $date)->first()->unique_visitors);

        // The rows stay: they carry route, country and device, which name nobody.
        $this->assertSame(3, AnalyticsEvent::whereDate('created_at', $date)->count());

        // And no fingerprint is left.
        $this->assertSame(0, AnalyticsEvent::whereDate('created_at', $date)
            ->whereNotNull('visitor_hash')->count());
    }
}
