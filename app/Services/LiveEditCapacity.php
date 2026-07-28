<?php

namespace App\Services;

use App\Models\EditSessionToken;
use Illuminate\Support\Facades\Http;

/**
 * How close live edit sessions are to their ceilings, right now.
 *
 * This is the one dimension that saturates on concurrency rather than traffic:
 * an open SSE stream holds one of the host's concurrent request slots for its
 * whole life, so a few dozen simultaneous editors can starve the entire account
 * — storefront included — long before bandwidth or disk become a concern.
 *
 * Read by the admin page (instant gauge) and by the scheduler (daily peaks in
 * analytics_daily). Both need the same two numbers, hence one place to get them.
 */
class LiveEditCapacity
{
    /**
     * Both counts with their ceilings. Streams are null when the SSE server
     * cannot be asked — an unknown value, never to be shown or stored as zero.
     */
    public static function current(): array
    {
        $capacity = [
            'sessions' => EditSessionToken::where('expires_at', '>', now())->count(),
            'sessions_max' => EditSessionToken::maxActiveSessions(),
            'streams' => null,
            'streams_max' => null,
        ];

        $healthUrl = config('edit_session.sse_health_url');
        if (!$healthUrl) {
            return $capacity;
        }

        try {
            // Only the SSE server knows its own connection count: Laravel talks
            // to it through Redis and never over HTTP otherwise. Short timeout —
            // this runs inside an admin page render and inside a cron tick, and
            // a missing figure is merely a missing figure.
            $response = Http::timeout(2)->get($healthUrl);
            if ($response->successful()) {
                $capacity['streams'] = $response->json('connections');
                $capacity['streams_max'] = $response->json('max_connections');
            }
        } catch (\Throwable $e) {
            // Leave both null: the page says so, the sampler skips the reading
        }

        return $capacity;
    }
}
