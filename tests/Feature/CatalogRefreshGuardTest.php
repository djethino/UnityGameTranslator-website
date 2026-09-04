<?php

namespace Tests\Feature;

use App\Services\CatalogStore;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * What the catalogue refresh lets into storage/, and what it keeps out.
 *
 * 🔴 **The guard used to measure against the last fetched copy, in one direction only.** A file
 * that had gained five times its entries was accepted and became the yardstick; the real catalogue
 * was then "too small" against it and refused at every refresh, for good, with nothing said. The
 * yardstick is now the committed copy — the one thing a fetch cannot move — and both directions
 * are measured.
 *
 * ⚠ The legitimate cases matter as much: the committed copy itself, and a copy that has grown by
 * an entry or two between releases, must go through, or the mirror stops following the catalogue.
 */
class CatalogRefreshGuardTest extends TestCase
{
    private const SOURCE = 'https://raw.githubusercontent.com/djethino/unitygametranslator-catalogs/main/';

    /** What storage/ held before the test, per file, so nothing of the developer's is lost. */
    private array $previous = [];

    protected function setUp(): void
    {
        parent::setUp();

        File::ensureDirectoryExists(dirname(CatalogStore::refreshedPath('loaders')));

        foreach (CatalogStore::FILES as $name) {
            $path = CatalogStore::refreshedPath($name);
            $this->previous[$name] = File::exists($path) ? File::get($path) : null;
            File::delete($path);
        }

        CatalogStore::forget();
    }

    protected function tearDown(): void
    {
        foreach (CatalogStore::FILES as $name) {
            $path = CatalogStore::refreshedPath($name);
            if ($this->previous[$name] === null) {
                File::delete($path);
            } else {
                File::put($path, $this->previous[$name]);
            }
        }

        CatalogStore::forget();

        parent::tearDown();
    }

    private function committed(string $name): array
    {
        return json_decode(File::get(CatalogStore::committedPath($name)), true);
    }

    /** Serves every catalogue as committed, except the loaders document given. */
    private function fakeWithLoaders(array $loaders): void
    {
        $responses = [];
        foreach (CatalogStore::FILES as $name) {
            $body = $name === 'loaders' ? $loaders : $this->committed($name);
            $responses[self::SOURCE . "{$name}.json"] = Http::response(json_encode($body), 200);
        }

        Http::fake($responses);
    }

    private function refresh(): void
    {
        $this->artisan('catalog:refresh');
        CatalogStore::forget();
    }

    private function storedLoaders(): ?array
    {
        $path = CatalogStore::refreshedPath('loaders');

        return File::exists($path) ? json_decode(File::get($path), true) : null;
    }

    public function test_the_committed_document_itself_goes_through(): void
    {
        $this->fakeWithLoaders($this->committed('loaders'));

        $this->refresh();

        $this->assertNotNull($this->storedLoaders(), 'the reference must always be acceptable');
    }

    public function test_a_document_that_grew_by_a_few_entries_goes_through(): void
    {
        // What a real refresh brings between two releases: a loader version, a new entry.
        $loaders = $this->committed('loaders');
        $loaders['loaders'][] = ['id' => 'one-more', 'display' => 'One more'];
        $loaders['loaders'][] = ['id' => 'and-another', 'display' => 'And another'];

        $this->fakeWithLoaders($loaders);
        $this->refresh();

        $this->assertCount(count($this->committed('loaders')['loaders']) + 2, $this->storedLoaders()['loaders']);
    }

    public function test_an_older_schema_is_refused(): void
    {
        // The case seen in production: a loaders.json two schemas old, served over the committed
        // one for weeks, because "valid JSON with entries" was all that was asked.
        $loaders = $this->committed('loaders');
        $loaders['schema'] = $loaders['schema'] - 2;

        $this->fakeWithLoaders($loaders);
        $this->refresh();

        $this->assertNull($this->storedLoaders(), 'a schema going backwards is not an update');
    }

    public function test_a_document_missing_a_root_key_is_refused(): void
    {
        $loaders = $this->committed('loaders');
        unset($loaders['plugin_builds']);

        $this->fakeWithLoaders($loaders);
        $this->refresh();

        $this->assertNull($this->storedLoaders());
    }

    public function test_a_document_that_lost_a_third_of_its_entries_is_refused(): void
    {
        $loaders = $this->committed('loaders');
        $loaders['loaders'] = array_slice($loaders['loaders'], 0, (int) floor(count($loaders['loaders']) * 0.5));

        $this->fakeWithLoaders($loaders);
        $this->refresh();

        $this->assertNull($this->storedLoaders());
    }

    public function test_a_document_that_ballooned_is_refused(): void
    {
        $loaders = $this->committed('loaders');
        for ($i = 0; $i < count($this->committed('loaders')['loaders']) * 4; $i++) {
            $loaders['loaders'][] = ['id' => "padding-{$i}", 'display' => 'Padding'];
        }

        $this->fakeWithLoaders($loaders);
        $this->refresh();

        $this->assertNull($this->storedLoaders(), 'five times the entries is not a catalogue growing');
    }

    public function test_a_document_four_times_the_size_is_refused_even_with_the_right_entries(): void
    {
        $loaders = $this->committed('loaders');
        $loaders['_comment'][] = str_repeat('x', strlen(json_encode($this->committed('loaders'))) * 4);

        $this->fakeWithLoaders($loaders);
        $this->refresh();

        $this->assertNull($this->storedLoaders());
    }

    /**
     * 🔴 The case the old guard turned into a permanent state. A bloated copy is already in
     * storage/; the real catalogue arrives; it must be accepted, because it is measured against
     * the committed copy and not against the bloated one.
     */
    public function test_a_bad_copy_already_in_place_cannot_keep_the_real_one_out(): void
    {
        $bloated = $this->committed('loaders');
        for ($i = 0; $i < 40; $i++) {
            $bloated['loaders'][] = ['id' => "squat-{$i}", 'display' => 'Squat'];
        }
        File::put(CatalogStore::refreshedPath('loaders'), json_encode($bloated));
        CatalogStore::forget();

        $this->fakeWithLoaders($this->committed('loaders'));
        $this->refresh();

        $stored = $this->storedLoaders();
        $this->assertNotNull($stored);
        $this->assertCount(count($this->committed('loaders')['loaders']), $stored['loaders'],
            'the real catalogue replaced the bloated copy instead of being refused against it');
    }
}
