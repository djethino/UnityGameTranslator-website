<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Translation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * REST endpoint for sync state.
 * Called by the Node.js SSE server on each client connection to get initial state.
 * Replaces the inline buildSyncState() that was in SseController.php.
 *
 * GET /api/v1/sync/state?uuid=xxx&hash=yyy
 * Requires Bearer authentication (forwarded from Unity mod via Node.js).
 */
class SyncStateController extends Controller
{
    /**
     * Get the combined sync state for a UUID.
     * Combines the logic of check-uuid + check in one payload.
     *
     * Response JSON:
     * {
     *   "exists": true,
     *   "role": "main"|"branch"|"none",
     *   "translation": { id, source_language, target_language, type, notes, line_count, file_hash, vote_count, updated_at } | null,
     *   "main": { id, uploader, source_language, target_language, line_count, file_hash, updated_at } | null,
     *   "branches_count": 0,
     *   "has_update": false,
     *   "vote": { target_id, count, user_vote, can_vote } | null
     * }
     */
    public function show(Request $request): JsonResponse
    {
        $request->validate([
            'uuid' => 'required|string|max:36',
            'hash' => 'nullable|string|max:100',
        ]);

        $uuid = $request->query('uuid');
        $clientHash = $request->query('hash');

        $state = $this->buildSyncState($uuid, $request->user(), $clientHash);

        return response()->json($state);
    }

    /**
     * Build the combined sync state.
     * Extracted from SseController for reuse as REST endpoint.
     */
    private function buildSyncState(string $uuid, \App\Models\User $user, ?string $clientHash): array
    {
        $state = [
            'exists' => false,
            'role' => 'none',
            'translation' => null,
            'main' => null,
            'branches_count' => 0,
            'has_update' => false,
            'vote' => null,
        ];

        // Votes on the PUBLISHED translation of this lineage — the one the player is actually
        // running, and the one the ranking ranks. Resolved whatever the caller's role: an
        // author needs the count of their own work, a player needs to be able to thank it.
        // Null when nothing of this lineage is published: no vote to show, not zero votes.
        $publicTranslation = Translation::where('file_uuid', $uuid)
            ->where('visibility', 'public')
            ->orderBy('created_at', 'asc')
            ->first();

        $state['vote'] = $publicTranslation?->voteStateFor($user);

        // Check if current user owns a translation with this UUID
        $ownTranslation = Translation::where('file_uuid', $uuid)
            ->where('user_id', $user->id)
            ->first();

        if ($ownTranslation) {
            $role = $ownTranslation->visibility === 'public' ? 'main' : 'branch';
            $state['exists'] = true;
            $state['role'] = $role;
            $state['translation'] = [
                'id' => $ownTranslation->id,
                'source_language' => $ownTranslation->source_language,
                'target_language' => $ownTranslation->target_language,
                'type' => $ownTranslation->type,
                'notes' => $ownTranslation->notes,
                'resources_url' => $ownTranslation->getEffectiveResourcesUrl(),
                'line_count' => $ownTranslation->line_count,
                'file_hash' => $ownTranslation->file_hash,
                'vote_count' => $ownTranslation->vote_count,
                'updated_at' => $ownTranslation->updated_at->toIso8601String(),

                // The Main's decision, sent on the stream the mod already reads at startup —
                // otherwise a game would only learn it by opening the upload panel, which is the
                // one moment it is too late to be useful.
                'accepts_branches' => $ownTranslation->lineageAcceptsBranches(),
                'branch_frozen' => $ownTranslation->isFrozenBranch(),
            ];

            if ($role === 'main') {
                $branches = Translation::where('file_uuid', $uuid)
                    ->where('visibility', 'branch')
                    ->get(['id', 'file_hash', 'reviewed_hash']);

                $state['branches_count'] = $branches->count();

                // A count alone says nothing: it does not move when a contributor
                // pushes new work to a branch already counted. What the Main owner
                // needs to hear about is what has NOT been reviewed yet — never
                // reviewed, or changed since the last review.
                $state['branches_pending_review'] = $branches
                    ->filter(fn($b) => !$b->reviewed_hash || $b->file_hash !== $b->reviewed_hash)
                    ->count();
            } else {
                // A branch used to learn nothing about the Main it derives from:
                // this block stopped here, so `main` stayed null and the branch
                // diverged in silence, however long the Main kept moving.
                // Additive field: an older mod simply ignores it.
                $main = $publicTranslation?->loadMissing('user:id,name');

                if ($main) {
                    $state['main'] = [
                        'id' => $main->id,
                        'uploader' => $main->user->name ?? '',
                        'source_language' => $main->source_language,
                        'target_language' => $main->target_language,
                        'line_count' => $main->line_count,
                        'file_hash' => $main->file_hash,
                        'updated_at' => $main->updated_at->toIso8601String(),
                    ];
                }
            }

            if ($clientHash) {
                $state['has_update'] = $ownTranslation->file_hash !== $clientHash;
            }

            return $state;
        }

        // Check if Main exists with this UUID (user would become branch)
        $mainTranslation = $publicTranslation?->loadMissing('user:id,name');

        if ($mainTranslation) {
            $state['exists'] = true;
            $state['main'] = [
                'id' => $mainTranslation->id,
                'uploader' => $mainTranslation->user->name,
                'source_language' => $mainTranslation->source_language,
                'target_language' => $mainTranslation->target_language,
                'line_count' => $mainTranslation->line_count,
                'file_hash' => $mainTranslation->file_hash,
                'updated_at' => $mainTranslation->updated_at->toIso8601String(),
            ];

            if ($clientHash) {
                $state['has_update'] = $mainTranslation->file_hash !== $clientHash;
            }
        }

        return $state;
    }
}
