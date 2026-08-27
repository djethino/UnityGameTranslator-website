<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * In-app notifications: bell count (AJAX poll), list page, mark as read.
 * The list page is server-rendered; only the badge count is polled.
 */
class NotificationController extends Controller
{
    /**
     * Full notifications page (localized, server-rendered).
     */
    public function index(Request $request)
    {
        $notifications = $request->user()
            ->notifications()
            ->paginate(20);

        return view('notifications.index', [
            'notifications' => $notifications,
        ]);
    }

    /**
     * Unread count for the header bell (AJAX poll).
     */
    public function count(Request $request)
    {
        return response()->json([
            'unread' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    /**
     * Mark one notification as read.
     */
    public function markRead(Request $request, string $id)
    {
        $notification = $request->user()->notifications()->where('id', $id)->first();
        if ($notification) {
            $notification->markAsRead();
        }

        if ($request->expectsJson()) {
            return response()->json(['success' => true]);
        }

        return back();
    }

    /**
     * Remove one notification.
     *
     * 🔴 There was no way to remove any, by anybody. Marking read was the only act, so the list
     * only ever grew and the sole way to be rid of it was to delete the account.
     *
     * ⚠ Safe to remove because every notification that reports a state STILL TRUE is also said
     * where the state lives, recomputed rather than stored: a frozen branch and an orphaned one
     * wear a chip on their row in My translations, a delisted Main and an empty published file
     * each have their banner there, and waiting contributions are counted on the merge button.
     * Deleting the message loses the message, never the fact. The two that report a past event —
     * a branch merged, an announcement — have nothing to outlive them.
     *
     * ⚠ Scoped through the relation, like markRead: the id is a uuid, but scoping is what makes
     * that an implementation detail rather than the thing standing between two accounts.
     */
    public function destroy(Request $request, string $id)
    {
        $request->user()->notifications()->where('id', $id)->delete();

        if ($request->expectsJson()) {
            return response()->json(['success' => true]);
        }

        return back()->with('success', __('notif.deleted'));
    }

    /**
     * Mark all notifications as read.
     */
    public function markAllRead(Request $request)
    {
        $request->user()->unreadNotifications->markAsRead();

        if ($request->expectsJson()) {
            return response()->json(['success' => true]);
        }

        return back();
    }
}
