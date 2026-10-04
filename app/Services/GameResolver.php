<?php

namespace App\Services;

use App\Models\Game;

/**
 * Which card a publication naming this game lands on — or that none does yet, and what the stores
 * say about it. Reads only: nothing here writes a card.
 *
 * 🔴 **One answer for two questions, on purpose.** The upload (`Api\TranslationController::
 * findOrCreateGame`) asks it to know where to file a translation; the publish screens of the mod
 * and the Manager ask it, through `GET games/adult`, to know BEFORE sending whether this upload
 * will create the game — the one moment its first publisher may say it is for adults only
 * (analyse/adult-declaration-at-publish.md). Two copies of these four steps would disagree the day
 * one of them changes, and the screen would offer a declaration the upload then ignores.
 */
class GameResolver
{
    /** The sources a publish list answer can come from, as `game_pick.source` names them. */
    public const PickSources = ['local', 'steam', 'igdb', 'rawg'];

    public function __construct(private GameSearchService $search)
    {
    }

    /**
     * The one entry both callers use: by what the person CHOSE in the publish list when the client
     * says it, otherwise by the name and Steam id it sent (every client released before
     * 2026-10-04). Same shape as locate().
     *
     * 🔴 **A choice is followed, never searched again** (analyse/identite-des-jeux-parcours.md, T1).
     * The list hands each hit's source and id to the client; only its title came back, and the site
     * searched that title anew — so the game the person picked and the game the site filed the
     * translation under could differ, with nothing on screen to say so.
     *
     * @param array{source: string, id: string|int}|null $pick
     */
    public function resolve(?string $steamId, ?string $gameName, ?array $pick): array
    {
        return $pick ? $this->locatePick((string) $pick['source'], (string) $pick['id']) : $this->locate($steamId, $gameName);
    }

    /**
     * The card a publish-list answer names, or the store's description of it when no card does
     * yet. `via: 'pick'` when a card answered.
     *
     * - `local`: the card itself — and nothing when it is gone (removed since the list was drawn);
     * - `steam` / `igdb` / `rawg`: the card holding that id; otherwise what that store says about
     *   it, which may carry a Steam id a card answers to (an IGDB pick of a game the site holds by
     *   its Steam id is that card).
     */
    public function locatePick(string $source, string $id): array
    {
        $none = ['game' => null, 'via' => null, 'external' => null];

        if (!in_array($source, self::PickSources, true) || !ctype_digit($id)) {
            return $none;
        }

        $card = match ($source) {
            'local' => Game::find((int) $id),
            'steam' => Game::answeringToSteamId($id)->first(),
            'igdb' => Game::where('igdb_id', $id)->first(),
            'rawg' => Game::where('rawg_id', $id)->first(),
        };

        if ($card) {
            return ['game' => $card, 'via' => 'pick', 'external' => null];
        }

        if ($source === 'local') {
            return $none;
        }

        $external = $source === 'steam'
            ? $this->search->getGameFromSteam($id)
            : $this->search->getGame((int) $id, $source);

        if (!$external) {
            return $none;
        }

        $known = !empty($external['steam_id']) ? Game::answeringToSteamId((string) $external['steam_id'])->first() : null;

        return ['game' => $known, 'via' => $known ? 'pick' : null, 'external' => $external];
    }

    /**
     * `['game' => ?Game, 'via' => 'steam'|'name'|'unity'|'external'|null, 'external' => ?array]`.
     *
     * ⚠ The order is a guard, read the comments in findOrCreateGame before moving a step: the
     * DISPLAY name comes before the declared `unity_name`, so an account cannot send any name and be
     * filed under the game holding it.
     *
     * `external` is what the stores answered when no card matched the request itself — present
     * whether or not it then matched a card (`via: 'external'`), because the upload creates the new
     * card from it.
     */
    public function locate(?string $steamId, ?string $gameName): array
    {
        if ($steamId) {
            $game = Game::answeringToSteamId($steamId)->first();
            if ($game) {
                return ['game' => $game, 'via' => 'steam', 'external' => null];
            }
        }

        if ($gameName) {
            // 🔴 **A Steam id that answered nothing still says which games this is NOT.** Every
            // card answering to it was tried above, so a card found by name that carries a Steam
            // id carries ANOTHER one: a different game under the same title — homonyms exist, each
            // with its own Steam id. Only a card with no Steam id yet can
            // be this game by its name; it then receives the id (findOrCreateGame, attachSteamId).
            $byName = fn ($query) => $query->when($steamId, fn ($q) => $q->whereNull('steam_id'));

            $game = $byName(Game::whereRaw('LOWER(name) = ?', [strtolower($gameName)]))->first();
            if ($game) {
                return ['game' => $game, 'via' => 'name', 'external' => null];
            }

            $game = $byName(Game::where('unity_name', $gameName))->first();
            if ($game) {
                return ['game' => $game, 'via' => 'unity', 'external' => null];
            }
        }

        if (!$gameName) {
            return ['game' => null, 'via' => null, 'external' => null];
        }

        $external = $this->search->findGame($steamId, $gameName);

        if ($external) {
            $title = $external['name'] ?? $gameName;
            $resolvedSteamId = $external['steam_id'] ?? $steamId;

            // Searched on what the resolution ANSWERED, not on what the caller sent — see
            // findOrCreateGame: one game is one card, wherever the copy came from. By the ids the
            // answer carries first (its Steam id, then its own IGDB or RAWG id), the title last.
            $storeField = match ($external['source'] ?? null) {
                'igdb' => 'igdb_id',
                'rawg' => 'rawg_id',
                default => null,
            };

            $known = $resolvedSteamId ? Game::answeringToSteamId($resolvedSteamId)->first() : null;

            if (!$known && $storeField && isset($external['id'])) {
                $known = Game::where($storeField, $external['id'])->first();
            }

            if (!$known && !$resolvedSteamId) {
                $known = Game::whereRaw('LOWER(name) = ?', [strtolower($title)])->first();
            }

            if ($known) {
                return ['game' => $known, 'via' => 'external', 'external' => $external];
            }
        }

        return ['game' => null, 'via' => null, 'external' => $external];
    }
}
