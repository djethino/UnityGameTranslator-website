<?php

namespace App\Console\Commands;

use App\Models\Translation;
use App\Services\TranslationService;
use Illuminate\Console\Command;

/**
 * Rebuild every column derived from a translation file: font config, settings
 * summary and tag counts.
 *
 * Needed after any migration that adds such a column, since translations
 * uploaded before it carry the default (null, or 0 for counts) and the pages
 * would state something false about them — "no settings" for a file that has
 * some, "0 lines marked as not to translate" for a file full of them.
 *
 * Re-running it is harmless: every value is recomputed from the file, never
 * from the previous column value.
 */
class BackfillDerivedColumns extends Command
{
    protected $signature = 'translations:backfill-derived {--dry-run : Report what would change without writing}';

    protected $description = 'Rebuild the columns derived from each translation file (fonts, settings, tag counts)';

    public function handle(TranslationService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $count = Translation::count();

        $this->info($dryRun
            ? "Inspecting {$count} translations (dry run, nothing will be written)..."
            : "Rebuilding derived columns for {$count} translations...");

        $bar = $this->output->createProgressBar($count);
        $bar->start();

        $updated = 0;
        $unchanged = 0;
        $failed = 0;

        Translation::query()->chunkById(100, function ($translations) use ($service, $dryRun, $bar, &$updated, &$unchanged, &$failed) {
            foreach ($translations as $translation) {
                $bar->advance();

                $json = $translation->decodeFileContent();
                if ($json === null) {
                    $this->newLine();
                    $this->warn("  #{$translation->id}: file missing or not valid JSON ({$translation->file_path})");
                    $failed++;
                    continue;
                }

                $fresh = array_merge(
                    [
                        'line_count' => $service->countLines($json),
                        'font_config' => $service->extractFontConfig($json),
                        'settings_summary' => $service->extractSettingsSummary($json),
                    ],
                    Translation::extractTagCounts($json)
                );

                // Compare decoded values, not JSON text: key order and float
                // formatting differ harmlessly between PHP and the stored column
                $differs = false;
                foreach ($fresh as $column => $value) {
                    if ($translation->{$column} != $value) {
                        $differs = true;
                        break;
                    }
                }

                if (!$differs) {
                    $unchanged++;
                    continue;
                }

                $updated++;
                if ($dryRun) {
                    continue;
                }

                // saveQuietly: this is a derived-data repair, not new content.
                // A regular save would fire the 'updated' event and ping
                // IndexNow once per row for pages that did not change.
                $translation->forceFill($fresh)->saveQuietly();
            }
        });

        $bar->finish();
        $this->newLine(2);

        $this->info($dryRun
            ? "Dry run done. Would update: {$updated}, already correct: {$unchanged}, unreadable: {$failed}"
            : "Done. Updated: {$updated}, already correct: {$unchanged}, unreadable: {$failed}");

        return Command::SUCCESS;
    }
}
