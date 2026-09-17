<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Notifications for the mod: a compact summary the StatusOverlay can show.
 * Texts are pre-rendered in English (the mod's UI language); the mod's own
 * "translate mod UI" option can translate them on display like any UI text.
 */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $unread = $user->unreadNotifications()->limit(20)->get();

        $items = $unread->take(5)->map(function ($notification) {
            $data = $notification->data;

            return [
                'id' => $notification->id,
                'type' => $data['type'] ?? 'unknown',
                'text' => $this->summarize($data),
                'url' => $this->urlFor($data),
            ];
        })->values();

        return response()->json([
            'unread' => $user->unreadNotifications()->count(),
            'items' => $items,
        ]);
    }

    /**
     * Mark notifications as read: specific ids, or everything when omitted.
     */
    public function markRead(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => 'nullable|array|max:50',
            'ids.*' => 'string|max:64',
        ]);

        $query = $request->user()->unreadNotifications();
        if (!empty($validated['ids'])) {
            $query->whereIn('id', $validated['ids']);
        }
        $query->get()->markAsRead();

        return response()->json(['success' => true]);
    }

    private function summarize(array $data): string
    {
        return match ($data['type'] ?? '') {
            'branch_submitted' => sprintf(
                '%d contribution(s) to review on your %s translation (%s)',
                $data['count'] ?? 1,
                $data['game_name'] ?? '?',
                $data['target_language'] ?? '?',
            ),
            'branch_merged' => sprintf(
                '@%s merged %d of your line(s) into the %s translation',
                $data['owner_username'] ?? '?',
                $data['merged_count'] ?? 1,
                $data['game_name'] ?? '?',
            ),
            // 🔴 Both of these reach a contributor whose work has just lost its road, and both
            // fell through to the bare word "Notification" in the mod and the Manager — the two
            // places where somebody is most likely to be mid-session and least likely to go and
            // look it up. A summary that says nothing is worse than none: it costs a glance and
            // returns nothing.
            //
            // ⚠ Plain words, and the SAME words the mod's status card uses for the same wall
            // (common/Uploads.cs, Walls.BranchFrozen / Walls.MainMissing): the fact, then the way
            // out. "hung from is gone" and "carry on" read as riddles to a player whose fourth
            // language this is — and a notification is read in a corner, mid-game, in one glance.
            'branches_closed' => sprintf(
                '%s (%s): @%s no longer accepts contributions. Fork keeps your lines as your own version.',
                $data['game_name'] ?? '?',
                $data['target_language'] ?? '?',
                $data['owner_username'] ?? '?',
            ),
            'branch_orphaned' => sprintf(
                '%s (%s): the translation you contributed to was removed by its author. Fork keeps your lines as your own version.',
                $data['game_name'] ?? '?',
                $data['target_language'] ?? '?',
            ),
            'announcement' => (string) ($data['title'] ?? 'Announcement'),
            default => 'Notification',
        };
    }

    private function urlFor(array $data): ?string
    {
        return match ($data['type'] ?? '') {
            'branch_submitted' => !empty($data['uuid'])
                ? url('/translations/' . $data['uuid'] . '/merge')
                : null,
            'branch_merged' => !empty($data['game_slug'])
                ? url('/games/' . $data['game_slug'])
                : null,
            // Both land on the branch's own dashboard: it is where the way out — turning it into
            // a translation of its own — actually lives.
            'branches_closed', 'branch_orphaned' => !empty($data['translation_id'])
                ? url('/my-translations/' . $data['translation_id'] . '/dashboard')
                : url('/notifications'),
            'announcement' => $data['link'] ?? url('/notifications'),
            default => null,
        };
    }
}
