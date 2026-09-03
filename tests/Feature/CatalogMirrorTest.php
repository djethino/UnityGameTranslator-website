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
     * A model with every figure spelled out, so each case below says only what it varies.
     *
     * @return array<string, mixed>
     */
    private function model(string $pull, float $held, float $needs, int $suite = 16,
                          int $suiteOf = 16, int $retried = 0, int $refused = 0,
                          bool $strict = false, float $load = 10.0,
                          string $role = 'tested'): array
    {
        return [
            'match' => $pull,
            'pull' => $pull,
            'role' => $role,
            'min_vram_gb' => $needs,
            'measured' => [
                'vram_gb' => $held,
                'suite' => $suite,
                'suite_of' => $suiteOf,
                'retried' => $retried,
                'refused' => $refused,
                'lines' => 20,
                'strict_source' => $strict,
                'load_s' => $load,
            ],
        ];
    }

    /** @param  array<int, array<string, mixed>>  $models @return array<int, string> */
    private function order(array $models): array
    {
        return array_column(\App\Services\ModelCatalog::rank($models), 'pull');
    }

    /**
     * The ladder the model table is presented on — asked as questions rather than re-derived.
     *
     * 🔸 THE SAME CASES ARE RUN BY THE MANAGER — `ModelOrderChecks` in the manager repository, on
     * the same synthetic models. The two implementations cannot share code (PHP and C#), so what
     * keeps them honest is that both answer the same questions. The same catalogue coming out in
     * two orders, in two of our own tools, reads to a user as one of them being wrong.
     *
     * ⚠ Synthetic on purpose. Pinning this to the live catalogue would make it fail the day a model
     * is added — which is not a defect — while saying nothing about the rule. The version this
     * replaced rebuilt the service's own sort key and compared it to the service's own output,
     * which proved only that the code agreed with itself.
     */
    public function test_a_lost_line_outranks_any_amount_of_memory(): void
    {
        // 🔴 The top rung, and the one that must never be traded away. A line the model gives up on
        // stays in its original language on screen while somebody plays; everything else on this
        // ladder is a wait. Half the memory does not buy that back.
        $this->assertSame(
            ['heavy-but-complete', 'gives-up'],
            $this->order([
                $this->model('gives-up', held: 1.0, needs: 4, refused: 1),
                $this->model('heavy-but-complete', held: 20.0, needs: 24),
            ])
        );

        // Same shape, one rung down: following every instruction beats being small.
        $this->assertSame(
            ['heavy-but-complete', 'misses-one'],
            $this->order([
                $this->model('misses-one', held: 1.0, needs: 4, suite: 15),
                $this->model('heavy-but-complete', held: 20.0, needs: 24),
            ])
        );
    }

    public function test_retries_are_a_threshold_and_never_a_count(): void
    {
        // ⚠ Four retries out of twenty against five is not a difference anybody can act on, and
        // ranking on it would seat a 7.8 GB model above a 2.8 GB one over a single line. Once both
        // are past the threshold, what decides is the memory left to play in.
        $this->assertSame(
            ['five-retries-light', 'four-retries-heavy'],
            $this->order([
                $this->model('five-retries-light', held: 2.8, needs: 4, retried: 5),
                $this->model('four-retries-heavy', held: 7.8, needs: 10, retried: 4),
            ])
        );

        // ...but the threshold itself is real: needing no second go at all is worth more than a
        // gigabyte, because the retry is paid on the line somebody is waiting to read.
        $this->assertSame(
            ['clean-heavier', 'retries-lighter'],
            $this->order([
                $this->model('retries-lighter', held: 1.7, needs: 4, retried: 4),
                $this->model('clean-heavier', held: 3.1, needs: 4),
            ])
        );
    }

    public function test_memory_is_compared_as_measured_and_not_as_rounded(): void
    {
        // 🔴 min_vram_gb is rounded up to real card sizes, so models holding 1.7 and 3.1 GB both
        // read "4 GB" and used to sort as equals — collapsing the one difference this rung exists
        // to expose. The rounded figure answers "will it fit"; only the measured one answers "how
        // much is left for the game".
        $this->assertSame(
            ['holds-less', 'holds-more'],
            $this->order([
                $this->model('holds-more', held: 3.1, needs: 4),
                $this->model('holds-less', held: 1.7, needs: 4),
            ])
        );
    }

    public function test_the_last_two_rungs_only_ever_settle_a_tie(): void
    {
        // An extra capability at equal cost, so it settles a tie and never creates one.
        $this->assertSame(
            ['strict', 'plain'],
            $this->order([
                $this->model('plain', held: 5.0, needs: 8),
                $this->model('strict', held: 5.0, needs: 8, strict: true),
            ])
        );

        // Paid once a session while a game is starting, so it speaks last.
        $this->assertSame(
            ['quick-start', 'slow-start'],
            $this->order([
                $this->model('slow-start', held: 5.0, needs: 8, load: 30.0),
                $this->model('quick-start', held: 5.0, needs: 8, load: 6.0),
            ])
        );
    }

    public function test_the_reference_model_is_not_forced_first(): void
    {
        // 🔴 Being what this project develops against is a fact about US, not a measurement.
        // Ranking it first put a 16 GB model at the top of a table people read to find one that
        // fits. It carries a mark saying what it is; it does not get a rank for it.
        $this->assertSame(
            ['light', 'reference-heavy'],
            $this->order([
                $this->model('reference-heavy', held: 16.1, needs: 24, role: 'reference'),
                $this->model('light', held: 3.1, needs: 4),
            ])
        );
    }

    public function test_an_unmeasured_model_sorts_last(): void
    {
        // An unknown score is not a zero, and is no reason to lead with it either.
        $this->assertSame(
            ['measured', 'never-run'],
            $this->order([
                ['match' => 'never-run', 'pull' => 'never-run', 'min_vram_gb' => 4],
                $this->model('measured', held: 20.0, needs: 24),
            ])
        );
    }

    /**
     * Two marks, and each answers a DIFFERENT question a reader arrives with. A mark on every row
     * is a mark on none — which is what the Manager's previous one had quietly become, its
     * condition met by nine rows out of ten without a line of it changing.
     */
    public function test_only_two_rows_carry_a_mark(): void
    {
        $reference = $this->model('reference', held: 16.1, needs: 24, strict: true, role: 'reference');
        $lightest = $this->model('tiny', held: 1.7, needs: 4, retried: 4);
        $middling = $this->model('middling', held: 3.1, needs: 4);
        $broken = $this->model('broken', held: 0.9, needs: 4, refused: 1);

        $rows = [$reference, $lightest, $middling, $broken];
        $mark = fn (array $m) => \App\Services\ModelCatalog::standout($m, $rows);

        $this->assertSame('reference', $mark($reference));

        // 🔴 The point of the whole revision: this row is sixth in the order, because four retries
        // out of twenty is a real cost. It is still the answer to "I have a small card", and the
        // order alone buries it.
        $this->assertSame('lightest', $mark($lightest));

        $this->assertNull($mark($middling), 'A mark on every row is a mark on none.');

        // ⚠ LIGHTEST, not smallest. A model that leaves a line untranslated is not in the running,
        // however little memory it holds.
        $this->assertNull($mark($broken), '0.9 GB, and it gave up on a line.');
    }

    public function test_the_light_mark_is_never_passed_down(): void
    {
        // If the reference were ever the lightest too, the honest outcome is that nothing else
        // carries the mark: handing it to the next one up would name the wrong model.
        $tinyReference = $this->model('tiny-reference', held: 1.0, needs: 4, role: 'reference');
        $other = $this->model('middling', held: 3.1, needs: 4);

        $rows = [$tinyReference, $other];

        $this->assertSame('reference',
            \App\Services\ModelCatalog::standout($tinyReference, $rows));
        $this->assertNull(\App\Services\ModelCatalog::standout($other, $rows));
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
