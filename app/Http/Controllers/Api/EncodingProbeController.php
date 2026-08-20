<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * What does PHP actually see, and does what it sends survive the way out?
 *
 * 🔴 **Written because the site holds compression code that is dead in production and nobody could
 * tell why.** `TranslationController::download` has gzipped its answer since it was written
 * (`gzencode`, guarded by `Accept-Encoding`). It works locally — 113 KB becomes 34 KB — and
 * produces plain JSON on the live site, byte for byte uncompressed.
 *
 * Two explanations fit that observation equally well from outside, and they lead to opposite
 * conclusions:
 *
 *   1. LiteSpeed strips `Accept-Encoding` before PHP, so the guard never passes. Then no amount of
 *      application code can decide anything — a middleware would be dead on arrival too.
 *   2. LiteSpeed receives the compressed answer and inflates it again on the way out. Then PHP does
 *      decide, and the decision is undone downstream — a different problem with a different fix.
 *
 * ⚠ **Guessing between the two costs a deployment either way**, and the last guess about this host
 * took the whole site's compression down (see `public/.htaccess`). So this asks the server rather
 * than assuming.
 *
 * ⚠ **Deliberately says nothing that is not about encoding.** It returns the request headers that
 * bear on compression and the NAMES of the others — never their values, since one of them carries
 * a bearer token. It reads no database, touches no session, and identifies nobody.
 *
 * ⚠ **Temporary.** Remove it, and its route, once the question is answered.
 */
class EncodingProbeController extends Controller
{
    public function __invoke(Request $request)
    {
        $sample = str_repeat('{"key":"a translated line, long enough to be worth compressing"},', 200);

        // ?force=gzip — does an answer PHP compressed ITSELF survive the way out?
        //
        // 🔴 The first run answered the first question: `Accept-Encoding` never reaches PHP, so no
        // application code can key off it. But `user-agent` DOES arrive, so deciding here is still
        // conceivable — on one condition, which nothing observed so far settles: that the proxy
        // in front passes our `Content-Encoding: gzip` through instead of inflating it.
        //
        // If the caller receives 0x1F 0x8B, it survives and a middleware keyed on the User-Agent
        // is possible. If it receives readable JSON, the proxy undoes the work and the whole
        // application route is closed.
        if ($request->query('force') === 'gzip') {
            return response(gzencode($sample, 6))
                ->header('Content-Type', 'application/json')
                ->header('Content-Encoding', 'gzip')
                ->header('X-Probe-Plain-Bytes', (string) strlen($sample));
        }

        return response()->json([
            // What reached PHP. If this is empty on the live site while curl sent it, question 1
            // is answered: the header does not survive the server.
            'accept_encoding_seen_by_php' => $request->header('Accept-Encoding', '(absent)'),

            // Some servers keep the original under another name when they consume it themselves.
            'alternates' => array_filter([
                'HTTP_ACCEPT_ENCODING' => $_SERVER['HTTP_ACCEPT_ENCODING'] ?? null,
                'HTTP_X_ORIGINAL_ACCEPT_ENCODING' => $_SERVER['HTTP_X_ORIGINAL_ACCEPT_ENCODING'] ?? null,
                'ORIG_HTTP_ACCEPT_ENCODING' => $_SERVER['ORIG_HTTP_ACCEPT_ENCODING'] ?? null,
            ]),

            // Names only. Enough to see whether the header was renamed rather than dropped.
            'header_names' => array_values(array_keys($request->headers->all())),

            // Can this PHP compress at all, and by how much on realistic content?
            'zlib_loaded' => extension_loaded('zlib'),
            'sample_plain_bytes' => strlen($sample),
            'sample_gzipped_bytes' => strlen(gzencode($sample, 6)),

            // Set by the server when it handles compression itself.
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? '(unknown)',
        ]);
    }
}
