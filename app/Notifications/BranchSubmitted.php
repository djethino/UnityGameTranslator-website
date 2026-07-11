<?php

namespace App\Notifications;

use App\Models\Translation;
use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Notification;

/**
 * Sent to a Main owner when a contributor submits (or updates) a Branch.
 *
 * Anti-spam: grouped per lineage — if the owner already has an UNREAD
 * notification for the same UUID, it is updated in place (count bumped,
 * timestamp refreshed) instead of piling up new rows.
 */
class BranchSubmitted extends Notification
{
    public function __construct(
        private readonly Translation $main,
        private readonly string $contributorUsername,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'branch_submitted',
            'uuid' => $this->main->file_uuid,
            'game_name' => $this->main->game?->name,
            'target_language' => $this->main->target_language,
            'count' => 1,
            'last_contributor' => $this->contributorUsername,
        ];
    }

    /**
     * Send with grouping: update the existing unread notification for this
     * lineage instead of creating a new one.
     */
    public static function sendGrouped(User $owner, Translation $main, string $contributorUsername): void
    {
        $existing = $owner->unreadNotifications()
            ->where('type', self::class)
            ->get()
            ->first(fn(DatabaseNotification $n) => ($n->data['uuid'] ?? null) === $main->file_uuid);

        if ($existing) {
            $data = $existing->data;
            $data['count'] = ($data['count'] ?? 1) + 1;
            $data['last_contributor'] = $contributorUsername;
            $existing->update(['data' => $data, 'created_at' => now()]);
            return;
        }

        $owner->notify(new self($main, $contributorUsername));
    }
}
