<?php

namespace App\Console\Commands;

use App\Models\ApiToken;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Cut the accesses nobody uses any more.
 *
 * ### Why this is the only thing that ever cleans up
 *
 * A site cannot tell a forgotten credential from a quiet one. Signing out now hands the access back
 * — the mod and the Manager both do it — but that only reaches installations that update, and a
 * fraction never will. Everything else leaves the line behind for ever: a wiped config, a
 * reinstall, a machine given away, a game uninstalled without a thought for the account.
 *
 * ⚠ **It is not a security measure and must not be sold as one.** A stolen access that is being
 * USED stays alive, because being used is exactly what keeps it here. What this does is keep the
 * list short — and a short list is what makes an access somebody does not recognise stand out.
 * The security gain is second-hand, and real for that reason.
 *
 * ### The grace period, which is the whole difficulty
 *
 * Deploying a six-month rule against a table that has never had one would revoke, on the first
 * night and without a word, every access dormant for longer than that. So the clock starts at
 * {@see GRACE_FROM} rather than at whatever `last_used_at` says:
 *
 *     cut on = MAX(last exchange, grace start) + six months
 *
 * 🔴 **A calculation, never a write.** Stamping the grace date into `last_used_at` would be the
 * obvious shortcut and it would corrupt the record: the screen would announce "exchange this month"
 * for an access untouched in a year. On a page about deciding what to cut, that is the worst
 * possible place to be wrong. `last_used_at` keeps saying what happened; the deadline is worked out
 * beside it.
 *
 * ⚠ `GRACE_FROM` only has to be in the past and identical everywhere the code runs — it is the day
 * the rule was written, not the day it was deployed. Deploying later shortens the grace to whatever
 * remains, never below zero, and nothing is cut before the six months have run from it.
 */
class PurgeIdleTokens extends Command
{
    /**
     * When the six-month clock starts for an access that has been quiet since before the rule
     * existed. Written the day the rule was.
     */
    private const GRACE_FROM = '2026-08-27';

    protected $signature = 'tokens:purge-idle
                            {--months=6 : How long an access may stay unused}
                            {--dry-run : Count what would be cut, delete nothing}';

    protected $description = 'Revoke access tokens unused for six months';

    public function handle(): int
    {
        $months = max(1, (int) $this->option('months'));
        $cutoff = now()->subMonths($months);
        $grace = Carbon::parse(self::GRACE_FROM);

        // Nothing can be cut until six months have run from the grace date, whatever the rows say.
        if ($grace->greaterThan($cutoff)) {
            $this->info("Grace period runs until {$grace->copy()->addMonths($months)->toDateString()}. Nothing cut.");

            return Command::SUCCESS;
        }

        // ⚠ COALESCE, because a token that was issued and never used has a null last exchange — and
        // "never used" is the emptiest line of all, not a reason to keep it for ever.
        $query = ApiToken::whereRaw('COALESCE(last_used_at, created_at) < ?', [$cutoff]);

        if ($this->option('dry-run')) {
            $this->info("Would cut {$query->count()} access(es) unused since {$cutoff->toDateString()}.");

            return Command::SUCCESS;
        }

        $cut = $query->delete();

        $this->info($cut > 0
            ? "Cut {$cut} access(es) unused since {$cutoff->toDateString()}."
            : 'Nothing to cut.');

        return Command::SUCCESS;
    }

    /**
     * When an access would be cut if nothing else touched it — the figure the Linked devices screen
     * shows beside each line.
     *
     * ⚠ Read from the token, computed here, stored nowhere. Two places working the same rule out
     * separately is how they come to disagree.
     */
    public static function deadlineFor(ApiToken $token, int $months = 6): Carbon
    {
        $from = $token->last_used_at ?? $token->created_at;
        $grace = Carbon::parse(self::GRACE_FROM);

        return ($from === null || $grace->greaterThan($from) ? $grace : $from)
            ->copy()
            ->addMonths($months);
    }
}
