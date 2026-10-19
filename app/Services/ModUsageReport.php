<?php

namespace App\Services;

use App\Models\Game;
use App\Models\Translation;
use App\Support\Span;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What the admin "Mod usage" screen shows: `usage_daily` over a span (ModUsage writes it).
 *
 * ⚠ **A session is one copy on one day, and a span ADDS days**: a copy playing every day of a week
 * is seven sessions. Distinct copies over a span cannot be had — the fingerprint that would tell
 * them apart is destroyed every night, which is the point. The screen says it.
 *
 * ⚠ **Today is counted again each time the screen opens** (ModUsage::countDay rewrites the day
 * whole), so the screen and the night read one table and one shape.
 */
final class ModUsageReport
{
    public function __construct(public readonly Span $span)
    {
        if ($span->includesToday()) {
            ModUsage::countDay(now()->toDateString());
        }
    }

    /** Days since counting began — the ceiling of the span (AnalyticsPeriods). */
    public static function daysStored(): int
    {
        // From the day counting began, like copies(): one beginning for the whole screen.
        return max(1, (int) CarbonImmutable::parse(ModUsage::COUNTING_STARTED)->diffInDays(now()) + 1);
    }

    /** The span's rows of one act. */
    private function rows(string $act)
    {
        return DB::table('usage_daily')
            ->where('act', $act)
            ->whereBetween('date', [$this->span->from->toDateString(), $this->span->to->toDateString()]);
    }

    /**
     * Sessions of the mod over the span — every copy that called the site, whatever for. Read from
     * `client_usage_daily`, which counts copies with the same daily fingerprint, so it is the whole
     * the sessions below are a part of.
     */
    public function copies(): int
    {
        // 🔴 **Only over the days usage was counted.** Copies are counted since 2026-08-20, usage
        // since ModUsage::COUNTING_STARTED: over a span reaching before, the copies of days nobody
        // looked at would all read as "called for something else only" — a measurement of something
        // never measured.
        //
        // ⚠ A fixed date, not the first row of `usage_daily`: before any act is counted that table is
        // empty, and this tile read 0 while the dashboard — reading the same copies — said 1.
        $from = max($this->span->from->toDateString(), ModUsage::COUNTING_STARTED);

        return (int) DB::table('client_usage_daily')
            ->where('product', \App\Support\ClientAgent::MOD)
            ->whereBetween('date', [$from, $this->span->to->toDateString()])
            ->sum('installs');
    }

    /**
     * What each session did at most, with how many of them were signed in.
     *
     * @return array<string, array{sessions: int, signed_in: int}> in ModUsage::SESSION_OUTCOMES order
     */
    public function sessions(): array
    {
        $rows = $this->rows(ModUsage::SESSION)
            ->selectRaw('outcome, SUM(sessions) as n, SUM(CASE WHEN signed_in = 1 THEN sessions ELSE 0 END) as signed')
            ->groupBy('outcome')
            ->get()
            ->keyBy('outcome');

        return collect(ModUsage::SESSION_OUTCOMES)->map(fn ($_, $outcome) => [
            'sessions' => (int) ($rows[$outcome]->n ?? 0),
            'signed_in' => (int) ($rows[$outcome]->signed ?? 0),
        ])->all();
    }

    /** Sessions that took a translation — a session taking two counts twice. */
    public function takes(): int
    {
        return (int) $this->rows(ModUsage::TAKE)->sum('sessions');
    }

    /**
     * One stacked bar per day, a band per outcome — the shape the Flows chart draws
     * (`admin-charts.js`, stackedBars).
     */
    public function daily(): array
    {
        $days = $this->span->days();
        $buckets = array_fill_keys($days, 0);

        $byOutcome = [];
        foreach ($this->rows(ModUsage::SESSION)->selectRaw('date, outcome, SUM(sessions) as n')->groupBy('date', 'outcome')->get() as $row) {
            $byOutcome[$row->outcome][substr((string) $row->date, 0, 10)] = (int) $row->n;
        }

        return [
            'labels' => array_map(fn ($day) => CarbonImmutable::parse($day)->format('d/m'), $days),
            'datasets' => collect(ModUsage::SESSION_OUTCOMES)
                ->filter(fn ($_, $outcome) => isset($byOutcome[$outcome]))
                ->map(fn ($style, $outcome) => [
                    'label' => $style['label'],
                    'rgb' => $style['rgb'],
                    'data' => array_values(array_replace($buckets, array_intersect_key($byOutcome[$outcome], $buckets))),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * The games looked for with nothing in the language asked — what to translate next.
     *
     * Each row also counts the sessions that DID find one for that game and language in the span: a
     * translation published since shows up beside the demand it answers. And whether one is listed
     * now, which no past day can say.
     *
     * @return list<array{game: ?Game, steam_id: ?string, game_name: ?string, language: string, without: int, found: int, other_languages: bool, available_now: bool}>
     */
    public function wanted(int $limit): array
    {
        $rows = $this->rows(ModUsage::LOOK)
            ->select('game_id', 'steam_id', 'game_name', 'language')
            ->selectRaw('SUM(CASE WHEN outcome = ? THEN 0 ELSE sessions END) as without', [ModUsage::FOUND])
            ->selectRaw('SUM(CASE WHEN outcome = ? THEN sessions ELSE 0 END) as found', [ModUsage::FOUND])
            ->selectRaw('SUM(CASE WHEN outcome = ? THEN sessions ELSE 0 END) as other_languages', [ModUsage::OTHER_LANGUAGES])
            ->groupBy('game_id', 'steam_id', 'game_name', 'language')
            ->havingRaw('SUM(CASE WHEN outcome = ? THEN 0 ELSE sessions END) > 0', [ModUsage::FOUND])
            ->orderByDesc('without')
            ->limit($limit)
            ->get();

        $gameIds = $rows->pluck('game_id')->filter()->unique()->values();
        $games = Game::whereIn('id', $gameIds)->get()->keyBy('id');
        // Listed today, as game|language — one query for the rows shown.
        $listed = Translation::publiclyListed()->whereIn('game_id', $gameIds)
            ->distinct()->get(['game_id', 'target_language'])
            ->map(fn ($t) => $t->game_id . '|' . $t->target_language)
            ->flip();

        return $rows->map(fn ($r) => [
            'game' => $r->game_id ? $games->get($r->game_id) : null,
            'steam_id' => $r->steam_id,
            'game_name' => $r->game_name,
            'language' => $r->language,
            'without' => (int) $r->without,
            'found' => (int) $r->found,
            'other_languages' => (int) $r->other_languages > 0,
            'available_now' => $listed->has($r->game_id . '|' . $r->language),
        ])->all();
    }

    /**
     * The translations of the site in use, most played first, with what the copies are to them.
     *
     * @return list<array{id: int, translation: ?Translation, sessions: int, same: int, differs: int, own: int, unknown: int, signed_in: int}>
     */
    public function inUse(int $limit): array
    {
        $rows = $this->rows(ModUsage::PLAY)
            ->whereNotNull('translation_id')
            ->select('translation_id')
            ->selectRaw('SUM(sessions) as n')
            ->selectRaw('SUM(CASE WHEN outcome = ? THEN sessions ELSE 0 END) as same', [ModUsage::SAME])
            ->selectRaw('SUM(CASE WHEN outcome = ? THEN sessions ELSE 0 END) as differs', [ModUsage::DIFFERS])
            ->selectRaw('SUM(CASE WHEN outcome = ? THEN sessions ELSE 0 END) as own', [ModUsage::OWN])
            ->selectRaw('SUM(CASE WHEN signed_in = 1 THEN sessions ELSE 0 END) as signed')
            ->groupBy('translation_id')
            ->orderByDesc('n')
            ->limit($limit)
            ->get();

        // A deleted translation is still named by its number: it was played, whatever became of it.
        $translations = Translation::with('game:id,name')->whereIn('id', $rows->pluck('translation_id'))->get()->keyBy('id');

        return $rows->map(fn ($r) => [
            'id' => (int) $r->translation_id,
            'translation' => $translations->get($r->translation_id),
            'sessions' => (int) $r->n,
            'same' => (int) $r->same,
            'differs' => (int) $r->differs,
            'own' => (int) $r->own,
            'unknown' => (int) $r->n - (int) $r->same - (int) $r->differs - (int) $r->own,
            'signed_in' => (int) $r->signed,
        ])->all();
    }

    /**
     * The languages asked for, with how many of those looks found one. A look with no catalogue
     * language is left out: it says nothing about a language.
     *
     * @return array<string, array{looks: int, found: int}> most asked first
     */
    public function languages(): array
    {
        return $this->rows(ModUsage::LOOK)
            ->where('language', '!=', '')
            ->select('language')
            ->selectRaw('SUM(sessions) as n')
            ->selectRaw('SUM(CASE WHEN outcome = ? THEN sessions ELSE 0 END) as found', [ModUsage::FOUND])
            ->groupBy('language')
            ->orderByDesc('n')
            ->get()
            ->mapWithKeys(fn ($r) => [$r->language => ['looks' => (int) $r->n, 'found' => (int) $r->found]])
            ->all();
    }
}
