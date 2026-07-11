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
            'announcement' => $data['link'] ?? url('/notifications'),
            default => null,
        };
    }
}
