<?php

namespace App\Notifications;

use App\Models\Translation;
use Illuminate\Notifications\Notification;

/**
 * Sent to a contributor when the Main their branch hangs from is deleted.
 *
 * This is the one state nobody else can fix. A branch needs a head to be merged into: with the
 * Main gone, no amount of waiting will make this work reachable, and re-uploading keeps it a
 * branch of a lineage that no longer has one. The way forward is to publish it as a translation
 * of its own — which the site offers on the branch's dashboard, and which is deliberately never
 * done automatically: an uuid is an identity, not a detail.
 *
 * Sent at the moment of deletion rather than found later, because from the contributor's side
 * nothing changes visibly — their file still opens, still translates, still saves.
 */
class BranchOrphaned extends Notification
{
    public function __construct(
        private readonly Translation $branch,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'branch_orphaned',
            'translation_id' => $this->branch->id,
            'uuid' => $this->branch->file_uuid,
            'game_name' => $this->branch->game?->name,
            'target_language' => $this->branch->target_language,
        ];
    }
}
