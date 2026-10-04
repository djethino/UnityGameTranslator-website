<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Game;
use App\Models\Translation;
use App\Models\User;

/**
 * Which game a lineage is filed under — and who may change it.
 *
 * 🔴 **The person who published under the wrong game had no way out** (analyse/
 * identite-des-jeux-parcours.md, T22): deleting and publishing again landed on the same wrong card
 * every time, because the card outlived the translation and the same search found it again. The
 * game of a translation is fixed by the upload that creates it (every later upload keeps it), so
 * changing it is an act of its own, here.
 *
 * Decided with the user (2026-10-02):
 *
 * | who | may |
 * |---|---|
 * | the owner of a Main | move it to any game picked in the list — and its contributions follow |
 * | the owner of a fork | only follow the game of the translation it was forked from: having forked, the original's game was the right one; this is the way out when the ORIGINAL was moved since |
 * | a branch author | nothing — a branch is filed with its Main |
 * | an admin, from /admin | any of the above, freely |
 *
 * ⚠ **The whole lineage moves, never one row**: Main and branches share a uuid, and a lineage split
 * across two cards would show half its contributions on each. A fork has a uuid of its own, so it is
 * a lineage of its own and stays where it is when its original moves.
 */
class LineageGame
{
    /** Free to pick any game. */
    public const AnyGame = 'any';

    /** Only the game of the translation it was forked from. */
    public const OriginalsGame = 'original';

    /**
     * What `$user` may do about the game of `$translation`: AnyGame, OriginalsGame, or null.
     *
     * ⚠ `$fromAdmin` is the ROUTE, not the role: the admin's freedom exists only from /admin
     * (CLAUDE.md, "Who can WRITE on the server") — on the public page an admin is an ordinary owner.
     */
    public static function mayChange(Translation $translation, User $user, bool $fromAdmin): ?string
    {
        if ($fromAdmin && $user->isAdmin()) {
            return self::AnyGame;
        }

        if ($translation->user_id !== $user->id || $translation->visibility !== 'public' || $translation->parent_id !== null) {
            return null;
        }

        return $translation->origin_translation_id ? self::OriginalsGame : self::AnyGame;
    }

    /** The game of the translation `$fork` was forked from, when it still exists. */
    public static function originalsGame(Translation $fork): ?Game
    {
        return $fork->origin_translation_id
            ? Translation::with('game')->find($fork->origin_translation_id)?->game
            : null;
    }

    /**
     * File `$translation`'s whole lineage under `$to`. Returns how many rows moved (0 when it is
     * already there).
     *
     * ⚠ **No date moves.** Changing where a translation is filed is not a change to its content:
     * `updated_at` would reorder every listing sorted by freshness. Written without timestamps.
     */
    public function move(Translation $translation, Game $to, User $actor, string $how): int
    {
        $from = $translation->game;

        if ($from && $from->id === $to->id) {
            return 0;
        }

        $rows = Translation::where('file_uuid', $translation->file_uuid)->pluck('id');

        Translation::whereIn('id', $rows)->toBase()->update(['game_id' => $to->id]);

        // Where a translation is filed decides which players are ever offered it: a change here
        // is invisible everywhere else, so it is traced — who, from which card to which.
        AuditLog::log('translation.game_changed', $actor->id, 'Translation', $translation->id, [
            'how' => $how,
            'from' => $from ? ['id' => $from->id, 'name' => $from->name] : null,
            'to' => ['id' => $to->id, 'name' => $to->name],
            'rows' => $rows->all(),
        ]);

        return $rows->count();
    }
}
