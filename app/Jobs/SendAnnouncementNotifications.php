<?php

namespace App\Jobs;

use App\Models\Announcement;
use App\Models\User;
use App\Notifications\AnnouncementPublished;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;

/**
 * Fan out an announcement to every user, in chunks so a growing user base
 * never builds one giant insert. Runs inline when the queue is sync.
 */
class SendAnnouncementNotifications implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private readonly Announcement $announcement,
    ) {
    }

    public function handle(): void
    {
        User::query()
            ->whereNull('banned_at')
            ->chunkById(500, function ($users) {
                Notification::send($users, new AnnouncementPublished($this->announcement));
            });
    }
}
