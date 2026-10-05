<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A first publication refused because nothing says FOR SURE which game it is (App\Services\
 * GameFiling::cardFor) — "on refuse quand on n'est pas sûr". The message is a whole sentence, shown
 * as-is by the clients, ending on the way out; the code is the API's `refused_code`.
 *
 * - `game_not_picked`: a client that knows the publish list (it says what it read, `game_read`)
 *   sent no answer of it (`game_pick`);
 * - `game_ambiguous`: a client from before the list (a name, maybe a Steam id) named a game several
 *   games carry — cards of ours, or the stores' exact titles.
 */
class UnsureGame extends RuntimeException
{
    public const NotPicked = 'game_not_picked';
    public const Ambiguous = 'game_ambiguous';

    public function __construct(string $message, public readonly string $refusedCode)
    {
        parent::__construct($message);
    }
}
