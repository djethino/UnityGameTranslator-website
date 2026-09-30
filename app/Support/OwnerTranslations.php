<?php

namespace App\Support;

use App\Models\Game;
use App\Models\Translation;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Every translation one account holds — Mains, branches, forks — as its author reads them on
 * "My translations" and as an admin reads them on that account's page.
 *
 * 🔴 **One list for the two screens.** The admin page is the same list seen by somebody else; if it
 * sorted or counted differently, an admin comparing it with what the author describes would be
 * comparing two different things.
 */
final class OwnerTranslations
{
    /** The orders offered, first one the default. Same words on both screens (my_translations.sort.*). */
    public const SORTS = ['updated', 'new', 'game', 'downloads', 'review'];

    public static function sortOf(?string $asked): string
    {
        return in_array($asked, self::SORTS, true) ? $asked : self::SORTS[0];
    }

    /**
     * Sorting, same vocabulary as the games list — with one option that only makes sense on one
     * author's files: what is left to read. That is the list an author actually works from.
     *
     * Default is "recently worked on" rather than "recently uploaded": the reason to open the
     * list is to carry on, and the file touched last is the one carried on with. It reads
     * content_updated_at, never updated_at — a vote or a download must not float a translation
     * back to the top as if its author had just worked on it.
     */
    public static function of(User $user, string $sort): Collection
    {
        $query = $user->translations()->with(['game', 'forks', 'user']);

        match ($sort) {
            'new' => $query->orderByDesc('created_at'),
            'downloads' => $query->orderByDesc('download_count'),
            'review' => $query->orderByDesc('ai_count'),
            'game' => $query->orderBy(
                Game::select('name')->whereColumn('games.id', 'translations.game_id')
            ),
            default => $query->orderByRaw('COALESCE(content_updated_at, updated_at) DESC'),
        };

        // Two files differing only by language must not swap places between page loads, and
        // sorting by game puts a game's languages in an order anyone can predict.
        return $query->orderBy('target_language')->orderByDesc('id')->get();
    }

    /**
     * Branches waiting on each listed Main, keyed by file_uuid, in one query. A branch waits when
     * it was never reviewed or has changed since it was.
     */
    public static function waitingBranches(Collection $translations): array
    {
        $mainUuids = $translations->filter(fn ($t) => $t->isMain())->pluck('file_uuid')->unique();

        if ($mainUuids->isEmpty()) {
            return [];
        }

        return Translation::whereIn('file_uuid', $mainUuids)
            ->where('visibility', 'branch')
            ->where(function ($q) {
                $q->whereNull('reviewed_hash')
                  ->orWhereColumn('file_hash', '!=', 'reviewed_hash');
            })
            ->selectRaw('file_uuid, COUNT(*) as count')
            ->groupBy('file_uuid')
            ->pluck('count', 'file_uuid')
            ->toArray();
    }

    /**
     * How far the furthest translation of each listed game reaches, asked once for the whole list:
     * the coverage badge needs it, and the model would otherwise run its own MAX per card.
     */
    public static function gameMaxes(Collection $translations): array
    {
        return Translation::maxResolvedLinesByGame($translations->pluck('game_id'));
    }
}
