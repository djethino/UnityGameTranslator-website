<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Translation;
use App\Services\TranslationService;
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
    public function __construct(private readonly TranslationService $translationService)
    {
    }

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
            'lineage' => 'nullable|in:0,1',
        ]);

        $uuid = $request->query('uuid');
        $clientHash = $request->query('hash');

        // 🔴 **`lineage=0` asks only about the caller's OWN line.** What other people are doing —
        // contributions received, a Main that moved — is weighed against their files, and a stream
        // re-asks this on every push anybody makes to the lineage. A Main whose contributor
        // publishes every ten minutes would pay that read every ten minutes, for nothing they can
        // act on in the moment.
        //
        // ⚠ Included by DEFAULT: a mod that predates the parameter must go on receiving what it
        // has always received. The three fields are then simply ABSENT, never zero — a client that
        // asked not to be told has not been told there is nothing.
        $withLineage = $request->query('lineage') !== '0';

        $state = $this->buildSyncState($uuid, $request->user(), $clientHash, $withLineage);

        return response()->json($state);
    }

    /**
     * Build the combined sync state.
     * Extracted from SseController for reuse as REST endpoint.
     */
    private function buildSyncState(string $uuid, \App\Models\User $user, ?string $clientHash,
                                    bool $withLineage = true): array
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

        // 🔴 At the TOP level, because it is a fact about the LINEAGE and not about the caller's
        // own row. It used to be sent only inside `translation`, which exists only for somebody
        // who has published into this lineage — so the one person who most needs the answer, the
        // player holding somebody else's translation and wondering whether they may send their
        // corrections back, was the only one never told.
        //
        // Null when nothing of this lineage is published: there is no Main to have decided.
        $state['accepts_branches'] = $publicTranslation
            ? (bool) $publicTranslation->accepts_branches
            : null;

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
                if ($withLineage) {
                    $branches = Translation::where('file_uuid', $uuid)
                        ->where('visibility', 'branch')
                        ->get(['id', 'file_hash', 'reviewed_hash']);

                    $state['branches_count'] = $branches->count();

                    // A count alone says nothing: it does not move when a contributor pushes new
                    // work to a branch already counted. What the Main owner needs to hear about is
                    // what has not been reviewed yet AND is holding something — see
                    // contributionsWaiting, where both filters are applied and explained.
                    //
                    // 🔴 **`branches_pending_review` is that same number, deliberately.** It used
                    // to count every unreviewed contribution, empty ones included, and it drives
                    // the status overlay's notice — so a published mod announced work that did not
                    // exist. Two fields describing one thing must not be free to differ: a player
                    // would read one figure on the overlay and another on the button beside it.
                    $waiting = $this->translationService->contributionsWaiting($ownTranslation);
                    $state['branches_pending_review'] = $waiting['branches'];
                    $state['branches_with_work'] = $waiting['branches'];
                    $state['lines_available'] = $waiting['lines'];

                    // 🔴 **What there is to look at, and what it is made of.** `lines_available`
                    // above is what would be TAKEN; this is what needs a decision — new lines plus
                    // lines both sides hold differently, the Main's own included. Neither can be
                    // derived from the other, and a review is weighed on both: how long it takes,
                    // and whether anything comes out of it.
                    //
                    // Each broken down by the contribution's tag, which is what says it is worth
                    // the evening — 21 new lines written by hand is not 21 the machine produced.
                    // Additive: an older mod ignores the field and prints the total alone.
                    $state['lines_waiting'] = [
                        'review' => $waiting['review'],
                        'take' => $waiting['lines'],
                        'new' => $waiting['new'],
                        'differing' => $waiting['differing'],
                    ];
                }
            } elseif ($withLineage) {
                // A branch used to learn nothing about the Main it derives from:
                // this block stopped here, so `main` stayed null and the branch
                // diverged in silence, however long the Main kept moving.
                // Additive field: an older mod simply ignores it.
                //
                // ⚠ **Part of the lineage, not of one's own line** — so a stream does not carry it.
                // A Main publishing every ten minutes signals the lineage every time, and each
                // signal would tell every contributor connected that upstream moved. That belongs
                // to the rhythm they chose, not to the second it happened.
                $main = $publicTranslation?->loadMissing('user:id,name');

                // 🔴 **What became of the Main, told without being asked for** (2026-08-20).
                //
                // These three answered on check-uuid alone, and the mod calls that endpoint from
                // ONE place: the publish screen. So a contributor whose Main had been deleted, or
                // who was being ignored, learned it at the moment they tried to publish — after
                // the work, which is the one moment it is too late to be useful. Nothing on their
                // side shows it either: the file still opens and still saves.
                //
                // ⚠ Additive: a mod that does not read them behaves exactly as before.
                $state['main_missing'] = $publicTranslation === null;
                $state['main_ignoring'] = $ownTranslation->mainIgnoresContributions();
                $state['merged_lines_total'] = $ownTranslation->merged_lines_total;

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

            // 🔴 **Absent, not zero.** branches_count is initialised to 0 in the skeleton above, so
            // leaving it in a narrow answer would state "nobody contributes" — a claim, and a false
            // one. The client keeps what it already knew only when the key is missing; present at
            // zero, it overwrites the real count with a lie.
            //
            // ⚠ Found by calling the endpoint, not by the tests: they asserted the value was 0,
            // which is exactly the bug written down as an expectation.
            if (!$withLineage) {
                unset($state['branches_count']);
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
