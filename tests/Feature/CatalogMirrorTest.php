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

    /**
     * The state of a deployment that has never reached GitHub — which is also the state of one
     * whose scheduler is not running. The site is correct either way, so this line on the admin
     * page is the only thing that would ever say so.
     */
    public function test_an_admin_is_told_when_nothing_has_ever_been_fetched(): void
    {
        $live = storage_path('app/catalog');
        $aside = storage_path('app/catalog-under-test');
        $moved = is_dir($live) && @rename($live, $aside);

        try {
            $admin = \App\Models\User::factory()->create(['is_admin' => true]);

            $this->actingAs($admin)
                ->get(route('admin.analytics'))
                ->assertOk()
                ->assertSee('Never fetched', false);
        } finally {
            // In a finally so a failed assertion cannot leave a developer's storage renamed.
            if ($moved) {
                @rename($aside, $live);
            }
        }
    }

    public function test_the_documentation_names_the_model_the_catalogue_names(): void
    {
        $reference = \App\Services\ModelCatalog::reference();
        $this->assertNotNull($reference, 'The catalogue no longer marks any model as the reference.');

        $response = $this->get(route('docs'));
        $response->assertOk();
        $response->assertSee($reference['pull'], false);

        // ⚠ The paragraph this replaced recommended a `:latest` tag, which the catalogue forbids in
        // as many words: a moving tag makes every figure printed beside it a lie the day it moves,
        // and the reader has no way of knowing which day that was. Asserting the absence is the
        // point — it is how the old wording came back unnoticed the first time.
        $response->assertDontSee(':latest', false);
    }

    /**
     * The order is a decision, not a side effect of how the catalogue happens to be written — and
     * a decision nothing else would notice going wrong. Reordering the file, or adding a model,
     * would silently reshuffle the page.
     */
    public function test_the_models_are_ordered_by_the_rule_the_page_claims(): void
    {
        $models = \App\Services\ModelCatalog::installable();
        $this->assertGreaterThan(1, count($models));

        $this->assertSame('reference', $models[0]['role'] ?? null, 'The reference model must come first.');

        // Everything after it: instructions followed (most first), then memory (least first), then
        // claimed languages (most first) — compared as one ascending tuple, exactly as the service
        // builds it.
        $key = function (array $m) {
            $measured = $m['measured'] ?? [];
            $followed = isset($measured['suite'], $measured['suite_of']) && $measured['suite_of'] > 0
                ? $measured['suite'] / $measured['suite_of']
                : -1.0;

            return [-$followed, $m['min_vram_gb'] ?? PHP_INT_MAX,
                    -(\App\Services\ModelCatalog::claimedLanguages($m) ?? 0)];
        };

        $rest = array_slice($models, 1);

        for ($i = 1; $i < count($rest); $i++) {
            $this->assertLessThanOrEqual(
                0,
                $key($rest[$i - 1]) <=> $key($rest[$i]),
                sprintf('%s is listed before %s but sorts after it.',
                    $rest[$i - 1]['pull'], $rest[$i]['pull'])
            );
        }
    }

    public function test_the_documentation_only_offers_models_that_can_be_downloaded(): void
    {
        // Some catalogue entries carry no `pull`: they exist so the tool can RECOGNISE a model
        // somebody already has, matching as a substring. Offering them would advertise a download
        // that does not exist.
        $families = array_filter(
            \App\Services\ModelCatalog::installable(),
            fn ($m) => empty($m['pull'])
        );

        $this->assertSame([], $families, 'A recognition-only entry reached the list of choices.');
    }

    public function test_the_upload_form_offers_the_catalogue_languages(): void
    {
        $user = \App\Models\User::factory()->create();

        $response = $this->actingAs($user)->get(route('translations.create'));

        $response->assertOk();
        $response->assertSee('Traditional Chinese');
    }
}
