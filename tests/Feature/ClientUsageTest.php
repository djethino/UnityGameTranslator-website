<?php

namespace Tests\Feature;

use App\Models\AnalyticsEvent;
use App\Models\ClientUsageDaily;
use App\Models\Game;
use App\Models\Translation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Knowing what is installed, without knowing who installed it.
 *
 * 🔴 **Two decisions had no data behind them**: whether an old release is still out there in
 * numbers — which is what allows JSON compression to be switched on without cutting those installs
 * off — and whether a mod loader adapter is still worth maintaining. Nothing measured either, and
 * the one place that tried (the API download's analytics event) had been throwing on every call
 * since it was written, hidden by a try/catch.
 */
class ClientUsageTest extends TestCase
{
    use RefreshDatabase;

    private function callAs(string $agent): void
    {
        $this->withHeaders(['User-Agent' => $agent])->get('/api/v1/games')->assertOk();
    }

    public function test_the_mod_is_recorded_with_its_version_and_loader(): void
    {
        $this->callAs('UnityGameTranslator/0.11.1 (BepInEx6-IL2CPP)');

        $row = ClientUsageDaily::first();

        $this->assertNotNull($row, 'a call from the mod was not recorded at all');
        $this->assertSame('mod', $row->product);
        $this->assertSame('0.11.1', $row->version);
        $this->assertSame('BepInEx6-IL2CPP', $row->variant);
    }

    public function test_the_manager_is_recorded_apart_from_the_mod(): void
    {
        $this->callAs('UnityGameTranslatorManager/0.1.0');

        $row = ClientUsageDaily::first();

        $this->assertSame('manager', $row->product);
        $this->assertSame('0.1.0', $row->version);
        $this->assertNull($row->variant, 'the Manager has no loader');
    }

    /**
     * 🔴 The row that decides whether compression can be enabled: every build published up to
     * 2026-08-20 says "1.0" whatever it really is, so it must never be filed as a real version.
     */
    public function test_the_placeholder_version_is_recorded_as_unknown(): void
    {
        $this->callAs('UnityGameTranslator/1.0');

        $row = ClientUsageDaily::first();

        $this->assertSame('mod', $row->product);
        $this->assertNull($row->version);
    }

    /** ⚠ Not traffic analytics: browsers and strangers write nothing here. */
    public function test_only_our_own_programs_are_recorded(): void
    {
        foreach (['Mozilla/5.0 (Windows NT 10.0)', 'curl/8.4.0', 'python-requests/2.31', ''] as $agent) {
            $this->callAs($agent);
        }

        $this->assertSame(0, ClientUsageDaily::count());
        $this->assertSame(0, DB::table('client_daily_seen')->count());
    }

    /**
     * 🔴 The difference between "how many copies" and "how chatty one copy is". A mod polling
     * hourly must not weigh as much as twenty-four separate installations.
     */
    public function test_repeat_calls_count_once_as_an_install(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->callAs('UnityGameTranslator/0.11.1 (BepInEx5)');
        }

        $row = ClientUsageDaily::first();

        $this->assertSame(5, $row->requests);
        $this->assertSame(1, $row->installs);
    }

    public function test_two_loaders_of_the_same_version_are_counted_apart(): void
    {
        $this->callAs('UnityGameTranslator/0.11.1 (BepInEx5)');
        $this->callAs('UnityGameTranslator/0.11.1 (MelonLoader-Mono)');

        $this->assertSame(2, ClientUsageDaily::count());
        $this->assertEqualsCanonicalizing(
            ['BepInEx5', 'MelonLoader-Mono'],
            ClientUsageDaily::pluck('variant')->all()
        );
    }

    /**
     * 🔴 **Anonymous by construction.** The table holds counts per version, and the only other
     * thing written is a daily fingerprint with nothing joinable to it — no address, no product,
     * nothing that survives to the next day.
     */
    public function test_nothing_stored_can_identify_a_caller(): void
    {
        $this->callAs('UnityGameTranslator/0.11.1 (BepInEx5)');

        $this->assertEqualsCanonicalizing(
            ['date', 'fingerprint'],
            array_keys((array) DB::table('client_daily_seen')->first())
        );

        $stored = (array) ClientUsageDaily::first()->getAttributes();
        foreach (['ip', 'ip_address', 'user_agent', 'visitor_hash', 'user_id'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $stored);
        }
    }

    /** A period sums the calls but takes the busiest day for installs, never the sum. */
    public function test_installs_over_a_period_are_the_busiest_day_not_the_total(): void
    {
        ClientUsageDaily::insert([
            ['date' => now()->subDays(2)->toDateString(), 'product' => 'mod', 'version' => '0.11.1',
             'variant' => 'BepInEx5', 'requests' => 10, 'installs' => 4],
            ['date' => now()->subDay()->toDateString(), 'product' => 'mod', 'version' => '0.11.1',
             'variant' => 'BepInEx5', 'requests' => 6, 'installs' => 7],
        ]);

        $rows = ClientUsageDaily::overPeriod(30);

        $this->assertCount(1, $rows);
        $this->assertSame(16, $rows[0]['requests']);
        $this->assertSame(7, $rows[0]['installs']);
    }

    /**
     * 🔴 The bug this whole thing grew out of: `device` was an enum of desktop/mobile/tablet, the
     * API download wrote 'mod', every insert threw and the catch hid it. Months of downloads
     * counted nowhere while the site's own button was counted.
     */
    public function test_a_download_through_the_api_is_finally_recorded(): void
    {
        $user = User::factory()->create()->refresh();
        $game = Game::forceCreate(['name' => 'Recorded', 'slug' => 'rec-' . uniqid('', true)]);

        $dir = storage_path('app/private/translations');
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $relative = 'translations/test_' . uniqid('', true) . '.json';
        $full = storage_path('app/private/' . $relative);
        file_put_contents($full, json_encode(['Hello' => ['v' => 'Bonjour', 't' => 'V']]));

        $translation = new Translation();
        $translation->forceFill([
            'game_id' => $game->id, 'user_id' => $user->id,
            'source_language' => 'English', 'target_language' => 'French',
            'file_path' => $relative, 'file_uuid' => 'uuid-' . uniqid('', true),
            'file_hash' => 'x', 'visibility' => 'public', 'line_count' => 1,
        ])->save();

        $this->withHeaders(['User-Agent' => 'UnityGameTranslator/0.11.1 (BepInEx5)'])
            ->get("/api/v1/translations/{$translation->id}/download")
            ->assertOk();

        @unlink($full);

        $event = AnalyticsEvent::where('route', 'api.translations.download')->first();

        $this->assertNotNull($event, 'the download event was refused by the database again');
        $this->assertSame('mod', $event->device);
        // ⚠ Null, not "Other": a mod filed under a browser name makes that chart lie.
        $this->assertNull($event->browser);
    }
}
