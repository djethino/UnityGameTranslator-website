<?php

namespace App\Support;

/**
 * Escaping for what a person typed, before it goes into a LIKE.
 *
 * 🔴 **One implementation, because there were five.** Three controllers carried a private copy and
 * two more places inlined the same `str_replace` — including a service, which inherits from no
 * controller and so could not share theirs. Five copies of one rule is five chances for the next
 * search to forget it.
 */
class Like
{
    /**
     * ⚠ `%` and `_` are wildcards. Left as they are, a search for "100%" matches everything from
     * "100" onwards, and one for "a_b" matches "axb" — a person searching gets results they did
     * not ask for, and a name with an underscore cannot be searched for at all.
     */
    public static function escape(string $value): string
    {
        return str_replace(['%', '_'], ['\\%', '\\_'], $value);
    }
}
