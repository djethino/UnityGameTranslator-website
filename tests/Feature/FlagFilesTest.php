<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CatalogStore;
use Tests\TestCase;

/**
 * Flags are served as files (/flags/{id}.svg) instead of being written into every page — see
 * CatalogStore::flagSvg. The layout's two language menus carried three hundred inline flags on
 * every page: 1.8 MB of 2.3 and 90 % of the elements, paid for in every open tab.
 */
class FlagFilesTest extends TestCase
{
    public function test_every_flag_file_draws_exactly_the_catalogue_grid(): void
    {
        // Re-derived from the catalogue, not from the code that writes the file: each rect is laid
        // back onto a blank grid and the result must be the catalogue's own rows, pixel for pixel.
        $document = CatalogStore::document('flags');
        $width = (int) $document['grid']['width'];
        $height = (int) $document['grid']['height'];
        $this->assertGreaterThan(100, count($document['flags']), 'the whole catalogue is checked');

        foreach ($document['flags'] as $id => $flag) {
            $svg = CatalogStore::flagSvg($id);
            $this->assertNotNull($svg, "flag {$id}");

            $xml = simplexml_load_string($svg);
            $this->assertNotFalse($xml, "flag {$id} is a well-formed document");

            $drawn = array_fill(0, $height, array_fill(0, $width, null));
            foreach ($xml->rect as $rect) {
                for ($x = (int) $rect['x']; $x < (int) $rect['x'] + (int) $rect['width']; $x++) {
                    $this->assertNull($drawn[(int) $rect['y']][$x], "flag {$id}: two rects on one pixel");
                    $drawn[(int) $rect['y']][$x] = (string) $rect['fill'];
                }
            }

            foreach ($flag['rows'] as $y => $row) {
                foreach (str_split($row) as $x => $key) {
                    $expected = ($key === '.' || !isset($flag['palette'][$key])) ? null : $flag['palette'][$key];
                    $this->assertSame($expected, $drawn[$y][$x], "flag {$id} at {$x},{$y}");
                }
            }
        }
    }

    public function test_the_file_is_cached_for_a_year_only_under_the_current_version(): void
    {
        $url = CatalogStore::flagUrl('gb');
        $this->assertStringContainsString('v=' . CatalogStore::flagsVersion(), $url);

        $this->get($url)
            ->assertOk()
            ->assertHeader('Content-Type', 'image/svg+xml')
            ->assertHeader('Cache-Control', 'immutable, max-age=31536000, public')
            ->assertCookieMissing(config('session.cookie'));

        // An old page in a tab still gets a flag, briefly cached, rather than a hole in the menu.
        $this->get(route('flag', ['flag' => 'gb', 'v' => 'stale']))
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=300, public');

        $this->get('/flags/not-a-flag.svg')->assertNotFound();
    }

    public function test_pages_name_the_file_instead_of_drawing_the_flag(): void
    {
        $html = $this->actingAs(User::factory()->make(['id' => 1]))->get('/')->getContent();

        $this->assertStringContainsString('/flags/', $html);
        $this->assertStringNotContainsString('shape-rendering="crispEdges"', $html);
    }
}
