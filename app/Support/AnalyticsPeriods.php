<?php

namespace App\Support;

/**
 * The spans the analytics screen offers, and what they mean.
 *
 * 🔴 **This used to live in a `@php` block inside `admin/analytics.blade.php`, with the clamp in the
 * controller.** That was tolerable while the span was only a display filter. It stopped being one
 * the day the version inventory started using it to decide what counts as GONE: a version with no
 * call inside the span is shown as extinct, and the reader is invited to break the API it uses.
 * **A rule that decides that cannot live in a view.**
 *
 * ⚠ The ceiling follows what is actually stored, it is not a fixed 365. Daily aggregates are kept
 * indefinitely, so anything asked beyond a year used to be quietly answered with a year — a wrong
 * measurement wearing the face of a right one.
 *
 * ⚠ 1 day is a real choice, not a floor: "yesterday and today" is the window for watching something
 * happen now, and the smallest offer used to be a week.
 */
class AnalyticsPeriods
{
    /** What the screen opens on. */
    public const DEFAULT_DAYS = 30;

    /**
     * The fixed offers, in days. "All" is added on top when there is more than a year stored.
     *
     * ⚠ **48 h and 72 h are here because a launch happens in them.** The list used to jump straight
     * from a day to a week, so the two spans that say whether a release is being picked up — the
     * day after, and the day after that — could only be reached by editing the address bar. A gap
     * in an offer is a question nobody gets to ask.
     *
     * ⚠ Hours below a week, days above: it is how every dashboard reads, and "24 h" was already
     * written that way. Mixing "2 days" in beside "24 h" would make two neighbouring buttons name
     * the same kind of thing differently.
     */
    private const OFFERS = [
        1 => '24 h',
        2 => '48 h',
        3 => '72 h',
        7 => '7 days',
        30 => '30 days',
        90 => '90 days',
        365 => '1 year',
    ];

    /**
     * How far back the screen may look: never less than a year, more once there is more stored.
     */
    public static function ceiling(int $daysStored): int
    {
        return max(365, $daysStored);
    }

    /**
     * The buttons, as [days => label], oldest span last.
     *
     * ⚠ "All" is only offered once there is more than a year to show — below that it would be a
     * second button asking for exactly what "1 year" already asks for.
     *
     * ⚠ **A span that is not on the list gets a button of its own.** Any number of days can be asked
     * for in the address, and it is answered — but without this, none of the buttons lit up and the
     * screen stopped saying which span it was showing. A bookmark, a shared link or a hand-typed
     * `?period=45` would leave the reader looking at figures with no idea what they cover.
     */
    public static function choices(int $daysStored, ?int $current = null): array
    {
        $choices = self::OFFERS;
        $ceiling = self::ceiling($daysStored);

        if ($ceiling > 365) {
            $choices[$ceiling] = 'All (' . number_format($daysStored) . ' d)';
        }

        if ($current !== null && !isset($choices[$current])) {
            $choices[$current] = self::label($current);
            ksort($choices);
        }

        return $choices;
    }

    /** What the request asked for, brought back inside what can be answered. */
    public static function clamp(mixed $requested, int $daysStored): int
    {
        return max(1, min((int) ($requested ?? self::DEFAULT_DAYS), self::ceiling($daysStored)));
    }

    /**
     * The span named inside a sentence: "nothing in the last 30 days".
     *
     * ⚠ Deliberately reuses the button's own words. The reader has just clicked one of them, and
     * naming the same span differently three inches lower reads as a different span.
     */
    public static function label(int $days): string
    {
        if (isset(self::OFFERS[$days])) {
            return self::OFFERS[$days];
        }

        // ⚠ Under a week, hours — the same way the buttons of that range are written. "4 days"
        // sitting between "72 h" and "7 days" would name the same kind of span two ways.
        return $days < 7
            ? ($days * 24) . ' h'
            : number_format($days) . ' days';
    }
}
