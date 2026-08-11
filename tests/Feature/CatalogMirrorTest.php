<?php

namespace Tests\Feature;

use App\Services\CatalogStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The shared catalogues: what this site reads, and what it serves to the Manager.
 *
 * Worth testing precisely because none of it is visible. The Manager only asks this site for a
 * catalogue when GitHub cannot be reached — so a broken endpoint here surfaces on the day the
 * primary source is already down, which is the worst possible day to discover it. Likewise the
 * language list: it is the upload contract, and it failing shows up as somebody else's upload
 * being refused, not as an error we would ever see.
 */
class CatalogMirrorTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_mirror_serves_each_catalogue_as_parseable_json(): void
    {
        foreach (CatalogStore::FILES as $name) {
            $response = $this->get("/catalog/{$name}.json");

            $response->assertOk();
            $response->assertHeader('Content-Type', 'application/json; charset=utf-8');

            $this->assertIsArray(
                json_decode($response->getContent(), true),
                "/catalog/{$name}.json did not come back as a JSON document."
            );
        }
    }

    public function test_the_mirror_refuses_a_name_it_does_not_know(): void
    {
        // Not merely tidiness: the name reaches the filesystem through CatalogStore, so anything
        // outside the known set must stop at the door rather than be resolved into a path.
        $this->get('/catalog/secrets.json')->assertNotFound();
        $this->get('/catalog/..%2F..%2Fenv.json')->assertNotFound();
    }

    public function test_a_client_holding_the_current_version_is_told_it_is_unchanged(): void
    {
        $first = $this->get('/catalog/loaders.json');
        $etag = $first->headers->get('ETag');

        $this->assertNotNull($etag, 'The mirror must carry an ETag, or every fallback re-downloads.');

        $this->withHeaders(['If-None-Match' => $etag])
            ->get('/catalog/loaders.json')
            ->assertStatus(304);
    }

    public function test_the_language_list_is_read_from_the_catalogue(): void
    {
        $names = CatalogStore::languageNames();

        // The count is not asserted exactly: languages get added, and a test that has to be
        // edited whenever the catalogue grows teaches nothing. What must hold is that the list is
        // a real one and carries the names the rest of the project resolves to.
        $this->assertGreaterThan(50, count($names));
        $this->assertContains('English', $names);
        $this->assertContains('Simplified Chinese', $names);
        $this->assertContains('Traditional Chinese', $names);

        // Five of the catalogue's languages have no ISO 639-1 code at all, which is exactly why
        // the name — never the code — is the identity everywhere.
        $this->assertContains('Cantonese', $names);
    }

    public function test_the_upload_form_offers_the_catalogue_languages(): void
    {
        $user = \App\Models\User::factory()->create();

        $response = $this->actingAs($user)->get(route('translations.create'));

        $response->assertOk();
        $response->assertSee('Traditional Chinese');
    }
}
