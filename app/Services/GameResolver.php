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
     * `['game' => ?Game, 'via' => 'steam'|'name'|'unity'|'external'|null, 'external' => ?array, 'shared'?: true]`
     * — `shared` when the NAME names several games (cards of ours, or the stores' exact titles):
     * nothing is chosen then.
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

            // ⚠ **And a card with no Steam id can still be ANOTHER game** — the one its own store
            // id names (contradicts): passed over, never taken and given this Steam id.
            $fits = fn (?Game $game) => $game && !($steamId && $this->contradicts($game, $steamId));

            // 🔴 **Several cards of this name: none is taken** (user, 2026-10-05, namesakes). The
            // first one used to be — the card of one game could receive another's translation on
            // a name alone. `shared` says so, and the upload asks for the game to be picked.
            foreach ([
                'name' => $byName(Game::whereRaw('LOWER(name) = ?', [strtolower($gameName)])),
                'unity' => $byName(Game::where('unity_name', $gameName)),
            ] as $via => $query) {
                $cards = $query->limit(10)->get()->filter($fits)->values();

                if ($cards->count() > 1) {
                    return ['game' => null, 'via' => null, 'external' => null, 'shared' => true];
                }

                if ($cards->count() === 1) {
                    return ['game' => $cards->first(), 'via' => $via, 'external' => null];
                }
            }
        }

        if (!$gameName) {
            return ['game' => null, 'via' => null, 'external' => null];
        }

        $external = $this->search->findGame($steamId, $gameName, $shared);

        if (!$external && $shared) {
            return ['game' => null, 'via' => null, 'external' => null, 'shared' => true];
        }

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

    /**
     * Whether a card is SURELY not the game a Steam id names — two facts disagreeing, never a
     * resemblance.
     *
     * 🔴 **The trap this closes** (analyse/identite-des-jeux-parcours.md, the production case): a
     * card with no Steam id of its own took, by mistake, the name another game states on disk.
     * Found by that name, it was given that game's Steam id — and every later publication of the
     * real game landed on it. A card with no Steam id is not a blank: when it carries an IGDB id,
     * IGDB says which Steam apps that game is.
     *
     * - a card with a Steam id: contradicted when it does not answer to this one (its own, or one
     *   recorded as also being it);
     * - a card with an IGDB id: contradicted when IGDB links Steam ids to that game and this one is
     *   not among them — nor a demo of one of them (Steam's own `fullgame` link);
     * - otherwise, or when IGDB does not answer: not contradicted — nothing is known.
     *
     * ⚠ A RAWG id says nothing here: RAWG gives no Steam app id to compare.
     */
    public function contradicts(Game $card, string $steamId): bool
    {
        if ($card->steam_id) {
            return !Game::answeringToSteamId($steamId)->whereKey($card->id)->exists();
        }

        if (!$card->igdb_id) {
            return false;
        }

        $linked = $this->search->steamIdsOfIgdbGame((int) $card->igdb_id);

        if (!$linked || in_array($steamId, $linked, true)) {
            return false;
        }

        $store = $this->search->getGameFromSteam($steamId);

        return !(($store['demo_steam_id'] ?? null) === $steamId && in_array((string) ($store['steam_id'] ?? ''), $linked, true));
    }
}
