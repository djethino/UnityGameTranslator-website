<?php

namespace App\Http\Controllers;

use App\Services\CatalogStore;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * One flag of the catalogue, as an image file — see CatalogStore::flagSvg for why flags stopped
 * being written into the pages.
 *
 * 🔴 **Cached for a year, and that is safe only because the address carries the version.** A page
 * asks for `/flags/fr.svg?v=<catalogue fingerprint>` (CatalogStore::flagUrl); once the catalogue
 * changes, the pages name a new address and the old copy is simply never asked for again. An
 * address with another version — an old page in a tab — still gets the current drawing, briefly
 * cached, rather than a 404 that would leave a hole in the menu.
 */
class FlagController extends Controller
{
    public function show(Request $request, string $flag): Response
    {
        $svg = CatalogStore::flagSvg($flag);
        abort_if($svg === null, 404);

        $current = $request->query('v') === CatalogStore::flagsVersion();

        $response = response($svg, 200)
            ->header('Content-Type', 'image/svg+xml')
            ->header('Cache-Control', $current ? 'public, max-age=31536000, immutable' : 'public, max-age=300')
            ->header('X-Content-Type-Options', 'nosniff');

        $response->setEtag(hash('sha256', $svg));
        $response->isNotModified($request);

        return $response;
    }
}
