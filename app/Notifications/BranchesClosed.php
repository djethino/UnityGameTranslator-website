<?php

namespace App\Notifications;

use App\Models\Translation;
use Illuminate\Notifications\Notification;

/**
 * Sent to a contributor when the Main they contribute to stops accepting contributions.
 *
 * 🔴 **Nothing about their side changes visibly, and that is why this exists.** Their file still
 * opens, still translates, still saves. What has gone is the road it was on: it can no longer be
 * sent, and its details can no longer be edited. Without a word, they would find out at the moment
 * they tried to publish — after the work, never before.
 *
 * ⚠ **Not a reproach, and the wording must not read as one.** Keeping a translation open to
 * contributions is work nobody agreed to by publishing, and an author is entitled to stop. What
 * the contributor needs is the fact and the way out, not a grievance.
 *
 * The way out is the same as for an orphaned branch: publish it as a translation of its own. Also
 * deliberately never done automatically — an uuid is an identity, not a detail.
 */
class BranchesClosed extends Notification
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
            'type' => 'branches_closed',
            'translation_id' => $this->branch->id,
            'uuid' => $this->branch->file_uuid,
            'game_name' => $this->branch->game?->name,
            'game_slug' => $this->branch->game?->slug,
            'target_language' => $this->branch->target_language,
            'owner_username' => $this->branch->getMain()?->user?->name,
        ];
    }
}
