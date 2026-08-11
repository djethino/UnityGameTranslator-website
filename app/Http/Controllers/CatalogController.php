<?php

namespace App\Http\Controllers;

use App\Services\CatalogStore;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves the shared catalogues — the mirror the Manager falls back to.
 *
 * The Manager fetches its loader and model catalogues in this order: GitHub, this site, a local
 * cache, then the copy baked into its binary. GitHub is deliberately first, so that launching the
 * tool does not drop an IP into our own logs; this endpoint exists for the case where GitHub is
 * unreachable — blocked network, corporate proxy, an outage.
 *
 * ⚠ What is served here must be the catalogue file itself, unaltered. The Manager parses it with
 * the same code it uses on the GitHub copy, so anything reformatted, wrapped in an envelope or
 * re-encoded here becomes a failure that only appears when GitHub is already down — the one moment
 * nobody is watching.
 */
class CatalogController extends Controller
{
    public function show(Request $request, string $name): Response
    {
        abort_unless(in_array($name, CatalogStore::FILES, true), 404);

        $body = CatalogStore::raw($name);

        $response = response($body, 200)
            ->header('Content-Type', 'application/json; charset=utf-8')
            // A day: this mirrors data that moves a few times a month, and whoever is asking is a
            // program that already has three other ways to get it.
            ->header('Cache-Control', 'public, max-age=86400');

        $response->setEtag(hash('sha256', $body));

        // Turns the response into an empty 304 in place when the client already holds this
        // version. Called for its effect, not its answer.
        $response->isNotModified($request);

        return $response;
    }
}
