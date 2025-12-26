<?php

namespace App\Console\Commands;

use App\Models\Translation;
use Illuminate\Console\Command;

class RecalculateHashes extends Command
{
    protected $signature = 'translations:recalculate-hashes';

    protected $description = 'Recalculate file hashes for all translations using normalized JSON (sorted keys)';

    public function handle(): int
    {
        $translations = Translation::all();
        $count = $translations->count();

        $this->info("Recalculating hashes for {$count} translations...");

        $bar = $this->output->createProgressBar($count);
        $bar->start();

        $updated = 0;
        $failed = 0;

        foreach ($translations as $translation) {
            $oldHash = $translation->file_hash;

            // Debug info
            $safePath = $translation->getSafeFilePath();
            if (!$safePath) {
                $this->newLine();
                $this->error("  #{$translation->id}: file_path is empty or invalid: '{$translation->file_path}'");
                $failed++;
                $bar->advance();
                continue;
            }

            if (!file_exists($safePath)) {
                $this->newLine();
                $this->error("  #{$translation->id}: file does not exist: {$safePath}");
                $failed++;
                $bar->advance();
                continue;
            }

            $newHash = $translation->computeHash();

            if ($newHash === null) {
                $this->newLine();
                $this->warn("  #{$translation->id}: Failed to parse JSON or compute hash");
                $failed++;
            } else {
                $translation->file_hash = $newHash;
                $translation->save();

                if ($oldHash !== $newHash) {
                    $updated++;
                }
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Done! Updated: {$updated}, Failed: {$failed}");

        return Command::SUCCESS;
    }
}
