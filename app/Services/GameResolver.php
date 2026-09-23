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
    public function __construct(private GameSearchService $search)
    {
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
            $game = Game::whereRaw('LOWER(name) = ?', [strtolower($gameName)])->first();
            if ($game) {
                return ['game' => $game, 'via' => 'name', 'external' => null];
            }

            $game = Game::where('unity_name', $gameName)->first();
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
            // findOrCreateGame: one game is one card, wherever the copy came from.
            $known = Game::query()
                ->when($resolvedSteamId, fn ($q) => $q->answeringToSteamId($resolvedSteamId))
                ->when(!$resolvedSteamId, fn ($q) => $q->whereRaw('LOWER(name) = ?', [strtolower($title)]))
                ->first();

            if ($known) {
                return ['game' => $known, 'via' => 'external', 'external' => $external];
            }
        }

        return ['game' => null, 'via' => null, 'external' => $external];
    }
}
