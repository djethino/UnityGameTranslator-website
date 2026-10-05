<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * The period an admin screen reads its figures over: the last N days up to now, or two dates.
 *
 * 🔴 **Two dates, asked on 2026-10-05** ("si on a des stats > à 1 an, je ne vois nul part où les
 * voir" → "oui tu peux le faire"): the spans only ever ended today, so March of last year could
 * not be read on its own, nor two months compared. A past range is now one more answer to the same
 * question, read by the same code: every figure of the screen asks `from` and `to` of this object,
 * never `now()` — a figure that did would quietly show another span than the one named.
 *
 * ⚠ **"Today" exists only in a span that reaches it.** The analytics screen adds today, counted
 * live, to the days already aggregated; a range that ends in the past must not.
 */
final class Span
{
    private function __construct(
        /** The first day, from its first second. */
        public readonly CarbonImmutable $from,
        /** The last day, to its last second — now, for a span ending today. */
        public readonly CarbonImmutable $to,
        /** The offer pressed, in days — null for two dates. */
        public readonly ?int $days,
    ) {
    }

    /**
     * What the request asks for: `from` and `to` (Y-m-d) when both are valid dates, otherwise
     * `period` (AnalyticsPeriods). Dates are brought inside what is stored and what has happened:
     * nothing before the first stored day, nothing after today, `from` never after `to`.
     */
    public static function fromRequest(Request $request, int $daysStored): self
    {
        $from = self::date($request->get('from'));
        $to = self::date($request->get('to'));

        if ($from !== null && $to !== null) {
            $today = CarbonImmutable::today();
            $oldest = $today->subDays(max(1, $daysStored) - 1);

            if ($from->greaterThan($to)) {
                [$from, $to] = [$to, $from];
            }
            $to = $to->greaterThan($today) ? $today : $to;
            $from = $from->lessThan($oldest) ? $oldest : $from;
            // Both outside on the same side: the nearest stored day, once.
            $from = $from->greaterThan($to) ? $to : $from;

            return new self($from->startOfDay(), $to->isToday() ? CarbonImmutable::now() : $to->endOfDay(), null);
        }

        $days = AnalyticsPeriods::clamp($request->get('period'), $daysStored);

        return new self(CarbonImmutable::now()->subDays($days), CarbonImmutable::now(), $days);
    }

    /** A rolling span of `$days` up to now — for code that is handed a number of days. */
    public static function lastDays(int $days): self
    {
        return new self(CarbonImmutable::now()->subDays($days), CarbonImmutable::now(), $days);
    }

    /** Two dates were asked for, rather than an offer. */
    public function isRange(): bool
    {
        return $this->days === null;
    }

    /** Whether today is inside the span — and therefore its live figures. */
    public function includesToday(): bool
    {
        return $this->to->isToday();
    }

    /** How many calendar days it touches. */
    public function dayCount(): int
    {
        return (int) $this->from->startOfDay()->diffInDays($this->to->startOfDay()) + 1;
    }

    /** How the screen names it: "Last 30 days", or "2027-03-01 → 2027-03-31". */
    public function label(): string
    {
        return $this->isRange()
            ? $this->from->toDateString() . ($this->dayCount() > 1 ? ' → ' . $this->to->toDateString() : '')
            : 'Last ' . AnalyticsPeriods::label($this->days);
    }

    /** The address parameters that ask for this span again, for links that keep it. */
    public function query(): array
    {
        return $this->isRange()
            ? ['from' => $this->from->toDateString(), 'to' => $this->to->toDateString()]
            : ['period' => $this->days];
    }

    private static function date(mixed $value): ?CarbonImmutable
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);

        return $date && $date->toDateString() === $value ? $date : null;
    }
}
