<?php

namespace App\Console\Commands;

use App\Models\Game;
use App\Services\AdultRating;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Support\Facades\Event;

/**
 * Asks the stores which games are for adults only.
 *
 * New games are rated by the upload that creates them, so this is for two other jobs: the
 * catalogue published before the column existed, and games whose store page has changed since —
 * a descriptor added, an 18+ DLC published, a game delisted.
 *
 * ⚠ **Bounded by the stores' own limit, counted request by request** (2026-10-05). Steam allows
 * about 200 requests per 5 minutes and one game costs 1 to 11 (its DLC are asked too). A pass
 * used to take a fixed 40 games a night: at 20,000 games a full round would have taken some 500
 * nights, and "re-asked after 30 days" would never have been true, with nothing to say so. Now a
 * pass spends up to `--budget` requests — counted on the HTTP client, so a cached answer costs
 * nothing — and runs every five minutes (routes/console.php), each run inside one window of the
 * store's limit. A run with nothing due costs one query. The games screen says when games are
 * overdue all the same (AdminController::games).
 *
 * ⚠ **Nothing is re-timestamped.** `saveQuietly()` silences events and still writes `updated_at`,
 * which would reorder every listing sorted by freshness — the trap this project has paid for
 * before, and why AdultRating::rate takes a quiet mode.
 */
class RateGamesForAdults extends Command
{
    /** A game asked longer ago than this is asked again — a store page can change and tell nobody. */
    public const StaleDays = 30;

    /** The most one game can cost: its page and its first ten DLC (AdultRating::DlcAsked). */
    private const WorstCasePerGame = 11;

    protected $signature = 'games:rate-adult
        {--budget=180 : store requests this pass may spend (Steam allows about 200 per 5 minutes)}
        {--stale= : re-ask about a game checked more than this many days ago (default: StaleDays)}
        {--all : ignore --stale and re-ask about everything, oldest first}';

    protected $description = 'Ask Steam, then IGDB, which games are for adults only';

    public function handle(AdultRating $rating): int
    {
        $budget = max(self::WorstCasePerGame, (int) $this->option('budget'));
        $stale = max(1, (int) ($this->option('stale') ?: self::StaleDays));

        $spent = 0;
        Event::listen(RequestSending::class, function () use (&$spent) {
            $spent++;
        });

        $due = Game::query()
            ->unless($this->option('all'), fn ($q) => $q
                ->where(fn ($w) => $w
                    ->whereNull('adult_checked_at')
                    ->orWhere('adult_checked_at', '<', now()->subDays($stale))))
            // Never checked first, then the oldest check. A game nobody has ever asked about is
            // the one that can be listed wrongly right now.
            ->orderByRaw('adult_checked_at IS NULL DESC')
            ->orderBy('adult_checked_at')
            ->orderBy('id');

        // A page, never the catalogue: at best a game costs one request, so no more than the
        // budget can ever be reached in one pass.
        $games = (clone $due)->limit($budget)->get();

        if ($games->isEmpty()) {
            $this->info('Nothing to ask about.');

            return self::SUCCESS;
        }

        $marked = 0;
        $changed = 0;

        $asked = 0;
        foreach ($games as $game) {
            // Stop while one more game, at its worst, still fits in the budget.
            if ($spent + self::WorstCasePerGame > $budget) {
                break;
            }

            $asked++;
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
            '%d game(s) asked about, %d answer(s) changed, %d marked for adults only, %d store request(s)%s.',
            $asked,
            $changed,
            $marked,
            $spent,
            // Read after the pass: the games just asked are no longer due.
            $this->option('all') ? '' : ', ' . $due->count() . ' still due'
        ));

        return self::SUCCESS;
    }
}
