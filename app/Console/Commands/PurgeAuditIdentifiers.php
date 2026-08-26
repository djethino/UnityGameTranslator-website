<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use Illuminate\Console\Command;

/**
 * Forget WHO, keep WHAT — twelve months after the fact.
 *
 * 🔴 **The audit log had no retention at all.** Every login, every upload, every token issued kept
 * its IP address for ever, in a table nothing ever reads back. That is the one clear breach in this
 * project's handling of personal data: not what is collected, but that it was collected without end.
 *
 * ### Why twelve months, and not a number we argued for
 *
 * It is the single figure that satisfies both bounds at once — the floor set by French hosting law
 * (loi n° 2004-575 du 21 juin 2004 and décret n° 2021-1362 du 20 octobre 2021, which is why a site
 * hosting translations its users publish keeps this log at all) and the ceiling the CNIL applies to
 * security logging. One number, nothing to justify either way.
 *
 * ### Why the column and not the row
 *
 * The obligation that ends at twelve months is the one about identifying a contributor. The rest of
 * the line — an account uploaded a translation on this date — is the memory of moderation, and it
 * has no reason to expire with it. So the event stays and the identifier goes.
 *
 * ⚠ Written as its own command rather than folded into `analytics:aggregate`, which already purges
 * two tables. Erasing personal data is not a step of a statistics job: somebody reading the
 * schedule must be able to see that it happens, and somebody removing the statistics must not
 * remove this with them.
 */
class PurgeAuditIdentifiers extends Command
{
    protected $signature = 'audit:purge-ips
                            {--months=12 : How long an identifier is kept}
                            {--dry-run : Count what would be cleared, write nothing}';

    protected $description = 'Clear IP addresses from audit log entries older than twelve months';

    public function handle(): int
    {
        $months = max(1, (int) $this->option('months'));
        $cutoff = now()->subMonths($months);

        $query = AuditLog::whereNotNull('ip_address')->where('created_at', '<', $cutoff);

        if ($this->option('dry-run')) {
            $this->info("Would clear {$query->count()} identifier(s) older than {$cutoff->toDateString()}.");

            return Command::SUCCESS;
        }

        // ⚠ update(), never delete(): the line is kept, only the identifier goes. And no touch() —
        // updated_at does not exist on this table, and the moment an event happened must never be
        // rewritten by the job that forgets who caused it.
        $cleared = $query->update(['ip_address' => null]);

        $this->info($cleared > 0
            ? "Cleared {$cleared} identifier(s) older than {$cutoff->toDateString()}."
            : 'Nothing to clear.');

        return Command::SUCCESS;
    }
}
