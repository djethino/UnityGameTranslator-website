<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Translation;
use App\Services\TranslationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function __construct(private readonly TranslationService $translationService)
    {
    }

    /**
     * Get the authenticated user's profile.
     *
     * GET /api/v1/me
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'created_at' => $user->created_at->toIso8601String(),
        ]);
    }

    /**
     * Get the authenticated user's translations.
     *
     * GET /api/v1/me/translations
     *
     * Carries each file's lineage and the position its owner holds in it, so a client can place a
     * whole library in ONE call. check-uuid answers the same question for a single uuid, which is
     * right before an upload and wrong for a manager: the installer sees fifty games at once, and
     * fifty calls against a 60-per-minute budget would starve everything else the account does.
     *
     * Every added field is additive — the mod reads none of them and is unaffected.
     */
    public function translations(Request $request): JsonResponse
    {
        $user = $request->user();

        $translations = $user->translations()
            ->with('game:id,name,slug,steam_id')
            ->orderBy('updated_at', 'desc')
            ->get();

        // Two grouped queries rather than two per translation: a prolific author has hundreds of
        // rows, and this endpoint is called on every refresh.
        $uuids = $translations->pluck('file_uuid')->filter()->unique();

        $branchCounts = Translation::whereIn('file_uuid', $uuids)
            ->branches()
            ->selectRaw('file_uuid, COUNT(*) as total')
            ->groupBy('file_uuid')
            ->pluck('total', 'file_uuid');

        // The lineages that still have something published. A branch missing from this set is a
        // branch of nothing: its Main was deleted or unpublished, and no screen would ever have
        // said so — the author keeps contributing upstream to a head that no longer exists.
        //
        // ⚠ Carries the Main's decision alongside, rather than asking row by row: getMain() is a
        // query, and a listing that runs one per line is a listing that gets slower the more
        // somebody contributes.
        $mains = Translation::whereIn('file_uuid', $uuids)
            ->public()
            ->pluck('accepts_branches', 'file_uuid');
        $withMain = $mains->keys()->flip();

        return response()->json([
            'count' => $translations->count(),
            'translations' => $translations->map(function ($t) use ($branchCounts, $withMain, $mains) {
                $role = $t->lineageRole();

                return [
                    'id' => $t->id,
                    'game' => [
                        'id' => $t->game->id,
                        'name' => $t->game->name,
                        'slug' => $t->game->slug,
                        'steam_id' => $t->game->steam_id,
                    ],
                    // The lineage identifier: what a local translations.json calls _uuid, and the
                    // only way a client can tell that a file on disk is this very row.
                    'file_uuid' => $t->file_uuid,
                    'role' => $role,
                    // Contributions waiting on a Main owner. Null on a branch rather than 0: a
                    // branch has no branches to answer about, which is not the same as none.
                    'branches_count' => $role === 'main'
                        ? (int) ($branchCounts[$t->file_uuid] ?? 0)
                        : null,

                    // How many of them are actually holding something, and how many lines that is.
                    // ⚠ **Only asked when there is a contribution to weigh**: this reads files, and
                    // a listing that opens one per row is a listing that gets slower the more
                    // somebody publishes. A Main with no branch costs nothing, which is the common
                    // case; the answer is then cached on the files' own hashes.
                    'branches_with_work' => $role === 'main' && ($branchCounts[$t->file_uuid] ?? 0) > 0
                        ? $this->translationService->contributionsWaiting($t)['branches']
                        : ($role === 'main' ? 0 : null),
                    'lines_available' => $role === 'main' && ($branchCounts[$t->file_uuid] ?? 0) > 0
                        ? $this->translationService->contributionsWaiting($t)['lines']
                        : ($role === 'main' ? 0 : null),
                    'main_missing' => $role === 'branch'
                        ? !$withMain->has($t->file_uuid)
                        : null,

                    // Whether this lineage takes contributions: its own answer on a Main, the
                    // Main's on a branch. Both read the same lineage, so both must say the same
                    // thing — a contributor's card and its Main's card are two views of one fact.
                    'accepts_branches' => $role === 'main'
                        ? (bool) $t->accepts_branches
                        : (bool) ($mains[$t->file_uuid] ?? false),

                    // 🔴 A contribution whose Main has closed since. Told without being asked for,
                    // because there is nothing on the contributor's side to notice: the file still
                    // opens and still saves, and only the road it was on has gone.
                    //
                    // ⚠ Null off a branch, like main_missing above — "not a branch" is not "fine".
                    // And false on an orphan: nobody refused them anything.
                    'branch_frozen' => $role === 'branch'
                        ? $withMain->has($t->file_uuid) && !($mains[$t->file_uuid] ?? false)
                        : null,
                    'source_language' => $t->source_language,
                    'target_language' => $t->target_language,
                    'line_count' => $t->line_count,
                    'type' => $t->type,
                    'status' => $t->status,
                    'vote_count' => $t->vote_count,
                    'download_count' => $t->download_count,
                    'file_hash' => $t->file_hash,
                    'updated_at' => $t->updated_at->toIso8601String(),
                ];
            }),
        ]);
    }
}
