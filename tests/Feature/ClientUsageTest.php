<?php

namespace Tests\Feature;

use App\Models\AnalyticsEvent;
use App\Models\ClientUsageDaily;
use App\Models\Game;
use App\Models\Translation;
use App\Models\User;
use App\Models\VersionActivity;
use App\Services\KnownReleases;
use App\Services\VersionInventory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Knowing what is installed, without knowing who installed it — and without letting the callers
 * decide what the answer is.
 *
 * 🔴 **Two decisions depend on this table**: whether an old release is still out there in numbers
 * — which is what allows JSON compression to be switched on without cutting those installs off —
 * and whether a mod loader adapter is still worth maintaining.
 *
 * 🔴 **And a User-Agent is written by whoever is calling.** Before the bound below, anyone could
 * invent versions: one new row per made-up number, without limit, in a table meant to hold a dozen
 * rows a day. That is both a disk filling up and, worse, an outsider shaping the figures that
 * decide the two questions above.
 */
class ClientUsageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // ⚠ **No more `Storage::fake('local')`.** It used to be here because KnownReleases wrote to
        // the local disk, so a run of this suite replaced whatever `releases:refresh` had fetched
        // with a two-line fixture — and the next admin page reported almost every install as
        // unrecognised, because of a test. Releases live in a table now, which RefreshDatabase
        // isolates on its own.
        KnownReleases::forget();
        // What the hourly command would have fetched. Tests that need "we know nothing" clear it.
        $this->publish(['0.11.1', '0.11.0'], ['0.1.0']);
    }

    protected function tearDown(): void
    {
        KnownReleases::forget();
        parent::tearDown();
    }

    /**
     * What `releases:refresh` would have recorded.
     *
     * ⚠ A helper rather than a second accepted shape in `KnownReleases::store()`: one format in the
     * code, and the verbosity paid here where it costs nothing.
     */
    private function publish(array $mod, array $manager = [], ?string $publishedAt = null): void
    {
        $entry = fn (string $version) => [
            'version' => $version,
            'published_at' => $publishedAt,
            'prerelease' => false,
        ];

        KnownReleases::store([
            'mod' => array_map($entry, $mod),
            'manager' => array_map($entry, $manager),
        ]);
    }

    private function callAs(string $agent): void
    {
        $this->withHeaders(['User-Agent' => $agent])->get('/api/v1/games')->assertOk();
    }

    /** A different fingerprint every time, the way separate installations look. */
    private function callAsDistinct(string $agent, int $times): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.$i"])
                ->withHeaders(['User-Agent' => $agent])
                ->get('/api/v1/games')
                ->assertOk();
        }
    }

    public function test_a_published_version_gets_its_own_row(): void
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
     * 🔴 **Recognised by the ABSENCE of a loader, never by the number.** Testing for the literal
     * "1.0" would work until the mod actually reaches 1.0 — and that release would then be filed
     * among the builds that cannot decompress, which is the row deciding whether compression can
     * be enabled.
     */
    public function test_a_build_from_before_versioning_is_marked_legacy(): void
    {
        $this->callAs('UnityGameTranslator/1.0');

        $this->assertSame(ClientUsageDaily::LEGACY, ClientUsageDaily::first()->version);
    }

    public function test_a_real_version_one_is_not_mistaken_for_a_legacy_build(): void
    {
        $this->publish(['1.0.0']);

        $this->callAs('UnityGameTranslator/1.0.0 (BepInEx5)');

        $row = ClientUsageDaily::first();

        $this->assertSame('1.0.0', $row->version, 'a genuine v1 was filed as an old build');
        $this->assertNotSame(ClientUsageDaily::LEGACY, $row->version);
    }

    /**
     * 🔴 **The bound.** Invented versions all land on one row instead of creating one each, so the
     * table cannot be grown by a caller and no invention can masquerade as a release.
     */
    public function test_invented_versions_all_share_one_row(): void
    {
        foreach (['9.9.1', '9.9.2', '9.9.3', '4242.1', '0.0.7'] as $i => $version) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.1.0.$i"])
                ->withHeaders(['User-Agent' => "UnityGameTranslator/$version (BepInEx5)"])
                ->get('/api/v1/games')->assertOk();
        }

        $rows = ClientUsageDaily::all();

        $this->assertCount(1, $rows, 'each invented version created a row of its own');
        $this->assertSame(ClientUsageDaily::UNRECOGNISED, $rows[0]->version);
        $this->assertSame(5, $rows[0]->installs);
    }

    /** ⚠ And a made-up loader cannot multiply rows either, for the same reason. */
    public function test_an_invented_loader_cannot_multiply_rows(): void
    {
        foreach (['AAA', 'BBB', 'CCC'] as $i => $loader) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.2.0.$i"])
                ->withHeaders(['User-Agent' => "UnityGameTranslator/9.9.9 ($loader)"])
                ->get('/api/v1/games')->assertOk();
        }

        $this->assertCount(1, ClientUsageDaily::all());
    }

    /** Rubbish in a version or a loader is refused before it reaches the database. */
    public function test_a_hostile_user_agent_writes_nothing_of_its_own(): void
    {
        $this->callAs('UnityGameTranslator/<script>alert(1)</script> (' . str_repeat('X', 40) . ')');

        $row = ClientUsageDaily::first();

        $this->assertSame(ClientUsageDaily::UNRECOGNISED, $row->version);
        $this->assertNull($row->variant);
    }

    /**
     * 🔴 Unrecognised and legacy must never merge: one is "a build that cannot decompress", the
     * other is "a version we cannot place". Confusing them would either hide the compression
     * blocker or invent one.
     */
    public function test_legacy_and_unrecognised_stay_apart(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '10.3.0.1'])
            ->withHeaders(['User-Agent' => 'UnityGameTranslator/1.0'])->get('/api/v1/games');
        $this->withServerVariables(['REMOTE_ADDR' => '10.3.0.2'])
            ->withHeaders(['User-Agent' => 'UnityGameTranslator/7.7.7 (BepInEx5)'])->get('/api/v1/games');

        $this->assertEqualsCanonicalizing(
            [ClientUsageDaily::LEGACY, ClientUsageDaily::UNRECOGNISED],
            ClientUsageDaily::pluck('version')->all()
        );
    }

    /** ⚠ Knowing nothing must produce a missing measurement, never a wrong one. */
    public function test_without_a_published_list_nothing_is_taken_for_a_release(): void
    {
        DB::table('releases')->delete();
        KnownReleases::forget();

        $this->callAs('UnityGameTranslator/0.11.1 (BepInEx5)');

        $this->assertSame(ClientUsageDaily::UNRECOGNISED, ClientUsageDaily::first()->version);
        $this->assertFalse(KnownReleases::known());
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
     * 🔴 One copy is one copy, however often it calls — and every later call of the day writes
     * nothing at all, which is what makes this cheap enough to sit on every API request.
     */
    public function test_a_copy_is_counted_once_a_day_however_often_it_calls(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->callAs('UnityGameTranslator/0.11.1 (BepInEx5)');
        }

        $this->assertSame(1, ClientUsageDaily::first()->installs);
        $this->assertSame(1, DB::table('client_daily_seen')->count());
    }

    public function test_separate_copies_are_counted_separately(): void
    {
        $this->callAsDistinct('UnityGameTranslator/0.11.1 (BepInEx5)', 4);

        $this->assertSame(4, ClientUsageDaily::first()->installs);
    }

    public function test_two_loaders_of_the_same_version_are_counted_apart(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '10.4.0.1'])
            ->withHeaders(['User-Agent' => 'UnityGameTranslator/0.11.1 (BepInEx5)'])->get('/api/v1/games');
        $this->withServerVariables(['REMOTE_ADDR' => '10.4.0.2'])
            ->withHeaders(['User-Agent' => 'UnityGameTranslator/0.11.1 (MelonLoader-Mono)'])->get('/api/v1/games');

        $this->assertEqualsCanonicalizing(
            ['BepInEx5', 'MelonLoader-Mono'],
            ClientUsageDaily::pluck('variant')->all()
        );
    }

    /**
     * 🔴 **Anonymous by construction.** Counts per version, and one daily fingerprint with nothing
     * joinable to it — no address, no product, nothing surviving to the next day.
     */
    public function test_nothing_stored_can_identify_a_caller(): void
    {
        $this->callAs('UnityGameTranslator/0.11.1 (BepInEx5)');

        $this->assertEqualsCanonicalizing(
            ['date', 'fingerprint'],
            array_keys((array) DB::table('client_daily_seen')->first())
        );

        $stored = (array) ClientUsageDaily::first()->getAttributes();
        foreach (['ip', 'ip_address', 'user_agent', 'visitor_hash', 'user_id', 'requests'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $stored);
        }
    }

    /** The fingerprints serve only the day they are written. */
    public function test_old_fingerprints_are_purged(): void
    {
        DB::table('client_daily_seen')->insert([
            ['date' => now()->subDays(5)->toDateString(), 'fingerprint' => str_repeat('a', 32)],
            ['date' => now()->toDateString(), 'fingerprint' => str_repeat('b', 32)],
        ]);

        $this->assertSame(1, ClientUsageDaily::purgeFingerprints());
        $this->assertSame(1, DB::table('client_daily_seen')->count());
    }

    /**
     * ⚠ A row written by the first version of the collector, before the markers had names. It
     * stored the empty string for a build that did not say its version — and the screen rendered
     * an empty cell, which reads as a broken page rather than as an unidentifiable build.
     */
    public function test_a_row_with_no_version_is_still_named_on_screen(): void
    {
        $admin = User::factory()->create(['is_admin' => true])->refresh();

        ClientUsageDaily::insert([
            'date' => now()->toDateString(), 'product' => 'mod',
            'version' => '', 'variant' => '', 'installs' => 3,
        ]);

        $html = $this->actingAs($admin)->get('/admin/analytics?period=1')->assertOk()->getContent();

        $this->assertStringContainsString('before versioning', $html,
            'a row with no version rendered as an empty cell');
    }

    public function test_copies_over_a_span_are_the_busiest_day_not_the_total(): void
    {
        ClientUsageDaily::insert([
            ['date' => now()->subDays(2)->toDateString(), 'product' => 'mod', 'version' => '0.11.1',
             'variant' => 'BepInEx5', 'installs' => 4],
            ['date' => now()->subDay()->toDateString(), 'product' => 'mod', 'version' => '0.11.1',
             'variant' => 'BepInEx5', 'installs' => 7],
        ]);

        $line = collect(VersionInventory::forSpan(30)['mod']['versions'])
            ->firstWhere('name', '0.11.1');

        $this->assertSame(7, $line['copies'], 'the days were added up instead of taking the busiest');
        $this->assertSame(2, $line['days_in_span']);
    }

    /**
     * 🔴 **The register is written beside the count, not by a job.** Without this, "since when / until
     * when" would only be as fresh as the last nightly run — and the screen would invite breaking an
     * API that something called an hour ago.
     */
    public function test_a_call_records_when_it_was_seen(): void
    {
        $this->callAs('UnityGameTranslator/0.11.1 (BepInEx5)');

        $row = VersionActivity::first();

        $this->assertNotNull($row, 'the call was counted but never dated');
        $this->assertSame(now()->toDateString(), $row->first_seen->toDateString());
        $this->assertSame(now()->toDateString(), $row->last_seen->toDateString());
        $this->assertSame(1, $row->days_active);
    }

    /**
     * 🔴 **Fresh traffic reactivates a version, and that is the whole point of the register.**
     * "Extinct for 94 days, seen again today" is the sentence that must stop a hand before it breaks
     * an API call. A screen that recomputes over a span never produces it.
     */
    public function test_an_extinct_version_is_reactivated_by_a_single_call(): void
    {
        VersionActivity::create([
            'product' => 'mod', 'version' => '0.11.0', 'variant' => 'BepInEx5',
            'first_seen' => now()->subDays(120)->toDateString(),
            'last_seen' => now()->subDays(94)->toDateString(),
            'days_active' => 3,
        ]);

        $this->callAs('UnityGameTranslator/0.11.0 (BepInEx5)');

        $row = VersionActivity::first();

        $this->assertSame(now()->toDateString(), $row->last_seen->toDateString());
        $this->assertSame(4, $row->days_active);
        $this->assertSame(
            now()->subDays(120)->toDateString(),
            $row->first_seen->toDateString(),
            'reappearing rewrote the beginning of its history'
        );
    }

    /** ⚠ Several copies of one build on one day are one day, not several. */
    public function test_a_day_is_only_counted_once_however_many_copies_call(): void
    {
        $this->callAsDistinct('UnityGameTranslator/0.11.1 (BepInEx5)', 4);

        $row = VersionActivity::first();

        $this->assertSame(4, ClientUsageDaily::first()->installs);
        $this->assertSame(1, $row->days_active, 'four copies on one day counted as four days');
    }

    /**
     * 🔴 **The dates are the whole history, never what the span can see.** Otherwise "last seen"
     * changes when the reader presses a different button, which takes away the only thing it is
     * for.
     */
    public function test_the_dates_ignore_the_chosen_span(): void
    {
        VersionActivity::create([
            'product' => 'mod', 'version' => '0.11.0', 'variant' => 'BepInEx5',
            'first_seen' => now()->subDays(200)->toDateString(),
            'last_seen' => now()->subDays(150)->toDateString(),
            'days_active' => 9,
        ]);

        $line = collect(VersionInventory::forSpan(7)['mod']['versions'])->firstWhere('name', '0.11.0');

        $this->assertSame(now()->subDays(200)->toDateString(), $line['first_seen']);
        $this->assertSame(now()->subDays(150)->toDateString(), $line['last_seen']);
        $this->assertFalse($line['active'], 'a version silent for 150 days read as alive over 7');
    }

    /**
     * 🔴 **A version that has not called inside the span stays LISTED, extinct.** The card used to
     * filter the list itself, so it vanished — and an absent version is indistinguishable from one
     * that never existed. Nobody decides to break something on an absence.
     */
    public function test_a_version_with_no_recent_call_is_shown_rather_than_dropped(): void
    {
        $admin = User::factory()->create(['is_admin' => true])->refresh();

        VersionActivity::create([
            'product' => 'mod', 'version' => '0.11.0', 'variant' => 'BepInEx5',
            'first_seen' => now()->subDays(90)->toDateString(),
            'last_seen' => now()->subDays(60)->toDateString(),
            'days_active' => 5,
        ]);

        $html = $this->actingAs($admin)->get('/admin/analytics?period=7')->assertOk()->getContent();

        $this->assertStringContainsString('0.11.0', $html, 'an extinct version was hidden instead of shown');
        $this->assertStringContainsString('nothing in the last 7 days', $html, 'the extinct line was never drawn');
    }

    /**
     * 🔴 **Published and never run.** It has no usage row at all, so the previous card could not show
     * it — and "nobody took the update" is precisely what one wants to know.
     */
    public function test_a_release_nobody_runs_is_still_listed(): void
    {
        $admin = User::factory()->create(['is_admin' => true])->refresh();

        $this->publish(['9.9.9'], [], now()->subDays(3)->toIso8601String());

        $html = $this->actingAs($admin)->get('/admin/analytics?period=30')->assertOk()->getContent();

        $this->assertStringContainsString('9.9.9', $html);
        $this->assertStringContainsString('never', $html, 'a release nobody runs showed no "never"');
    }

    /**
     * ⚠ A release that stops being returned by GitHub — withdrawn, or simply pushed past the
     * hundredth — must stay recognised. Dropping it would file every copy still running it under
     * `unrecognised`, destroying the one measurement that says to keep supporting it.
     */
    public function test_a_refresh_never_forgets_a_release(): void
    {
        $this->publish(['0.9.0']);
        $this->publish(['0.11.1']);

        $this->assertTrue(KnownReleases::recognises('mod', '0.9.0'), 'a refresh erased an older release');
        $this->assertTrue(KnownReleases::recognises('mod', '0.11.1'));
    }

    /**
     * 🔴 The bug this whole thing grew out of: `device` was an enum of desktop/mobile/tablet, the
     * API download wrote 'mod', every insert threw and the catch hid it.
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
