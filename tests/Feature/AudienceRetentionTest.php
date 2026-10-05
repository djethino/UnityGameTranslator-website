<?php

namespace Tests\Feature;

use App\Models\AnalyticsDaily;
use App\Models\AnalyticsEvent;
use App\Models\AnalyticsMonthly;
use App\Models\ClientUsageDaily;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * What the audience figures keep once the raw events are gone (analyse/retention-et-mesures.md).
 *
 * 🔴 The raw events live 90 days; what the nightly job does not sum before then is lost for good.
 * These hold the three things added on 2026-10-05 — pages and site languages per day, download
 * languages per day, distinct visitors and copies per MONTH — and that the monthly fingerprints do
 * not outlive their month.
 */
class AudienceRetentionTest extends TestCase
{
    use RefreshDatabase;

    private function event(string $at, string $route, array $extra = [], string $ip = '10.0.0.1'): void
    {
        $when = \Carbon\Carbon::parse($at);

        AnalyticsEvent::create([
            'route' => $route,
            'device' => 'desktop',
            'created_at' => $when,
        ] + $extra + AnalyticsEvent::visitorFingerprints($ip, 'Browser', $when));
    }

    public function test_pages_site_languages_and_download_languages_are_kept_per_day(): void
    {
        $this->travelTo('2026-10-06 03:00');

        $this->event('2026-10-05 10:00', 'home', ['locale' => 'fr']);
        $this->event('2026-10-05 11:00', 'games.show', ['locale' => 'fr']);
        $this->event('2026-10-05 12:00', 'home', ['locale' => 'ja']);
        $this->event('2026-10-05 13:00', 'api.translations.download', ['target_language' => 'French']);
        $this->event('2026-10-05 14:00', 'translations.download', ['target_language' => 'French', 'locale' => 'fr']);
        // A refresh of a file already held is not somebody taking a translation.
        $this->event('2026-10-05 15:00', 'api.translations.refresh', ['target_language' => 'German']);

        $this->artisan('analytics:aggregate', ['--date' => '2026-10-05'])->assertSuccessful();

        $day = AnalyticsDaily::whereDate('date', '2026-10-05')->firstOrFail();
        $this->assertSame(2, $day->routes['home']);
        $this->assertSame(['fr' => 3, 'ja' => 1], $day->locales);
        $this->assertSame(['French' => 2], $day->download_languages, 'downloads only, never refreshes');
    }

    public function test_a_month_counts_a_person_once_however_many_days_they_came(): void
    {
        $this->travelTo('2026-10-21 03:00');

        // The same visitor on three days, another on one: four daily visitors, two in the month.
        foreach (['2026-10-03', '2026-10-10', '2026-10-20'] as $day) {
            $this->event("{$day} 10:00", 'home', [], '10.0.0.1');
        }
        $this->event('2026-10-20 11:00', 'home', [], '10.0.0.2');

        // One copy of the mod seen on two days, one Manager.
        $mod = ['kind' => 'mod', 'version' => '0.14.1', 'variant' => 'BepInEx5', 'legacy' => false];
        $manager = ['kind' => 'manager', 'version' => '0.4.1', 'variant' => null, 'legacy' => false];
        ClientUsageDaily::record($mod, 'day-1', '2026-10-19', 'month-mod-a');
        ClientUsageDaily::record($mod, 'day-2', '2026-10-20', 'month-mod-a');
        ClientUsageDaily::record($manager, 'day-3', '2026-10-20', 'month-manager-a');

        $this->artisan('analytics:aggregate', ['--date' => '2026-10-20'])->assertSuccessful();

        $month = AnalyticsMonthly::whereDate('month', '2026-10-01')->firstOrFail();
        $this->assertSame(2, $month->unique_visitors);
        $this->assertSame(1, $month->mod_copies, 'one copy seen on two days is one copy');
        $this->assertSame(1, $month->manager_copies);
    }

    public function test_a_counted_month_forgets_its_fingerprints_and_is_never_recounted_to_zero(): void
    {
        // The night after the month's last day: September is counted, then forgotten.
        $this->travelTo('2026-10-01 02:00');
        $this->event('2026-09-30 10:00', 'home', [], '10.0.0.1');
        ClientUsageDaily::record(['kind' => 'mod', 'version' => '0.14.1', 'variant' => 'BepInEx5', 'legacy' => false],
            'day-1', '2026-09-30', 'month-mod-a');

        $this->artisan('analytics:aggregate', ['--date' => '2026-09-30'])->assertSuccessful();

        $september = AnalyticsMonthly::whereDate('month', '2026-09-01')->firstOrFail();
        $this->assertSame(1, $september->unique_visitors);
        $this->assertSame(1, $september->mod_copies);
        $this->assertSame(0, AnalyticsEvent::whereNotNull('visitor_month_hash')->count(), 'counted: who is forgotten');
        $this->assertSame(0, DB::table('client_monthly_seen')->count());

        // Re-run by hand a fortnight later for a September day: the month is left as it is.
        $this->travelTo('2026-10-15 10:00');
        $this->artisan('analytics:aggregate', ['--date' => '2026-09-12'])->assertSuccessful();
        $this->assertSame(1, $september->fresh()->unique_visitors, 'a forgotten month is never recounted to zero');
    }

    public function test_the_admin_page_shows_what_is_kept(): void
    {
        $this->travelTo('2026-10-21 03:00');
        $this->event('2026-10-20 10:00', 'games.show', ['locale' => 'ko']);
        $this->event('2026-10-20 11:00', 'api.translations.download', ['target_language' => 'Korean']);
        $this->artisan('analytics:aggregate', ['--date' => '2026-10-20'])->assertSuccessful();

        $admin = \App\Models\User::factory()->create(['is_admin' => true]);
        $html = $this->actingAs($admin)->get(route('admin.analytics', ['period' => 7]))->assertOk()->getContent();

        $this->assertStringContainsString('Per month', $html);
        $this->assertStringContainsString('2026-10', $html);
        $this->assertStringContainsString('games.show', $html);
        $this->assertStringContainsString('Korean', $html);
        $this->assertStringContainsString('>ko<', $html);
    }

    public function test_the_two_fingerprints_are_made_together_and_differ(): void
    {
        $prints = AnalyticsEvent::visitorFingerprints('10.0.0.1', 'Browser', \Carbon\Carbon::parse('2026-10-05'));

        $this->assertSame(32, strlen($prints['visitor_hash']));
        $this->assertSame(32, strlen($prints['visitor_month_hash']));
        $this->assertNotSame($prints['visitor_hash'], $prints['visitor_month_hash']);

        // Same month, another day: the monthly one holds, the daily one moves.
        $later = AnalyticsEvent::visitorFingerprints('10.0.0.1', 'Browser', \Carbon\Carbon::parse('2026-10-20'));
        $this->assertSame($prints['visitor_month_hash'], $later['visitor_month_hash']);
        $this->assertNotSame($prints['visitor_hash'], $later['visitor_hash']);
    }

    public function test_game_days_older_than_thirteen_months_become_months_and_still_count(): void
    {
        $this->travelTo('2026-10-06 03:00');
        $game = \App\Models\Game::create(['name' => 'Some Game']);

        // Two days of August 2025 — older than thirteen months — and one recent day.
        foreach ([['2025-08-03', 10, 2], ['2025-08-20', 5, 1], ['2026-10-01', 7, 3]] as [$date, $views, $downloads]) {
            \App\Models\AnalyticsGame::create(['date' => $date, 'game_id' => $game->id, 'page_views' => $views, 'downloads' => $downloads]);
        }

        $this->assertSame(2, \App\Models\AnalyticsGame::foldOldDays());

        $this->assertSame(1, \App\Models\AnalyticsGame::count(), 'the recent day stays a day');
        $month = DB::table('analytics_games_monthly')->first();
        $this->assertSame('2025-08-01', \Carbon\Carbon::parse($month->month)->toDateString());
        $this->assertSame([15, 3], [(int) $month->page_views, (int) $month->downloads]);

        // A late day of a folded month joins it the next night instead of being lost.
        \App\Models\AnalyticsGame::create(['date' => '2025-08-25', 'game_id' => $game->id, 'page_views' => 4, 'downloads' => 0]);
        \App\Models\AnalyticsGame::foldOldDays();
        $this->assertSame(19, (int) DB::table('analytics_games_monthly')->value('page_views'));

        // The top games add both tables over a long span, and only the days over a short one.
        $long = \App\Models\AnalyticsGame::topOverPeriod(500)->first();
        $this->assertSame(26, (int) $long->attention);
        $this->assertSame(3 + 3, (int) $long->downloads);
        $this->assertSame('Some Game', $long->game->name);
        $this->assertSame(7, (int) \App\Models\AnalyticsGame::topOverPeriod(30)->first()->attention);
    }
}
