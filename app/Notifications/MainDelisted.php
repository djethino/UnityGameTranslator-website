<?php

namespace App\Notifications;

use App\Models\Translation;
use Illuminate\Notifications\Notification;

/**
 * Sent to a CONTRIBUTOR whose branch hangs from a translation that has just left the catalogue.
 *
 * Their situation is not the author's, so neither is the message. They did nothing wrong, their
 * work is intact, and the Main can still merge it — but no player will find it while it sits
 * behind an empty translation, and they have no say in when that changes.
 *
 * The way out named here is the one that takes a single click IN THE MOD: forking there
 * republishes the file under their own name, from the copy they already have in hand. The site
 * offers the same thing, but only after downloading and re-uploading.
 */
class MainDelisted extends Notification
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
            'type' => 'main_delisted',
            'translation_id' => $this->branch->id,
            'uuid' => $this->branch->file_uuid,
            'game_name' => $this->branch->game?->name,
            'target_language' => $this->branch->target_language,
        ];
    }
}
