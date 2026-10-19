<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\Game;
use App\Models\Translation;
use App\Models\User;
use App\Services\ModUsage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The admin "Mod usage" screen: what the mod's existing calls say, counted per session (one copy,
 * one day) and forgotten the night after (ModUsage).
 *
 * 🔴 What is held here is as much what is NOT counted as what is: a browser, the Manager, the
 * live stream's relay — and a session past the night that counts it.
 */
class ModUsageTest extends TestCase
{
    use RefreshDatabase;

    private const MOD = 'UnityGameTranslator/0.11.1 (BepInEx5)';

    private array $createdFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->createdFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    private function game(string $name, ?string $steamId = null): Game
    {
        return Game::create(['name' => $name, 'slug' => \Illuminate\Support\Str::slug($name), 'steam_id' => $steamId]);
    }

    /** A published translation with a real file — the download reads it. */
    private function translation(Game $game, string $language = 'French', ?User $owner = null): Translation
    {
        $owner ??= User::factory()->create();
        $relative = 'translations/usage_' . uniqid('', true) . '.json';
        $full = storage_path('app/private/' . $relative);
        @mkdir(dirname($full), 0777, true);
        file_put_contents($full, json_encode(['_uuid' => 'u-' . uniqid(), 'Play' => ['v' => 'Jouer', 't' => 'A']]));
        $this->createdFiles[] = $full;

        $translation = new Translation();
        $translation->forceFill([
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'source_language' => 'English',
            'target_language' => $language,
            'file_path' => $relative,
            'file_uuid' => 'uuid-' . uniqid(),
            'visibility' => 'public',
            'file_hash' => str_repeat('a', 64),
            'line_count' => 1,
            'ai_count' => 1,
        ])->save();

        return $translation;
    }

    /** One copy of the mod: its own address, so its own daily fingerprint. */
    private function asCopy(int $copy, string $agent = self::MOD): static
    {
        return $this->withHeaders(['User-Agent' => $agent])
            ->withServerVariables(['REMOTE_ADDR' => '10.0.0.' . $copy]);
    }

    private function counted(string $act): \Illuminate\Support\Collection
    {
        ModUsage::countDay(now()->toDateString());

        return DB::table('usage_daily')->where('act', $act)->get();
    }

    public function test_a_game_with_nothing_published_is_counted_as_nothing_found(): void
    {
        $game = $this->game('Lonely Game', '400100');

        $this->asCopy(1)->getJson('/api/v1/translations?steam_id=400100&lang=French')->assertOk();

        $look = $this->counted(ModUsage::LOOK)->sole();
        $this->assertSame($game->id, (int) $look->game_id);
        $this->assertNull($look->steam_id, 'a card found is the identity — the Steam id beside it would count the game twice');
        $this->assertSame('French', $look->language);
        $this->assertSame(ModUsage::NONE, $look->outcome);
        $this->assertSame(ModUsage::NONE, $this->counted(ModUsage::SESSION)->sole()->outcome);
    }

    public function test_a_browser_and_a_manager_lookup_are_not_counted(): void
    {
        $this->game('Lonely Game', '400100');

        $this->asCopy(1, 'Mozilla/5.0 (Windows NT 10.0)')->getJson('/api/v1/translations?steam_id=400100&lang=French')->assertOk();
        $this->asCopy(2, 'UnityGameTranslatorManager/0.5.1')->getJson('/api/v1/translations?steam_id=400100&lang=French')->assertOk();

        $this->assertSame(0, DB::table('usage_seen')->count());
    }

    public function test_a_translation_in_another_language_only_is_not_in_their_language(): void
    {
        $game = $this->game('Known Game', '400200');
        $this->translation($game, 'French');

        $this->asCopy(1)->getJson('/api/v1/translations?steam_id=400200&lang=German')->assertOk();
        $this->asCopy(2)->getJson('/api/v1/translations?steam_id=400200&lang=French')->assertOk();

        $outcomes = $this->counted(ModUsage::LOOK)->pluck('outcome', 'language')->all();
        $this->assertSame([ModUsage::OTHER_LANGUAGES, ModUsage::FOUND], [$outcomes['German'], $outcomes['French']]);
    }

    public function test_a_game_the_site_does_not_know_is_kept_under_the_name_asked(): void
    {
        $this->asCopy(1)->getJson('/api/v1/translations?q=' . urlencode('Nobody Knows This') . '&lang=French')->assertOk();
        // Not a catalogue language: kept as none rather than as whatever was typed.
        $this->asCopy(2)->getJson('/api/v1/translations?q=' . urlencode('Nobody Knows This') . '&lang=Klingonese')->assertOk();

        $looks = $this->counted(ModUsage::LOOK);
        $this->assertSame(['Nobody Knows This'], $looks->pluck('game_name')->unique()->values()->all());
        $this->assertEqualsCanonicalizing(['French', ''], $looks->pluck('language')->all());
        $this->assertSame([ModUsage::NONE], $looks->pluck('outcome')->unique()->values()->all());
    }

    /**
     * 🔴 What the player was SHOWN: the mod keeps the game named exactly as asked
     * (common/GameNames.Which), so a namesake's translation was never on its screen.
     */
    public function test_a_namesake_with_a_translation_does_not_make_a_look_found(): void
    {
        $asked = $this->game('Foo');
        $this->translation($this->game('Foo Deluxe Edition'));

        $this->asCopy(1)->getJson('/api/v1/translations?q=Foo&lang=French')->assertOk();

        $look = $this->counted(ModUsage::LOOK)->sole();
        $this->assertSame($asked->id, (int) $look->game_id);
        $this->assertSame(ModUsage::NONE, $look->outcome);
    }

    public function test_checking_a_held_translation_says_whether_the_copy_is_the_published_file(): void
    {
        $translation = $this->translation($this->game('Played Game'));

        $this->asCopy(1)->getJson("/api/v1/translations/{$translation->id}/check?hash=" . str_repeat('a', 64))->assertOk();
        $this->asCopy(2)->getJson("/api/v1/translations/{$translation->id}/check?hash=" . str_repeat('b', 64))->assertOk();

        $plays = $this->counted(ModUsage::PLAY)->pluck('outcome')->sort()->values()->all();
        $this->assertSame([ModUsage::DIFFERS, ModUsage::SAME], $plays);
    }

    public function test_a_session_is_told_by_the_most_it_did(): void
    {
        $game = $this->game('Played Game', '400300');
        $translation = $this->translation($game);

        // The same copy looks, takes, then checks — one session, which plays.
        $this->asCopy(1)->getJson('/api/v1/translations?steam_id=400300&lang=French')->assertOk();
        $this->asCopy(1)->get("/api/v1/translations/{$translation->id}/download")->assertOk();
        $this->asCopy(1)->getJson("/api/v1/translations/{$translation->id}/check")->assertOk();
        // Another copy only takes it.
        $this->asCopy(2)->get("/api/v1/translations/{$translation->id}/download")->assertOk();

        $sessions = $this->counted(ModUsage::SESSION)->pluck('sessions', 'outcome')->map(fn ($n) => (int) $n)->all();
        ksort($sessions);
        $this->assertSame([ModUsage::PLAYS => 1, ModUsage::TOOK => 1], $sessions);
        $this->assertSame(2, (int) $this->counted(ModUsage::TAKE)->sum('sessions'));
    }

    public function test_the_relay_is_not_counted_and_an_author_plays_their_own(): void
    {
        $author = User::factory()->create()->refresh();
        $token = ApiToken::createForUser($author)->plain_token;
        $translation = $this->translation($this->game('Authored Game'), 'French', $author);

        $this->asCopy(1, 'UnityGameTranslator-SSE/1.0')->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/sync/state?uuid=' . $translation->file_uuid)->assertOk();
        $this->assertSame(0, DB::table('usage_seen')->count(), 'the relay asks on behalf of a stream, under its own name');

        $this->asCopy(1)->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/sync/state?uuid=' . $translation->file_uuid)->assertOk();

        $play = $this->counted(ModUsage::PLAY)->sole();
        $this->assertSame(ModUsage::OWN, $play->outcome);
        $this->assertTrue((bool) $play->signed_in);
    }

    /** 🔴 Counted, then forgotten — every pending day, never today, never a day already gone. */
    public function test_the_night_counts_every_pending_day_then_forgets_its_sessions(): void
    {
        $game = $this->game('Lonely Game', '400100');

        // ⚠ Each day from the same start: travelling moves now(), so "N days ago" must not be asked of it.
        $today = now()->startOfDay();
        foreach ([3, 2, 0] as $daysAgo) {
            $this->travelTo($today->copy()->subDays($daysAgo)->addMinute());
            $this->asCopy(1)->getJson('/api/v1/translations?steam_id=400100&lang=French')->assertOk();
        }
        $this->travelBack();

        $this->artisan('analytics:aggregate')->assertSuccessful();

        $this->assertSame([now()->toDateString()], DB::table('usage_seen')->distinct()->pluck('date')->map(fn ($d) => substr((string) $d, 0, 10))->all());
        $this->assertSame(2, DB::table('usage_daily')->where('act', ModUsage::LOOK)->where('game_id', $game->id)->count());

        // Run again with an old date: the sessions of that day are gone, so nothing is recounted to zero.
        $this->artisan('analytics:aggregate', ['--date' => now()->subDays(3)->toDateString()])->assertSuccessful();
        $this->assertSame(2, (int) DB::table('usage_daily')->where('act', ModUsage::LOOK)->sum('sessions'));
    }

    /** The first tile and the dashboard read the same copies — before any act is counted too. */
    public function test_a_copy_that_only_called_for_something_else_is_still_a_session(): void
    {
        $this->asCopy(1)->getJson('/api/v1/games')->assertOk();

        $report = new \App\Services\ModUsageReport(\App\Support\Span::lastDays(1));

        $this->assertSame(1, $report->copies());
        $this->assertSame(0, DB::table('usage_daily')->count());
    }

    public function test_the_screen_shows_what_was_looked_for_and_what_is_played(): void
    {
        $this->game('Lonely Game', '400100');
        $translation = $this->translation($this->game('Played Game'));

        $this->asCopy(1)->getJson('/api/v1/translations?steam_id=400100&lang=French')->assertOk();
        $this->asCopy(2)->getJson('/api/v1/translations?steam_id=999999&lang=French')->assertOk();
        $this->asCopy(3)->getJson("/api/v1/translations/{$translation->id}/check?hash=" . str_repeat('a', 64))->assertOk();

        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();

        $this->actingAs($admin)->get(route('admin.usage'))
            ->assertOk()
            ->assertSee('Lonely Game')
            ->assertSee('Steam app 999999')
            ->assertSee('#' . $translation->id)
            ->assertSee('Played Game');

        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()->assertSee('Mod usage');
    }

    /**
     * The Manager counts for what it writes into a game — never for its library lookups — and a
     * Manager is not a session of the mod.
     */
    public function test_a_manager_take_is_counted_apart_and_its_lookups_are_not(): void
    {
        $translation = $this->translation($this->game('Installed Game', '400500'));
        $manager = 'UnityGameTranslatorManager/0.5.1';

        $this->asCopy(1, $manager)->getJson('/api/v1/translations?steam_id=400500&lang=French')->assertOk();
        $this->asCopy(1, $manager)->get("/api/v1/translations/{$translation->id}/download")->assertOk();
        // Comparing a copy the game already holds is not a take.
        $this->asCopy(1, $manager)->get("/api/v1/translations/{$translation->id}/download?update=1")->assertOk();

        $take = $this->counted(ModUsage::TAKE)->sole();
        $this->assertSame('manager', $take->product);
        $this->assertSame(0, DB::table('usage_daily')->where('act', '!=', ModUsage::TAKE)->count(),
            'no look from the Manager, and no session of the mod');

        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $this->actingAs($admin)->get(route('admin.usage'))->assertOk()
            ->assertSee('Installed Game')->assertSee('1 installed by the Manager');
    }

    /**
     * From a Manager's batch, only the games where the mod has run — a translation file or a
     * confirmed card. A game of the library with neither leaves nothing.
     */
    public function test_a_manager_batch_reports_only_the_games_where_the_mod_has_run(): void
    {
        $published = $this->translation($this->game('Published Game', '400601'));
        $this->game('Untouched Game', '400603');

        $this->asCopy(1, 'UnityGameTranslatorManager/0.5.1')->postJson('/api/v1/translations/for-games', ['games' => [
            ['steam_id' => '400601', 'uuid' => $published->file_uuid],
            ['steam_id' => '400602', 'uuid' => 'never-published-lineage'],
            ['steam_id' => '400603'],
            ['name' => 'Some Library Game'],
        ]])->assertOk();

        $held = $this->counted(ModUsage::HELD)->keyBy(fn ($r) => $r->game_id ?? $r->steam_id);
        $this->assertCount(2, $held, 'the two games without a file or a card leave nothing');
        $this->assertSame(ModUsage::ON_SITE, $held[$published->game_id]->outcome);
        $this->assertSame($published->id, (int) $held[$published->game_id]->translation_id);
        $this->assertSame(ModUsage::LOCAL_ONLY, $held['400602']->outcome);
        $this->assertSame(0, DB::table('usage_daily')->where('act', ModUsage::SESSION)->count(), 'a Manager is not a session of the mod');

        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $this->actingAs($admin)->get(route('admin.usage'))->assertOk()
            ->assertSee('Steam app 400602')->assertDontSee('Untouched Game')->assertDontSee('Some Library Game');
    }

    /**
     * 🔴 Old days become months — merged with what the month holds, their first and last day kept —
     * and the screen still reads them.
     */
    public function test_old_days_fold_into_months_and_are_still_read(): void
    {
        $game = $this->game('Old Game', '400700');
        $month = \App\Models\AnalyticsGame::foldedBefore()->subMonths(2);
        $row = fn (string $day, int $sessions) => [
            'date' => $day, 'product' => 'mod', 'act' => ModUsage::LOOK, 'game_id' => $game->id,
            'steam_id' => null, 'game_name' => null, 'language' => 'French', 'translation_id' => null,
            'outcome' => ModUsage::NONE, 'signed_in' => false, 'sessions' => $sessions,
        ];
        DB::table('usage_daily')->insert([
            $row($month->copy()->addDays(4)->toDateString(), 2),
            $row($month->copy()->addDays(9)->toDateString(), 3),
        ]);
        // A month already folded once: the late days join it.
        DB::table('usage_monthly')->insert(array_diff_key($row('', 1), ['date' => 0]) + [
            'month' => $month->toDateString(),
            'first_day' => $month->copy()->addDay()->toDateString(),
            'last_day' => $month->copy()->addDay()->toDateString(),
        ]);
        $recent = $row(now()->subDay()->toDateString(), 1);
        DB::table('usage_daily')->insert($recent);

        $this->assertSame(2, ModUsage::foldOldDays());

        $folded = DB::table('usage_monthly')->sole();
        $this->assertSame(6, (int) $folded->sessions);
        $this->assertSame($month->copy()->addDay()->toDateString(), substr((string) $folded->first_day, 0, 10));
        $this->assertSame($month->copy()->addDays(9)->toDateString(), substr((string) $folded->last_day, 0, 10));
        $this->assertSame(1, DB::table('usage_daily')->count(), 'the recent day stays a day');

        $span = \App\Support\Span::fromRequest(\Illuminate\Http\Request::create('/', 'GET', [
            'from' => $month->toDateString(), 'to' => now()->toDateString(),
        ]), 100000);
        $wanted = (new \App\Services\ModUsageReport($span))->wanted('last', 'desc', 'wpage')->items();
        $this->assertSame(7, $wanted[0]['without'], 'six folded and one day');
        $this->assertSame($month->copy()->addDay()->toDateString(), $wanted[0]['first']);
    }

    /** Each table sorts on its own parameters, over every row of the span — never a top N. */
    public function test_the_wanted_table_sorts_on_its_own_parameters(): void
    {
        $this->game('Rarely Asked', '400401');
        $this->game('Often Asked', '400402');

        $this->asCopy(1)->getJson('/api/v1/translations?steam_id=400401&lang=French')->assertOk();
        foreach ([2, 3, 4] as $copy) {
            $this->asCopy($copy)->getJson('/api/v1/translations?steam_id=400402&lang=French')->assertOk();
        }

        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();

        $this->actingAs($admin)->get(route('admin.usage', ['wsort' => 'without', 'wdir' => 'desc', 'usort' => 'game']))
            ->assertOk()->assertSeeInOrder(['Often Asked', 'Rarely Asked']);
        $this->actingAs($admin)->get(route('admin.usage', ['wsort' => 'without', 'wdir' => 'asc']))
            ->assertOk()->assertSeeInOrder(['Rarely Asked', 'Often Asked']);
        // An unknown key falls back to the most recent, never to an error.
        $this->actingAs($admin)->get(route('admin.usage', ['wsort' => 'drop table', 'usort' => 'x']))->assertOk();
    }
}
