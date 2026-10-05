<?php

namespace App\Exceptions;

/**
 * Steam's store could not be asked — our own ceiling reached, a refusal (429/403), or no answer.
 *
 * 🔴 **Not "no such game"** (user, 2026-10-05: "il ne faut pas … d'entrée erronée en base"). The
 * store's `success: false` is a fact about an app; this is the absence of any fact. Read as the
 * first, it refused publications as "game not found", recorded "nothing found" as an adult verdict
 * and cached "not a demo" for a month. Every caller decides what an unknown means for it: a list
 * shows the other sources, a check stays due, a publication that only Steam could identify says to
 * try again in a few minutes.
 */
class StoreUnavailable extends \RuntimeException
{
    public const Code = 'store_unavailable';

    /** What a person publishing reads — the mod shows it as-is. */
    public const Sentence = "Steam is not answering right now. Nothing was sent: try again in a few minutes.";

    public function __construct(string $why = 'Steam store unavailable')
    {
        parent::__construct($why);
    }
}
