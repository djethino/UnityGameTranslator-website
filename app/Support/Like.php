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
     *
     * 🔴 **The backslash goes first, and it is not decoration.** Escaping only the two wildcards
     * turns an input of `\%` into `\\%`, which the engine reads as a literal backslash followed by
     * a live wildcard — so both characters stay injectable behind one. Measured against MariaDB:
     * searching for `\%` also matched `a\ANYTHINGb`. Escaping `\` into `\\` first leaves `\\\%`,
     * and only the literal matches.
     *
     * ⚠ Order matters and str_replace applies these in sequence: the backslashes it adds for the
     * wildcards must not themselves be doubled, which is exactly what putting `\` last would do.
     *
     * ⚠ This assumes the engine treats `\` as the LIKE escape, which MariaDB does unless
     * NO_BACKSLASH_ESCAPES is set — checked, it is not. Nothing here is an SQL injection either
     * way: every caller binds the value as a parameter, so the worst case was a search wider than
     * asked for, bounded by the limits above it.
     */
    public static function escape(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
