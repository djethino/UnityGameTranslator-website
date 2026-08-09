<?php

namespace App\Notifications;

use App\Models\Translation;
use Illuminate\Notifications\Notification;

/**
 * Sent to the AUTHOR of a translation that has just left the public catalogue: published without
 * a single translated line, and past its grace period.
 *
 * They have already been told twice — a banner on their translations page, and a warning in the
 * game itself. What this adds is the moment: those two only speak to somebody who came back on
 * their own, and this one is aimed at somebody who did not.
 *
 * It carries the count of branches still waiting, because that is the part they cannot see from
 * outside and the part that changes the decision: a file nobody contributed to is theirs alone to
 * revive or forget, while three contributors waiting is a reason to come back.
 */
class TranslationDelisted extends Notification
{
    public function __construct(
        private readonly Translation $translation,
        private readonly int $waitingBranches,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'translation_delisted',
            'translation_id' => $this->translation->id,
            'uuid' => $this->translation->file_uuid,
            'game_name' => $this->translation->game?->name,
            'target_language' => $this->translation->target_language,
            'waiting_branches' => $this->waitingBranches,
        ];
    }
}
