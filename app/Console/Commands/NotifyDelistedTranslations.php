<?php

namespace App\Console\Commands;

use App\Models\Translation;
use App\Notifications\MainDelisted;
use App\Notifications\TranslationDelisted;
use Illuminate\Console\Command;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Tell people when a translation has left the public catalogue.
 *
 * Being delisted is computed, not stored: the rule reads the file's own counters and its
 * publication date, so it is always true of the present and one translated line undoes it with
 * nothing to clean up. That is the right design for a STATE — but it leaves no moment. On the
 * thirtieth day no code runs, and the banners only speak to somebody who came back on their own.
 * This command is that moment, and the only reason a scheduled job is needed here at all.
 *
 * Two audiences, two messages, because the situations are not the same. The author can act — one
 * translated line brings it back — and needs to know how many contributors are waiting behind
 * them. The contributors did nothing wrong and cannot fix it; what they need is the way out.
 */
class NotifyDelistedTranslations extends Command
{
    protected $signature = 'translations:notify-delisted {--dry-run : List who would be told, and tell nobody}';

    protected $description = 'Notify authors and contributors when a translation leaves the public catalogue';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $notified = 0;

        Translation::query()
            ->where('visibility', 'public')
            ->whereRaw('(human_count + validated_count + ai_count) = 0')
            ->where('capture_count', '>', 0)
            ->where('created_at', '<=', now()->subDays(Translation::EMPTY_GRACE_DAYS))
            ->with(['user', 'game'])
            ->chunkById(100, function ($translations) use (&$notified, $dryRun) {
                foreach ($translations as $translation) {
                    $notified += $this->announce($translation, $dryRun);
                }
            });

        $this->info($dryRun
            ? "{$notified} notification(s) would be sent."
            : "{$notified} notification(s) sent.");

        return self::SUCCESS;
    }

    /**
     * @return int how many notifications were sent (or would be)
     */
    private function announce(Translation $translation, bool $dryRun): int
    {
        $sent = 0;

        // Branches with work the Main has not taken in. "Waiting" is the count that changes the
        // author's decision: a file nobody contributed to is theirs alone to revive or forget,
        // three people waiting is a reason to come back.
        $branches = Translation::where('file_uuid', $translation->file_uuid)
            ->where('visibility', 'branch')
            ->with('user')
            ->get();

        $waiting = $branches->filter(
            fn (Translation $b) => $b->resolved_lines > 0
                && (!$b->reviewed_hash || $b->file_hash !== $b->reviewed_hash)
        )->count();

        if ($translation->user && $this->shouldTell($translation->user, TranslationDelisted::class, $translation)) {
            $this->line("  → {$translation->user->name}: {$translation->game?->name} ({$waiting} waiting)");
            if (!$dryRun) {
                $translation->user->notify(new TranslationDelisted($translation, $waiting));
            }
            $sent++;
        }

        foreach ($branches as $branch) {
            if (!$branch->user || !$this->shouldTell($branch->user, MainDelisted::class, $branch)) {
                continue;
            }

            $this->line("  → {$branch->user->name} (branch): {$branch->game?->name}");
            if (!$dryRun) {
                $branch->user->notify(new MainDelisted($branch));
            }
            $sent++;
        }

        return $sent;
    }

    /**
     * Once per translation, and once more if it came back and left again.
     *
     * The test is against the file's last content change rather than a flag: a notification older
     * than the last edit belongs to a previous round, and an author who revived their translation
     * and let it lapse a second time is in a genuinely new situation. No column, nothing to reset,
     * and no way for the two to drift.
     */
    private function shouldTell($user, string $notificationClass, Translation $translation): bool
    {
        $since = $translation->contentChangedAt();

        return !$user->notifications()
            ->where('type', $notificationClass)
            ->where('created_at', '>=', $since)
            ->get()
            ->contains(fn (DatabaseNotification $n) => ($n->data['translation_id'] ?? null) === $translation->id);
    }
}
