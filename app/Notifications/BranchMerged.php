<?php

namespace App\Notifications;

use App\Models\Translation;
use Illuminate\Notifications\Notification;

/**
 * Sent to a Branch contributor when the Main owner merges lines
 * coming from their branch.
 */
class BranchMerged extends Notification
{
    public function __construct(
        private readonly Translation $main,
        private readonly int $mergedCount,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'branch_merged',
            'uuid' => $this->main->file_uuid,
            'game_name' => $this->main->game?->name,
            'game_slug' => $this->main->game?->slug,
            'target_language' => $this->main->target_language,
            'owner_username' => $this->main->user?->name,
            'merged_count' => $this->mergedCount,
        ];
    }
}
