<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A publication refused because a fact read in the game's files contradicts the game found
 * (App\Services\GameFiling::refuseWrongGame). The message is a whole sentence, shown as-is by the
 * clients — it ends on the way out.
 */
class WrongGame extends RuntimeException
{
    /** What the upload API answers beside the sentence (spec/api-v1, RefusedCode). */
    public const Code = 'game_mismatch';
}
