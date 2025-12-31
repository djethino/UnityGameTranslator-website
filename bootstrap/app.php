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
            'check.banned.api' => \App\Http\Middleware\CheckBannedApi::class,
        ]);

        // Check if user is banned on every request
        $middleware->appendToGroup('web', [
            \App\Http\Middleware\CheckBanned::class,
            \App\Http\Middleware\SetLocale::class,
            \App\Http\Middleware\TrackPageView::class,
            \App\Http\Middleware\ContentSecurityPolicy::class,
        ]);

        // Decode gzip-compressed API requests from Unity mod
        $middleware->prependToGroup('api', [
            \App\Http\Middleware\DecodeGzipRequest::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
