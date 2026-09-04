<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Forget WHO, keep WHAT — twelve months after the fact, for BOTH identifiers.
 *
 * 🔴 **The user agent was never cleared.** The job forgot the IP address and left the browser,
 * system and versions beside the same user id for ever — so a row kept, indefinitely, what the
 * privacy policy said was gone after a year. Same job, same cutoff, one more column.
 */
class PurgeAuditIdentifiersTest extends TestCase
{
    use RefreshDatabase;

    private function entry(int $monthsAgo): AuditLog
    {
        return AuditLog::forceCreate([
            'action' => 'login',
            'user_id' => null,
            'ip_address' => '203.0.113.7',
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0) Firefox/130.0',
            'created_at' => now()->subMonths($monthsAgo),
        ]);
    }

    public function test_both_identifiers_go_after_twelve_months_and_the_event_stays(): void
    {
        $old = $this->entry(13);
        $recent = $this->entry(11);
        $when = $old->created_at;

        $this->artisan('audit:purge-ips')->assertSuccessful();

        $old->refresh();
        $this->assertNull($old->ip_address);
        $this->assertNull($old->user_agent, 'the user agent identifies a machine as surely as its address');
        $this->assertSame('login', $old->action, 'the event itself is kept');
        $this->assertEquals($when, $old->created_at, 'the moment it happened is never rewritten');

        $recent->refresh();
        $this->assertNotNull($recent->ip_address);
        $this->assertNotNull($recent->user_agent);
    }

    public function test_a_row_that_only_kept_its_user_agent_is_cleared_too(): void
    {
        // The state every row purged before this change was left in.
        $old = $this->entry(13);
        $old->forceFill(['ip_address' => null])->saveQuietly();

        $this->artisan('audit:purge-ips')->assertSuccessful();

        $this->assertNull($old->fresh()->user_agent);
    }
}
