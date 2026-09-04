<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Maximum concurrent live edit sessions
    |--------------------------------------------------------------------------
    |
    | Live edit sessions are created without authentication, so this cap is
    | what keeps the private disk from being filled by anonymous callers. The
    | worst case is this count multiplied by EditSessionToken::MAX_CONTENT_BYTES
    | (20 MB), so raise it only as far as the instance's disk allows.
    |
    | Reaching the cap answers 503 to new sessions, so it should stay
    | comfortably above the expected number of simultaneous editors.
    |
    */

    'max_active' => (int) env('EDIT_SESSION_MAX_ACTIVE', 200),

    /*
    |--------------------------------------------------------------------------
    | SSE server health endpoint
    |--------------------------------------------------------------------------
    |
    | Read by the admin analytics page to show how many streams are open. Only
    | the SSE server knows that figure — Laravel talks to it through Redis and
    | never over HTTP otherwise. Leave empty to hide the stream counter; the
    | page works without it.
    |
    */

    'sse_health_url' => env('SSE_HEALTH_URL'),

    /*
    | The secret the relay expects in X-Health-Token before it answers with its capacity, its
    | limits and its refusal counts — figures that only this site's admin page needs and that
    | somebody sizing a flood would like to have. Same value as the relay's HEALTH_TOKEN. Leave
    | empty and the relay answers the public minimum: the counters above simply stay unknown.
    */
    'sse_health_token' => env('SSE_HEALTH_TOKEN'),

];
