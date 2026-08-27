<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Forget the messages, twelve months on.
 *
 * 🔴 **They had no retention at all.** A contribution arriving, a branch merged, a Main closing —
 * every one of them stayed for ever, in a table where nothing ever removed anything: there was not
 * even a way for the person concerned to delete one. The only exit was deleting the account. Same
 * shape as the audit log before it had a limit, and the same answer: not what is collected, but
 * that it was collected without end.
 *
 * ### Why twelve, and why one number
 *
 * The same figure the sign-in record already carries. One number in the retention table rather than
 * two is one thing to explain and one thing to check, and nothing here argues for a different
 * answer: a message about work done a year ago has told whoever it was for whatever it had to say.
 *
 * ### Why the row and not a column
 *
 * Nothing survives a notification worth keeping. Unlike the audit log, where the event stays and
 * only the identifier goes, the message IS the personal data — it names a game, a language, and on
 * a contribution the person who sent it.
 *
 * ⚠ **Read or unread makes no difference, deliberately.** A rule with two clocks is a rule nobody
 * can hold in their head, and an unread message a year old has not been read because nobody is
 * going to read it. What guards against losing something that still matters is not this command:
 * it is that every state STILL TRUE is said where the state lives — a chip on the row, a banner on
 * My translations, a count on the merge button — and recomputed rather than remembered.
 */
class PurgeOldNotifications extends Command
{
    protected $signature = 'notifications:purge
                            {--months=12 : How long a message is kept}
                            {--dry-run : Count what would go, delete nothing}';

    protected $description = 'Delete in-app notifications older than twelve months';

    public function handle(): int
    {
        $months = max(1, (int) $this->option('months'));
        $cutoff = now()->subMonths($months);

        $query = DB::table('notifications')
            ->where('notifiable_type', User::class)
            ->where('created_at', '<', $cutoff);

        if ($this->option('dry-run')) {
            $this->info("Would delete {$query->count()} notification(s) older than {$cutoff->toDateString()}.");

            return Command::SUCCESS;
        }

        $deleted = $query->delete();

        $this->info($deleted > 0
            ? "Deleted {$deleted} notification(s) older than {$cutoff->toDateString()}."
            : 'Nothing to delete.');

        return Command::SUCCESS;
    }
}
