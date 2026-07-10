<?php

namespace App\Jobs;

use App\Models\Game;
use App\Services\IndexNowService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Queued so the IndexNow HTTP ping never delays an upload/merge response.
 */
class SubmitGameToIndexNow implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public int $gameId)
    {
    }

    public function handle(): void
    {
        $game = Game::find($this->gameId);
        if ($game) {
            IndexNowService::submitGame($game);
        }
    }
}
