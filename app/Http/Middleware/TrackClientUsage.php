<?php

namespace App\Http\Middleware;

use App\Models\AnalyticsEvent;
use App\Models\ClientUsageDaily;
use App\Support\ClientAgent;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Note which build of our own software just called.
 *
 * 🔴 **Nothing measured this before, and two decisions depended on it**: whether an old release is
 * still out there in numbers — which is what allows JSON compression to be switched on without
 * cutting those installs off (`CompressJsonResponse`) — and whether a mod loader adapter is still
 * worth maintaining. Until 2026-08-20 the mod called itself `UnityGameTranslator/1.0` whatever
 * build it was, so even the raw server logs could not have answered.
 *
 * ⚠ **Only our own programs.** A browser, a script, an unrecognised caller: nothing is written.
 * This is not general traffic analytics — page views already have `TrackPageView` — it is an
 * inventory of what we ship.
 *
 * ⚠ **Anonymous by construction, not by promise.** What lands in the database is one row per
 * (day, product, version, loader) with two counters. The fingerprint used to avoid counting the
 * same copy twice is the site's existing salted daily hash, which is not stored here and cannot be
 * followed from one day to the next.
 *
 * ⚠ **Never allowed to break a request.** A translation download must not fail because a counter
 * did — but the failure is reported, not swallowed: that is precisely how the API download's own
 * analytics stayed broken for months (an enum refusing `'mod'`, thrown and hidden).
 */
class TrackClientUsage
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $client = ClientAgent::ours($request->userAgent());

        if ($client !== null) {
            try {
                ClientUsageDaily::record(
                    $client,
                    AnalyticsEvent::generateVisitorHash(
                        $request->ip() ?? '0.0.0.0',
                        (string) $request->userAgent(),
                        now()->toDateString()
                    )
                );
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $response;
    }
}
