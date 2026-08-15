<?php

namespace App\Console\Commands;

use App\Services\CatalogStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fetch the shared catalogues and keep a local copy.
 *
 * The catalogues change without us — a provider adds a language, a mod loader ships a release —
 * so waiting for a deployment to carry them would make the deployment the bottleneck for facts we
 * do not decide. This is the only thing on this site that goes and gets them; every reader works
 * from what this leaves behind (see CatalogStore).
 *
 * ⚠ THIS COMMAND MAY NEVER MAKE THE SITE WORSE. The language list is the upload contract: a
 * truncated or empty answer written over the local copy would refuse perfectly valid uploads, with
 * an error the contributor did not cause and cannot read. So every payload is parsed and weighed
 * BEFORE it replaces anything, and a rejected fetch changes nothing at all. Keeping yesterday's
 * catalogue costs a missing novelty; accepting a bad one costs uploads.
 */
class RefreshCatalog extends Command
{
    protected $signature = 'catalog:refresh {--force : Write even when the payload is byte-identical}';

    protected $description = 'Fetch languages/loaders/models from the catalogue repository into local storage';

    /**
     * Where the catalogues are published. The same address the Manager fetches from first, on
     * purpose: this site is meant to MIRROR that file, so it has to be reading the same one.
     */
    private const BASE = 'https://raw.githubusercontent.com/djethino/unitygametranslator-catalogs/main';

    public function handle(): int
    {
        $directory = storage_path('app/catalog');

        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            $this->error("Cannot create {$directory}");

            return self::FAILURE;
        }

        $refused = 0;

        foreach (CatalogStore::FILES as $name) {
            $outcome = $this->refreshOne($name, $directory);
            $this->line(sprintf('  %-10s %s', $name, $outcome['message']));

            if (!$outcome['ok']) {
                $refused++;
            }
        }

        // A refusal is reported but is not a failed run: the previous copy is still there and the
        // site is still correct. Only a run where nothing at all could be read deserves attention.
        if ($refused === count(CatalogStore::FILES)) {
            Log::warning('Catalogue refresh: every document was refused or unreachable', [
                'stale_for_days' => $this->stalest(),
            ]);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * How long ago the least-recently confirmed catalogue was last known to be current, in days.
     * Null when none has ever been fetched — a site that has only ever run on its committed copy.
     *
     * ⚠ This is for US, in the log, and nowhere else. A visitor has no use for it and an upload
     * form that muttered about a stale catalogue would be alarming about something that is working
     * exactly as designed. The whole point of the committed copy is that this failure is invisible
     * from the outside; the cost of that is that it has to be made visible somewhere on the inside.
     */
    private function stalest(): ?int
    {
        $ages = [];

        foreach (CatalogStore::FILES as $name) {
            $at = CatalogStore::lastConfirmedAt($name);
            if ($at !== null) {
                $ages[] = (int) $at->diff(new \DateTimeImmutable())->days;
            }
        }

        return $ages === [] ? null : max($ages);
    }

    /**
     * @return array{ok: bool, message: string}
     */
    private function refreshOne(string $name, string $directory): array
    {
        $url = self::BASE . "/{$name}.json";

        try {
            $response = Http::timeout(15)->get($url);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'unreachable (' . class_basename($e) . ') — kept the local copy'];
        }

        if (!$response->successful()) {
            return ['ok' => false, 'message' => "HTTP {$response->status()} — kept the local copy"];
        }

        $text = $response->body();

        if (!CatalogStore::looksLikeDocument($text)) {
            Log::warning('Catalogue refresh: refused a malformed payload', ['catalogue' => $name]);

            return ['ok' => false, 'message' => 'malformed — kept the local copy'];
        }

        if ($shrunk = $this->shrinkage($name, $text)) {
            Log::warning('Catalogue refresh: refused a payload that lost entries', [
                'catalogue' => $name,
                'detail' => $shrunk,
            ]);

            return ['ok' => false, 'message' => "{$shrunk} — kept the local copy"];
        }

        $target = $directory . DIRECTORY_SEPARATOR . "{$name}.json";

        if (!$this->option('force') && is_readable($target) && @file_get_contents($target) === $text) {
            // Touched even though nothing changed: the timestamp answers "when did we last know
            // this was current", not "when did it last change". Without this, a catalogue that is
            // simply stable becomes indistinguishable from a source that stopped answering months
            // ago — and that failure has no other symptom (see CatalogStore::lastConfirmedAt).
            @touch($target);

            return ['ok' => true, 'message' => 'unchanged'];
        }

        // Written aside then moved: a reader that arrives mid-write must never see half a file,
        // and rename() within one filesystem is atomic.
        $temporary = $target . '.incoming';

        if (@file_put_contents($temporary, $text) === false || !@rename($temporary, $target)) {
            @unlink($temporary);

            return ['ok' => false, 'message' => 'could not be written — kept the local copy'];
        }

        return ['ok' => true, 'message' => 'updated (' . strlen($text) . ' bytes)'];
    }

    /**
     * Whether the incoming document holds dramatically fewer entries than the one in use.
     *
     * A catalogue loses an entry occasionally — a provider drops a language, a loader is retired —
     * so shrinking is not by itself wrong. Losing a third of it at once is not a fact changing, it
     * is a file that got truncated somewhere between there and here.
     */
    private function shrinkage(string $name, string $incoming): ?string
    {
        // ⚠ An unlisted document threw here rather than being refused — a `match` with no default
        // on a list that grows. `flags` was the first to arrive after this was written, and the
        // whole refresh died on it instead of skipping one file.
        $key = match ($name) {
            'languages' => 'languages',
            'loaders' => 'loaders',
            'models' => 'models',
            'flags' => 'flags',
        };

        $new = count(json_decode($incoming, true)[$key] ?? []);

        try {
            $current = count(CatalogStore::document($name)[$key] ?? []);
        } catch (\Throwable) {
            // Nothing readable locally: anything parseable is an improvement.
            return null;
        }

        if ($current === 0 || $new >= $current * 0.7) {
            return null;
        }

        return "only {$new} entries against {$current}";
    }
}
