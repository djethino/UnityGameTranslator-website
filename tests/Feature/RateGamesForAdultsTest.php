<?php

namespace Tests\Feature;

use App\Models\Game;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The automatic adult check spends a budget of store requests per run, counted on the HTTP client
 * (games:rate-adult, every five minutes) — never a fixed number of games.
 */
class RateGamesForAdultsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_run_stops_at_its_request_budget_and_says_what_is_left(): void
    {
        Http::preventStrayRequests();
        // Each game: its page, then two DLC pages — three requests, none of them adult.
        Http::fake(['store.steampowered.com/api/appdetails*' => function ($request) {
            $id = (string) ($request->data()['appids'] ?? '');

            return Http::response([$id => ['success' => true, 'data' => ['content_descriptors' => ['ids' => []],
                'dlc' => str_starts_with($id, '9') ? [] : ['9' . $id . '1', '9' . $id . '2']]]]);
        }]);

        foreach (range(1, 10) as $n) {
            Game::create(['name' => "Game {$n}", 'steam_id' => (string) (1000 + $n)]);
        }
        Game::query()->update(['adult_checked_at' => null]);

        // 22 requests: at most 11 a game, so the run stops once a twelfth could not be paid for.
        $this->artisan('games:rate-adult', ['--budget' => 22])->assertSuccessful();

        $asked = Game::whereNotNull('adult_checked_at')->count();
        $this->assertGreaterThan(0, $asked);
        $this->assertLessThan(10, $asked, 'the budget, not the catalogue, bounds a run');
        $this->assertLessThanOrEqual(22, Http::recorded()->count());
    }
}
