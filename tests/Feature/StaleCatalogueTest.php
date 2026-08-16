<?php

namespace Tests\Feature;

use App\Services\CatalogStore;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * A downloaded catalogue that predates the flags must not win over the committed one.
 *
 * 🔴 Seen in production on 2026-08-16: every language on the site rendered without its flag, while
 * the same code showed them locally. Nothing was broken in the code — storage/app/catalog held a
 * languages.json downloaded before the field existed, and the fetched copy is read FIRST. It is
 * well-formed JSON of the right shape, so the only guard in the way (looksLikeDocument) accepted
 * it, and no deployment could clear it: git does not track storage/, optimize:clear does not touch
 * it either.
 *
 * ⚠ What made it read as a component bug rather than a stale file: the interface language picker
 * kept its flags, because it names them in config/locales.php instead of asking the catalogue.
 */
class StaleCatalogueTest extends TestCase
{
    private string $fetched;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fetched = CatalogStore::refreshedPath('languages');
        File::ensureDirectoryExists(dirname($this->fetched));
        CatalogStore::forget();
    }

    protected function tearDown(): void
    {
        if (File::exists($this->fetched)) {
            File::delete($this->fetched);
        }
        CatalogStore::forget();

        parent::tearDown();
    }

    /**
     * The shape the catalogue had before flags were drawn: valid, complete, and unusable here.
     *
     * ⚠ Sixty entries, not a handful: CatalogStore already refuses a list shorter than
     * MINIMUM_LANGUAGES, and a fixture under that would be rejected for the wrong reason — the
     * test would pass while proving nothing about flags. The existing guard counts NAMES; this
     * one is about a FIELD, and the two have to be told apart.
     */
    private function writeFetched(bool $withFlags): void
    {
        $entries = [];
        for ($i = 0; $i < 60; $i++) {
            $entry = ['name' => "Language {$i}", 'tag' => "l{$i}"];
            if ($withFlags) {
                $entry['flag'] = 'fr';
            }
            $entries[] = $entry;
        }

        File::put($this->fetched, json_encode(['languages' => $entries]));
        CatalogStore::forget();
    }

    public function test_a_fetched_list_without_flags_is_refused(): void
    {
        $this->writeFetched(withFlags: false);

        // The committed copy answers instead, and it knows French carries a flag.
        $this->assertNotNull(CatalogStore::languageMark('French')['flag']);

        // And the stale entries are nowhere in the answer.
        $this->assertNull(CatalogStore::languageMark('Language 0')['flag']);
        $this->assertNotContains('Language 0', CatalogStore::languageNames());
    }

    public function test_a_fetched_list_that_carries_flags_is_used(): void
    {
        // ⚠ The guard must not turn into "always prefer ours": a catalogue fetched from the repo
        // is the whole point of fetching, and it wins as soon as it says what the site reads.
        $this->writeFetched(withFlags: true);

        $this->assertContains('Language 0', CatalogStore::languageNames());
        $this->assertSame('fr', CatalogStore::languageMark('Language 0')['flag']);
    }
}
