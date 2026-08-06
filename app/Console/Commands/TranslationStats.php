<?php

namespace App\Console\Commands;

use App\Models\Translation;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * What the catalogue actually looks like, before changing how it is scored or ranked.
 *
 * The quality score and the ranking formula both rest on constants nobody measured: how much a
 * vote should weigh against quality, where the thresholds of a quality label belong, whether
 * engagement discriminates anything at all. If the median translation has zero votes, weighting
 * votes is weighting noise — and no amount of reasoning from a desk will reveal that.
 *
 * Reads only, writes nothing. Meant to be run on production and read before deciding.
 */
class TranslationStats extends Command
{
    protected $signature = 'translations:stats';

    protected $description = 'Report the real distribution of quality, engagement and activity across translations';

    public function handle(): int
    {
        $translations = Translation::where('visibility', 'public')->get();

        if ($translations->isEmpty()) {
            $this->warn('No public translation to report on.');

            return Command::SUCCESS;
        }

        $this->info("Public translations: {$translations->count()}");
        $this->newLine();

        $this->reportQualityScore($translations);
        $this->reportReviewCoverage($translations);
        $this->reportIntervention($translations);
        $this->reportEngagement($translations);
        $this->reportActivity($translations);

        return Command::SUCCESS;
    }

    /**
     * The score as it stands today. The point is to see the SPREAD: a scale where everything
     * lands between 1.0 and 2.5 uses half of what it displays.
     */
    private function reportQualityScore(Collection $translations): void
    {
        $scores = $translations->map(fn ($t) => round($t->quality_score, 2))->filter(fn ($s) => $s > 0);

        $this->line('<comment>Quality score today (0-3, H×3+V×2+A×1 / translated lines)</comment>');
        $this->histogram($scores, [
            '0.0 - 0.99  (Raw AI)' => fn ($s) => $s < 1.0,
            '1.0 - 1.49  (Basic)' => fn ($s) => $s >= 1.0 && $s < 1.5,
            '1.5 - 1.99  (Fair)' => fn ($s) => $s >= 1.5 && $s < 2.0,
            '2.0 - 2.49  (Good)' => fn ($s) => $s >= 2.0 && $s < 2.5,
            '2.5 - 3.0   (Excellent)' => fn ($s) => $s >= 2.5,
        ]);
        $this->newLine();
    }

    /**
     * What share of a file a human has actually looked at. The candidate replacement for the
     * score, and the one thing a downloader really wants to know.
     */
    private function reportReviewCoverage(Collection $translations): void
    {
        $coverage = $translations
            ->map(function ($t) {
                $translated = $t->human_count + $t->validated_count + $t->ai_count;

                return $translated > 0 ? ($t->human_count + $t->validated_count) / $translated : null;
            })
            ->filter(fn ($c) => $c !== null);

        $this->line('<comment>Review coverage (H+V / translated lines)</comment>');
        $this->histogram($coverage, [
            '0%        (never opened)' => fn ($c) => $c == 0,
            '1-39%' => fn ($c) => $c > 0 && $c < 0.4,
            '40-74%' => fn ($c) => $c >= 0.4 && $c < 0.75,
            '75-99%' => fn ($c) => $c >= 0.75 && $c < 1,
            '100%      (fully reviewed)' => fn ($c) => $c >= 1,
        ]);
        $this->newLine();
    }

    /**
     * Share of lines the author actively acted on — typed (H) or set aside (S) — as opposed to
     * merely ticked. The signal behind "a file with 5000 V and no H was never really read".
     */
    private function reportIntervention(Collection $translations): void
    {
        $rates = $translations
            ->map(function ($t) {
                $total = $t->human_count + $t->validated_count + $t->ai_count + $t->skipped_count;

                return $total > 0 ? ($t->human_count + $t->skipped_count) / $total : null;
            })
            ->filter(fn ($r) => $r !== null);

        $this->line('<comment>Active intervention (H+S / all tagged lines)</comment>');
        $this->histogram($rates, [
            '0%        (nothing typed or set aside)' => fn ($r) => $r == 0,
            '1-9%' => fn ($r) => $r > 0 && $r < 0.1,
            '10-29%' => fn ($r) => $r >= 0.1 && $r < 0.3,
            '30-59%' => fn ($r) => $r >= 0.3 && $r < 0.6,
            '60-100%' => fn ($r) => $r >= 0.6,
        ]);
        $this->newLine();
    }

    /**
     * Whether votes and downloads discriminate anything. If the median is zero, weighting them
     * in the ranking weights noise.
     */
    private function reportEngagement(Collection $translations): void
    {
        $votes = $translations->pluck('vote_count');
        $downloads = $translations->pluck('download_count');

        $this->line('<comment>Engagement</comment>');
        $this->line(sprintf(
            '  Votes      median %s, mean %s, max %s — %d%% have none',
            $this->median($votes),
            round($votes->avg(), 1),
            $votes->max(),
            round($votes->filter(fn ($v) => $v == 0)->count() / $votes->count() * 100)
        ));
        $this->line(sprintf(
            '  Downloads  median %s, mean %s, max %s — %d%% have none',
            $this->median($downloads),
            round($downloads->avg(), 1),
            $downloads->max(),
            round($downloads->filter(fn ($d) => $d == 0)->count() / $downloads->count() * 100)
        ));
        $this->newLine();
    }

    /**
     * How old the catalogue is and how long translations stay worked on. Decides whether a
     * 90-day freshness half-life makes everything invisible, and whether "maintained for
     * months" is a signal that exists in the data at all.
     */
    private function reportActivity(Collection $translations): void
    {
        $ages = $translations->map(fn ($t) => $t->created_at?->diffInDays(now()))->filter();
        $spans = $translations
            ->map(fn ($t) => $t->created_at && $t->content_updated_at
                ? max(0, $t->created_at->diffInDays($t->content_updated_at))
                : null)
            ->filter(fn ($s) => $s !== null);

        $complete = $translations->filter(fn ($t) => $t->status === 'complete')->count();

        $this->line('<comment>Activity</comment>');
        $this->line(sprintf('  Declared complete: %d%%', round($complete / $translations->count() * 100)));
        $this->newLine();

        $this->line('  Age since publication');
        $this->histogram($ages, [
            '0-29 days' => fn ($d) => $d < 30,
            '30-89 days' => fn ($d) => $d >= 30 && $d < 90,
            '90-364 days   (freshness < 0.5 today)' => fn ($d) => $d >= 90 && $d < 365,
            '1 year and over (freshness < 0.06 today)' => fn ($d) => $d >= 365,
        ]);
        $this->newLine();

        // Age since the last CONTENT change — the only thing that separates a translation still
        // being worked on from an abandoned one. Age since publication cannot: a translation
        // maintained for a year is old and alive.
        $idle = $translations
            ->map(fn ($t) => $t->contentChangedAt()?->diffInDays(now()))
            ->filter(fn ($d) => $d !== null);

        $this->line('  Time since the last content change');
        $this->histogram($idle, [
            '0-29 days     (being worked on)' => fn ($d) => $d < 30,
            '30-89 days' => fn ($d) => $d >= 30 && $d < 90,
            '90-179 days' => fn ($d) => $d >= 90 && $d < 180,
            '180 days and over (likely abandoned)' => fn ($d) => $d >= 180,
        ]);
        $this->newLine();

        $this->line('  Maintenance span (first publication to last content change)');
        $this->histogram($spans, [
            'same day   (never reopened)' => fn ($s) => $s < 1,
            '1-29 days' => fn ($s) => $s >= 1 && $s < 30,
            '30-179 days' => fn ($s) => $s >= 30 && $s < 180,
            '180 days and over' => fn ($s) => $s >= 180,
        ]);
        $this->newLine();
    }

    /**
     * A text histogram. Percentages matter more than counts here: the question is always
     * "where does the catalogue sit", not "how many rows".
     */
    private function histogram(Collection $values, array $buckets): void
    {
        $total = $values->count();
        if ($total === 0) {
            $this->line('    (no data)');

            return;
        }

        foreach ($buckets as $label => $test) {
            $count = $values->filter($test)->count();
            $percent = $count / $total * 100;
            $bar = str_repeat('#', (int) round($percent / 2));

            $this->line(sprintf('    %-42s %5.1f%% %-50s (%d)', $label, $percent, $bar, $count));
        }
    }

    private function median(Collection $values): float|int
    {
        $sorted = $values->sort()->values();
        $count = $sorted->count();
        if ($count === 0) {
            return 0;
        }

        $middle = (int) floor($count / 2);

        return $count % 2 === 0
            ? ($sorted[$middle - 1] + $sorted[$middle]) / 2
            : $sorted[$middle];
    }
}
