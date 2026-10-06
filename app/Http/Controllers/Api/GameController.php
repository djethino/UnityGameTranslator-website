<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Services\GameSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GameController extends Controller
{
    /**
     * List/search games.
     *
     * GET /api/v1/games
     * GET /api/v1/games?q=hollow
     * GET /api/v1/games?steam_id=111111
     */
    public function index(Request $request): JsonResponse
    {
        // Only games with translations.
        //
        // 🔴 **whereHas, not having.** `having('translations_count', '>', 0)` on a query with no
        // GROUP BY is accepted by MySQL and REFUSED by SQLite ("HAVING clause on a non-aggregate
        // query"), so this endpoint answered in production and returned a 500 for anyone running
        // the site on SQLite. It is the kind of divergence nothing catches: the test suite passes,
        // the site works, and only a local API call fails.
        //
        // whereHas expresses the same condition as an EXISTS subquery, which both engines run.
        // The code stays portable on purpose: this endpoint must not depend on one engine's
        // tolerance.
        //
        // ⚠ Public translations only, in the count as in the filter: a branch is a proposal between
        // its author and a Main's owner, and it was being counted here — and listed by show() — as
        // if it were a catalogue entry the mod could download. Same rule in the language filter.
        //
        // ⚠ And LISTED, not merely public (Translation::scopePubliclyListed): a file with no
        // translated line leaves the catalogue after its grace period, and the site's own
        // catalogue already counted it out while this still counted it in.
        $query = Game::withCount(['translations' => fn ($q) => $q->publiclyListed()])
            ->whereHas('translations', fn ($q) => $q->publiclyListed());

        // Games marked for adults only are left out of BROWSING, never out of a lookup.
        //
        // 🔴 **Discovery and access are not the same act.** Asking by `steam_id` is a caller that
        // already has the game installed in front of it — the mod and the Manager only ever ask
        // that way — and hiding a translation from somebody already playing the game would be
        // absurd. Everything else here is browsing, so it follows the site's default.
        //
        // ⚠ Additive, and off unless asked: `include_adult` absent means "not asked for", never
        // "refused" (the rule the whole v1 contract follows). There is no session on this route,
        // so the parameter is the only way to say it.
        if (!$request->filled('steam_id') && !$request->boolean('include_adult')) {
            $query->notAdult();
        }

        // Search by Steam ID (exact match) — a demo's own id reaches the game it is a demo of.
        if ($request->filled('steam_id')) {
            $query->answeringToSteamId($request->steam_id);
        }
        // Search by name
        elseif ($request->filled('q')) {
            // Title in its own script or in latin letters — Game::scopeTitleMatches.
            $query->titleMatches($request->q);
        }

        // Filter by games that have translations in a specific language
        if ($request->filled('lang')) {
            $query->whereHas('translations', function ($q) use ($request) {
                $q->publiclyListed()->where('target_language', $request->lang);
            });
        }

        $games = $query
            ->orderBy('translations_count', 'desc')
            ->limit(50)
            ->get();

        return response()->json([
            'count' => $games->count(),
            'games' => $games->map(function ($game) {
                return [
                    'id' => $game->id,
                    'name' => $game->name,
                    'slug' => $game->slug,
                    'steam_id' => $game->steam_id,
                    'image_url' => $game->image_url,
                    // Additive: shown with the title (Game::otherNames).
                    'other_names' => $game->otherNames(),
                    'translations_count' => $game->translations_count,
                ];
            }),
        ]);
    }

    /**
     * Search for games using local DB first, then external APIs.
     * Used when uploading a new translation for a game not yet in the database.
     *
     * Order: Local DB → Steam (if ID) → IGDB → RAWG
     * This optimizes API quota by checking our database first.
     *
     * GET /api/v1/games/search?q=hollow+knight
     * GET /api/v1/games/search?steam_id=111111
     *
     * 🔴 **Open to a caller with no account, on the catalogue alone** (user, 2026-10-05). A player
     * without an account whose game was detected wrong could not change it — the search was for
     * signed-in callers only — so they never found the translations others had published for it.
     * Those translations are on cards of ours, so the catalogue is all they need; the stores, which
     * cost quota and only matter to publish a game the site does not know, stay for an account.
     * `stores` says which list was given, so a client can tell why a game is missing.
     */
    public function search(Request $request, GameSearchService $gameSearchService): JsonResponse
    {
        $stores = $request->user() !== null;
        $results = $gameSearchService->searchFull($request->input('q'), $request->input('steam_id'), stores: $stores);

        return response()->json([
            'count' => count($results),
            'games' => $results,
            'stores' => $stores,
        ]);
    }

    /**
     * Is the game a first publication names for adults only, and may this publication say so?
     *
     * GET /api/v1/games/adult?steam_id=…&game_name=…  — the same two fields the upload sends.
     *
     * 🔴 **Asked BEFORE the upload, for the game picked** (analyse/adult-declaration-at-publish.md).
     * Games are created by the upload that publishes their first translation, from the mod or the
     * Manager — so the one moment a publisher can be told "this game is classified for adults" or be
     * asked about it is on their publish screen, never on a site page they may never visit.
     *
     * - `known`: a card already answers to it (App\Services\GameResolver, the resolver the upload
     *   itself uses — so the screen and the upload cannot disagree). Its mark is shown as it stands
     *   and nobody declares anything: the first publisher already had their say.
     * - otherwise the stores are asked on the spot (App\Services\AdultRating::judge) and
     *   `declarable` says the box is offered — true only when they found nothing;
     * - `identified: false` when no card answers and no store describes the game: the upload will
     *   be refused, so no box is offered.
     *
     * ⚠ The verdict of an unknown game is cached for a day: judging costs one store call plus up to
     * ten for its DLC, against a limit of 200 per five minutes, and a screen opened twice asks twice.
     */
    public function adult(Request $request, \App\Services\GameResolver $resolver, \App\Services\AdultRating $rating): JsonResponse
    {
        $request->validate([
            'steam_id' => 'nullable|required_without_all:game_name,game_pick|string|max:32',
            'game_name' => 'nullable|required_without_all:steam_id,game_pick|string|max:255',

            // The choice the upload will send (`game_pick`), so this answer and the upload resolve
            // the same game — GameResolver::resolve for both.
            'game_pick' => 'nullable|array',
            'game_pick.source' => ['required_with:game_pick', 'string', 'in:' . implode(',', \App\Services\GameResolver::PickSources)],
            'game_pick.id' => ['required_with:game_pick', 'regex:/^\d{1,20}$/'],
        ]);

        $steamId = $request->filled('steam_id') ? (string) $request->steam_id : null;
        $gameName = $request->filled('game_name') ? (string) $request->game_name : null;
        $pick = $request->filled('game_pick')
            ? ['source' => (string) $request->input('game_pick.source'), 'id' => (string) $request->input('game_pick.id')]
            : null;

        $found = $resolver->resolve($steamId, $gameName, $pick);

        // 🔴 **Nothing identifies the game: no box, and the screen says so before sending** (user,
        // 2026-10-04: "la case doit arriver que si on a sélectionné un jeu et qu'il est nouveau,
        // jamais si on a rien"). No card answers and no store describes it, so the upload will be
        // refused (GameFiling::cardFor, `game_not_found`) — offering a declaration for a game that
        // will not be created was a dead end. Not cached: nothing was judged.
        if (!$found['game'] && !$found['external']) {
            return response()->json([
                'known' => false,
                'identified' => false,
                'adult' => false,
                'source' => null,
                'declarable' => false,
            ]);
        }

        if ($found['game']) {
            $game = $found['game'];

            return response()->json([
                'known' => true,
                'identified' => true,
                'adult' => (bool) $game->adult,
                'source' => $game->adultCitation(),
                'declarable' => false,
            ]);
        }

        $external = $found['external'];
        $judgedSteamId = $external['steam_id'] ?? $steamId;
        $judgedName = $external['name'] ?? $gameName;

        // Wrapped, because Cache::remember does not keep a null — and "nothing found" is the
        // answer most worth not asking twice.
        $verdict = \Illuminate\Support\Facades\Cache::remember(
            'adult-judge:' . sha1(json_encode([$judgedSteamId, $judgedName])),
            now()->addDay(),
            fn () => ['v' => $rating->judge($judgedSteamId, null, $judgedName)]
        )['v'];

        return response()->json([
            'known' => false,
            'identified' => true,
            'adult' => $verdict !== null,
            // The words a reader is shown, as on a card: the add-on detail is how we asked.
            'source' => $verdict === 'steam_dlc' ? 'steam' : $verdict,
            'declarable' => $verdict === null,
        ]);
    }

    /**
     * Get a specific game with its translations.
     *
     * GET /api/v1/games/{game}
     */
    public function show(Game $game, Request $request): JsonResponse
    {
        // ⚠ Public translations only, here and in the languages below. A branch inherits its Main's
        // status, so once the Main was complete every branch came out of this route with an id and
        // an uploader — and /download then refused it. The API must not announce what it will not
        // serve, and a branch is a proposal between two people, not a catalogue entry.
        // Listed, same as index(): this is a LIST of what the game offers, not the resolution of
        // one lineage — a mod syncing against a delisted Main finds it through its own routes.
        $translationsQuery = $game->translations()
            ->publiclyListed()
            ->with('user:id,name')
            ->where('status', 'complete');

        // Filter by target language
        if ($request->filled('lang')) {
            $translationsQuery->where('target_language', $request->lang);
        }

        $translations = $translationsQuery
            ->orderBy('vote_count', 'desc')
            ->orderBy('download_count', 'desc')
            ->get();

        // Get available languages for this game
        $languages = $game->translations()
            ->publiclyListed()
            ->where('status', 'complete')
            ->distinct()
            ->pluck('target_language')
            ->sort()
            ->values();

        return response()->json([
            'game' => [
                'id' => $game->id,
                'name' => $game->name,
                'slug' => $game->slug,
                'steam_id' => $game->steam_id,
                'other_names' => $game->otherNames(),
                'image_url' => $game->image_url,
            ],
            'available_languages' => $languages,
            'translations' => $translations->map(function ($t) {
                return [
                    'id' => $t->id,
                    'uploader' => $t->user->name,
                    'source_language' => $t->source_language,
                    'target_language' => $t->target_language,
                    'line_count' => $t->line_count,
                    'type' => $t->type,
                    'vote_count' => $t->vote_count,
                    'download_count' => $t->download_count,
                    'file_hash' => $t->file_hash,
                    'updated_at' => $t->updated_at->toIso8601String(),
                    'content_updated_at' => $t->contentChangedAt()->toIso8601String(),
                ];
            }),
        ]);
    }
}
