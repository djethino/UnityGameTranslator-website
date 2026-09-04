<?php

namespace App\Http\Controllers;

use App\Support\Like;

abstract class Controller
{
    /**
     * Escapes what a person typed before it goes into a LIKE.
     *
     * ⚠ A shorthand over <see cref="Like::escape"/>, which holds the rule — a service inherits from
     * no controller and needs it too, so the implementation cannot live here.
     */
    protected function escapeLike(string $value): string
    {
        return Like::escape($value);
    }
}
