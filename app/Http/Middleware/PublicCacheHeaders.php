<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PublicCacheHeaders
{
    /**
     * Add public cache headers to responses for non-authenticated GET requests.
     * This helps search engine crawlers and CDNs cache public pages.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Only apply to successful GET requests from non-authenticated users
        if ($request->method() !== 'GET') {
            return $response;
        }

        $statusCode = $response->getStatusCode();
        if ($statusCode < 200 || $statusCode >= 400) {
            return $response;
        }

        // Don't override if already set explicitly by the controller
        if ($response->headers->has('X-Cache-Set')) {
            return $response;
        }

        // For authenticated users, keep private
        if (auth()->check()) {
            return $response;
        }

        // 🔴 THE LANGUAGE MUST BE IN THE URL, or nothing here may be cached.
        //
        // A page reached without a locale prefix is rendered in whatever language the SESSION
        // holds. That language is in neither the URL nor the cookie — the cookie is the same
        // before and after switching — so the two responses are byte-identical requests with
        // different bodies, and **no Vary can tell them apart**. The Vary set here said
        // "Cookie, Accept-Language" and was honest about the intent while being unable to work.
        //
        // What it produced, reported on 2026-08-16: switch to Spanish on /games, click Docs,
        // and the browser serves its English copy of /docs from less than a minute ago. With
        // s-maxage a shared cache would go further and hand one visitor's language to the next.
        //
        // Prefixed URLs keep the cache, and they are the ones that matter: the layout advertises
        // /es/games and friends through hreflang, so that is what crawlers index. The bare URL is
        // only x-default, and it is precisely the one whose body depends on who is asking.
        if (SetLocale::localeInPath($request) === null) {
            $response->headers->set('Cache-Control', 'private, no-cache');

            return $response;
        }

        // Public pages: allow shared caches (CDN, proxies, crawlers)
        // Vary by Cookie so a shared cache never serves a signed-in rendering to somebody else
        $response->headers->set('Cache-Control', 'public, max-age=60, s-maxage=300');
        $response->headers->set('Vary', 'Cookie, Accept-Language');

        return $response;
    }
}
