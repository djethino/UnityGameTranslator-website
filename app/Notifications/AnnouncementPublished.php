<?php

namespace App\Notifications;

use App\Models\Announcement;
use Illuminate\Notifications\Notification;

/**
 * Admin announcement delivered to every user's in-app notifications.
 */
class AnnouncementPublished extends Notification
{
    public function __construct(
        private readonly Announcement $announcement,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'announcement',
            'announcement_id' => $this->announcement->id,
            'title' => $this->announcement->title,
            'body' => $this->announcement->body,
            'link' => $this->announcement->link,
        ];
    }
}
