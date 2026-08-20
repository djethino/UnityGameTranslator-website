<?php

namespace App\Console\Commands;

use App\Services\KnownReleases;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Fetch the list of published versions, so the usage table can tell a real release from an
 * invented one.
 *
 * ⚠ **The only place that goes to the network for this.** Answering an API call must never depend
 * on GitHub being up — same rule as `catalog:refresh`, and for the same reason.
 *
 * ⚠ **Hourly, not daily.** A release published at 10 a.m. would otherwise spend the day filed under
 * "unrecognised" — and those first hours are exactly the ones worth watching when a version starts
 * spreading. One request an hour to a public endpoint is nothing; a day of mislabelled adoption is
 * not.
 */
class RefreshKnownReleases extends Command
{
    protected $signature = 'releases:refresh {--show : Print what is currently known and change nothing}';

    protected $description = 'Fetch the published versions of the mod and the Manager';

    public function handle(): int
    {
        if ($this->option('show')) {
            foreach (KnownReleases::all() as $product => $versions) {
                $this->line(sprintf('%-8s %s', $product, $versions ? implode(', ', $versions) : '(nothing known)'));
            }
            $at = KnownReleases::lastFetchedAt();
            $this->line('fetched: ' . ($at?->format('Y-m-d H:i') ?? 'never'));

            return self::SUCCESS;
        }

        $found = [];
        $failed = [];

        foreach (KnownReleases::SOURCES as $product => $repository) {
            $versions = $this->versionsOf($repository);

            if ($versions === null) {
                $failed[] = $repository;
                // ⚠ Keep whatever is already known for this product rather than dropping it: one
                // unreachable repository must not erase the other, nor yesterday's answer.
                $found[$product] = KnownReleases::all()[$product] ?? [];
                continue;
            }

            $found[$product] = $versions;
            $this->info(sprintf('%s: %d release(s)', $repository, count($versions)));
        }

        if ($failed !== []) {
            $this->warn('Unreachable, previous list kept: ' . implode(', ', $failed));
        }

        return KnownReleases::store($found) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Tags of published releases, newest first. Null means "could not ask" — which is not the same
     * as "nothing is published", and the caller keeps what it had.
     */
    private function versionsOf(string $repository): ?array
    {
        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'Accept' => 'application/vnd.github+json',
                    'User-Agent' => 'UnityGameTranslator-Website',
                ])
                ->get("https://api.github.com/repos/{$repository}/releases", ['per_page' => 100]);

            if (!$response->successful()) {
                $this->warn("{$repository}: HTTP {$response->status()}");

                return null;
            }

            return collect($response->json())
                // Drafts are not out there; pre-releases are, and somebody running one must not be
                // counted as unrecognised.
                ->reject(fn ($release) => ($release['draft'] ?? false) === true)
                ->pluck('tag_name')
                ->filter(fn ($tag) => is_string($tag) && $tag !== '')
                // Tags are written "v0.11.0"; the User-Agent carries "0.11.0".
                ->map(fn (string $tag) => ltrim($tag, 'vV'))
                ->unique()
                ->values()
                ->all();
        } catch (\Throwable $e) {
            $this->warn("{$repository}: {$e->getMessage()}");

            return null;
        }
    }
}
