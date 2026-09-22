<?php

namespace App\Services;

/**
 * Whether this visitor asked to see games marked for adults only — for anybody, account or not.
 *
 * 🔴 **Off by default, and it stays off for a crawler.** Googlebot has no session, so it always
 * gets the default listing; that is what a first-time visitor gets too, so it is not cloaking. It
 * also means the marked games are reachable for indexing ONLY through the sitemap — which is why
 * they stay in it.
 *
 * ## Why the session and not a cookie of ours
 *
 * Asked on 2026-09-22: "le cookie ça expose les gens ?". Not to other sites — a cookie is
 * partitioned by domain and this site loads no third-party script. But a PERSISTENT cookie whose
 * name says "adult" is readable in any browser's dev tools, months later, on a shared machine;
 * Laravel encrypts the value, never the name. The session cookie already exists, costs nothing
 * more, and dies with the browser. For a filter that REVEALS rather than hides, starting over at
 * each visit is the right behaviour, not a cost.
 *
 * Precedence, most explicit first: what this browsing session was told · the account. The session
 * wins because it is the box the person just ticked on the page in front of them; the account is
 * for somebody who does not want to tick it again on another machine.
 *
 * ⚠ The account setting is a durable record of an adult opt-in, so it is personal data: it belongs
 * in the profile export and goes with the account when it is deleted.
 */
class AdultVisibility
{
    public const SESSION_KEY = 'show_adult_games';

    /**
     * May this request see games marked for adults only?
     */
    public static function allowed(): bool
    {
        $session = session(self::SESSION_KEY);

        if ($session !== null) {
            return (bool) $session;
        }

        return (bool) auth()->user()?->show_adult_games;
    }

    /**
     * Record what the box on the page says, for this browsing session only.
     *
     * ⚠ Never written to the account here. Ticking a box on a listing answers "for now"; changing
     * what every machine does is a different act, and it has its own place in the profile.
     */
    public static function remember(bool $show): void
    {
        session([self::SESSION_KEY => $show]);
    }
}
