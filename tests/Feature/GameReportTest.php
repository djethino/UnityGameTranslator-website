<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Reporting a game card (2026-10-05): an adult-content report is settled by the stores when they
 * agree, without the admin; otherwise it waits for one with what the stores said.
 */
class GameReportTest extends TestCase
{
    use RefreshDatabase;

    private function steamSays(?array $descriptors, int $status = 200): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'store.steampowered.com/*' => fn (Request $r) => $status === 200
                ? Http::response([(string) ($r->data()['appids'] ?? '') => ['success' => true, 'data' => ['content_descriptors' => ['ids' => $descriptors ?? []]]]])
                : Http::response('', $status),
        ]);
    }

    private function game(): Game
    {
        $game = Game::create(['name' => 'Some Game', 'steam_id' => '1001']);
        $game->forceFill(['adult_checked_at' => now()->subMonth()])->saveQuietly();

        return $game->refresh();
    }

    public function test_an_adult_report_the_stores_confirm_marks_the_game_and_closes_itself(): void
    {
        $this->steamSays([3]);
        $game = $this->game();

        $this->actingAs(User::factory()->create())
            ->post(route('reports.game', $game->id), ['kind' => 'adult'])
            ->assertSessionHas('success', __('report.game_marked'));

        $this->assertTrue($game->refresh()->adult);
        $report = Report::sole();
        $this->assertSame('reviewed', $report->status, 'the admin is not asked');
        $this->assertSame('adult', $report->stores_answer);
        $this->assertNull($report->reviewed_by);
    }

    public function test_an_adult_report_the_stores_do_not_confirm_waits_for_an_admin_with_their_answer(): void
    {
        $this->steamSays([]);
        $game = $this->game();

        $this->actingAs(User::factory()->create())
            ->post(route('reports.game', $game->id), ['kind' => 'adult', 'reason' => 'Its DLC is explicit'])
            ->assertSessionHas('success', __('report.success'));

        $report = Report::sole();
        $this->assertSame('pending', $report->status);
        $this->assertSame('nothing', $report->stores_answer);
        $this->assertFalse($game->refresh()->adult);

        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->get(route('admin.reports'))
            ->assertOk()
            ->assertSee('Some Game')
            ->assertSee('Adult content, not marked')
            ->assertSee('the stores found nothing');
        $this->actingAs($admin)->get(route('admin.reports.show', $report))
            ->assertOk()
            ->assertSee('Open in Games');
        $this->actingAs($admin)->post(route('admin.reports.handle', $report), ['action' => 'resolve'])
            ->assertRedirect();
        $this->assertSame('reviewed', $report->refresh()->status);
    }

    public function test_steam_not_answering_is_said_to_the_admin_not_read_as_nothing(): void
    {
        $this->steamSays(null, 500);
        $game = $this->game();

        $this->actingAs(User::factory()->create())->post(route('reports.game', $game->id), ['kind' => 'adult']);

        $this->assertSame(['pending', 'not_asked'], [Report::sole()->status, Report::sole()->stores_answer]);
    }

    public function test_other_kinds_need_details_and_go_to_an_admin(): void
    {
        $game = $this->game();
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('reports.game', $game->id), ['kind' => 'wrong_info'])
            ->assertSessionHasErrors('reason');

        $this->actingAs($user)->post(route('reports.game', $game->id), ['kind' => 'wrong_info', 'reason' => 'Wrong cover'])
            ->assertSessionHas('success');
        $this->assertSame(['pending', null], [Report::sole()->status, Report::sole()->stores_answer]);

        // One waiting report per person and game.
        $this->actingAs($user)->post(route('reports.game', $game->id), ['kind' => 'other', 'reason' => 'Again'])
            ->assertSessionHas('error', __('report.game_already'));
        $this->assertSame(1, Report::count());
    }

    public function test_the_game_page_offers_it(): void
    {
        $game = $this->game();
        $game->update(['slug' => 'some-game']);

        $this->actingAs(User::factory()->create())->get(route('games.show', $game))
            ->assertOk()
            ->assertSee('data-report-game="' . $game->id . '"', false)
            ->assertSee(__('report.kind_wrong_info'));
    }
}
