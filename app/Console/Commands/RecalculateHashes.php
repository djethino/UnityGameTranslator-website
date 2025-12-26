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
            $newHash = $translation->computeHash();

            if ($newHash === null) {
                $this->newLine();
                $this->warn("  Failed to compute hash for translation #{$translation->id}");
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
