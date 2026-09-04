<?php

namespace App\Console\Commands;

use App\Models\Game;
use App\Support\LatinSearch;
use Illuminate\Console\Command;

/**
 * Gives a latin handle to the games already in the catalogue.
 *
 * New games get one on save; this is for everything published before the column existed. Run once
 * after deploying, and again only if `intl` was missing the first time.
 *
 * ⚠ **Nothing is re-timestamped.** `saveQuietly()` silences events and still writes `updated_at`,
 * which would reorder every listing sorted by freshness and make a catalogue look like it changed
 * on the day of a migration. `timestamps = false` is what actually holds the dates — the trap this
 * project has paid for before.
 */
class BackfillLatinSearch extends Command
{
    protected $signature = 'games:latin-search {--force : also recompute handles already stored}';

    protected $description = 'Give games written in another script something to type in a search box';

    public function handle(): int
    {
        if (!extension_loaded('intl')) {
            $this->error('The intl extension is not loaded, so no handle can be built here.');
            $this->line('Nothing was written. Games stay findable by their own name.');

            return self::FAILURE;
        }

        $written = 0;
        $seen = 0;

        Game::query()
            ->when(!$this->option('force'), fn ($q) => $q->whereNull('latin_search'))
            ->chunkById(200, function ($games) use (&$written, &$seen) {
                foreach ($games as $game) {
                    $seen++;

                    $handle = LatinSearch::for($game->name);

                    // Most titles are latin already and get none. Writing null over null would
                    // touch every row of the table for nothing.
                    if ($handle === null && $game->latin_search === null) {
                        continue;
                    }

                    if ($handle === $game->latin_search) {
                        continue;
                    }

                    $game->latin_search = $handle;
                    $game->timestamps = false;
                    $game->saveQuietly();

                    $written++;
                }
            });

        $this->info("{$seen} game(s) looked at, {$written} handle(s) written.");

        return self::SUCCESS;
    }
}
