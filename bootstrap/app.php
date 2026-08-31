<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'admin' => \App\Http\Middleware\AdminMiddleware::class,
            'auth.api' => \App\Http\Middleware\AuthenticateApi::class,
            'auth.api.optional' => \App\Http\Middleware\OptionalAuthenticateApi::class,
            'check.banned.api' => \App\Http\Middleware\CheckBannedApi::class,

            // 🔴 **`throttle` counted once for the whole site, not once per route.** Laravel keys
            // the counter on the caller alone, so every `throttle:N,1` in this application shared
            // one tally while each was judged against its own N — the strictest route fell first,
            // on traffic it never received. Pressing "End session" in the live editor answered 429
            // on its first press, because the page had polled its own state twelve times.
            //
            // Replaced here rather than route by route: there are some forty of them, the fault is
            // in how the key is built, and a fix spread over forty lines is a fix somebody forgets
            // on the forty-first. See ThrottlePerRoute.
            'throttle' => \App\Http\Middleware\ThrottlePerRoute::class,
        ]);

        // GLOBAL, not on the web group, and that distinction is the whole point.
        //
        // ⚠ A URL matching no route never enters a route group. So while this sat on `web`, every
        // 404 rendered errors/404.blade.php — which extends the site layout, which asks for
        // $cspNonce — with no middleware having shared it: "Undefined variable $cspNonce", and a
        // 500 in place of the 404. Every mistyped address, dead link and stale indexed URL served
        // a server error, to visitors and to crawlers alike. Found 2026-08-11.
        //
        // Moving it to the group was already an attempt at this: it was appended before, sitting
        // after SubstituteBindings, so a 404 from a failed route binding went out with no CSP.
        // That fixed the bindings and left the unmatched URLs, which is the larger case.
        //
        // Safe to run on everything: the middleware returns early for any response that is not
        // text/html, so API answers are untouched.
        $middleware->prepend([
            \App\Http\Middleware\ContentSecurityPolicy::class,
        ]);

        // Check if user is banned on every request
        $middleware->appendToGroup('web', [
            \App\Http\Middleware\CheckBanned::class,
            \App\Http\Middleware\SetLocale::class,
            \App\Http\Middleware\TrackPageView::class,
            \App\Http\Middleware\PublicCacheHeaders::class,
        ]);

        // Decode gzip-compressed API requests from Unity mod
        $middleware->prependToGroup('api', [
            \App\Http\Middleware\DecodeGzipRequest::class,
        ]);

        // ...and compress the answers, which the host does not: it compresses HTML, CSS, JS and
        // SVG, but never application/json — so a translation leaves as 113 KB where 34 KB would do.
        //
        // ⚠ Prepended, so it is the LAST thing to touch the response on the way out and sees the
        // finished body. Both groups: the API serves the mod, and the editors fetch their JSON
        // over web routes, where the same bytes are just as uncompressed.
        //
        // ⚠ It compresses only for callers verified to inflate — the reasoning, and why it cannot
        // be the other way round on this host, is written in the middleware itself.
        $middleware->prependToGroup('api', [
            \App\Http\Middleware\CompressJsonResponse::class,
        ]);

        // Inventory of what we ship: which build of the mod or the Manager just called.
        // API only — this is not traffic analytics (TrackPageView covers that), it is the answer
        // to "is that old release still out there" and "is that loader adapter still used".
        $middleware->appendToGroup('api', [
            \App\Http\Middleware\TrackClientUsage::class,
        ]);
        $middleware->prependToGroup('web', [
            \App\Http\Middleware\CompressJsonResponse::class,
        ]);

        // The sign-in page can say WHY it is asking, and the menu links already pass it
        // (?action=upload). A guest who arrives by bookmark, shared link or a redirect from
        // the middleware got the same form with no explanation at all — the mechanism was
        // there, the redirect just did not use it. Derived from the route name so a new
        // protected page is covered by naming, not by a list to maintain.
        $middleware->redirectGuestsTo(function ($request) {
            $route = $request->route()?->getName() ?? '';
            $action = match (true) {
                str_contains($route, 'upload') => 'upload',
                str_contains($route, 'vote') => 'vote',
                str_contains($route, 'report') => 'report',
                default => null,
            };

            return route('login', array_filter([
                'redirect' => $request->fullUrl(),
                'action' => $action,
            ]));
        });

        // navigator.sendBeacon (page close signal) cannot carry a CSRF token.
        // Safe to exempt: session-bound, no parameters, and a forged call only
        // marks the browser as away — the page's 10s state heartbeat rejoins
        // well within the mod's 90s grace period.
        $middleware->validateCsrfTokens(except: [
            'edit-session-leave',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
