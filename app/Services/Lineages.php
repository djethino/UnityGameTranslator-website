<?php

namespace App\Services;

use App\Models\Translation;
use Illuminate\Support\Facades\DB;

/**
 * Where each lineage stands: what it has been offered, what it took, what it left waiting.
 *
 * 🔴 **An upload is a birth; a lineage has a life, and nothing measured it.** The analytics page
 * counted translations as they were created and never looked at them again — so a Main that has
 * been maintained for a year, has taken twelve contributions and spawned three forks looked exactly
 * like one uploaded once and abandoned. Every signal needed was already stored and none was read.
 *
 * 🔴 **`merged_at` exists precisely for the question this answers.** Its own migration says why:
 * without it, "the Main publishes and never merges you" — the most discouraging thing that can
 * happen to a contributor — was indistinguishable from "it merged you and moved on". This is the
 * first screen to ask.
 *
 * ⚠ **A standing, not a flow — so it does NOT follow the period selector**, and it lives outside the
 * section that one drives. Filtering by span would be worse than useless here: a branch left waiting
 * for six months is the one that matters most, and a 30-day window is exactly what would hide it.
 *
 * ⚠ **"Waiting", never "worst".** The figure names what can be done — a Main with contributions
 * pending can be nudged — where a league table of the worst names somebody to blame and suggests
 * nothing. Same reading, and only one of the two is an instrument.
 */
class Lineages
{
    /**
     * Mains ranked by the contributions they have drawn, the ones left waiting first.
     *
     * ⚠ Ordered on what is pending, then on what has been received: the top of this list is where
     * somebody is waiting for an answer, which is the only part of it that asks for an action.
     */
    public static function standing(int $limit = 10): \Illuminate\Support\Collection
    {
        // Branches per Main, and how many of them were taken. A branch carries its parent's id and
        // its own merged_at, so one pass over the branches answers both.
        $branches = DB::table('translations')
            ->where('visibility', '!=', 'public')
            ->whereNotNull('parent_id')
            ->selectRaw('parent_id, COUNT(*) as received, SUM(CASE WHEN merged_at IS NULL THEN 0 ELSE 1 END) as taken, SUM(merged_lines_total) as lines_taken')
            ->groupBy('parent_id');

        // ⚠ Forks are counted through `origin_translation_id`, never `parent_id`: a fork leaves the
        // lineage and takes a new uuid, so grouping by parent loses it entirely — the mistake that
        // once made a "Community Forks" list show branches instead.
        $forks = DB::table('translations')
            ->whereNotNull('origin_translation_id')
            ->where('visibility', 'public')
            ->selectRaw('origin_translation_id, COUNT(*) as forks')
            ->groupBy('origin_translation_id');

        return Translation::query()
            ->where('translations.visibility', 'public')
            ->leftJoinSub($branches, 'b', 'b.parent_id', '=', 'translations.id')
            ->leftJoinSub($forks, 'f', 'f.origin_translation_id', '=', 'translations.id')
            ->select('translations.*')
            ->selectRaw('COALESCE(b.received, 0) as branches_received')
            ->selectRaw('COALESCE(b.taken, 0) as branches_taken')
            ->selectRaw('COALESCE(b.received, 0) - COALESCE(b.taken, 0) as branches_waiting')
            ->selectRaw('COALESCE(b.lines_taken, 0) as lines_taken')
            ->selectRaw('COALESCE(f.forks, 0) as forks')
            // Only lineages that have drawn something: a Main nobody has ever contributed to or
            // forked says nothing here, and there are far more of those than of the rest.
            ->havingRaw('branches_received > 0 OR forks > 0')
            ->orderByDesc('branches_waiting')
            ->orderByDesc('branches_received')
            ->orderByDesc('forks')
            ->with(['game', 'user'])
            ->limit($limit)
            ->get();
    }
}
