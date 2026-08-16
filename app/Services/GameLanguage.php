<?php

namespace App\Services;

use App\Models\User;

/**
 * The language the visitor plays in — for anybody, account or not.
 *
 * 🔴 **Signing in is not what makes the question answerable.** This used to live on the User model,
 * so a visitor had no way to say which language they play in: the site guessed from the browser and
 * offered no way to correct the guess. A guess you cannot rectify is worse than no guess, because it
 * silently reorders every list of games and never says why.
 *
 * ⚠ The session is not a new concession: the interface language already goes through it for
 * everybody (LocaleController::switch), signed in or not. It costs no extra cookie — the session
 * cookie is there regardless — and it is nothing like a remembered sort order, which is invisible.
 * A selector SHOWS its state; that is its whole job, and the "detected" badge says when the value
 * is only a guess.
 *
 * Precedence, most explicit first: the account · this browser's session · the browser's own
 * languages. The page-level `?target=` filter sits above all of it and stays in GameController —
 * it answers "order this page like that", not "this is what I play in".
 */
class GameLanguage
{
    public const SESSION_KEY = 'game_language';

    /**
     * The tag somebody actually CHOSE, or null when nothing was chosen.
     *
     * ⚠ The account first, and its session as a fallback rather than the reverse: a choice made
     * before signing in should not vanish at the door, but it must never override what the account
     * says. Choosing "follow the browser" clears both, so an old session value cannot resurface.
     */
    public static function tag(): ?string
    {
        $stored = auth()->user()?->game_language;
        if ($stored) {
            return $stored;
        }

        $session = session(self::SESSION_KEY);

        // Session content is user input like any other, and the catalogue can shrink between two
        // visits. An unknown tag is treated as no choice at all.
        return is_string($session) && CatalogStore::nameOfCode($session) !== null ? $session : null;
    }

    /**
     * The language NAME to rank translations by — chosen if there is one, detected otherwise.
     *
     * A name, not a tag, because that is what translations carry in `target_language`.
     */
    public static function name(): ?string
    {
        $tag = self::tag();

        return $tag !== null
            ? CatalogStore::nameOfCode($tag)
            : User::detectedGameLanguage();
    }

    /**
     * Was this stated, or is the site guessing? Drives the "detected" badge on the selector.
     */
    public static function isChosen(): bool
    {
        return self::tag() !== null;
    }

    /**
     * Record a choice — null meaning "follow the browser again".
     */
    public static function remember(?string $tag): void
    {
        // Written for everybody, including signed-in visitors: the two stay in step, so signing
        // out on a shared computer does not hand the next reader somebody else's ranking through
        // a session that outlived the account it came from.
        session([self::SESSION_KEY => $tag]);

        auth()->user()?->update(['game_language' => $tag]);
    }
}
