<?php

namespace App\Services;

use App\Models\Game;
use App\Support\GameNaming;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GameSearchService
{
    /**
     * IGDB's number for Steam in `external_games.external_game_source` — read on 2026-10-02 from
     * real answers (the Steam app ids of known games came back under 1).
     *
     * ⚠ `external_game_source`, never `category`: IGDB retired `category` on external games, and a
     * filter on it matches nothing without a word.
     */
    private const IgdbSteamSource = 1;

    /** Where the Twitch app token IGDB is asked with is kept between requests. */
    private const TwitchTokenKey = 'twitch_api_token';

    /**
     * Full search: local DB first, then Steam (if ID), then external APIs.
     * Optimizes API quota by checking local database first.
     *
     * Order:
     * 0. Ids typed as the query: digits asked of Steam, IGDB and RAWG; a page address of its source
     * 1. Local database (games we already have)
     * 2. The games those ids (and `steam_id`) name — our card holding it, or what the source says
     * 3. IGDB (Twitch), only if not enough local results — each hit with its Steam id when known
     * 4. Steam's title search
     * 5. RAWG, only if nothing at all came back
     *
     * @param string|null $query Search query (game name)
     * @param string|null $steamId Steam App ID for exact match
     * @param int $perSource How many answers the catalogue and each store are asked for — the size
     *                       of the question, which bounds the list; nothing found is cut afterwards
     * @return array Results, each game once (deduplicateResults), best matches first
     */
    public function searchFull(?string $query, ?string $steamId = null, int $perSource = 10): array
    {
        $results = [];

        // 0. An id or a store page address typed into the search box — the way to name a game
        // whose title the stores do not know under the name the person has (a game Steam lists
        // only under its Chinese title, even in English).
        //
        // 🔴 **No store is THE id** (user, 2026-10-04: "je ne vois pas pourquoi la liste ne
        // pourrait pas tout chercher et afficher"). Bare digits are asked of every source that
        // numbers its games — Steam, IGDB, RAWG — AND searched as a title ("2048" is a game): the
        // list shows each answer with its source and its cover, and the person picks. An address
        // names one source and nothing else.
        $typed = $query ? $this->idsTyped($query) : [];
        if ($typed !== [] && !ctype_digit(trim((string) $query))) {
            $query = null;
        }
        if ($steamId) {
            $typed[] = ['source' => 'steam', 'id' => $steamId];
        }

        // 1. The catalogue of ours: a card here is the one to pick when it is the game.
        if ($query && strlen($query) >= 2) {
            $results = array_merge($results, $this->searchLocal($query, $perSource));
        }

        // 2. The games those ids name — the card of ours holding it, or what that source says.
        // First in the list: an id typed is the most precise thing a person can give.
        $byId = [];
        foreach ($typed as $asked) {
            $card = match ($asked['source']) {
                'steam' => Game::answeringToSteamId($asked['id']),
                'igdb' => ctype_digit($asked['id']) ? Game::where('igdb_id', $asked['id']) : null,
                'rawg' => ctype_digit($asked['id']) ? Game::where('rawg_id', $asked['id']) : null,
            };
            $card = $card?->withCount(['translations' => fn ($q) => $q->publiclyListed()])->first();

            $hit = $card ? $this->localRow($card) : match ($asked['source']) {
                'steam' => $this->getGameFromSteam($asked['id']),
                'igdb' => ctype_digit($asked['id']) ? $this->getGameFromIGDB((int) $asked['id']) : $this->getGameFromIGDBSlug($asked['id']),
                'rawg' => $this->getGameFromRAWG($asked['id']),
            };

            if ($hit) {
                $byId[] = $hit;
            }
        }
        $results = array_merge($byId, $results);

        // 3-5. Every store, by title: IGDB (its hits carry the Steam id it links, so a pick from
        // here names the game by id even from a client that only sends a title and a Steam id),
        // Steam's own search (the games IGDB does not know), RAWG.
        //
        // 🔴 **Never skipped because the list "has enough"** (user, 2026-10-04: "3 c'est pas
        // beaucoup, c'est pas source de cacher les bons résultats ?"). IGDB was asked only when the
        // catalogue held fewer than 3 matching cards, RAWG only when everything else was empty: a
        // title shared by three cards of ours hid the real game, which only a store knew — and a
        // list without the right game is how a person picks a wrong one. A store is skipped only
        // when it cannot be asked. These searches come from a person picking a game in a publish
        // or change-game list, so the quota they cost stays small.
        //
        // ⚠ Loose by nature — stores answer neighbours too — which is fine in a list a person
        // reads, and why nothing automatic ever takes a first hit (findGame keeps exact titles).
        if ($query && strlen($query) >= 2) {
            $results = array_merge($results, $this->searchIGDB($query, $perSource));

            // ⚠ Steam's title search gives a name and a capsule, nothing more: asking each hit's page
            // for its year would be one more store call per row. Its link is there to check it.
            foreach ($this->steamSearch($query) as $hit) {
                $results[] = [
                    'name' => $hit['name'],
                    'steam_id' => $hit['id'],
                    'image_url' => $hit['image_url'],
                    'source' => 'steam',
                ] + $this->details('steam', null, [], [], \App\Support\StoreLinks::steam($hit['id']));
            }

            $results = array_merge($results, $this->searchRAWG($query, $perSource));
        }

        $results = $this->deduplicateResults($this->rawgSteamIds($results));

        // Calculate match_score for each result
        $results = $this->calculateMatchScores($results, $query, $steamId);

        // Best matches first. ⚠ Nothing is cut after the sort: each source was asked for
        // `$perSource` answers, which bounds the list; dropping rows already found would only hide
        // a right answer that ranked low.
        usort($results, fn($a, $b) => ($b['match_score'] ?? 0) <=> ($a['match_score'] ?? 0));

        return $results;
    }

    /**
     * Search local database for games.
     *
     * ⚠ `translations_count` is what the catalogue shows for the game, so it follows the
     * catalogue's rule (Translation::scopePubliclyListed): it used to count every row, branches
     * and delisted files included, and weighted the match score with them.
     */
    public function searchLocal(string $query, int $limit = 10): array
    {
        // ⚠ By title in its own script OR in latin letters (Game::scopeTitleMatches). This is the
        // search behind the publish form: without the latin half, somebody publishing for
        // 龙胤立志传 who typed "longyin" did not find the card and was steered towards the external
        // sources — the first step towards a second card for the same game.
        //
        // ⚠ And by the name a game states on disk, exactly: that is what the mod and the Manager
        // put in this box for a game with no Steam id, and a card whose title says something else
        // ("JHL" for Jianghu Chronicles) was otherwise never offered — the next publisher was sent
        // to the stores, towards a second card.
        //
        // ⚠ And by the names the machines of its translations read (Game::readAs), which a card
        // with a Steam id never takes as `unity_name` when they do not look like its title.
        //
        // ⚠ The exact matches come first, BEFORE the limit: a short word matches many titles, and
        // the card whose title or name on disk IS the query must never be the one cut.
        return Game::titleMatches($query)
            ->orWhere('unity_name', $query)
            ->orWhereIn('id', Game::readAs([$query])->flatten()->all())
            ->withCount(['translations' => fn ($q) => $q->publiclyListed()])
            ->orderByRaw('(LOWER(name) = ? OR unity_name = ?) DESC', [mb_strtolower(trim($query)), trim($query)])
            ->orderBy('name')
            ->limit($limit)
            ->get()
            ->map(fn (Game $game) => $this->localRow($game))
            ->toArray();
    }

    /**
     * A card of ours as the list shows it (`translations_count` loaded by the caller) — its page
     * on this site to check it on, and the store ids it holds, which tell homonyms apart.
     */
    private function localRow(Game $game): array
    {
        return [
            'id' => $game->id,
            'name' => $game->name,
            'steam_id' => $game->steam_id,
            'igdb_id' => $game->igdb_id,
            'rawg_id' => $game->rawg_id,
            'image_url' => $game->image_url,
            'source' => 'local',
            'translations_count' => (int) ($game->translations_count ?? 0),
        ] + $this->details('local', null, [], [], route('games.show', $game));
    }

    /**
     * One RAWG game as this service hands it out — a search hit carries its release date, a game
     * read by id also its developers and publishers.
     */
    private function rawgRow(array $game): array
    {
        $year = preg_match('/^(\d{4})/', (string) ($game['released'] ?? ''), $m) ? (int) $m[1] : null;

        return [
            'id' => $game['id'],
            'name' => $game['name'],
            'image_url' => $game['background_image'] ?? null,
            'source' => 'rawg',
            // Whether RAWG lists a Steam page for it — what makes asking its Steam id worth a call
            // (rawgSteamIds). Internal, removed before the list is handed out.
            '_on_steam' => collect($game['stores'] ?? [])->contains(fn ($s) => ($s['store']['slug'] ?? null) === 'steam'),
        ] + $this->details(
            'rawg',
            $year,
            collect($game['developers'] ?? [])->pluck('name')->all(),
            collect($game['publishers'] ?? [])->pluck('name')->all(),
            \App\Support\StoreLinks::rawgId((string) $game['id']),
        );
    }

    /**
     * A RAWG hit that may be a game already listed is given its Steam id, so it folds into that
     * row (deduplicateResults) — one game, one entry.
     *
     * 🔴 **Folded on the id, never on the name** (user, 2026-10-05: "quand on l'a pas tant pis on met
     * tout mais si on l'a c'est une entrée"). A RAWG search hit carries no store id, only which
     * stores sell the game; its Steam page — and so its Steam id — is one more call
     * (`/games/{id}/stores`). The name only decides whether that call is worth making: a RAWG hit
     * whose flattened title is the title of a row already holding a Steam id, and that RAWG says
     * Steam sells. What folds it is the id that comes back; a hit RAWG gives no Steam page for
     * stays its own row.
     *
     * ⚠ Bounded by the list, not by a count: in practice one call per search (the game's own RAWG
     * twin), never one per RAWG hit.
     */
    private function rawgSteamIds(array $results): array
    {
        $onSteam = [];
        foreach ($results as $row) {
            if (!empty($row['steam_id']) && isset($row['name'])) {
                $onSteam[\App\Support\GameNaming::flatten((string) $row['name'])] = true;
            }
        }

        foreach ($results as $i => $row) {
            $isRawg = ($row['source'] ?? null) === 'rawg';

            if ($isRawg && empty($row['steam_id']) && ($row['_on_steam'] ?? false)
                && isset($onSteam[\App\Support\GameNaming::flatten((string) ($row['name'] ?? ''))])) {
                $steamId = $this->rawgSteamId($row['id']);
                if ($steamId !== null) {
                    $results[$i]['steam_id'] = $steamId;
                }
            }

            unset($results[$i]['_on_steam']);
        }

        return $results;
    }

    /** The Steam app id of a RAWG game, from the Steam page RAWG lists for it, or null. */
    private function rawgSteamId(int|string $id): ?string
    {
        try {
            $apiKey = config('services.rawg.key');
            if (!$apiKey || !ctype_digit((string) $id)) {
                return null;
            }

            $response = Http::get("https://api.rawg.io/api/games/{$id}/stores", ['key' => $apiKey]);
            if (!$response->successful()) {
                return null;
            }

            foreach ($response->json('results') ?? [] as $store) {
                if (preg_match('~store\.steampowered\.com/app/(\d{1,20})~i', (string) ($store['url'] ?? ''), $m)) {
                    return $m[1];
                }
            }

            return null;
        } catch (\Exception $e) {
            Log::error('RAWG stores error', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Each game once — told apart by its ids, never by its name. Earlier entries win (local >
     * Steam by id > IGDB > Steam search > RAWG).
     *
     * 🔴 **Not by name, and that was the defect** (analyse/identite-des-jeux-parcours.md, T18).
     * Two different games can share one title — each with its own Steam id — and folding by name
     * showed one line for both, so whichever one somebody picked, the other could not be.
     *
     * ⚠ What still folds: the same game reached twice — an IGDB hit and a Steam hit carrying the
     * same Steam id, or a store hit for a game a card of ours already holds the id of (that card is
     * listed, and is the one to pick).
     *
     * 🔴 **Folded INTO the row kept, never dropped** (user, 2026-10-04: "on peut peut-être
     * regrouper les fiches/id quand on sait que c'est le même"). The row that stays is still the
     * one picked, but it gathers what the others knew: every id the game answers to (`ids`, by
     * source), each source's page to check it on (`pages`), and the year, makers and cover a
     * poorer source lacked. A person sees one game with its Steam, IGDB and RAWG numbers side by
     * side, rather than one source's view of it.
     */
    private function deduplicateResults(array $results): array
    {
        $unique = [];
        // `source:value` → the index in $unique of the row that answers to it.
        $owner = [];

        foreach ($results as $game) {
            $keys = $this->identityOf($game);

            if ($keys === []) {
                continue;
            }

            $into = null;
            foreach ($keys as $key) {
                if (isset($owner[$key])) {
                    $into = $owner[$key];
                    break;
                }
            }

            if ($into === null) {
                $into = count($unique);
                // ⚠ A card of ours answers to the store ids it holds (localRow carries them), so a
                // store hit for it joins its row rather than standing beside it as a second way
                // to the same game.
                $unique[] = $game + ['ids' => []];
            } else {
                $unique[$into] = $this->gather($unique[$into], $game);
            }

            foreach ($keys as $key) {
                $owner[$key] ??= $into;
                [$source, $value] = explode(':', $key, 2);
                $unique[$into]['ids'][$source] ??= $value;
            }
        }

        return $unique;
    }

    /**
     * What a second hit for the same game adds to the row kept: its page, and what the kept row
     * did not know. Nothing the kept row states is overwritten — it is the row picked.
     */
    private function gather(array $kept, array $other): array
    {
        $kept['pages'] = ($kept['pages'] ?? []) + ($other['pages'] ?? []);

        foreach (['year', 'image_url'] as $field) {
            if (empty($kept[$field]) && !empty($other[$field])) {
                $kept[$field] = $other[$field];
            }
        }

        foreach (['developers', 'publishers', 'engines'] as $field) {
            if (empty($kept[$field]) && !empty($other[$field])) {
                $kept[$field] = $other[$field];
            }
        }

        return $kept;
    }

    /**
     * Every id one result answers to, as `source:value` keys — its own, and the Steam id it
     * carries whatever its source.
     *
     * ⚠ A card of ours is keyed `local:` by its own id, and by the store ids it holds (localRow
     * carries them), so a card and a store hit for the same game meet on them.
     */
    private function identityOf(array $game): array
    {
        $source = $game['source'] ?? '';
        $keys = [];

        if ($source === 'local' && ($game['id'] ?? null) !== null) {
            $keys[] = 'local:' . $game['id'];
        }

        if (in_array($source, ['igdb', 'rawg'], true) && ($game['id'] ?? null) !== null) {
            $keys[] = $source . ':' . $game['id'];
        }

        if (!empty($game['steam_id'])) {
            $keys[] = 'steam:' . $game['steam_id'];
        }

        // A card's own store ids — what a store hit for the same game meets it on.
        foreach (['igdb_id' => 'igdb', 'rawg_id' => 'rawg'] as $field => $store) {
            if (!empty($game[$field])) {
                $keys[] = $store . ':' . $game[$field];
            }
        }

        return $keys;
    }

    /**
     * Calculate match_score for each result based on query and steam_id.
     * Higher score = better match.
     *
     * Scoring:
     * - Steam ID exact match: +50 (very high confidence)
     * - Local source: +20 (game exists in our DB)
     * - Has translations: +1 per translation (max +10)
     * - Exact name match: +20
     * - Partial name match (contains query): +5
     */
    private function calculateMatchScores(array $results, ?string $query, ?string $steamId): array
    {
        $normalizedQuery = $query ? strtolower(trim($query)) : null;

        foreach ($results as &$result) {
            $score = 0;
            $resultName = strtolower($result['name'] ?? '');
            $resultSteamId = $result['steam_id'] ?? null;

            // Steam ID exact match = very high confidence
            if ($steamId && $resultSteamId && $resultSteamId === $steamId) {
                $score += 50;
            }

            // Local source = game exists in our database
            if (($result['source'] ?? '') === 'local') {
                $score += 20;
                // Bonus for having translations (max +10)
                $translationsCount = $result['translations_count'] ?? 0;
                $score += min(10, $translationsCount);
            }

            // Steam API source = verified game info
            if (($result['source'] ?? '') === 'steam') {
                $score += 10;
            }

            // Name matching
            if ($normalizedQuery) {
                if ($resultName === $normalizedQuery) {
                    // Exact match
                    $score += 20;
                } elseif (str_contains($resultName, $normalizedQuery)) {
                    // Partial match
                    $score += 5;
                }
            }

            $result['match_score'] = $score;
        }

        return $results;
    }

    /**
     * Escape special characters for IGDB query language
     * Prevents injection attacks via search queries
     *
     * ⚠ Public so that every caller building an Apicalypse body from a title goes through the
     * same allowlist (App\Services\AdultRating, App\Services\StoreProposals). A second copy of the
     * pattern is a second place to get the escaping wrong.
     *
     * 🔴 **It guards against the query language, never against a language** (user, 2026-10-05:
     * "il faut se protéger des attaques pas des langues"). The title goes between double quotes
     * of an Apicalypse body: what could leave that string is a quote, a backslash or a control
     * character, never a letter. So letters, marks and digits of EVERY script are kept — a game
     * titled only in Chinese is searched like any other — with the same few punctuation signs as
     * before, and any run of whitespace becomes one plain space. Until that day it kept Latin
     * letters only, and a title in another script never reached IGDB at all.
     *
     * ⚠ What is left can still be empty (a title of symbols only, or bytes that are not UTF-8) —
     * callers must treat that as "nothing to ask", never send an empty search.
     *
     * ⚠ Static because it is a pure function: called on an instance, a test that stubs this
     * service would have to stub the escaping too, and a stub of an escape proves nothing.
     */
    public static function escapeIGDBQuery(string $query): string
    {
        // Allowlist: \p{L} letters, \p{M} the marks that complete them (Devanagari, Thai,
        // Arabic vowels), \p{N} digits — of any script — plus safe punctuation. Not ", \, ; or |.
        $kept = preg_replace('/[^\p{L}\p{M}\p{N}\s\-\'\.,:!?]/u', '', $query);

        // Null when the input is not valid UTF-8: nothing safe to ask.
        if ($kept === null) {
            return '';
        }

        return preg_replace('/\s+/u', ' ', $kept) ?? '';
    }

    /**
     * One Apicalypse query against IGDB, or an empty array — the single place that talks to it.
     *
     * ⚠ **Public for the same reason as steamApp()**: App\Services\AdultRating asks IGDB a
     * different question (a game's `themes`) and must not carry a second copy of the token dance,
     * which is cached and would otherwise be requested twice as often.
     *
     * ⚠ `$body` is Apicalypse, not SQL, and it is NOT escaped here — a caller building one from
     * user input passes it through escapeIGDBQuery() first, as searchIGDB does.
     */
    public function igdb(string $endpoint, string $body): array
    {
        try {
            $token = $this->getTwitchToken();
            if (!$token) {
                return [];
            }

            $response = $this->askIgdb($endpoint, $body, $token);

            // 🔴 **A refused token is forgotten, and asked for again — once.** Twitch can revoke an
            // app token long before the expiry it announced (measured 2026-10-02: `/validate` →
            // "invalid access token" on a token cached until 2026-11-25). Kept in the cache, it
            // turned every IGDB question into an empty answer for weeks, and the searches quietly
            // fell back to RAWG — which is how a publication could be filed under a RAWG neighbour.
            // The event that makes a new token worth asking for is this refusal, nothing else.
            if ($response->status() === 401) {
                Cache::forget(self::TwitchTokenKey);
                Log::warning('IGDB refused the cached token; asking Twitch for a new one', ['endpoint' => $endpoint]);

                $token = $this->getTwitchToken();
                if (!$token) {
                    return [];
                }

                $response = $this->askIgdb($endpoint, $body, $token);
            }

            if (!$response->successful()) {
                Log::warning('IGDB API error', ['status' => $response->status(), 'endpoint' => $endpoint]);
                return [];
            }

            return $response->json() ?: [];

        } catch (\Exception $e) {
            Log::error('IGDB error', ['error' => $e->getMessage(), 'endpoint' => $endpoint]);
            return [];
        }
    }

    /** One POST to IGDB with this token — the request igdb() may have to make twice. */
    private function askIgdb(string $endpoint, string $body, string $token): \Illuminate\Http\Client\Response
    {
        return Http::withHeaders([
            'Client-ID' => config('services.twitch.client_id'),
            'Authorization' => 'Bearer ' . $token,
            'Accept' => 'application/json',
        ])->withBody($body, 'text/plain')->post('https://api.igdb.com/v4/' . $endpoint);
    }

    /**
     * The fields every game read from IGDB is asked for: its Steam id comes with it, so a hit
     * names the game by id rather than by title (see igdbRow).
     */
    private const IgdbGameFields = 'id,name,url,cover.url,first_release_date,external_games.uid,external_games.external_game_source,game_engines.name,involved_companies.developer,involved_companies.publisher,involved_companies.company.name';

    /**
     * One IGDB game as this service hands it out — the same shape for a search hit and a lookup.
     */
    private function igdbRow(array $game): array
    {
        $imageUrl = null;
        if (isset($game['cover']['url'])) {
            // Convert thumbnail to larger image
            $imageUrl = 'https:' . str_replace('t_thumb', 't_cover_big', $game['cover']['url']);
        }

        // The Steam app id IGDB links to this game, when it links one.
        $steamId = collect($game['external_games'] ?? [])
            ->first(fn ($external) => ($external['external_game_source'] ?? null) === self::IgdbSteamSource
                && ctype_digit((string) ($external['uid'] ?? '')))['uid'] ?? null;

        $companies = collect($game['involved_companies'] ?? []);

        return [
            'id' => $game['id'],
            'name' => $game['name'],
            'steam_id' => $steamId !== null ? (string) $steamId : null,
            'image_url' => $imageUrl,
            'source' => 'igdb',
            // The engines IGDB declares, often none. A stated engine that is not the one the game
            // runs on is a sure sign of the wrong game (GameFiling::refuseWrongGame); none stated
            // proves nothing.
            'engines' => collect($game['game_engines'] ?? [])->pluck('name')->filter()->values()->all(),
        ] + $this->details(
            'igdb',
            isset($game['first_release_date']) ? (int) date('Y', (int) $game['first_release_date']) : null,
            $companies->where('developer', true)->pluck('company.name')->all(),
            $companies->where('publisher', true)->pluck('company.name')->all(),
            \App\Support\StoreLinks::igdb($game['url'] ?? null) ?? \App\Support\StoreLinks::igdbId((string) $game['id']),
        );
    }

    /**
     * What tells two homonyms apart in a list a person reads — the year, who made it, who
     * published it, and the page to check it on — each only when the source gives it.
     *
     * 🔴 **For the person picking, never for a decision** (user, 2026-10-04: "ça permet à
     * l'utilisateur d'aller vérifier avant de cliquer"). Two games of one title used to differ by
     * their cover alone. Nothing here takes part in resolving a game.
     */
    private function details(string $source, ?int $year, array $developers, array $publishers, ?string $pageUrl): array
    {
        $names = fn (array $list) => array_values(array_unique(array_filter(array_map('strval', $list))));

        return [
            'year' => $year ?: null,
            'developers' => $names($developers),
            'publishers' => $names($publishers),
            // One page per source, keyed by it — a row that gathered several sources
            // (deduplicateResults) links each of them.
            'pages' => $pageUrl ? [$source => $pageUrl] : [],
        ];
    }

    /**
     * Search IGDB (Twitch) API
     */
    private function searchIGDB(string $query, int $limit): array
    {
        try {
            // Escape query to prevent IGDB query injection
            $safeQuery = $this->escapeIGDBQuery($query);

            // ⚠ A title of symbols only escapes to NOTHING, and an empty search answers with
            // whatever IGDB likes — taken as "the" game by findGame before 2026-10-02. Nothing to
            // ask is nothing found.
            if (trim($safeQuery) === '') {
                return [];
            }

            // Ensure limit is a valid integer
            $safeLimit = max(1, min(50, (int) $limit));

            $games = $this->igdb('games', "search \"{$safeQuery}\"; fields " . self::IgdbGameFields . "; limit {$safeLimit};");

            return collect($games)->map(fn ($game) => $this->igdbRow($game))->toArray();

        } catch (\Exception $e) {
            Log::error('IGDB search error', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Search RAWG API (fallback)
     */
    private function searchRAWG(string $query, int $limit): array
    {
        try {
            $apiKey = config('services.rawg.key');
            if (!$apiKey) {
                return [];
            }

            $response = Http::get('https://api.rawg.io/api/games', [
                'key' => $apiKey,
                'search' => $query,
                'page_size' => $limit,
            ]);

            if (!$response->successful()) {
                Log::warning('RAWG API error', ['status' => $response->status()]);
                return [];
            }

            $data = $response->json();

            return collect($data['results'] ?? [])->map(fn ($game) => $this->rawgRow($game))->toArray();

        } catch (\Exception $e) {
            Log::error('RAWG search error', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Get Twitch OAuth token for IGDB API
     *
     * ⚠ IGDB has no key of its own: it is asked with the Twitch app's credentials
     * (`TWITCH_CLIENT_ID` / `TWITCH_CLIENT_SECRET`, the same as the Twitch sign-in). A client
     * credentials token checks no address, so a site that is not reachable from outside asks IGDB
     * exactly like production.
     */
    private function getTwitchToken(): ?string
    {
        // Check cache first
        $cached = Cache::get(self::TwitchTokenKey);
        if ($cached) {
            return $cached;
        }

        try {
            $clientId = config('services.twitch.client_id');
            $clientSecret = config('services.twitch.client_secret');

            if (!$clientId || !$clientSecret) {
                return null;
            }

            $response = Http::asForm()->post('https://id.twitch.tv/oauth2/token', [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'grant_type' => 'client_credentials',
            ]);

            if (!$response->successful()) {
                Log::error('Twitch token error', ['status' => $response->status()]);
                return null;
            }

            $data = $response->json();
            $token = $data['access_token'];
            $expiresIn = $data['expires_in'] ?? 3600;

            // Cache token (expire 1 hour before actual expiry)
            Cache::put(self::TwitchTokenKey, $token, $expiresIn - 3600);

            return $token;

        } catch (\Exception $e) {
            Log::error('Twitch token error', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Get game details from Steam by Steam App ID
     *
     * 🔴 **A demo answers as the game it is a demo of.** Steam publishes the link itself — an app of
     * `type: "demo"` carries `fullgame: {appid, name}` — so a demo needs no guessing from titles.
     * Without this, a player on a demo resolved nothing, this method answered with the demo, and a
     * SECOND card was created for the same text: same strings, same translation work, two places.
     *
     * ⚠ The app id that was asked for comes back as `demo_steam_id`, so whoever writes the card can
     * record it (App\Models\GameIdentifier) and the next player on that demo resolves it locally,
     * without asking Steam again.
     *
     * ⚠ **One redirection, never a chain.** A full game does not carry `fullgame`; following it
     * twice could only ever be a loop.
     */
    public function getGameFromSteam(string $steamId): ?array
    {
        $game = $this->steamApp($steamId);

        if ($game === null) {
            return null;
        }

        $fullGameId = ($game['type'] ?? null) === 'demo'
            ? (string) ($game['fullgame']['appid'] ?? '')
            : '';

        if ($fullGameId !== '' && $fullGameId !== $steamId) {
            $full = $this->steamApp($fullGameId);

            return [
                // ⚠ If Steam does not answer for the full game (it is delisted, or the store is
                // down), `fullgame` still carried its name — enough to resolve the right card
                // rather than fall back to creating the demo as a game of its own.
                'name' => $full['name'] ?? ($game['fullgame']['name'] ?? null),
                'steam_id' => $fullGameId,
                'image_url' => $full['header_image'] ?? null,
                'source' => 'steam',
                'demo_steam_id' => $steamId,
            ] + $this->steamDetails($full ?? [], $fullGameId);
        }

        return [
            'name' => $game['name'] ?? null,
            'steam_id' => $steamId,
            'image_url' => $game['header_image'] ?? null,
            'source' => 'steam',
        ] + $this->steamDetails($game, $steamId);
    }

    /**
     * Year, developers and publishers from a Steam store page's data (`release_date.date` is a
     * display string in the store's own format — only its four-digit year is read).
     */
    private function steamDetails(array $app, string $appId): array
    {
        $year = preg_match('/\b(\d{4})\b/', (string) ($app['release_date']['date'] ?? ''), $m) ? (int) $m[1] : null;

        return $this->details('steam', $year, $app['developers'] ?? [], $app['publishers'] ?? [], \App\Support\StoreLinks::steam($appId));
    }

    /**
     * What the store says about one app id, or null.
     *
     * ⚠ **Public because this is the only place that knows how to ask Steam.** App\Services\
     * AdultRating needs the same answer for a different question (`content_descriptors`), and
     * copying five lines of HTTP beside this one is how two callers end up disagreeing about what
     * a refusal means — `success: false` here covers a delisted app as much as a wrong id, and
     * that reading must stay in one place.
     */
    public function steamApp(string $steamId): ?array
    {
        try {
            $response = Http::timeout(5)->get('https://store.steampowered.com/api/appdetails', [
                'appids' => $steamId,
            ]);

            if (!$response->successful()) {
                Log::warning('Steam API error', ['status' => $response->status()]);
                return null;
            }

            $data = $response->json();

            // Steam returns {steamId: {success: bool, data: {...}}}
            if (!isset($data[$steamId]['success']) || !$data[$steamId]['success']) {
                return null;
            }

            return $data[$steamId]['data'];

        } catch (\Exception $e) {
            Log::warning('Steam API error', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * What the store's own search answers for a title — `[{id, name, image_url}, ...]`, or an
     * empty array.
     *
     * ⚠ Loose by nature: it answers with add-ons, sequels and neighbours. A caller that means to
     * attach an id to a card must keep only an EXACT title match (App\Services\StoreProposals),
     * and even then an admin accepts it — a title is a guess, an id is a fact.
     */
    public function steamSearch(string $term): array
    {
        try {
            $response = Http::timeout(5)->get('https://store.steampowered.com/api/storesearch/', [
                'term' => $term,
                'cc' => 'us',
                'l' => 'en',
            ]);

            if (!$response->successful()) {
                Log::warning('Steam search error', ['status' => $response->status()]);
                return [];
            }

            return collect($response->json('items') ?? [])
                ->map(fn ($item) => [
                    'id' => (string) ($item['id'] ?? ''),
                    'name' => (string) ($item['name'] ?? ''),
                    // The capsule Steam shows beside the hit, or null when it is not an https address.
                    'image_url' => \App\Support\StoreLinks::image($item['tiny_image'] ?? null),
                ])
                ->filter(fn ($item) => $item['id'] !== '' && $item['name'] !== '')
                ->values()
                ->all();

        } catch (\Exception $e) {
            Log::warning('Steam search error', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * The one game the stores name for a publication, or null — by Steam id when one was sent,
     * otherwise by a title that IS the name sent, and only when exactly one game carries it.
     *
     * 🔴 **Never the first hit of a search** (analyse/identite-des-jeux-parcours.md, T1). This asked
     * IGDB for ONE result and took it: a title came back as another series' game, IGDB's first
     * answer, while the game that carries that exact title — with its Steam id — sat further down
     * the same answer. A search ranks; it does not identify.
     *
     * ⚠ Null is the honest answer when the title is shared (two games of the same name) or
     * unknown — and `$shared` says which of the two it was, so a caller can ask for the game to be
     * picked rather than call it unknown (user, 2026-10-05: namesakes are refused, with the way out).
     */
    public function findGame(?string $steamId, string $gameName, ?bool &$shared = null): ?array
    {
        $shared = false;

        // Try Steam API first if we have a Steam ID
        if ($steamId) {
            $result = $this->getGameFromSteam($steamId);
            if ($result) {
                return $result;
            }
        }

        // IGDB, by exact title. Asked for enough answers to see a homonym: one result would hide
        // the second game of the same name.
        $igdb = $this->searchIGDB($gameName, 10);
        $exact = GameNaming::exactTitleMatches($igdb, $gameName);
        if (count($exact) === 1) {
            return $exact[0];
        }

        // ⚠ RAWG only when IGDB had nothing to say at all. IGDB naming two games of this title is
        // an answer — that a machine cannot choose — not a silence for RAWG to fill.
        if ($exact !== []) {
            $shared = true;
            return null;
        }

        $rawg = GameNaming::exactTitleMatches($this->searchRAWG($gameName, 10), $gameName);
        $shared = count($rawg) > 1;

        return count($rawg) === 1 ? $rawg[0] : null;
    }

    /**
     * The store ids a search box was given, as `[{source, id}]` — empty when it was given a title.
     *
     * - bare digits: that number in EVERY source that numbers its games — Steam, IGDB, RAWG;
     * - a page address: its own source only — `store.steampowered.com/app/<id>`,
     *   `igdb.com/games/<slug>`, `rawg.io/games/<slug or id>`. An IGDB page is addressed by a
     *   slug, not a number, so the slug is what is looked up.
     *
     * ⚠ A slug is kept to `[a-z0-9-]`: it travels into an IGDB query and a RAWG path.
     */
    private function idsTyped(string $query): array
    {
        $query = trim($query);

        if (ctype_digit($query) && $query !== '0' && strlen($query) <= 20) {
            return [
                ['source' => 'steam', 'id' => $query],
                ['source' => 'igdb', 'id' => $query],
                ['source' => 'rawg', 'id' => $query],
            ];
        }

        if (preg_match('~^(?:https?://)?store\.steampowered\.com/app/(\d{1,20})~i', $query, $m)) {
            return [['source' => 'steam', 'id' => $m[1]]];
        }

        if (preg_match('~^(?:https?://)?(?:www\.)?igdb\.com/games/([a-z0-9-]{1,200})~i', $query, $m)) {
            return [['source' => 'igdb', 'id' => strtolower($m[1])]];
        }

        if (preg_match('~^(?:https?://)?(?:www\.)?rawg\.io/games/([a-z0-9-]{1,200})~i', $query, $m)) {
            return [['source' => 'rawg', 'id' => strtolower($m[1])]];
        }

        return [];
    }

    /** One IGDB game by the slug its page is addressed by, or null. */
    private function getGameFromIGDBSlug(string $slug): ?array
    {
        if (!preg_match('/^[a-z0-9-]{1,200}$/', $slug)) {
            return null;
        }

        $rows = $this->igdb('games', 'where slug = "' . $slug . '"; fields ' . self::IgdbGameFields . ';');

        return empty($rows) ? null : $this->igdbRow($rows[0]);
    }

    /**
     * Get game details by ID from the appropriate source
     */
    public function getGame(int $id, string $source): ?array
    {
        if ($source === 'igdb') {
            return $this->getGameFromIGDB($id);
        }

        return $this->getGameFromRAWG($id);
    }

    /**
     * Every Steam app id IGDB links to one of its games — an edition, a demo or a re-release can
     * each have its own — or null when IGDB does not answer for that id.
     *
     * ⚠ All of them, never the first: a game is not contradicted by a Steam id IGDB links to it
     * second (GameResolver::contradicts).
     *
     * @return list<string>|null
     */
    public function steamIdsOfIgdbGame(int $id): ?array
    {
        $rows = $this->igdb('games', 'where id = ' . intval($id) . '; fields external_games.uid,external_games.external_game_source;');

        if (empty($rows)) {
            return null;
        }

        return collect($rows[0]['external_games'] ?? [])
            ->filter(fn ($external) => ($external['external_game_source'] ?? null) === self::IgdbSteamSource
                && ctype_digit((string) ($external['uid'] ?? '')))
            ->map(fn ($external) => (string) $external['uid'])
            ->unique()->values()->all();
    }

    private function getGameFromIGDB(int $id): ?array
    {
        try {
            // Use intval() for defense-in-depth even though $id is type-hinted int
            $safeId = intval($id);
            $rows = $this->igdb('games', "where id = {$safeId}; fields " . self::IgdbGameFields . ';');

            if (empty($rows)) {
                return null;
            }

            return $this->igdbRow($rows[0]);

        } catch (\Exception $e) {
            Log::error('IGDB get game error', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * One RAWG game by its id or its slug (RAWG answers either on the same route), or null.
     */
    private function getGameFromRAWG(int|string $id): ?array
    {
        try {
            $apiKey = config('services.rawg.key');
            if (!$apiKey || !preg_match('/^[a-z0-9-]{1,200}$/', (string) $id)) {
                return null;
            }

            $response = Http::get("https://api.rawg.io/api/games/{$id}", [
                'key' => $apiKey,
            ]);

            if (!$response->successful()) {
                return null;
            }

            $row = $this->rawgRow($response->json());

            // Its Steam id when RAWG lists a Steam page — a card made from a RAWG pick then answers
            // to the id the mod reads on disk, and folds with that game's other rows.
            if ($row['_on_steam'] ?? false) {
                $row['steam_id'] = $this->rawgSteamId($row['id']);
            }
            unset($row['_on_steam']);

            return $row;

        } catch (\Exception $e) {
            Log::error('RAWG get game error', ['error' => $e->getMessage()]);
            return null;
        }
    }
}
