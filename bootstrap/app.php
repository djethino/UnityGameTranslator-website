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
        ]);

        // First in, last out: the security headers then cover everything, including the
        // responses that never reach a controller. Appended, it sat after SubstituteBindings,
        // so a 404 raised by a failed route binding went out with no CSP at all — and once
        // error pages started using the site layout, without the nonce the layout expects.
        $middleware->prependToGroup('web', [
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
