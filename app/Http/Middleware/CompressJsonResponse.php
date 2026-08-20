<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Compress JSON answers ourselves, because the host will not.
 *
 * 🔴 **Why this exists at all.** The server compresses `text/html`, `text/css`,
 * `application/javascript` and `image/svg+xml` — and not `application/json`, whether dynamic or a
 * static file. A translation download therefore leaves as 113 KB of plain text where 34 KB would
 * do. Measured on the live site, 2026-08-20.
 *
 * 🔴 **Why it decides on the User-Agent and not on `Accept-Encoding`.** There is a proxy in front
 * of PHP, and it STRIPS `Accept-Encoding` before the request arrives — verified: PHP sees the
 * header as absent while curl sent it. So the usual test is impossible here; what does arrive is
 * `user-agent`. Ordinary content negotiation is not available on this host.
 *
 * 🔴 **Hence a WHITELIST, and this is the whole safety argument.** Not knowing whether a caller
 * accepts gzip means compressing only for callers we have checked ourselves. The alternative — a
 * blacklist — would send gzip to every unknown client on the assumption that it copes, and this
 * project has already paid for that assumption: the mod announced gzip for its whole life while
 * being unable to inflate it, and every published build still does. Those builds are not on this
 * list, so they keep receiving exactly what they receive today.
 *
 * ⚠ **It cannot corrupt content.** gzip is lossless and applied to the finished bytes: no
 * re-encoding, no character set involved, an accented translation comes back identical. What could
 * go wrong is a client that cannot read it — which is what the whitelist answers — or a response
 * that must not be buffered, which is what the guards below answer.
 */
class CompressJsonResponse
{
    /**
     * Below this, compression costs more than it saves: gzip's own header and dictionary make a
     * short answer bigger, and every byte still has to be deflated.
     */
    private const MIN_BYTES = 1024;

    /**
     * Level 6, not 9. Measured on a real translation file: 9 shaves about 2% off 34 KB for
     * roughly twice the CPU, on a shared host, for every download.
     */
    private const LEVEL = 6;

    /**
     * Callers known to inflate what they are sent.
     *
     * ⚠ Each entry is a claim we have verified, not a guess:
     *  - the mod, ONLY in the shape that carries a version and a loader. Builds published up to
     *    2026-08-20 call themselves the bare literal "UnityGameTranslator/1.0" and cannot
     *    decompress; that literal matches nothing here, on purpose.
     *  - the Manager, which sets AutomaticDecompression on its handler (Manager.Core/Net/Http.cs)
     *    and has never been published without it.
     *  - browsers, which have inflated gzip for twenty-five years and are what loads the editors'
     *    JSON.
     */
    private const KNOWN_GOOD = [
        '#^UnityGameTranslator/\d+\.\d+\.\d+ \(#',
        '#^UnityGameTranslatorManager/#',
        '#^Mozilla/#',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (!$this->shouldCompress($request, $response)) {
            return $response;
        }

        $body = $response->getContent();

        if ($body === false || strlen($body) < self::MIN_BYTES) {
            return $response;
        }

        $packed = gzencode($body, self::LEVEL);

        // A body that grew is a body left alone. Rare on JSON, but free to check.
        if ($packed === false || strlen($packed) >= strlen($body)) {
            return $response;
        }

        $response->setContent($packed);
        $response->headers->set('Content-Encoding', 'gzip');
        $response->headers->set('Content-Length', (string) strlen($packed));

        // 🔴 The answer now depends on WHO asked. Without this, a cache anywhere on the way could
        // hand a compressed body to one of the very builds this whitelist exists to protect.
        //
        // ⚠ **The live host strips it back out** — measured 2026-08-20: sent from here, absent on
        // arrival. Harmless as things stand, because every one of these answers carries
        // `Cache-Control: private` (the listings add `no-cache`), so no shared cache may store
        // them in the first place and there is nothing for a `Vary` to disambiguate.
        //
        // ⚠ It stays, and it is not decoration: it is correct HTTP, it works on any other host,
        // and the day one of these endpoints is made publicly cacheable it is the only thing
        // standing between a CDN and a mod that cannot inflate. Whoever loosens a `Cache-Control`
        // here must check that this header survives to the client.
        $response->headers->set('Vary', trim(
            ($response->headers->get('Vary') ? $response->headers->get('Vary') . ', ' : '')
            . 'User-Agent, Accept-Encoding', ', '
        ));

        return $response;
    }

    private function shouldCompress(Request $request, Response $response): bool
    {
        // Streaming and file responses have no body to read here, and buffering one would defeat
        // the reason it streams: the live-edit endpoint readfile()s a translation to the mod.
        if ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse) {
            return false;
        }

        // 304 carries no body, 204 carries none by definition; HEAD must answer like GET without
        // one, and rewriting Content-Length there would misreport the real answer's size.
        if ($response->isEmpty() || $response->getStatusCode() === 304 || $request->isMethod('HEAD')) {
            return false;
        }

        // Somebody upstream already did it — never compress twice.
        if ($response->headers->has('Content-Encoding')) {
            return false;
        }

        $type = strtolower((string) $response->headers->get('Content-Type', ''));
        if (!str_contains($type, 'application/json') && !str_contains($type, '+json')) {
            return false;
        }

        return $this->callerCanInflate((string) $request->userAgent());
    }

    private function callerCanInflate(string $agent): bool
    {
        foreach (self::KNOWN_GOOD as $pattern) {
            if (preg_match($pattern, $agent) === 1) {
                return true;
            }
        }

        return false;
    }
}
