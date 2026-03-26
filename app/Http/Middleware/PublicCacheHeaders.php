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

        // Public pages: allow shared caches (CDN, proxies, crawlers)
        $response->headers->set('Cache-Control', 'public, max-age=60, s-maxage=300');

        return $response;
    }
}
