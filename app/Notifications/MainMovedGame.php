<?php

namespace App\Notifications;

use App\Models\Game;
use App\Models\Translation;
use Illuminate\Notifications\Notification;

/**
 * Sent to a contributor when the Main they contribute to is filed under another game.
 *
 * 🔴 **Their branch is held until they follow** (user, 2026-10-05: "ça doit bloquer la contribution
 * de la branche tant que la synchro de nom n'est pas faite"): it moved with its Main, and its next
 * upload must name the new game as the one its author confirmed (App\Services\LineageGame::move,
 * Api\TranslationController, `game_changed`). Without a word they would find out at the moment they
 * tried to publish — after the work, never before. They keep using the translation meanwhile.
 *
 * ⚠ The way out names a control of the mod and the Manager, `Switch game`, as it is written there:
 * those two are not translated, and the reader looks for those words on their screen.
 */
class MainMovedGame extends Notification
{
    public function __construct(
        private readonly Translation $branch,
        private readonly ?Game $from,
        private readonly Game $to,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'main_moved_game',
            'translation_id' => $this->branch->id,
            'uuid' => $this->branch->file_uuid,
            'from_game' => $this->from?->name,
            'game_name' => $this->to->name,
            'game_slug' => $this->to->slug,
            'target_language' => $this->branch->target_language,
            'owner_username' => $this->branch->getMain()?->user?->name,
        ];
    }
}
