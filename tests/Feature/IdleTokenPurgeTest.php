<?php

namespace Tests\Feature;

use App\Console\Commands\PurgeIdleTokens;
use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Cutting the accesses nobody uses any more.
 *
 * ⚠ Not a security measure, and the tests do not pretend otherwise: an access being used stays
 * alive, because being used is what keeps it here. What this keeps is the list SHORT — and a short
 * list is what makes an access somebody does not recognise stand out.
 */
class IdleTokenPurgeTest extends TestCase
{
    use RefreshDatabase;

    /** Travels past the grace date so the rule is actually in force. */
    private function inForce(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-27')->addMonths(7));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * 🔴 The whole difficulty. Deploying a six-month rule against a table that never had one would
     * revoke every dormant access on the first night, silently and all at once.
     */
    public function test_nothing_is_cut_while_the_grace_period_runs(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-27')->addMonths(2));

        $user = User::factory()->create();
        $token = ApiToken::createForUser($user);
        DB::table('api_tokens')->where('id', $token->id)->update(['last_used_at' => now()->subYears(3)]);

        $this->artisan('tokens:purge-idle')->assertSuccessful();

        $this->assertDatabaseHas('api_tokens', ['id' => $token->id]);
    }

    public function test_an_access_unused_for_six_months_is_cut(): void
    {
        $this->inForce();

        $user = User::factory()->create();
        $stale = ApiToken::createForUser($user);
        $fresh = ApiToken::createForUser($user);

        DB::table('api_tokens')->where('id', $stale->id)->update(['last_used_at' => now()->subMonths(7)]);
        DB::table('api_tokens')->where('id', $fresh->id)->update(['last_used_at' => now()->subDays(3)]);

        $this->artisan('tokens:purge-idle')->assertSuccessful();

        $this->assertDatabaseMissing('api_tokens', ['id' => $stale->id]);
        $this->assertDatabaseHas('api_tokens', ['id' => $fresh->id]);
    }

    /**
     * ⚠ An access issued and never used has no last exchange at all — the emptiest line there is,
     * and not a reason to keep it for ever.
     */
    public function test_an_access_never_used_is_cut_from_when_it_was_issued(): void
    {
        $this->inForce();

        $user = User::factory()->create();
        $token = ApiToken::createForUser($user);

        DB::table('api_tokens')->where('id', $token->id)
            ->update(['last_used_at' => null, 'created_at' => now()->subMonths(8)]);

        $this->artisan('tokens:purge-idle')->assertSuccessful();

        $this->assertDatabaseMissing('api_tokens', ['id' => $token->id]);
    }

    /**
     * 🔴 A calculation, never a write. Stamping the grace date into `last_used_at` would be the
     * obvious shortcut and would make the screen announce "exchange this month" for an access
     * untouched in a year — on the page where somebody decides what to cut.
     */
    public function test_the_dry_run_changes_nothing_and_no_date_is_rewritten(): void
    {
        $this->inForce();

        $user = User::factory()->create();
        $token = ApiToken::createForUser($user);
        DB::table('api_tokens')->where('id', $token->id)->update(['last_used_at' => now()->subMonths(9)]);

        $before = $token->fresh()->last_used_at;

        $this->artisan('tokens:purge-idle --dry-run')->assertSuccessful();

        $this->assertDatabaseHas('api_tokens', ['id' => $token->id]);
        $this->assertTrue($before->equalTo($token->fresh()->last_used_at));
    }

    /**
     * The deadline the screen shows is the one the command will act on, worked out in one place.
     */
    public function test_the_deadline_starts_at_the_grace_date_for_a_long_dormant_access(): void
    {
        $this->inForce();

        $user = User::factory()->create();
        $token = ApiToken::createForUser($user);
        DB::table('api_tokens')->where('id', $token->id)->update(['last_used_at' => Carbon::parse('2024-01-01')]);

        $this->assertSame(
            Carbon::parse('2026-08-27')->addMonths(6)->toDateString(),
            PurgeIdleTokens::deadlineFor($token->fresh())->toDateString()
        );
    }
}
