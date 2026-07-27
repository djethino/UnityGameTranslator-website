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

];
