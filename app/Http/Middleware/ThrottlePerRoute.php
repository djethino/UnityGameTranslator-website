<?php

namespace App\Http\Middleware;

use Illuminate\Routing\Middleware\ThrottleRequests;

/**
 * `throttle:N,1`, counted PER ROUTE instead of once for the whole site.
 *
 * 🔴 **Laravel's own signature is the caller, never the route.** `resolveRequestSignature()`
 * returns the signed-in user, or the domain and the IP — and nothing else. So every route carrying
 * a bare `throttle:N,1` incremented ONE shared counter, while each was compared against its OWN N.
 * The strictest route on the site therefore fell first, fed by traffic it never received.
 *
 * Found on the live editor, and it is the shape of the whole defect: the page polls its state every
 * ten seconds (`throttle:30,1`, twelve polls a minute — comfortably inside its own allowance), and
 * pressing "End session" (`throttle:10,1`) answered **429 Too Many Requests** on its first press.
 * Nothing had been hammered; the page had been open for a minute.
 *
 * ⚠ **The expiry belonged to whichever route opened the window**, which is what makes this so hard
 * to recognise from a bug report. `RateLimiter::hit` sets the TTL when it creates the key, so one
 * request to a `throttle:5,60` route made every other route on the site count inside an HOUR-long
 * bucket. Two people doing the same thing got different answers depending on what they had touched
 * first.
 *
 * ⚠ **Not a loosening.** Each route keeps exactly the limit written beside it in the route file;
 * they simply stop spending each other's budget. What was in force before was not a policy anybody
 * chose — it was the accidental minimum of everything declared anywhere.
 *
 * ⚠ The route NAME is preferred over its path: paths carry ids and tokens, and one bucket per token
 * would be no limit at all. Unnamed routes fall back on the matched URI pattern — the pattern, not
 * the filled-in URL — so `/edit-session/{token}` stays one bucket for everybody.
 */
class ThrottlePerRoute extends ThrottleRequests
{
    protected function resolveRequestSignature($request)
    {
        $caller = parent::resolveRequestSignature($request);

        $route = $request->route();
        $which = $route?->getName() ?: $route?->uri() ?: $request->path();

        return sha1($which . '|' . $caller);
    }
}
