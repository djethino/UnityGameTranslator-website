<?php

namespace App\Console\Commands;

use App\Models\Game;
use App\Services\AdultRating;
use Illuminate\Console\Command;

/**
 * Asks the stores which games are for adults only.
 *
 * New games are rated by the upload that creates them, so this is for two other jobs: the
 * catalogue published before the column existed, and games whose store page has changed since —
 * a descriptor added, an 18+ DLC published, a game delisted.
 *
 * ⚠ **Bounded on purpose.** Steam allows about 200 requests per 5 minutes and one game can cost
 * several (its DLC), so a pass takes `--limit` games at a time, oldest check first. Run daily, the
 * whole catalogue comes round; run it by hand after a deploy to catch up faster.
 *
 * ⚠ **Nothing is re-timestamped.** `saveQuietly()` silences events and still writes `updated_at`,
 * which would reorder every listing sorted by freshness — the trap this project has paid for
 * before, and why AdultRating::rate takes a quiet mode.
 */
class RateGamesForAdults extends Command
{
    protected $signature = 'games:rate-adult
        {--limit=40 : how many games to ask about in this pass}
        {--stale=30 : re-ask about a game checked more than this many days ago}
        {--all : ignore --stale and re-ask about everything, oldest first}';

    protected $description = 'Ask Steam, then IGDB, which games are for adults only';

    public function handle(AdultRating $rating): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $stale = max(1, (int) $this->option('stale'));

        $games = Game::query()
            ->unless($this->option('all'), fn ($q) => $q
                ->where(fn ($w) => $w
                    ->whereNull('adult_checked_at')
                    ->orWhere('adult_checked_at', '<', now()->subDays($stale))))
            // Never checked first, then the oldest check. A game nobody has ever asked about is
            // the one that can be listed wrongly right now.
            ->orderByRaw('adult_checked_at IS NULL DESC')
            ->orderBy('adult_checked_at')
            ->limit($limit)
            ->get();

        if ($games->isEmpty()) {
            $this->info('Nothing to ask about.');

            return self::SUCCESS;
        }

        $marked = 0;
        $changed = 0;

        foreach ($games as $game) {
            $before = $game->adult;

            if ($rating->rate($game, quiet: true)) {
                $changed++;
            }

            if ($game->adult) {
                $marked++;
            }

            if ($before !== $game->adult) {
                // Worth a line of its own: a game appearing in or leaving the default catalogue is
                // the visible consequence, and it should be readable without querying the table.
                $this->line(sprintf(
                    '  %s %s (%s)',
                    $game->adult ? 'marked  ' : 'unmarked',
                    $game->name,
                    $game->adultSource() ?? 'nothing found'
                ));
            }
        }

        $this->info(sprintf(
            '%d game(s) asked about, %d answer(s) changed, %d marked for adults only.',
            $games->count(),
            $changed,
            $marked
        ));

        return self::SUCCESS;
    }
}
