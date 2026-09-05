<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AnalyticsEvent;
use App\Models\AuditLog;
use App\Models\Game;
use App\Models\GameIdentifier;
use App\Models\MergePreviewToken;
use App\Models\Translation;
use App\Models\User;
use App\Notifications\BranchSubmitted;
use App\Services\CatalogStore;
use App\Services\GameSearchService;
use App\Services\SsePublisher;
use App\Services\TranslationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class TranslationController extends Controller
{
    /**
     * How many GAMES one answer may describe.
     *
     * ⚠ Not a cap on translations — a cap on how many games a fuzzy `q=` may resolve to, and the
     * answer says how many there were (`games_total`). Taken from what the site already shows in
     * one go rather than picked: `GameController::index` paginates games by 24.
     *
     * 🔴 The distinction this whole class had lost: **a limit bounds the transport, never the
     * truth.** Nothing here may make a caller believe there is less than there is.
     */
    private const MaxGamesPerAnswer = 24;

    /**
     * How many rows the FLAT `translations` array carries.
     *
     * 🔴 **Kept only for callers that predate `games`** — every mod already published reads
     * `count` and `translations` and nothing else. Its value is the one it has always had, so a
     * deployed mod sees exactly what it saw yesterday.
     *
     * ⚠ Everything true lives in `games` now: complete per game, with its own total. The flat
     * array is a legacy view, and the note beside it in the payload says so.
     */
    private const LegacyFlatRows = 50;

    /**
     * Search translations by game (steam_id, game name, or game slug) and language.
     *
     * GET /api/v1/translations?steam_id=111111&lang=French
     * GET /api/v1/translations?game=hollow-knight&lang=French
     * GET /api/v1/translations?q=Hollow&lang=French
     *
     * 🔴 **Rewritten 2026-09-04. What it used to do, and why it was wrong** (the full account is
     * in `analyse/api-search-troncature.md`):
     *
     *  · `limit(300)` with NO `orderBy` — so past 300 matches the sample was whatever the engine
     *    returned first, and two identical calls could answer differently;
     *  · `take(50)` with no total and no pagination — past 50, legitimate translations became
     *    invisible and the caller had no way to know. The Manager then said "50 translations are
     *    published", listed a truncated set of languages, and OFFERED ONE FOR INSTALL out of a
     *    sample;
     *  · with `q=` the cap was not even per game: `name LIKE %…%` spans several, and neither the
     *    mod nor the Manager filtered the answer back onto the game they asked about.
     *
     * What replaces it, and it is what the site itself already does on a game's page
     * (`GameController::show` — *"Get ALL translations for this game"*, no cap, same ranking):
     * everything matching is loaded and ranked, and the answer is GROUPED BY GAME. Ranking only
     * ever happens inside one game — comparing a translation of one game to another's is meaningless
     * — which is also why `ranking_score` never needed to move into SQL.
     */
    public function search(Request $request): JsonResponse
    {
        // Public AND still listed: the mod's community list is a listing like any other, and a
        // player must not be offered a file that hands the game's own text back. The lineage
        // endpoints below keep plain visibility — a delisted Main is still the Main.
        // originAuthor is eager-loaded like the uploader: the listing credits a fork's source on
        // every row, and reading that per row is fifty queries on a screen that already runs one.
        // The relation has no foreign key on purpose (a credit outlives the account it names), so
        // it simply resolves to null when the account is gone — which is a state the payload says.
        $query = Translation::with([
            'game:id,name,slug,steam_id,image_url',
            'user:id,name',
            'originAuthor:id,name',

            // ⚠ **Loaded because the ranking reads it.** `ranking_score` reads `fork_bonus`, which
            // reads `$this->parent` — one query per fork otherwise. GameController::show says the
            // same thing about the same accessor; the API had simply never been told.
            'parent',
        ])->publiclyListed();

        // 🔴 **Which GAMES first, translations second.** The filters used to be `whereHas` on the
        // translation query, so nothing ever knew how many games were involved — which is exactly
        // how a cap meant for one game ended up shared between four.
        $gameIds = null;
        $gamesTotal = null;

        // Filter by Steam ID (exact match) — a demo's own id reaches the game it is a demo of.
        if ($request->filled('steam_id')) {
            $matching = Game::answeringToSteamId($request->steam_id);

            // Nothing answers to it yet: it may be a demo nobody has published from. Asking the
            // store settles it and records the answer, so this happens once per app id, ever.
            if (!$matching->exists() && ($full = $this->fullGameOfDemo($request->steam_id))) {
                $matching = Game::answeringToSteamId($full);
            }
        }
        // Filter by game slug or ID
        elseif ($request->filled('game')) {
            $gameIdentifier = $request->game;
            $matching = Game::where('slug', $gameIdentifier)
                ->orWhere('id', is_numeric($gameIdentifier) ? $gameIdentifier : 0);
        }
        // Search by game name
        elseif ($request->filled('q')) {
            // 🔴 **The name the machine reads comes first, the loose match only after.**
            // `unity_name` is exactly what the caller sends — the mod and the Manager both take it
            // from `<Game>_Data/app.info` — so where the site knows it there is nothing to guess.
            // The LIKE below is for the games it does not know yet, and it is what lets one lookup
            // answer about several games at once.
            $exact = Game::where('unity_name', $request->q)->pluck('id');

            $search = $this->escapeLike($request->q);

            // 🔴 **A union, never a short-circuit.** `unity_name` is declared by whoever published,
            // so letting a match on it REPLACE the ordinary search handed one account the power to
            // hide every other candidate behind a name it had chosen. Added to them, it can only
            // ever widen the answer — and the caller picks by display name (GameNames in the
            // socle), or is told the answer covers several games.
            //
            // ⚠ The latin handle is in the same half, and for the same reason: it is generated, so
            // it may help somebody FIND a game and never decide which one they meant.
            $matching = Game::where(function ($q) use ($search, $exact) {
                $q->where('name', 'like', '%' . $search . '%')
                    ->orWhere('latin_search', 'like', '%' . mb_strtolower($search) . '%');

                if ($exact->isNotEmpty()) {
                    $q->orWhereIn('id', $exact);
                }
            });
        }

        if (isset($matching)) {
            // Counted before the cap, and reported: a caller that receives 24 games must be able
            // to know there were 40.
            $gamesTotal = (clone $matching)->count();

            // ⚠ Ordered before the cap, or "the first 24" means nothing — the very fault the old
            // `limit(300)` had.
            $gameIds = $matching->orderBy('name')->limit(self::MaxGamesPerAnswer)->pluck('id');

            $query->whereIn('game_id', $gameIds);
        }

        // Filter by target language (full name, e.g., "French")
        if ($request->filled('lang')) {
            $query->where('target_language', $request->lang);
        }

        // Filter by source language (full name, e.g., "English")
        if ($request->filled('source_lang')) {
            $query->where('source_language', $request->source_lang);
        }

        // Note: 'type' filter removed - type is now a computed accessor from HVASM stats
        // and cannot be filtered at the database level

        // Ordered like the website: how much of the game a translation reaches, lifted by how
        // much of it was reviewed, then reception and freshness.
        //
        // It used to be votes then downloads, which meant the ONE screen where players actually
        // choose a translation was ordered by the weakest signal we have — most translations
        // have never been voted on at all, so in practice it was a download count. It ignored
        // review, coverage and whether the file was still maintained.
        //
        // Sorted in PHP because usefulness reads the game's other translations and cannot be
        // expressed in this query.
        //
        // ⚠ **No cap here any more, and that is the point.** What bounds this query is the number
        // of GAMES above; within them everything is loaded, exactly as a game's own page on the
        // site does. `limit(300)` stood here with no `orderBy` in front of it, which made the
        // sample arbitrary at the very moment it started to matter.
        //
        // ⚠ Without a game filter — nobody in this project calls it that way — this is still every
        // published translation, and the old cap is the honest thing to keep: there is no "per
        // game" to be complete about.
        $candidates = $gameIds === null ? $query->limit(300)->get() : $query->get();

        $gameMaxes = Translation::maxResolvedLinesByGame($candidates->pluck('game_id'));
        foreach ($candidates as $candidate) {
            $candidate->gameMaxHint = $gameMaxes[$candidate->game_id] ?? 0;
        }

        $ranked = $candidates->sortByDesc(fn (Translation $t) => $t->ranking_score)->values();

        // The flat array every published mod reads, unchanged — see LegacyFlatRows.
        $translations = $ranked->take(self::LegacyFlatRows)->values();

        // The caller's own votes, in ONE query for the whole page (auth.api.optional resolves the
        // user only when a valid token was sent; voting requires an account, so anonymous callers
        // simply get null). Without this the mod cannot colour the arrows until the user votes
        // again — it only ever learned its vote from the POST response.
        // ⚠ Over everything ranked, not just the flat fifty: the same rows are served again inside
        // `games`, and a vote missing there would draw a neutral arrow to somebody who has voted —
        // which is how a second click WITHDRAWS the vote they meant to confirm.
        $user = $request->user();
        $userVotes = $user
            ? \App\Models\Vote::where('user_id', $user->id)
                ->whereIn('translation_id', $ranked->pluck('id'))
                ->pluck('value', 'translation_id')
            : collect();

        return response()->json([
            // ── What every published mod reads. Unchanged, on purpose. ────────────────────────
            'count' => $translations->count(),
            'translations' => $translations->map(fn ($t) => $this->listingRow($t, $userVotes)),

            // ── The truth, per game, complete. ───────────────────────────────────────────────
            //
            // Absent when no game filter was given: there is no "per game" to be complete about,
            // and a caller must never be handed a shape that implies exhaustiveness we cannot give.
            ...($gameIds === null ? [] : [
                'games' => $this->groupsByGame($ranked, $gameIds, $userVotes,
                                               $this->languageTallies($gameIds),
                                               Game::whereIn('id', $gameIds)->get()),
                'games_total' => $gamesTotal,
            ]),
        ]);
    }

    /**
     * The published translations of each game, complete, with what the whole game holds beside it.
     *
     * 🔴 **`total` and `languages` are what make any cap harmless.** They are measured, not counted
     * off the rows returned, so a caller can never conclude "there are 50" or "none in French" from
     * a partial list. That conclusion — drawn from a truncated answer, then used to OFFER AN
     * INSTALL — is the defect this whole rewrite is about.
     *
     * ⚠ `total` answers the request (language filters included); `languages` answers about the GAME,
     * ignoring them. Both are needed and they are not the same question: "3 French ones match what
     * you asked" and "this game exists in 12 languages" are what a screen says side by side.
     */
    /// <summary>The language tallies for a set of games, in ONE query.</summary>
    private function languageTallies($gameIds)
    {
        return Translation::whereIn('game_id', $gameIds)
            ->publiclyListed()
            ->groupBy('game_id', 'target_language')
            ->selectRaw('game_id, target_language, COUNT(*) as n')
            ->get()
            ->groupBy('game_id');
    }

    /**
     * 🔴 **The two lookups are handed IN, never made here.** This method is called once per entry a
     * batch asks about, so making them inside it meant two queries per game — 47 for a library of
     * seventeen, and it would have been 200 for a hundred. The whole point of the batch is to stop
     * multiplying work by the size of a library; doing it in SQL instead of in HTTP would have been
     * the same fault one floor down.
     */
    private function groupsByGame($ranked, $gameIds, $userVotes, $tallies, $games): array
    {
        $byGame = $ranked->groupBy('game_id');

        // Games with nothing published still appear, saying zero. A key that is simply missing
        // reads as "not asked about", and the caller cannot tell the two apart.
        return $games->whereIn('id', $gameIds instanceof \Illuminate\Support\Collection
                ? $gameIds->all()
                : $gameIds)
            ->map(function (Game $game) use ($byGame, $tallies, $userVotes) {
            $rows = $byGame->get($game->id, collect());

            return [
                'game' => [
                    'id' => $game->id,
                    'name' => $game->name,
                    'slug' => $game->slug,
                    'steam_id' => $game->steam_id,
                    'image_url' => $game->image_url,
                ],
                'total' => $rows->count(),
                'languages' => $tallies->get($game->id, collect())
                    ->mapWithKeys(fn ($row) => [$row->target_language => (int) $row->n])
                    ->toArray(),
                'translations' => $rows->map(fn ($t) => $this->listingRow($t, $userVotes))->values(),
            ];
        })->values()->toArray();
    }

    /**
     * How many games one batch may ask about.
     *
     * ⚠ A transport bound, and the answer is complete for every one of them — nothing is dropped
     * silently. A caller with more games sends a second batch; two hundred games is two requests
     * where it is two hundred today.
     */
    private const MaxGamesPerBatch = 100;

    /**
     * Everything a manager needs about a whole library, in one request.
     *
     * 🔴 **Why this exists.** The community lookup is the only call that grows with the number of
     * games — one per game, 120 ms apart, against a `throttle:60,1` counted per IP. Under sixty
     * games it passes by luck; past that the rest are refused, nothing is cached for them (a
     * failure is not an empty catalogue), and the next launch repeats it identically. A game past
     * the sixtieth would never get an answer at all.
     *
     * POST body: `{"games":[{"steam_id":"367520","uuid":"abc"},{"name":"Foo"}]}`
     *
     * ⚠ **`uuid` resolves with plain visibility, everything else with `publiclyListed`** — and that
     * difference is the point of carrying it. A delisted Main is still the Main of its lineage: it
     * is out of the catalogue but it is what the game is running, and a manager that could not
     * find it would lose the author, the sync verdict and the votes of the very file in front of
     * the reader. It is the rule `scopePubliclyListed` already states: *"never where it resolves
     * one"*.
     *
     * ⚠ **Nothing private is reachable here.** Both paths answer with `visibility = public` only,
     * so a branch is never returned whoever asks — which is why this endpoint needs no permission
     * rule of its own. Where the account stands in a lineage is a different question, and
     * `/me/translations` already answers it for the whole account in one call.
     */
    public function forGames(Request $request): JsonResponse
    {
        $request->validate([
            'games' => 'required|array|min:1|max:' . self::MaxGamesPerBatch,
            'games.*.steam_id' => 'nullable|string|max:32',
            'games.*.name' => 'nullable|string|max:255',
            'games.*.uuid' => 'nullable|string|max:36',
        ]);

        $asked = collect($request->input('games'));

        // ── Which games, in as few queries as the shapes allow ────────────────────────────────
        $steamIds = $asked->pluck('steam_id')->filter()->unique()->values();
        $names = $asked->pluck('name')->filter()->unique()->values();

        // 🔴 **Keyed on the id that was ASKED, not on the one the card carries.** A demo resolves to
        // the full game's card, whose `steam_id` is a different string — grouping on the column
        // would file it under an id nobody asked about, and the entry would come back empty while
        // the game was right there. So each asked id is matched to whatever answers to it.
        $bySteam = collect();

        if ($steamIds->isNotEmpty()) {
            $found = Game::answeringToSteamId($steamIds->all())->with('identifiers')->get();

            foreach ($steamIds as $askedId) {
                $answering = $found->filter(function (Game $g) use ($askedId) {
                    return (string) $g->steam_id === (string) $askedId
                        || $g->identifiers->contains(fn ($i) => $i->source === \App\Models\GameIdentifier::Steam
                            && (string) $i->value === (string) $askedId);
                })->values();

                if ($answering->isNotEmpty()) {
                    $bySteam->put($askedId, $answering);
                }
            }
        }

        // Exact first, and it is the case that matters: a manager sends the name it read off the
        // game folder, and an indexed equality answers it. The fuzzy pass below runs only for
        // what that missed.
        //
        // ⚠ **No LOWER() around the column.** `games.name` carries an index and the table collates
        // `utf8mb4_unicode_ci`, which already ignores case — so a plain `whereIn` matches "Cat" for
        // "cat" AND uses the index, where a function on the column would force a full scan. The
        // lowering that remains is on OUR side, for the dictionary keys.
        $lowered = $names->map(fn ($n) => mb_strtolower(trim($n)));

        // 🔴 **On `unity_name` as well as on `name`, and it is the half that matters.** What a
        // batch sends is what a machine read off the folder; the site's display name comes from
        // IGDB when it knows the game, so the two are often different strings for one game. Looking
        // only at the display name is what sent this lookup into the fuzzy pass below — and the
        // fuzzy pass is where a translation of another game can end up attributed to this one.
        $trimmed = $names->map(fn ($n) => trim($n))->all();

        $byName = $names->isEmpty()
            ? collect()
            : Game::whereIn('unity_name', $trimmed)->orWhereIn('name', $trimmed)->get()
                ->groupBy(fn (Game $g) => mb_strtolower($g->unity_name ?? $g->name));

        // ⚠ A game folder is not always named as the site names it — "Foo" against "Foo: Deluxe
        // Edition". That is why `q=` matched loosely in the first place, and dropping it here
        // would lose those games rather than fix anything. What changes is that the answer now
        // NAMES each game it found, so an ambiguity is visible instead of being flattened into
        // "the translations of your game".
        // ⚠ Indexed under BOTH names, because either can be what the caller asked with: the group
        // above is keyed on the Unity name when there is one, so a game found in SQL by its display
        // name would otherwise have no key and fall into the fuzzy pass — cancelling the gain.
        foreach ($byName->values()->flatten() as $game) {
            $alias = mb_strtolower($game->name);
            if (!$byName->has($alias)) {
                $byName->put($alias, collect([$game]));
            }
        }

        $unresolved = $lowered->reject(fn ($n) => $byName->has($n))->values();

        // 🔴 **A name too short to identify anything never reaches the loose pass.** `%a%` matches
        // most of a catalogue, and a hundred of them in one batch loaded every game into memory —
        // with no SQL limit, twenty times a minute, from anybody. The Manager already refuses to
        // search with fewer than two characters; this is that rule, held where it counts.
        $unresolved = $unresolved->filter(fn ($n) => mb_strlen($n) >= 3)->values();

        if ($unresolved->isNotEmpty()) {
            $fuzzy = Game::where(function ($q) use ($unresolved) {
                foreach ($unresolved as $name) {
                    $escaped = $this->escapeLike($name);
                    $q->orWhere('name', 'like', '%' . $escaped . '%');
                }
            })
                // ⚠ Bounded in SQL, not after loading: the per-name cap below runs in PHP, so on
                // its own it bounded what came BACK and never what was read.
                ->orderBy('name')
                ->limit(self::MaxGamesPerAnswer * max(1, $unresolved->count()))
                ->get();

            foreach ($unresolved as $name) {
                $hits = $fuzzy->filter(fn (Game $g) => str_contains(mb_strtolower($g->name), $name))
                    ->take(self::MaxGamesPerAnswer)
                    ->values();

                if ($hits->isNotEmpty()) {
                    $byName->put($name, $hits);
                }
            }
        }

        // ── Their translations, all of them, in one query ─────────────────────────────────────
        // 🔴 **Deliberately NOT capped here, and a cap was tried and removed the same day.**
        // Cutting this set bounds the work and makes the answer lie: `games_total` counts the
        // candidates that were found, while `games` is built from the ids kept — so an entry could
        // announce three games and return none. That is the exact fault this whole endpoint was
        // written to end.
        //
        // What bounds the work instead sits upstream, where it costs nothing true: names under
        // three characters never reach the loose pass, and that pass is limited in SQL.
        $gameIds = $bySteam->flatten()->merge($byName->flatten())->pluck('id')->unique()->values();

        $rows = $gameIds->isEmpty() ? collect() : Translation::with([
            'game:id,name,slug,steam_id,image_url',
            'user:id,name',
            'originAuthor:id,name',
            // Same reason as the search above: the ranking reads `parent` through `fork_bonus`.
            'parent',
        ])->publiclyListed()->whereIn('game_id', $gameIds)->get();

        $gameMaxes = Translation::maxResolvedLinesByGame($gameIds);
        foreach ($rows as $row) {
            $row->gameMaxHint = $gameMaxes[$row->game_id] ?? 0;
        }

        $ranked = $rows->sortByDesc(fn (Translation $t) => $t->ranking_score)->values();

        // ── The lineage each game is actually running ─────────────────────────────────────────
        $uuids = $asked->pluck('uuid')->filter()->unique()->values();

        // ⚠ **`parent` préchargé ici aussi, et il a fallu le mesurer pour le savoir.** Ces lignes
        // ne passent par aucun classement, donc rien n'y lit `fork_bonus` — et le retirer semblait
        // donc juste. Retiré, une requête PARESSEUSE apparaît pendant le rendu : quelque chose de
        // la ligne publiée lit la relation. Sur un lot d'une centaine de jeux, ce serait une
        // requête par fork.
        //
        // 🔴 Le raisonnement disait une chose et le compteur en disait une autre. Ne pas
        // « nettoyer » ce préchargement sans le remesurer.
        $matching = $uuids->isEmpty() ? collect() : Translation::with([
            'game:id,name,slug,steam_id,image_url',
            'user:id,name',
            'originAuthor:id,name',
            'parent',
        ])->public()->whereIn('file_uuid', $uuids)->get()->keyBy('file_uuid');

        foreach ($matching as $row) {
            $row->gameMaxHint = $gameMaxes[$row->game_id]
                ?? Translation::maxResolvedLinesForGame($row->game_id);
        }

        $user = $request->user();
        $userVotes = $user
            ? \App\Models\Vote::where('user_id', $user->id)
                ->whereIn('translation_id', $ranked->pluck('id')->merge($matching->pluck('id')))
                ->pluck('value', 'translation_id')
            : collect();

        // 🔴 **Once for the whole batch, and this is the difference between a batch and a loop.**
        // Both of these used to be made inside the per-entry composition below: seventeen games
        // cost 47 queries, a hundred would have cost 200. Saving a hundred HTTP requests only to
        // spend two hundred SQL ones is the same fault moved one floor down.
        $tallies = $gameIds->isEmpty() ? collect() : $this->languageTallies($gameIds);
        $games = $gameIds->isEmpty() ? collect() : Game::whereIn('id', $gameIds)->get();

        // ── One result per entry ASKED, in the order asked ────────────────────────────────────
        //
        // ⚠ Including the ones that found nothing. A missing key reads as "not asked about", and a
        // caller cannot tell that apart from "no translation exists" — the distinction the whole
        // sweep already keeps (`OnlineCatalogCache` never caches a failure as an empty catalogue).
        $results = $asked->map(function ($entry) use ($bySteam, $byName, $ranked, $userVotes, $matching, $tallies, $games) {
            $steamId = $entry['steam_id'] ?? null;
            $name = isset($entry['name']) ? mb_strtolower(trim($entry['name'])) : null;

            $games = collect();
            if ($steamId !== null && $bySteam->has($steamId)) {
                $games = $bySteam->get($steamId);
            } elseif ($name !== null && $byName->has($name)) {
                $games = $byName->get($name);
            }

            $ids = $games->pluck('id');
            $uuid = $entry['uuid'] ?? null;

            return [
                'key' => array_filter([
                    'steam_id' => $steamId,
                    'name' => $entry['name'] ?? null,
                ], fn ($v) => $v !== null),
                'games' => $ids->isEmpty()
                    ? []
                    : $this->groupsByGame($ranked, $ids, $userVotes, $tallies, $games),
                'games_total' => $games->count(),
                'matching' => $uuid !== null && $matching->has($uuid)
                    ? $this->listingRow($matching->get($uuid), $userVotes)
                    : null,
            ];
        })->values();

        return response()->json(['results' => $results]);
    }

    /**
     * One translation, as every listing in this API describes it.
     *
     * 🔴 **One mapper, used by the search, by the per-game groups and by the batch endpoint.** Two
     * shapes for one object is two truths waiting to drift, and this project has paid for that
     * before — a field added to one and forgotten in the other is invisible until somebody reports
     * that the same file reads differently in two windows.
     */
    private function listingRow(Translation $t, $userVotes): array
    {
        return [
                    'id' => $t->id,
                    'game' => [
                        'id' => $t->game->id,
                        'name' => $t->game->name,
                        'slug' => $t->game->slug,
                        'steam_id' => $t->game->steam_id,
                        'image_url' => $t->game->image_url,
                    ],
                    'uploader' => $t->user->name,
                    'source_language' => $t->source_language,
                    'target_language' => $t->target_language,
                    'line_count' => $t->line_count,
                    'status' => $t->status,

                    // On the listing so a card can say it without asking again: whether this
                    // lineage takes contributions is part of what a translation IS, like the
                    // author's "finished" beside it.
                    'accepts_branches' => (bool) $t->accepts_branches,

                    // Where a fork came from. The site has credited this since the origin_* columns
                    // were added and the mod credited nobody, so the same file named its source in
                    // a browser and looked home-grown in the game it came from.
                    //
                    // ⚠ The line count is the SNAPSHOT taken at the fork and never recomputed — the
                    // original keeps growing, so asking again would answer a different question.
                    // Null when the account is gone: the credit stands without a name.
                    'origin' => $t->hasOrigin() ? [
                        'author' => $t->originAuthor?->name,
                        'lines' => $t->origin_resolved_lines,
                    ] : null,
                    'type' => $t->type,
                    'notes' => $t->notes,
                    'resources_url' => $t->getEffectiveResourcesUrl(),
                    'vote_count' => $t->vote_count,
                    'user_vote' => $userVotes[$t->id] ?? null,
                    'download_count' => $t->download_count,
                    'human_count' => $t->human_count,
                    'validated_count' => $t->validated_count,
                    'ai_count' => $t->ai_count,
                    'capture_count' => $t->capture_count,
                    // Neither a translation nor missing work: shown on its own, never in the bar
                    'skipped_count' => $t->skipped_count,
                    // Kept for mods already published that read it; superseded by the two below
                    'quality_score' => $t->quality_score,
                    // Share of the file a human settled, and share of the game it reaches. The
                    // mod cannot work the second one out on its own: it would need every other
                    // translation of the game. Additive fields — an older mod ignores them.
                    'review_coverage' => $t->reviewCoverage(),
                    'game_coverage' => $t->gameCoverage(),
                    // Share of what the file already met in game that is translated. The mod
                    // could derive it from the counts, but the formula decides what a badge
                    // says, and a published measure has one definition — here.
                    'completeness' => $t->completeness(),
                    // When it was published, which updated_at cannot say: a vote moves that one.
                    // The mod flags a newcomer from it — being noticed is a newcomer's one
                    // advantage over a translation that has had months to gather downloads.
                    'created_at' => $t->created_at->toIso8601String(),
                    'file_hash' => $t->file_hash,
                    'file_uuid' => $t->file_uuid,
                    // Kept for mods that already read it, but it moves on a vote
                    // or a download — content_updated_at is the honest one
                    'updated_at' => $t->updated_at->toIso8601String(),
                    'content_updated_at' => $t->contentChangedAt()->toIso8601String(),
        ];
    }

    /**
     * Check if a translation has been updated.
     * Supports ETag/If-None-Match for efficient polling.
     *
     * GET /api/v1/translations/{id}/check
     */
    public function check(Translation $translation, Request $request): JsonResponse
    {
        // Same single definition as download. A contributor who could not even ask whether their
        // own branch had changed would be told nothing about work they wrote themselves.
        if (!$translation->isReadableBy($request->user())) {
            return response()->json([
                'error' => 'Forbidden',
                'message' => 'A branch is visible to whoever wrote it, and to the Main owner for merging',
            ], 403);
        }

        // Compute hash if not already stored
        if (!$translation->file_hash) {
            $translation->updateHash();
        }

        // ⚠ Covers everything this endpoint ANSWERS, not just the file. An ETag built on
        // file_hash alone — which is what stood here — replies 304 while the caller's copy of
        // the vote count or the uploader's name is stale, because neither of those touches a
        // single translated line. Deliberately WITHOUT updated_at: that one moves on every
        // download by anyone, so including it would invalidate the ETag constantly and the
        // 304 would never happen at all.
        // ⚠ Null-safe: a translation outlives the account that published it, and this endpoint
        // runs unauthenticated on a timer — a fatal here would take the update check down for
        // everyone still holding that file.
        $uploader = $translation->user?->name ?? '';

        $etag = '"' . substr(hash('sha256', implode('|', [
            $translation->file_hash,
            $translation->line_count,
            $translation->vote_count,
            $uploader,
        ])), 0, 32) . '"';

        // Check If-None-Match header for 304 response
        $ifNoneMatch = $request->header('If-None-Match');
        if ($ifNoneMatch && $ifNoneMatch === $etag) {
            return response()->json(null, 304);
        }

        $response = [
            'id' => $translation->id,
            'file_hash' => $translation->file_hash,
            // Who published it. The one endpoint someone with NO ACCOUNT can reach about the
            // translation they installed, so without this the mod can only call them "Website" —
            // it knows the site id it came from and nothing about whose work it is.
            //
            // No new disclosure: this same name is already returned by the public search and shown
            // on the public game pages. A branch never reaches this line — isReadableBy above
            // answers 403 to anyone but its author and the Main owner.
            'uploader' => $uploader,
            'line_count' => $translation->line_count,
            'vote_count' => $translation->vote_count,
            'updated_at' => $translation->updated_at->toIso8601String(),
            'content_updated_at' => $translation->contentChangedAt()->toIso8601String(),
        ];

        // If client sent their current hash, indicate if update is available
        if ($request->filled('hash')) {
            $response['has_update'] = $translation->file_hash !== $request->hash;
        }

        return response()
            ->json($response)
            ->header('ETag', $etag)
            ->header('Cache-Control', 'private, must-revalidate');
    }

    /**
     * Check if a UUID exists on the server.
     * Used by the mod to determine if upload is NEW, UPDATE, or FORK.
     *
     * GET /api/v1/translations/check-uuid?uuid={uuid}
     *
     * Returns:
     * - exists: false, role: 'none' → NEW (would become new Main)
     * - exists: true, role: 'main' → UPDATE (user is Main owner)
     * - exists: true, role: 'branch' → UPDATE (user is Branch contributor)
     * - exists: true, role: 'none' → Would become BRANCH (Main exists, different user)
     */
    public function checkUuid(Request $request, TranslationService $service): JsonResponse
    {
        $request->validate([
            'uuid' => 'required|string|max:36',
        ]);

        $uuid = $request->uuid;
        $user = $request->user();
        $userId = $user->id;

        // The published translation of this lineage — the one the ranking ranks, and the one
        // the mod's current-translation card lets a player vote on. Resolved for BOTH branches
        // below: an author needs the count of their own work, a player needs to be able to
        // thank the translation they are actually playing.
        $publicTranslation = Translation::where('file_uuid', $uuid)
            ->where('visibility', 'public')
            ->orderBy('created_at', 'asc')
            ->first();

        // Built by the model, so this endpoint and sync/state cannot describe a vote differently
        $voteBlock = $publicTranslation?->voteStateFor($user);

        // Check if current user owns a translation with this UUID
        $ownTranslation = Translation::where('file_uuid', $uuid)
            ->where('user_id', $userId)
            ->first();

        if ($ownTranslation) {
            // User has a translation with this UUID
            $role = $ownTranslation->lineageRole();
            $branchesCount = $role === 'main'
                ? Translation::where('file_uuid', $uuid)->branches()->count()
                : null;

            // 🔴 **How many of them are actually holding something.** `branches_count` says how
            // many people contribute — true, useful, and not the same question. A contributor who
            // took the file months ago and never came back has nothing to give, and counting them
            // sends their Main to review emptiness. Computed with the rule the merge screen
            // pre-selects with, so the number matches the rows behind the button.
            //
            // ⚠ Each side gets its own question and no more: a Main learns what its contributions
            // hold, a contributor learns what THEIRS holds — never what the others are doing,
            // which is none of their business and which `isReadableBy` keeps out of reach anyway.
            $waiting = $role === 'main'
                ? $service->contributionsWaiting($ownTranslation)
                : null;

            return response()->json([
                'exists' => true,
                'role' => $role,
                // A branch whose Main is gone. The mod had no way to know: this endpoint answers
                // on the user's OWN row, so it kept saying "you are a branch" long after there
                // was anything to be a branch of — and the next upload dutifully stayed one, of a
                // lineage with no head. Additive field: older mods ignore it and behave as before.
                'main_missing' => $role === 'branch' ? $publicTranslation === null : null,
                // 🔴 **The Main is there and nobody is behind it.** Erasing an account keeps its
                // translations — the work stays published, which is the point — so this lineage
                // still looks alive: the Main is listed, downloadable, and still says it accepts
                // contributions. Nobody will ever read one.
                //
                // ⚠ Distinct from main_missing, which says the Main itself is gone. The upload is
                // refused either way; what has to be understood differs, so the two are separate
                // fields with separate sentences. Additive: older mods ignore it and meet the
                // refusal on the click, whose text carries the way out on its own.
                'main_abandoned' => $role === 'branch'
                    ? (bool) $publicTranslation?->user?->isDeletedAccount()
                    : null,
                // Told, came back, took nothing. Distinct from silence, which the mod already
                // hears about through dormancy. Additive: older mods ignore it.
                'main_ignoring' => $role === 'branch' ? $ownTranslation->mainIgnoresContributions() : null,

                // Whether this lineage takes contributions at all, so a client can stop offering
                // one instead of letting somebody find out on the click. ⚠ Additive: a mod that
                // predates the field ignores it and meets the refusal at upload, which is why
                // that refusal reads as a whole sentence.
                'accepts_branches' => $ownTranslation->lineageAcceptsBranches(),
                'branch_frozen' => $ownTranslation->isFrozenBranch(),
                // The contributor's apport over time, for their own card in game.
                'merged_lines_total' => $role === 'branch' ? $ownTranslation->merged_lines_total : null,

                // Additive, both null on the side the question does not apply to. An older mod
                // ignores them and goes on showing the raw count, which is what it did before.
                'branches_with_work' => $waiting['branches'] ?? null,
                'lines_available' => $waiting['lines'] ?? null,

                // 🔴 **What there is to LOOK AT, and what it is made of.** `lines_available` above
                // is what would be taken; `review` is what needs a decision — new lines plus lines
                // both sides hold differently, including those where the Main keeps its own.
                // Neither figure can be derived from the other, and a Main weighs a review on both.
                // Each is broken down by the contribution's tag: 21 new lines written by hand is a
                // different proposition from 21 the machine produced.
                //
                // ⚠ Null on a branch, like the two above: what the other contributions carry is not
                // a contributor's business. Additive — an older mod ignores the field.
                'lines_waiting' => $waiting === null ? null : [
                    'review' => $waiting['review'],
                    'take' => $waiting['lines'],
                    'new' => $waiting['new'],
                    'differing' => $waiting['differing'],
                ],
                'lines_offered' => $role === 'branch'
                    ? $service->linesOfferedToMain($ownTranslation, $publicTranslation)
                    : null,
                'translation' => [
                    'id' => $ownTranslation->id,
                    'source_language' => $ownTranslation->source_language,
                    'target_language' => $ownTranslation->target_language,
                    'type' => $ownTranslation->type,
                    // 🔴 Sent so the mod can SHOW it and send it back unchanged. Without it the mod
                    // had nothing to display and nothing to preserve, so it posted "in_progress"
                    // every time — silently undoing a translation its author had marked complete
                    // on this site.
                    'status' => $ownTranslation->status,
                    'notes' => $ownTranslation->notes,
                    'resources_url' => $ownTranslation->getEffectiveResourcesUrl(),
                    // 🔴 The row's OWN link, beside the effective one above — two different
                    // questions that had one answer.
                    //
                    // A branch with no link of its own shows its Main's, which is right on a
                    // screen and wrong in an edit field: prefilled from the effective value and
                    // posted back, the branch adopts a copy of its Main's link and stops
                    // following it for ever after, over an edit its author never made.
                    //
                    // So: `resources_url` to DISPLAY, `resources_url_own` to EDIT. Additive —
                    // a client that does not know this field behaves exactly as before.
                    'resources_url_own' => $ownTranslation->resources_url,
                    'line_count' => $ownTranslation->line_count,
                    'file_hash' => $ownTranslation->file_hash,
                    'updated_at' => $ownTranslation->updated_at->toIso8601String(),
                    'content_updated_at' => $ownTranslation->contentChangedAt()->toIso8601String(),
                ],
                'branches_count' => $branchesCount,
                'vote' => $voteBlock,
            ]);
        }

        // Check if a Main exists with this UUID (user would become branch)
        $mainTranslation = $publicTranslation?->loadMissing('user:id,name');

        if ($mainTranslation) {
            // Main exists, user would become a branch if they upload
            return response()->json([
                'exists' => true,
                'role' => 'none', // User has no translation yet
                // ⚠ Said here too, and not only to branches. Somebody holding this file and about
                // to contribute for the first time needs it before the click, not after — it is
                // the same "no dead ends" rule the rest of this product follows. Additive.
                'main_abandoned' => (bool) $mainTranslation->user?->isDeletedAccount(),

                // 🔴 **The Main's refusal of contributions, to the one person it is FOR.** It was
                // sent on the caller's own row only — to somebody who had already contributed —
                // and never here, to somebody about to. Both clients read a missing field as "not
                // asked" (rightly: an older site never says), so both announced "Contribute" over a
                // translation whose author works alone, and determineOwnership refused after the
                // upload. Additive; sync/state has said it at this level all along.
                'accepts_branches' => (bool) $mainTranslation->accepts_branches,
                'main' => [
                    'id' => $mainTranslation->id,
                    'uploader' => $mainTranslation->user->name,
                    'source_language' => $mainTranslation->source_language,
                    'target_language' => $mainTranslation->target_language,
                    'line_count' => $mainTranslation->line_count,
                    'updated_at' => $mainTranslation->updated_at->toIso8601String(),
                    'content_updated_at' => $mainTranslation->contentChangedAt()->toIso8601String(),
                ],
                'vote' => $voteBlock,
            ]);
        }

        // NEW case: UUID doesn't exist → would become new Main
        return response()->json([
            'exists' => false,
            'role' => 'none',
        ]);
    }

    /**
     * Download a translation file.
     * Returns gzipped JSON with ETag for caching.
     *
     * GET /api/v1/translations/{id}/download
     */
    public function download(Translation $translation, Request $request): mixed
    {
        // The one definition of who may read a branch, in the model. This endpoint carried its
        // own copy, which had fallen behind: it admitted the Main owner alone, so contributors
        // were locked out of work they had written themselves — reinstall a game or lose the
        // file, and the only way back was to ask someone else. isReadableBy has always said "its
        // author, always", and its docblock warns in as many words that a further copy is one
        // more place to forget. This was that place.
        if (!$translation->isReadableBy($request->user())) {
            return response()->json([
                'error' => 'Forbidden',
                'message' => 'A branch is visible to whoever wrote it, and to the Main owner for merging',
            ], 403);
        }

        // Compute hash if not stored
        if (!$translation->file_hash) {
            $translation->updateHash();
        }

        $etag = '"' . $translation->file_hash . '"';

        // Check If-None-Match header for 304 response
        $ifNoneMatch = $request->header('If-None-Match');
        if ($ifNoneMatch && $ifNoneMatch === $etag) {
            return response('', 304)->header('ETag', $etag);
        }

        // Increment download counter
        $translation->incrementDownloads();

        // Track download for analytics
        try {
            $userAgent = $request->userAgent() ?? 'UnityGameTranslator';
            $ip = $request->ip() ?? '0.0.0.0';

            // ⚠ **Every one of these used to throw.** `device` was an enum of desktop/mobile/tablet
            // and this writes 'mod', so the insert failed on each download and the catch below hid
            // it — months of mod downloads recorded nowhere while the site's own button was
            // counted. Fixed 2026-08-20 by taking the list out of the database.
            $client = \App\Support\ClientAgent::ours($userAgent);

            AnalyticsEvent::create([
                'route' => 'api.translations.download',
                'game_id' => $translation->game_id,
                'country' => null,
                'referrer_domain' => 'mod', // Mark as mod download
                'device' => $client['kind'] ?? AnalyticsEvent::detectDevice($userAgent),
                // ⚠ Null rather than "Other" for our own programs: the browser breakdown skips
                // nulls, and a mod filed under a browser name makes that chart answer a question
                // nobody asked. Which build called is counted properly in client_usage_daily.
                'browser' => $client === null ? AnalyticsEvent::detectBrowser($userAgent) : null,
                'visitor_hash' => AnalyticsEvent::generateVisitorHash($ip, $userAgent, now()->toDateString()),
                'created_at' => now(),
            ]);
        } catch (\Exception $e) {
            report($e);
        }

        // Get validated file path (prevents path traversal)
        $filePath = $translation->getSafeFilePath();

        if (!$filePath || !file_exists($filePath)) {
            return response()->json(['error' => 'File not found'], 404);
        }

        $content = file_get_contents($filePath);

        // ⚠ **This used to gzip the answer here, guarded by `Accept-Encoding`, and that code never
        // ran in production.** A proxy sits in front of PHP and strips the header, so the guard
        // could not pass — verified 2026-08-20, PHP sees it as absent while the client sent it.
        // The branch worked locally and did nothing on the live site, which is the shape of bug
        // that survives for years.
        //
        // Compression now happens once, in CompressJsonResponse, which decides on something that
        // does reach PHP. Leaving a second mechanism here would mean two answers to one question
        // and a client served differently depending on the route it asked for.
        return response($content)
            ->header('Content-Type', 'application/json')
            ->header('Content-Disposition', 'attachment; filename="translations.json"')
            ->header('X-Content-Type-Options', 'nosniff')
            ->header('ETag', $etag)
            ->header('Cache-Control', 'private, max-age=3600');
    }

    /**
     * Upload a translation file (requires authentication).
     *
     * POST /api/v1/translations
     */
    /**
     * The origin a fork declares, once it has been checked.
     *
     * Only ever set on a genuinely new translation: an update keeps what it already had, and a
     * branch carries a parent_id, which says the same thing while the row still exists.
     *
     * The POINTER is verified, the NUMBERS are taken as declared — we cannot recompute how much
     * the original held at the instant of the fork, since it has grown since. A pointer that
     * resolves to nothing is dropped whole rather than stored dangling.
     *
     * 🔴 **Verified means "this caller could have forked it", not "a row with this id exists".** A
     * fork is what you make of a translation you HOLD — one you downloaded, or your own branch
     * leaving its lineage. It used to be enough that the id resolved: an account could then
     * declare somebody's private branch, or a translation it had never seen, as its origin, and
     * the catalogue credited "forked from @them" on the fork while their own page listed it among
     * their community forks. So the source has to be readable by the caller, and either public
     * or the caller's own. What fails that test is dropped whole, as a pointer to nothing is.
     *
     * A mod that sends none of this — every released version — lands on the same nulls as
     * before. Nothing here is required, and nothing here can fail an upload: a malformed number or
     * hash is left out, never refused.
     *
     * @return array{translation_id: ?int, user_id: ?int, resolved_lines: ?int, file_hash: ?string}
     */
    private function resolveForkOrigin(Request $request, ?Translation $existing, ?int $parentId): array
    {
        $empty = ['translation_id' => null, 'user_id' => null, 'resolved_lines' => null, 'file_hash' => null];

        if ($existing || $parentId) {
            return $empty;
        }

        $declaredId = (int) $request->input('forked_from_id');
        if ($declaredId <= 0) {
            return $empty;
        }

        $user = $request->user();
        $source = Translation::find($declaredId);

        if (!$source || !$source->isReadableBy($user)) {
            return $empty;
        }

        if ($source->visibility !== 'public' && $source->user_id !== $user->id) {
            return $empty;
        }

        // The snapshot, as declared — and only as a number the column can hold. Anything else
        // (a word, a negative, a figure past a 32-bit integer that would fail the insert) is no
        // number at all.
        $lines = filter_var($request->input('forked_from_lines'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0, 'max_range' => 2147483647],
        ]);

        // The version that was taken, as the site itself fingerprints one: sixty-four hex digits.
        $hash = $request->input('forked_from_hash');
        $hash = is_string($hash) && preg_match('/^[0-9a-f]{64}$/', $hash) ? $hash : null;

        return [
            'translation_id' => $source->id,
            'user_id' => $source->user_id,
            'resolved_lines' => $lines === false ? null : $lines,
            'file_hash' => $hash,
        ];
    }

    public function store(Request $request, TranslationService $service): JsonResponse
    {
        $languages = CatalogStore::languageNames();

        $request->validate([
            'steam_id' => 'nullable|required_without:game_name|string',
            'game_name' => 'nullable|required_without:steam_id|string|max:255',

            // ⚠ Same shape as the name it travels with. It was read straight into a varchar(255)
            // with no rule at all: in strict mode a longer string is a 500, and nothing in the
            // request had said what the field accepts.
            'game_company' => 'nullable|string|max:255',
            'source_language' => ['required', 'string', 'in:' . implode(',', $languages)],
            'target_language' => ['required', 'string', 'in:' . implode(',', $languages)],
            // 'type' is now auto-calculated from HVASM stats
            'status' => 'nullable|in:in_progress,complete', // Optional - branches inherit from Main
            // max aligned with DecodeGzipRequest::MAX_DECOMPRESSED_SIZE (100 MB)
            'content' => 'required|string|min:2|max:104857600',
            'notes' => 'nullable|string|max:1000',
            'resources_url' => 'nullable|string|max:2048|url:http,https',

            // Null from a branch — the mod sends nothing there, because the decision belongs to
            // the Main. Applied below only where the row being written IS a Main.
            'accepts_branches' => 'nullable|boolean',
        ]);

        // Parse and validate content (includes normalization)
        try {
            $parsed = $service->parseAndValidate($request->content);
        } catch (\InvalidArgumentException $e) {
            $message = $e->getMessage();
            // Check if it's a JSON-encoded error with details
            $decoded = json_decode($message, true);
            if (is_array($decoded)) {
                return response()->json($decoded, 422);
            }

            return response()->json(['error' => $message], 422);
        }

        $fileUuid = $parsed['uuid'];
        $userId = $request->user()->id;

        // Check for existing translation with same UUID (UPDATE case)
        $existingTranslation = $service->findUserTranslation($fileUuid, $userId);

        // Determine ownership and visibility
        $ownership = $service->determineOwnership($fileUuid, $userId);

        // 🔴 **Two ways in, and the second is the one that gets forgotten.** A NEW contribution is
        // refused by determineOwnership; an EXISTING branch never reaches that decision at all,
        // because $existingTranslation short-circuits it — so a Main who closes would still be
        // receiving updates from every branch already in place.
        //
        // Frozen means frozen: as a branch, nothing more can be done. Turning it into a fork is
        // the one move left, and it is the client that asks for it.
        if (isset($ownership['refused'])) {
            return response()->json(['error' => $ownership['refused']], 403);
        }

        if ($existingTranslation && $existingTranslation->isFrozenBranch()) {
            return response()->json([
                'error' => 'The translation you contribute to no longer accepts contributions. '
                         . 'Your work is untouched — turn it into your own version to carry on.',
            ], 403);
        }

        // 🔴 **The same file, already on the site under somebody else's account.** Sending it again
        // publishes one person's work under another's name — as a fork it becomes a second
        // identical entry competing with the first, as a branch it is a contribution containing
        // nothing. Neither is sharing, and both are what an automated flood looks like.
        //
        // ⚠ **Only when CREATING a row.** Updating one's own translation into something that
        // happens to match another's is a different situation — a branch whose work has all been
        // merged, for one — and refusing it would strand somebody on a row they already own.
        //
        // ⚠ **The author is named only if their translation is public.** A branch is visible to its
        // author and the Main's owner alone; saying "identical to @someone's" would report the
        // existence of a private contribution to a stranger.
        //
        // 🔴 **One's own account is checked too, with ONE exception: the branch being left behind.**
        // Leaving a lineage from the mod does not remove the branch already on the site — the mod
        // changes the local uuid and uploads, so for a moment the same content is legitimately held
        // twice by the same person, as a branch and as the fork that left it. That is the case, and
        // it happens once. Anything else identical to one's own PUBLISHED row is a second entry
        // competing with the first for the same readers, by the same author — which is what makes
        // "fork the fork, again and again" impossible rather than merely discouraged.
        if (!$existingTranslation) {
            $twins = Translation::where('content_hash', $parsed['content_hash'])
                ->with('user:id,name')
                ->get();

            // ⚠ **The link to the assets is not in the file.** Image replacements name PNG files
            // that live on the player's disk; resources_url is where they are downloaded from, and
            // it is a column, sent beside the content. Somebody publishing the same replacements
            // pointed at a pack of their own has made something — the one part of this decision the
            // fingerprint cannot see, so it is asked separately rather than left to refuse them.
            if ($request->filled('resources_url')) {
                $twins = $twins->filter(
                    fn (Translation $t) => $t->resources_url === $request->resources_url
                );
            }

            // The branch this upload is leaving: mine, and not published. Never a reason to refuse.
            $twins = $twins->reject(
                fn (Translation $t) => $t->user_id === $userId && $t->visibility === 'branch'
            );

            if ($twins->isNotEmpty()) {
                $twin = $twins->first();

                if ($twin->user_id === $userId) {
                    return response()->json([
                        'error' => 'You have already published this exact file. Update that '
                                 . 'translation instead of putting a second copy of it on the site.',
                    ], 422);
                }

                $whose = $twin->visibility === 'public' && $twin->user
                    ? ' by @' . $twin->user->name
                    : '';

                return response()->json([
                    'error' => 'This file is identical to a translation already published'
                             . $whose . '. Translate or correct at least one line before sending it.',
                ], 422);
            }
        }

        $originalTranslation = $existingTranslation ? null : $ownership['original'];
        $visibility = $existingTranslation ? $existingTranslation->visibility : $ownership['visibility'];
        $parentId = $existingTranslation ? $existingTranslation->parent_id : $ownership['parent_id'];

        // Resolve languages
        try {
            $languages = $service->resolveLanguages(
                $request->source_language,
                $request->target_language,
                $existingTranslation,
                $originalTranslation
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        // Resolve game by case (game_id is part of a translation's identity once created):
        //   UPDATE → keep the translation's existing game (never mutate; the user may have
        //            uploaded with a slightly different _game.name/steam_id, but identity wins)
        //   FORK   → inherit the parent's game (a fork of a translation is by definition a
        //            translation of the same game; the plugin's _game payload is informational)
        //   NEW    → resolve via findOrCreateGame (steam_id → name → external API → create)
        if ($existingTranslation) {
            $game = $existingTranslation->game;
        } elseif ($originalTranslation) {
            $game = $originalTranslation->game;
        } else {
            $game = $this->findOrCreateGame($request);
            if (!$game) {
                return response()->json(['error' => 'Could not find or create game'], 422);
            }
        }

        // Store file
        $fileName = $service->storeFile($parsed['normalized_content'], $fileUuid);

        // From here the upload is happening, on both the create and the update path — which is why
        // the mark is here and not next to either return.
        //
        // 🔴 This is what separates an unknown access that sleeps from one that has already spoken
        // for the account, and it is the whole reason the Linked devices screen shows it. A
        // boolean, written once: no date, or crossing it with the public catalogue would pin each
        // release on a named machine.
        $apiToken = $request->attributes->get('api_token');
        if ($apiToken !== null && !$apiToken->published_at_least_once) {
            $apiToken->timestamps = false;
            $apiToken->update(['published_at_least_once' => true]);
        }

        // Determine status: branches inherit from Main, only Main owners can set status
        $isBranch = $visibility === 'branch' || ($existingTranslation && $existingTranslation->visibility === 'branch');
        if ($isBranch) {
            // Branches inherit status from their existing value or from the Main translation
            if ($existingTranslation) {
                $status = $existingTranslation->status;
            } else {
                // New branch - find Main translation to inherit status
                $main = Translation::where('file_uuid', $fileUuid)
                    ->where('visibility', 'public')
                    ->whereNull('parent_id')
                    ->first();
                $status = $main ? $main->status : 'in_progress';
            }
        } else {
            // Main owners can set status, default to existing or 'in_progress'
            $status = $request->status ?? ($existingTranslation ? $existingTranslation->status : 'in_progress');
        }

        if ($existingTranslation) {
            // UPDATE: Delete old file and update record
            $service->deleteFile($existingTranslation->file_path);

            // game_id intentionally omitted — see resolution block above. The translation's
            // game is fixed at creation; the payload's _game metadata is treated as
            // informational on subsequent uploads.
            $existingTranslation->update([
                'line_count' => $parsed['line_count'],
                'human_count' => $parsed['tag_counts']['human_count'],
                'validated_count' => $parsed['tag_counts']['validated_count'],
                'ai_count' => $parsed['tag_counts']['ai_count'],
                'capture_count' => $parsed['tag_counts']['capture_count'],
                'skipped_count' => $parsed['tag_counts']['skipped_count'],
                'status' => $status,
                'notes' => $request->notes,
                'resources_url' => $request->resources_url,

                // ⚠ Only when the client said something AND this row leads its lineage. Absent
                // means "not answered" — an older mod, or a branch — and writing false there
                // would close a Main that never asked to be closed, on every upload it makes.
                'accepts_branches' => $request->has('accepts_branches') && $visibility !== 'branch'
                    ? $request->boolean('accepts_branches')
                    : $existingTranslation->accepts_branches,
                'file_path' => $fileName,
                'file_hash' => $parsed['file_hash'],
                'content_hash' => $parsed['content_hash'],
                'font_config' => $parsed['font_config'],
                'settings_summary' => $parsed['settings_summary'],
            ]);

            AuditLog::logTranslationUpload($userId, $existingTranslation->id, [
                'game_id' => $game->id,
                'game_name' => $game->name,
                'source_language' => $languages['source'],
                'target_language' => $languages['target'],
                'line_count' => $parsed['line_count'],
                'is_update' => true,
            ], $request);

            // Signal SSE via Redis pub/sub — Node.js relays to connected mods
            SsePublisher::translationUpdated($existingTranslation->id, [
                'file_hash' => $existingTranslation->file_hash,
                'line_count' => $existingTranslation->line_count,
                'vote_count' => $existingTranslation->vote_count,
                'updated_at' => $existingTranslation->updated_at->toIso8601String(),
                'content_updated_at' => $existingTranslation->contentChangedAt()->toIso8601String(),
            ]);
            SsePublisher::uuidChanged($fileUuid);

            // Updated Branch → tell the Main owner there is fresh work to review
            if ($existingTranslation->visibility === 'branch') {
                $this->notifyMainOwnerOfBranch($fileUuid, $userId);
            }

            return response()->json([
                'success' => true,
                'translation' => [
                    'id' => $existingTranslation->id,
                    'file_hash' => $existingTranslation->file_hash,
                    'line_count' => $existingTranslation->line_count,
                    'role' => $service->getRole($existingTranslation->visibility),
                    'web_url' => url("/games/{$game->slug}"),
                ],
            ], 200);
        }

        // NEW or BRANCH: Create new translation
        // Where a fork came from. The mod severs its sync with the original — it must, or it
        // would keep offering to merge from a lineage it has left — and used to sever the
        // provenance with it, so a fork reached the site as a brand-new translation and whoever
        // wrote the first thousands of lines lost every trace of it.
        //
        // The POINTER is verified, the NUMBERS are taken as declared. We cannot recompute how
        // much the original held at the instant of the fork — it has grown since — but a claim
        // that resolves to nothing is dropped whole rather than stored dangling.
        $origin = $this->resolveForkOrigin($request, $existingTranslation, $parentId);

        $translation = Translation::create([
            'game_id' => $game->id,
            'user_id' => $userId,
            'parent_id' => $parentId,
            'origin_translation_id' => $origin['translation_id'],
            'origin_user_id' => $origin['user_id'],
            'origin_resolved_lines' => $origin['resolved_lines'],
            'origin_file_hash' => $origin['file_hash'],
            'source_language' => $languages['source'],
            'target_language' => $languages['target'],
            'line_count' => $parsed['line_count'],
            'human_count' => $parsed['tag_counts']['human_count'],
            'validated_count' => $parsed['tag_counts']['validated_count'],
            'ai_count' => $parsed['tag_counts']['ai_count'],
            'capture_count' => $parsed['tag_counts']['capture_count'],
            'skipped_count' => $parsed['tag_counts']['skipped_count'],
            'status' => $status,
            'visibility' => $visibility,
            'notes' => $request->notes,
            'resources_url' => $request->resources_url,

            // Same rule at creation, with false as the default the whole feature rests on.
            'accepts_branches' => $visibility !== 'branch' && $request->boolean('accepts_branches'),
            'file_path' => $fileName,
            'file_uuid' => $fileUuid,
            'file_hash' => $parsed['file_hash'],
            'content_hash' => $parsed['content_hash'],
            'font_config' => $parsed['font_config'],
            'settings_summary' => $parsed['settings_summary'],
        ]);

        AuditLog::logTranslationUpload($userId, $translation->id, [
            'game_id' => $game->id,
            'game_name' => $game->name,
            'source_language' => $languages['source'],
            'target_language' => $languages['target'],
            'line_count' => $parsed['line_count'],
            'is_fork' => $parentId !== null,
        ], $request);

        // Signal SSE via Redis pub/sub — Node.js relays to connected mods
        SsePublisher::translationUpdated($translation->id, [
            'file_hash' => $translation->file_hash,
            'line_count' => $translation->line_count,
            'vote_count' => $translation->vote_count ?? 0,
            'updated_at' => $translation->updated_at->toIso8601String(),
            'content_updated_at' => $translation->contentChangedAt()->toIso8601String(),
        ]);
        SsePublisher::uuidChanged($fileUuid);

        // New Branch → tell the Main owner there is work to review
        if ($visibility === 'branch') {
            $this->notifyMainOwnerOfBranch($fileUuid, $userId);
        }

        return response()->json([
            'success' => true,
            'translation' => [
                'id' => $translation->id,
                'file_hash' => $translation->file_hash,
                'line_count' => $translation->line_count,
                'role' => $service->getRole($visibility),
                'web_url' => url("/games/{$game->slug}"),
            ],
        ], 201);
    }

    /**
     * List branches for a Main translation (owner only).
     *
     * GET /api/v1/translations/{uuid}/branches
     */
    public function branches(Request $request, string $uuid): JsonResponse
    {
        $userId = $request->user()->id;

        // Verify user is the Main owner of this UUID
        $main = Translation::where('file_uuid', $uuid)
            ->where('visibility', 'public')
            ->where('user_id', $userId)
            ->first();

        if (!$main) {
            return response()->json([
                'error' => 'Forbidden',
                'message' => 'You are not the Main owner of this translation',
            ], 403);
        }

        // ⚠ Ordered on the content date, not updated_at: a vote or a download writes updated_at,
        // so a branch nobody had touched climbed above one sent that morning. A Main reviewing
        // contributions reads this list top-down, so the order is the whole answer.
        $branches = Translation::where('file_uuid', $uuid)
            ->where('visibility', 'branch')
            ->with('user:id,name')
            ->orderByRaw('COALESCE(content_updated_at, updated_at) desc')
            ->get()
            ->map(fn($b) => [
                'id' => $b->id,
                'user' => ['id' => $b->user->id, 'name' => $b->user->name],
                'line_count' => $b->line_count,
                'human_count' => $b->human_count,
                'validated_count' => $b->validated_count,
                'ai_count' => $b->ai_count,
                'updated_at' => $b->updated_at->toIso8601String(),
                'content_updated_at' => $b->contentChangedAt()->toIso8601String(),
            ]);

        return response()->json([
            'main_id' => $main->id,
            'branches_count' => $branches->count(),
            'branches' => $branches,
        ]);
    }

    /**
     * Find or create a game from API request.
     * Uses Steam → IGDB → RAWG to get game details.
     */
    /**
     * Notify the Main owner of a lineage that a Branch was submitted/updated.
     * Grouped per lineage (see BranchSubmitted::sendGrouped) to avoid spam.
     */
    private function notifyMainOwnerOfBranch(string $fileUuid, int $contributorId): void
    {
        $main = Translation::where('file_uuid', $fileUuid)
            ->whereNull('parent_id')
            ->where('visibility', 'public')
            ->with(['user', 'game'])
            ->first();

        if (!$main || !$main->user || $main->user_id === $contributorId) {
            return;
        }

        $contributor = User::find($contributorId);
        BranchSubmitted::sendGrouped($main->user, $main, $contributor?->name ?? 'someone');
    }

    /**
     * The game a upload belongs to — found, or created.
     *
     * 🔴 **What the caller reads off the disk is now KEPT** (`unity_name`, `unity_company`). It was
     * used to look the game up and then thrown away: when the game is new, IGDB or RAWG names it,
     * and the string every client can actually see — `Application.productName`, the first lines of
     * `<Game>_Data/app.info` — was recorded nowhere. From then on the only way back to that game
     * was hoping one title contained the other, which is what `name LIKE %…%` was doing and why a
     * lookup could answer about several games at once.
     *
     * ⚠ Filled in on games that already exist too, and only when empty: every upload carries the
     * name, so the catalogue completes itself as people publish. Nothing overwrites a value already
     * there — two machines disagreeing about a game's productName is a thing to notice, not to
     * settle silently by last-writer-wins.
     */
    private function findOrCreateGame(Request $request): ?Game
    {
        $steamId = $request->filled('steam_id') ? $request->steam_id : null;
        $gameName = $request->filled('game_name') ? $request->game_name : null;
        $company = $request->filled('game_company') ? $request->game_company : null;

        // Try by Steam ID first — the card's own id, or an id recorded as also being this game
        // (a demo's). See Game::scopeAnsweringToSteamId.
        if ($steamId) {
            $game = Game::answeringToSteamId($steamId)->first();
            if ($game) {
                $this->rememberUnityNames($game, $gameName, $company);
                return $game;
            }
        }

        // 🔴 **The DISPLAY name before the declared one, and that order is a guard.** The display
        // name comes from IGDB or from the upload that created the game; `unity_name` is a string a
        // caller states about itself. Resolving on the declared one first let an account send any
        // name and be sent to the game holding it — see rememberUnityNames for the other half.
        if ($gameName) {
            $game = Game::whereRaw('LOWER(name) = ?', [strtolower($gameName)])->first();
            if ($game) {
                $this->attachSteamId($game, $steamId);
                $this->rememberUnityNames($game, $gameName, $company);
                return $game;
            }
        }

        // Then by the name the machine reads, which is what other machines will search with.
        if ($gameName) {
            $game = Game::where('unity_name', $gameName)->first();
            if ($game) {
                $this->attachSteamId($game, $steamId);
                $this->rememberUnityNames($game, $gameName, $company);
                return $game;
            }
        }

        // Game not found - try to get details from external APIs
        if (!$gameName) {
            return null;
        }

        $gameSearchService = app(GameSearchService::class);
        $externalGame = $gameSearchService->findGame($steamId, $gameName);

        if ($externalGame) {
            $title = $externalGame['name'] ?? $gameName;
            $resolvedSteamId = $externalGame['steam_id'] ?? $steamId;

            // 🔴 **One game is one card, wherever the copy came from** — Steam, GOG, Epic, a disc.
            // Asking IGDB is what turns "LONESTAR" into "Lonestar: The Game", and creating on that
            // answer without looking again is how the same game gets a second entry: the first was
            // published from a Steam copy and carries its id, this one arrives from a store that
            // has none, and nothing in the lookups above could match the two.
            //
            // ⚠ Searched on what the resolution ANSWERED, not on what the caller sent — that is
            // the whole point of having asked.
            $known = Game::query()
                ->when($resolvedSteamId, fn ($q) => $q->answeringToSteamId($resolvedSteamId))
                ->when(!$resolvedSteamId, fn ($q) => $q->whereRaw('LOWER(name) = ?', [strtolower($title)]))
                ->first();

            if ($known) {
                // The copy in hand may know something the card does not: an id it was created
                // without, and the product name a machine reads.
                if ($resolvedSteamId && !$known->steam_id) {
                    $known->update(['steam_id' => $resolvedSteamId]);
                }

                $this->rememberUnityNames($known, $gameName, $company);
                $this->rememberDemoId($known, $externalGame);

                return $known;
            }

            // Created under the title the world knows it by — and carrying the name the machine
            // that published it reads, which is what makes it findable from another machine.
            //
            // ⚠ **The same rule as an update, and it was missing here.** When the title comes from
            // IGDB rather than from the caller, the declared name is a separate claim about the
            // game — so it is held to the same test. Without it the FIRST publisher of a game chose
            // its key freely while every later one was refused, and a key chosen badly cannot be
            // written again ("never overwrite"), so the real product name was locked out for good.
            $created = Game::create([
                'name' => $title,
                'unity_name' => \App\Support\GameNaming::isFormOfTitle($gameName, $title) ? $gameName : null,
                'unity_company' => $company,
                'steam_id' => $resolvedSteamId,
                'image_url' => $externalGame['image_url'] ?? null,
            ]);

            $this->rememberDemoId($created, $externalGame);

            return $created;
        }

        // Fallback: Create basic game entry without external data. Here the two names are the same
        // string, and they are still both recorded: a display name can be edited afterwards, and
        // the lookup must go on working when it is.
        return Game::create([
            'name' => $gameName,
            'unity_name' => $gameName,
            'unity_company' => $company,
            'steam_id' => $steamId,
        ]);
    }

    /**
     * The app id of the game this one is a demo of, or null — asked of the store, once ever.
     *
     * 🔴 **Reading, not publishing.** The alias table fills itself when somebody publishes from a
     * demo. Until the first person does, a player ON that demo finds nothing while the full game
     * may hold translations. The store knows; nothing else does.
     *
     * ⚠ **Here and NOT in the batch, and that is the whole cost control.** This path answers about
     * ONE game somebody is looking at; the batch answers about a whole library at once, where the
     * same call would be one store request per unresolved id — 23 of 26 in a real library, in the
     * user's own HTTP request. The alias recorded here is what the batch then reads for free.
     *
     * ⚠ **The negative is cached too, and it has to be**: an app id that is not a demo is the
     * common case, and without remembering that, every launch of every unpublished game would ask
     * Steam again. An empty string is the "asked, and it is not a demo" answer — null would be
     * indistinguishable from "never asked".
     *
     * ⚠ 30 days because the answer does not change: a demo does not become a different game. What
     * can change is the full game appearing in our catalogue later — which needs no new store call,
     * since the alias resolves locally from then on.
     */
    private function fullGameOfDemo(string $steamId): ?string
    {
        $fullId = \Illuminate\Support\Facades\Cache::remember(
            'steam:fullgame:' . $steamId,
            now()->addDays(30),
            function () use ($steamId) {
                $store = app(GameSearchService::class)->getGameFromSteam($steamId);

                return ($store['demo_steam_id'] ?? null) ? (string) ($store['steam_id'] ?? '') : '';
            }
        );

        if ($fullId === '') {
            return null;
        }

        // The card exists: record the demo's id on it, so every later lookup — this endpoint, the
        // batch, the listings — resolves without the store.
        $card = Game::where('steam_id', $fullId)->first();

        if ($card) {
            GameIdentifier::remember($card, GameIdentifier::Steam, $steamId, GameIdentifier::BecauseDemo);
        }

        return $fullId;
    }

    /**
     * Gives a card the Steam id it was created without — as the id of a game, never of a demo.
     *
     * 🔴 **The last place an identity was written without being checked.** A card created from a
     * copy that has no Steam id (GOG, Epic, a disc) gets one from the first upload that carries one
     * — and if that upload came from the DEMO, the card's main id became the demo's. Every later
     * player of the full game then resolved nothing, and the card they were meant to find was
     * sitting there under an id that is not the game's.
     *
     * ⚠ **One store call, and only here**: the condition is a card with no id at all, so it can
     * happen once per card and never again. If Steam does not answer, the id is written as sent —
     * the behaviour that shipped — rather than the upload being refused for a detail.
     *
     * ⚠ Filling a blank only, exactly as before: an id already recorded is never moved.
     */
    private function attachSteamId(Game $game, ?string $steamId): void
    {
        if (!$steamId || $game->steam_id) {
            return;
        }

        $store = app(GameSearchService::class)->getGameFromSteam($steamId);
        $demoId = $store['demo_steam_id'] ?? null;

        if ($demoId && !empty($store['steam_id'])) {
            $game->update(['steam_id' => $store['steam_id']]);
            GameIdentifier::remember($game, GameIdentifier::Steam, $demoId, GameIdentifier::BecauseDemo);

            return;
        }

        $game->update(['steam_id' => $steamId]);
    }

    /**
     * Records the demo's own app id on the game it is a demo of.
     *
     * 🔴 **So the store is asked once, not once per player.** The resolution above only reaches the
     * network when nothing local matched; without this, every player on that demo would take the
     * same two round-trips to Steam to reach the same card. With it, the second one resolves in the
     * database — which is also what makes the card reachable when Steam is down.
     *
     * ⚠ The write refuses on its own if that id belongs elsewhere (App\Models\GameIdentifier);
     * there is nothing to decide here.
     */
    private function rememberDemoId(Game $game, array $externalGame): void
    {
        $demoId = $externalGame['demo_steam_id'] ?? null;

        if ($demoId) {
            GameIdentifier::remember($game, GameIdentifier::Steam, $demoId, GameIdentifier::BecauseDemo);
        }
    }

    /**
     * Writes what a machine reported about a game, without ever overwriting what is there.
     *
     * ⚠ Only fills blanks. A game published from two installs can report two different product
     * names — a repack, a demo, a regional build — and letting the last upload win would move the
     * key other machines resolve with, silently.
     */
    private function rememberUnityNames(Game $game, ?string $gameName, ?string $company): void
    {
        // 🔴 **Never on a game that has a Steam id, and that single line closes the hole.**
        //
        // `unity_name` is only ever consulted for games WITHOUT a Steam id — anything carrying one
        // is resolved by it, before any name is looked at. So writing it on a game that has one
        // buys nothing, and costs everything: an account could publish a translation declaring the
        // Steam id of a popular game and any product name it liked, and that name became the key
        // every other machine resolves with. From then on, players of the real game — the ones
        // without a Steam id, precisely those this column serves — were shown the popular game's
        // translations, offered them for install, and had their own uploads filed under it.
        //
        // ⚠ And "never overwrite" made it permanent rather than protecting anything: the squatter
        // held the name for good.
        //
        // ⚠ **Refusing outright would cost the very case this column exists for**, and it did for
        // half a day: a game published from a Steam copy carries an id, so it would never record
        // its product name — and a copy of that game WITHOUT one (a repack, a store that is not
        // Steam) is exactly who needs it. So the rule is narrower: on a game holding an id, the
        // declared name is recorded only when it is a FORM OF THE TITLE that game already carries.
        //
        // "LONESTAR" against "Lonestar: The Game" passes; "Cattails" against "Cat" does not, and
        // neither does anything unrelated. Compared without case, spaces or punctuation, because
        // that is the whole difference between a product name and a shop title.
        if ($game->steam_id && !\App\Support\GameNaming::isFormOfTitle($gameName, $game->name)) {
            return;
        }

        $fill = [];

        // ⚠ **Refused when the name already belongs to another game**, under either column. A
        // declared string may not be made to collide with a name somebody else's game answers to.
        $taken = $gameName !== null && Game::where('id', '!=', $game->id)
            ->where(fn ($q) => $q->where('unity_name', $gameName)
                                 ->orWhereRaw('LOWER(name) = ?', [strtolower($gameName)]))
            ->exists();

        if ($gameName && !$game->unity_name && !$taken) {
            $fill['unity_name'] = $gameName;
        }

        if ($company && !$game->unity_company) {
            $fill['unity_company'] = $company;
        }

        if ($fill !== []) {
            $game->update($fill);
        }
    }


    /**
     * Vote on a translation.
     * Requires authentication.
     *
     * POST /api/v1/translations/{translation}/vote
     */
    public function vote(Request $request, Translation $translation): JsonResponse
    {
        // Public translations only, and never your own — see Translation::canBeVotedBy()
        $user = $request->user();
        if (!$translation->canBeVotedBy($user)) {
            return response()->json([
                'error' => 'Forbidden',
                'message' => 'You cannot vote on this translation',
            ], 403);
        }

        $request->validate([
            'value' => 'required|integer|in:-1,1',
        ]);
        $translation->vote((int) $request->value, $user);

        return response()->json([
            'vote_count' => $translation->fresh()->vote_count,
            'user_vote' => $translation->userVoteFor($user)?->value,
        ]);
    }

    /**
     * What is SAID about a translation: its description, the link to what it needs, and
     * whether its author calls it finished.
     *
     * PATCH /api/v1/translations/{translation}/details
     *
     * 🔴 Separate from store() on purpose. Store takes the FILE and rewrites the row around it,
     * so changing a description through it meant publishing whatever else the local file had
     * gained meanwhile. These three fields are the author's words about the work, not the work.
     *
     * ⚠ Ownership is the ordinary rule, with no exception: one writes on one's own row and
     * nowhere else. A branch author edits their branch, a Main owner their Main, and neither
     * touches the other's — the admin path stays in /admin, behind its middleware.
     *
     * ⚠ `status` is refused on a branch rather than ignored. A contribution inherits its Main's,
     * as store() enforces; answering 200 to a request that changed nothing would teach a client
     * that it had.
     */
    public function updateDetails(Request $request, Translation $translation): JsonResponse
    {
        $user = $request->user();

        if ($translation->user_id !== $user->id) {
            return response()->json([
                'error' => 'Forbidden',
                'message' => 'This translation belongs to somebody else.',
            ], 403);
        }


        // ⚠ A frozen branch is frozen for everything, details included. The Main it contributes to
        // no longer takes contributions, so describing it better would be describing something
        // that can never move again. The one action left is turning it into a fork.
        if ($translation->isFrozenBranch()) {
            return response()->json([
                'error' => 'Frozen',
                'message' => 'The translation you contribute to no longer accepts contributions. '
                           . 'Turn this into your own version to carry on.',
            ], 403);
        }

        // Same limits as store(), so one field cannot be accepted here and refused there.
        $request->validate([
            'notes' => 'nullable|string|max:1000',
            'resources_url' => 'nullable|string|max:2048|url:http,https',
            'status' => 'nullable|in:in_progress,complete',
            'accepts_branches' => 'nullable|boolean',
        ]);

        $isBranch = $translation->visibility === 'branch';

        if ($isBranch && $request->filled('status')) {
            return response()->json([
                'error' => 'Unprocessable',
                'message' => 'A contribution inherits whether it is finished from the translation '
                           . 'it contributes to.',
            ], 422);
        }

        // ⚠ Only what was SENT is written. An absent field is "no opinion", never null — the
        // opposite of store()'s rule, and deliberately so: a client fixing a link has no reason
        // to restate a description it may not even have read.
        $changes = [];
        if ($request->has('notes')) {
            $changes['notes'] = $request->input('notes');
        }
        if ($request->has('resources_url')) {
            $changes['resources_url'] = $request->input('resources_url');
        }
        if (!$isBranch && $request->has('status')) {
            $changes['status'] = $request->input('status');
        }

        // 🔴 The Main's decision, and only the Main's. A branch sending this would be answering
        // for the lineage it contributes to — the same reason status is refused to it above.
        if (!$isBranch && $request->has('accepts_branches')) {
            $changes['accepts_branches'] = (bool) $request->input('accepts_branches');
        }

        // Same transition test as the website form — see the note there.
        $wasOpen = (bool) $translation->accepts_branches;

        if ($changes) {
            // ⚠ Not content_updated_at: nothing about the file changed. Bumping it would tell
            // every player the translation had moved on, and rank it as freshly worked on.
            $translation->update($changes);
        }

        if ($wasOpen && !$translation->fresh()->accepts_branches) {
            $translation->notifyBranchesOfClosure();
        }

        $translation->refresh();

        return response()->json([
            'translation' => [
                'id' => $translation->id,
                'status' => $translation->status,
                'notes' => $translation->notes,
                'resources_url' => $translation->getEffectiveResourcesUrl(),
                'resources_url_own' => $translation->resources_url,
            ],
        ]);
    }
}
