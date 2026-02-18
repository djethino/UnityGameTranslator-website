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
     *   "has_update": false
     * }
     */
    public function show(Request $request): JsonResponse
    {
        $request->validate([
            'uuid' => 'required|string|max:36',
            'hash' => 'nullable|string|max:100',
        ]);

        $uuid = $request->query('uuid');
        $userId = $request->user()->id;
        $clientHash = $request->query('hash');

        $state = $this->buildSyncState($uuid, $userId, $clientHash);

        return response()->json($state);
    }

    /**
     * Build the combined sync state.
     * Extracted from SseController for reuse as REST endpoint.
     */
    private function buildSyncState(string $uuid, int $userId, ?string $clientHash): array
    {
        $state = [
            'exists' => false,
            'role' => 'none',
            'translation' => null,
            'main' => null,
            'branches_count' => 0,
            'has_update' => false,
        ];

        // Check if current user owns a translation with this UUID
        $ownTranslation = Translation::where('file_uuid', $uuid)
            ->where('user_id', $userId)
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
                'line_count' => $ownTranslation->line_count,
                'file_hash' => $ownTranslation->file_hash,
                'vote_count' => $ownTranslation->vote_count,
                'updated_at' => $ownTranslation->updated_at->toIso8601String(),
            ];

            if ($role === 'main') {
                $state['branches_count'] = Translation::where('file_uuid', $uuid)
                    ->where('visibility', 'branch')
                    ->count();
            }

            if ($clientHash) {
                $state['has_update'] = $ownTranslation->file_hash !== $clientHash;
            }

            return $state;
        }

        // Check if Main exists with this UUID (user would become branch)
        $mainTranslation = Translation::where('file_uuid', $uuid)
            ->where('visibility', 'public')
            ->with('user:id,name')
            ->orderBy('created_at', 'asc')
            ->first();

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
