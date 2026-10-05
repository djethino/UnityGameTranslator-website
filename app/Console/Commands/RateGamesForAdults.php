<?php

namespace App\Console\Commands;

use App\Models\Game;
use App\Services\AdultRating;
use App\Services\StoreChanges;
use App\Support\SteamStore;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Support\Facades\Event;

/**
 * Asks the stores which games are for adults only — the games that are DUE, and only those.
 *
 * 🔴 **Due is an event, not an age** (user, 2026-10-05). A new game is rated by the upload that
 * creates it. After that, a game is asked about again only when a store says it changed
 * (App\Services\StoreChanges: Steam's change list, its DLC included, and IGDB's) or when an admin
 * presses Check again. It used to re-ask every game every 30 days: a timer, which at 20,000 games
 * would have had to hammer a store whose limit is counted per address and punishes insisting.
 *
 * ⚠ **A small budget, and the first refusal stops everything** (App\Support\SteamStore). The
 * store's limit (about 200 requests per 5 minutes per address) is shared with every player
 * publishing at the same moment, and they come first: a pass spends at most `--budget` store
 * requests, counted on the HTTP client, and a 429 or 403 ends it on the spot. It runs hourly: what
 * a pass leaves due, the next takes; while the store refuses, a pass asks it once and stops — the
 * store never says it accepts again, so asking is the only way to know.
 *
 * ⚠ **Nothing is re-timestamped.** `saveQuietly()` silences events and still writes `updated_at`,
 * which would reorder every listing sorted by freshness — the trap this project has paid for
 * before, and why AdultRating::rate takes a quiet mode.
 */
class RateGamesForAdults extends Command
{
    /** The most one game can cost: its page and its first ten DLC (AdultRating::DlcAsked). */
    private const WorstCasePerGame = 11;

    protected $signature = 'games:rate-adult
        {--budget=50 : store requests this pass may spend (Steam allows about 200 per 5 minutes, players first)}
        {--all : ask about every game, not only the due ones (by hand, after a deploy or an import)}';

    protected $description = 'Ask Steam, then IGDB, which games are for adults only — those due';

    public function handle(AdultRating $rating, StoreChanges $changes): int
    {
        $budget = max(self::WorstCasePerGame, (int) $this->option('budget'));

        $spent = 0;
        Event::listen(RequestSending::class, function () use (&$spent) {
            $spent++;
        });

        // While the store refuses, one game is asked about, as the probe: its answer says whether
        // the refusal is over (SteamStore clears it on the first success).
        $probing = SteamStore::refusing();

        $due = Game::query()
            ->unless($this->option('all'), fn ($q) => $q->whereNull('adult_checked_at'))
            ->orderByRaw('adult_checked_at IS NULL DESC')
            ->orderBy('adult_checked_at')
            ->orderBy('id');

        // A page, never the catalogue: at best a game costs one request.
        $games = (clone $due)->limit($probing ? 1 : $budget)->get();

        $asked = 0;
        $marked = 0;
        $changed = 0;

        foreach ($games as $game) {
            if ($spent + self::WorstCasePerGame > $budget || ($asked > 0 && SteamStore::refusing())) {
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

        // Then, with what is left, which games a store has changed since the last pass: they are
        // asked about by the next one. ⚠ After the due games, not before: a game already known to
        // have changed must not wait behind the tracing of a hundred DLC (measured 2026-10-05: two
        // days of Steam's DLC took five passes of thirty requests to trace to their games).
        if (!$this->option('all') && !SteamStore::refusing()) {
            // ⚠ A closure by reference, not `fn`: an arrow function would capture `$spent` once,
            // and the budget would never seem to be spent.
            $made = $changes->markDue(function () use (&$spent, $budget) {
                return $spent < $budget;
            });
            if ($made > 0) {
                $this->line("  {$made} game(s) changed in a store since the last pass — asked about next");
            }
        }

        $refusal = SteamStore::refusal();
        $this->info(sprintf(
            '%d game(s) asked about, %d answer(s) changed, %d marked for adults only, %d request(s)%s%s.',
            $asked,
            $changed,
            $marked,
            $spent,
            $this->option('all') ? '' : ', ' . $due->count() . ' still due',
            $refusal ? ", Steam's store refusing since {$refusal['at']} ({$refusal['status']})" : ''
        ));

        return self::SUCCESS;
    }
}
