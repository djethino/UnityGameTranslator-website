<?php

namespace App\Console\Commands;

use App\Models\Translation;
use App\Services\TranslationService;
use Illuminate\Console\Command;

/**
 * Fill settings_summary (and refresh font_config) from the stored files.
 *
 * Needed once after the settings_summary migration: every translation
 * uploaded before it has the column at null, so game pages would show
 * nothing for files that do carry settings. Re-running it is harmless —
 * the summary is derived from the file, never from previous values.
 */
class BackfillSettingsSummary extends Command
{
    protected $signature = 'translations:backfill-settings {--dry-run : Report what would change without writing}';

    protected $description = 'Rebuild font_config and settings_summary from each translation file';

    public function handle(TranslationService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $count = Translation::count();

        $this->info($dryRun
            ? "Inspecting {$count} translations (dry run, nothing will be written)..."
            : "Rebuilding settings for {$count} translations...");

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

                $fontConfig = $service->extractFontConfig($json);
                $settings = $service->extractSettingsSummary($json);

                // Compare decoded values, not JSON text: key order and float
                // formatting differ harmlessly between PHP and the stored column
                if ($translation->font_config == $fontConfig && $translation->settings_summary == $settings) {
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
                $translation->forceFill([
                    'font_config' => $fontConfig,
                    'settings_summary' => $settings,
                ])->saveQuietly();
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
