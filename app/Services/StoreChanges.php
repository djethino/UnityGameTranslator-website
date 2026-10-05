<?php

namespace App\Services;

use App\Models\Game;
use App\Support\SteamStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Which of our games a store has changed since we last looked — the EVENT that makes asking again
 * about a game worth it (user, 2026-10-05: re-asking every game every 30 days was a timer, and at
 * 20,000 games it would have had to hammer a store that bans for less).
 *
 * - **Steam** says it itself: `IStoreService/GetAppList?if_modified_since=` lists every app changed
 *   since a moment, 50,000 to a request, on the Web API (a key, its own limit of 100,000 calls a
 *   day) — not on the store's per-address limit.
 * - **A DLC is followed on its own.** Measured on 2026-10-05: of fifteen DLC changed in two days,
 *   the base game was listed for twelve and NOT for three — a DLC announced for next week among
 *   them. An 18+ DLC published after its game is exactly the case the adult check exists for, so a
 *   changed DLC leads to its game (`fullgame` on its store page, kept a day).
 * - **IGDB** answers the same question with `updated_at`, for the games Steam does not describe.
 *
 * A game found changed is made due — `adult_checked_at` cleared — and its kept store answers are
 * forgotten; games:rate-adult asks about the due ones within its budget. The moment checked from is
 * only moved on once the whole change list was handled: a pass stopped half-way (budget, refusal)
 * asks the same list again next time, and what it already resolved is kept.
 */
class StoreChanges
{
    private const SteamCursor = 'stores:changes:steam:since';
    private const IgdbCursor = 'stores:changes:igdb:since';

    public function __construct(private GameSearchService $stores)
    {
    }

    /**
     * Make due the games a store changed since the last pass. `$canSpend` says whether one more
     * store request fits the caller's budget. Returns how many games were made due.
     */
    public function markDue(callable $canSpend): int
    {
        return $this->fromSteam($canSpend) + $this->fromIgdb();
    }

    private function fromSteam(callable $canSpend): int
    {
        $key = config('services.steam.client_secret');
        if (!$key) {
            return 0;
        }

        $since = Cache::get(self::SteamCursor);
        $now = now()->timestamp;

        // The first pass has nothing to compare with: every game was asked about when its card was
        // made, so it starts counting changes from now.
        if ($since === null) {
            Cache::forever(self::SteamCursor, $now);
            return 0;
        }

        $games = $this->steamChanged($key, (int) $since, games: true);
        $dlc = $this->steamChanged($key, (int) $since, games: false);
        if ($games === null || $dlc === null) {
            return 0;
        }

        $ours = Game::whereNotNull('steam_id')->pluck('id', 'steam_id');
        $due = [];

        foreach ($games as $appId) {
            if (isset($ours[$appId])) {
                SteamStore::forget($appId);
                $due[] = $ours[$appId];
            }
        }

        $complete = true;
        foreach ($dlc as $appId) {
            // Its game, from its store page. A DLC never changes game, so the answer is kept a month
            // (not for good: every DLC Steam changes would pile up in the cache table). Our games'
            // own DLC lists would miss a DLC that is new.
            $parentKey = 'steam:dlc-parent:' . $appId;
            $base = Cache::get($parentKey);

            if ($base === null) {
                if (SteamStore::refusing() || !$canSpend()) {
                    $complete = false;
                    break;
                }
                SteamStore::forget($appId);
                try {
                    $base = (string) ($this->stores->steamApp($appId)['fullgame']['appid'] ?? '');
                } catch (\App\Exceptions\StoreUnavailable) {
                    // Our ceiling or the store: the rest of the list waits for the next pass.
                    $complete = false;
                    break;
                }
                Cache::put($parentKey, $base, now()->addDays(30));
            }

            // Changed: what the adult check reads of it must be asked again.
            SteamStore::forget($appId);

            if ($base !== '' && isset($ours[$base])) {
                SteamStore::forget($base);
                $due[] = $ours[$base];
            }
        }

        $made = $this->makeDue($due);

        if ($complete) {
            Cache::forever(self::SteamCursor, $now);
        }

        return $made;
    }

    /** Every app of a kind changed since `$since`, or null when Steam could not be asked. */
    private function steamChanged(string $key, int $since, bool $games): ?array
    {
        $apps = [];
        $last = 0;

        do {
            $response = Http::timeout(30)->get('https://api.steampowered.com/IStoreService/GetAppList/v1/', [
                'key' => $key,
                'if_modified_since' => $since,
                'include_games' => $games ? 'true' : 'false',
                'include_dlc' => $games ? 'false' : 'true',
                'max_results' => 50000,
                'last_appid' => $last,
            ]);

            if (!$response->successful()) {
                Log::warning('Steam change list error', ['status' => $response->status()]);
                return null;
            }

            $page = $response->json('response') ?? [];
            foreach ($page['apps'] ?? [] as $app) {
                $apps[] = (string) $app['appid'];
            }
            $last = (int) ($page['last_appid'] ?? 0);
        } while (!empty($page['have_more_results']) && $last > 0);

        return $apps;
    }

    private function fromIgdb(): int
    {
        $since = Cache::get(self::IgdbCursor);
        $now = now()->timestamp;

        if ($since === null) {
            Cache::forever(self::IgdbCursor, $now);
            return 0;
        }

        // Only the games Steam does not describe: for the others, Steam's answer stands
        // (AdultRating) and its own change list already covers them.
        $ids = Game::whereNull('steam_id')->whereNotNull('igdb_id')->pluck('id', 'igdb_id');
        $due = [];

        // ⚠ GameSearchService::igdb answers an empty list on a failure as well as on "nothing
        // changed", so a failed pass moves the cursor on: these are the few games Steam does not
        // describe, still checked at publication and by "Check again".
        foreach ($ids->keys()->chunk(500) as $chunk) {
            $rows = $this->stores->igdb('games',
                'fields id; where id = (' . $chunk->implode(',') . ') & updated_at > ' . (int) $since . '; limit 500;');
            foreach ($rows as $row) {
                if (isset($ids[$row['id'] ?? null])) {
                    $due[] = $ids[$row['id']];
                }
            }
        }

        Cache::forever(self::IgdbCursor, $now);

        return $this->makeDue($due);
    }

    /** Clear the check date of these games: they are asked about again by the next pass. */
    private function makeDue(array $gameIds): int
    {
        if ($gameIds === []) {
            return 0;
        }

        // A query, not a save: no event, no `updated_at` — a store changing is not the card changing.
        return Game::whereIn('id', array_unique($gameIds))->toBase()->update(['adult_checked_at' => null]);
    }
}
