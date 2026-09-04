<?php

namespace App\Http\Controllers;

abstract class Controller
{
    /**
     * Escapes what a person typed before it goes into a LIKE.
     *
     * ⚠ `%` and `_` are wildcards: left as they are, a search for "100%" matches everything from
     * "100" onwards, and one for "a_b" matches "axb". Kept here rather than in one controller
     * because three of them now search by name, and a second copy is a second chance to forget it.
     */
    protected function escapeLike(string $value): string
    {
        return str_replace(['%', '_'], ['\\%', '\\_'], $value);
    }
}
