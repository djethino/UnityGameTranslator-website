<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\AuditLog;
use App\Models\Game;
use App\Models\Translation;
use App\Models\User;
use App\Services\TranslationService;
use App\Support\TranslationFlows;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The admin Flows screen and the events it reads (App\Support\TranslationFlows).
 *
 * 🔴 What is proved is that every path that changes a translation leaves its event WITH the
 * context the screen filters on — a game, a language, the program — and that a refusal is traced
 * though no translation was written.
 */
class TranslationFlowsTest extends TestCase
{
    use RefreshDatabase;

    private const ModAgent = 'UnityGameTranslator/0.13.6 (BepInEx5)';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake();
    }

    private function publish(User $user, Game $game, string $uuid, array $fields = []): \Illuminate\Testing\TestResponse
    {
        $token = ApiToken::createForUser($user, 'test')->plain_token;

        return $this->withHeaders(['Authorization' => 'Bearer ' . $token, 'User-Agent' => self::ModAgent])
            ->postJson('/api/v1/translations', array_merge([
                'source_language' => 'English',
                'target_language' => 'French',
                'game_name' => $game->name,
                'game_pick' => ['source' => 'local', 'id' => $game->id],
                'content' => json_encode(['_uuid' => $uuid, 'Hello ' . uniqid() => ['v' => 'Bonjour', 't' => 'H']]),
            ], $fields));
    }

    private function latest(string $action): ?AuditLog
    {
        return AuditLog::where('action', $action)->latest('id')->first();
    }

    public function test_a_publication_carries_its_game_languages_and_program(): void
    {
        $game = Game::create(['name' => 'Some Game']);
        $user = User::factory()->create();

        $this->publish($user, $game, (string) Str::uuid())->assertSuccessful();

        $event = $this->latest(TranslationFlows::PUBLISHED);
        $this->assertSame($user->id, $event->user_id);
        $this->assertSame($game->id, $event->metadata['game_id']);
        $this->assertSame('French', $event->metadata['target_language']);
        $this->assertSame('mod', $event->metadata['via']);
        $this->assertSame('0.13.6', $event->metadata['version']);
        $this->assertSame('New translation — 1 lines', TranslationFlows::describe($event));
    }

    public function test_a_refused_publication_is_traced_with_its_code(): void
    {
        $game = Game::create(['name' => 'Some Game']);
        $uuid = (string) Str::uuid();
        $this->publish(User::factory()->create(), $game, $uuid)->assertSuccessful();

        // Another account, into a Main closed to branches.
        $stranger = User::factory()->create();
        $this->publish($stranger, $game, $uuid)->assertStatus(403)
            ->assertJsonPath('refused_code', 'branches_refused');

        $event = $this->latest(TranslationFlows::REFUSED);
        $this->assertNotNull($event, 'a refusal leaves an event though nothing was written');
        $this->assertSame($stranger->id, $event->user_id);
        $this->assertSame('branches_refused', $event->metadata['code']);
        $this->assertSame('Some Game', $event->metadata['sent']['game_name']);
        $this->assertNull($event->entity_id);
    }

    public function test_a_validation_error_is_not_a_refusal(): void
    {
        $game = Game::create(['name' => 'Some Game']);

        $this->publish(User::factory()->create(), $game, (string) Str::uuid(), ['target_language' => 'Klingon'])
            ->assertStatus(422);

        $this->assertNull($this->latest(TranslationFlows::REFUSED));
    }

    public function test_details_changed_from_any_path_are_traced_once_with_their_new_values(): void
    {
        $owner = User::factory()->create();
        $translation = $this->translation($owner);

        $this->actingAs($owner)->put(route('translations.update', $translation), [
            'status' => 'complete',
            'notes' => 'Now with a description',
            'accepts_branches' => '1',
        ])->assertRedirect();

        $events = AuditLog::where('action', TranslationFlows::DETAILS_CHANGED)->get();
        $this->assertCount(1, $events);
        $this->assertSame(['notes', 'status', 'accepts_branches'], $events[0]->metadata['fields']);
        $this->assertSame(['status' => 'complete', 'accepts_branches' => true], $events[0]->metadata['values']);
        $this->assertArrayNotHasKey('notes', $events[0]->metadata['values'], 'the text itself is not kept');
        $this->assertSame('site', $events[0]->metadata['via']);
        $this->assertSame($owner->id, $events[0]->user_id);

        // The same save again changes nothing, and says nothing.
        $this->actingAs($owner)->put(route('translations.update', $translation), [
            'status' => 'complete',
            'notes' => 'Now with a description',
            'accepts_branches' => '1',
        ]);
        $this->assertSame(1, AuditLog::where('action', TranslationFlows::DETAILS_CHANGED)->count());
    }

    public function test_a_counter_bump_is_not_a_details_change(): void
    {
        $translation = $this->translation(User::factory()->create());

        $translation->increment('download_count');
        $translation->increment('vote_count');

        $this->assertSame(0, AuditLog::where('action', TranslationFlows::DETAILS_CHANGED)->count());
    }

    public function test_a_deletion_remembers_how_long_the_translation_lived(): void
    {
        $owner = User::factory()->create();
        $translation = $this->translation($owner);
        Translation::whereKey($translation->id)->toBase()->update(['created_at' => now()->subHours(3)]);

        $this->actingAs($owner);
        app(TranslationService::class)->deleteTranslation($translation->fresh(), TranslationService::DELETED_BY_AUTHOR);

        $event = $this->latest(TranslationFlows::DELETED);
        $this->assertSame($translation->game_id, $event->metadata['game_id']);
        $this->assertSame(3 * 3600, TranslationFlows::lifespan($event));
        $this->assertSame('Deleted by its author, after 3 hours', TranslationFlows::describe($event));
    }

    public function test_the_dashboard_has_a_card_for_the_last_day(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $translation = $this->translation(User::factory()->create());
        TranslationFlows::log(TranslationFlows::PUBLISHED, $translation, ['line_count' => 4]);
        TranslationFlows::log(TranslationFlows::DELETED, $translation, ['how' => 'author']);

        // Older than the card's 24 h: not counted.
        $old = TranslationFlows::log(TranslationFlows::PUBLISHED, $translation, ['line_count' => 5]);
        $old->forceFill(['created_at' => now()->subDays(2)])->save();

        $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Translation flows')
            ->assertSeeInOrder(['2', 'events in the last 24 h', '1 deleted'])
            ->assertSee(route('admin.flows', ['period' => 1]), false);
    }

    public function test_repeated_updates_read_as_one_line_and_a_deletion_stays_its_own(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $owner = User::factory()->create();
        $translation = $this->translation($owner);

        $this->actingAs($owner);
        foreach ([10, 11, 12] as $i => $lines) {
            $e = TranslationFlows::log(TranslationFlows::PUBLISHED, $translation, ['line_count' => $lines, 'is_update' => true]);
            $e->forceFill(['created_at' => now()->subMinutes(30 - $i)])->save();
        }
        TranslationFlows::log(TranslationFlows::DELETED, $translation, ['how' => 'author']);

        $runs = \App\Services\TranslationFlowReport::runs(
            AuditLog::orderByDesc('created_at')->orderByDesc('id')->get());
        $this->assertSame([1, 3], array_map(fn ($run) => count($run['events']), $runs), 'the deletion alone, then the three updates');
        $this->assertSame('3 updates — 10 → 12 lines', TranslationFlows::describeRun($runs[1]['events']));

        $this->actingAs($admin)->get(route('admin.flows', ['period' => 1]))
            ->assertOk()
            ->assertSee('3 updates — 10 → 12 lines')
            ->assertSee('Show 3')
            ->assertSee('Per hour')
            ->assertSee('Last 24 h');
    }

    public function test_an_older_publication_says_its_program_from_the_agent_it_was_sent_with(): void
    {
        $translation = $this->translation(User::factory()->create());
        $event = AuditLog::create([
            'action' => TranslationFlows::PUBLISHED, 'entity_type' => 'Translation', 'entity_id' => $translation->id,
            'metadata' => ['line_count' => 3], 'user_agent' => self::ModAgent, 'created_at' => now(),
        ]);

        $this->assertSame('UGT Mod 0.13.6', TranslationFlows::viaOf($event));

        $event->user_agent = null;
        $this->assertNull(TranslationFlows::viaOf($event), 'nothing said once the agent is cleared');
    }

    public function test_the_screen_is_for_admins_only(): void
    {
        $this->actingAs(User::factory()->create())->get(route('admin.flows'))->assertForbidden();
    }

    public function test_the_screen_filters_by_game_without_losing_the_other_kinds(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $here = $this->translation(User::factory()->create(), 'Here Game');
        $elsewhere = $this->translation(User::factory()->create(), 'Elsewhere Game');

        TranslationFlows::log(TranslationFlows::PUBLISHED, $here, ['line_count' => 4]);
        TranslationFlows::log(TranslationFlows::PUBLISHED, $elsewhere, ['line_count' => 9]);
        TranslationFlows::log(TranslationFlows::DELETED, $here, ['how' => 'admin']);

        $this->actingAs($admin)->get(route('admin.flows', ['game' => $here->game_id, 'type' => 'deleted']))
            ->assertOk()
            ->assertSee('Game: Here Game')
            ->assertSee('Deleted by an admin')
            // The list holds the deletion only; the published one of this game is counted in its tile.
            ->assertDontSee('New translation — 4 lines')
            ->assertDontSee('Elsewhere Game');

        $this->actingAs($admin)->get(route('admin.flows', ['game' => $here->game_id]))
            ->assertOk()
            ->assertSee('New translation — 4 lines')
            ->assertDontSee('New translation — 9 lines');
    }

    public function test_a_move_is_found_from_either_game(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $translation = $this->translation(User::factory()->create(), 'Wrong Game');
        $right = Game::create(['name' => 'Right Game']);
        $wrongId = $translation->game_id;

        app(\App\Services\LineageGame::class)->move($translation, $right, $admin, 'admin');

        $event = $this->latest(TranslationFlows::GAME_CHANGED);
        $this->assertSame($right->id, $event->metadata['game_id'], 'filed under the game it is in now');

        $this->actingAs($admin)->get(route('admin.flows', ['game' => $wrongId]))
            ->assertOk()
            ->assertSee('Moved from Wrong Game to Right Game');
    }

    private function translation(User $owner, string $gameName = 'Some Game'): Translation
    {
        $game = Game::firstOrCreate(['name' => $gameName]);

        $t = new Translation();
        $t->forceFill([
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'source_language' => 'English',
            'target_language' => 'French',
            'file_path' => 'translations/none.json',
            'file_uuid' => (string) Str::uuid(),
            'visibility' => 'public',
            'status' => 'in_progress',
            'accepts_branches' => false,
            'line_count' => 10,
        ])->save();

        return $t->refresh();
    }
}
