<?php

namespace App\Services;

use App\Models\Game;
use App\Models\Translation;
use App\Support\Span;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
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

    /** How each table may be sorted — the column keys its headers send (x-admin.sortable-th). */
    public const WANTED_SORTS = ['last', 'first', 'game', 'language', 'without', 'found'];
    public const IN_USE_SORTS = ['last', 'translation', 'game', 'language', 'sessions', 'same', 'differs', 'own', 'signed'];

    /** Rows per page of the two tables, like the other admin lists. */
    public const PER_PAGE = 20;

    /**
     * The games looked for with nothing in the language asked — which translations players want,
     * over the span (user, 2026-10-19: "quelles traductions intéressent le plus les joueurs sur des
     * périodes"). Every row, paged and sorted by the server; the most recently asked first.
     *
     * Each row also counts the sessions that DID find one for that game and language in the span: a
     * translation published since shows up beside the demand it answers. And whether one is listed
     * now, which no past day can say.
     *
     * ⚠ Sorted on what the columns SHOW: the game by the name on screen — the card's, else the name
     * or Steam id the mod sent — and the days as the first and last the span holds.
     */
    public function wanted(string $sort, string $dir, string $pageName): LengthAwarePaginator
    {
        $found = ModUsage::FOUND;
        $query = DB::table('usage_daily as u')
            ->leftJoin('games', 'games.id', '=', 'u.game_id')
            ->where('u.act', ModUsage::LOOK)
            ->whereBetween('u.date', [$this->span->from->toDateString(), $this->span->to->toDateString()])
            ->select('u.game_id', 'u.steam_id', 'u.game_name', 'u.language')
            ->selectRaw('MAX(games.name) as card_name')
            ->selectRaw('MIN(u.date) as first_day, MAX(u.date) as last_day')
            ->selectRaw('SUM(CASE WHEN u.outcome = ? THEN 0 ELSE u.sessions END) as without', [$found])
            ->selectRaw('SUM(CASE WHEN u.outcome = ? THEN u.sessions ELSE 0 END) as found', [$found])
            ->selectRaw('SUM(CASE WHEN u.outcome = ? THEN u.sessions ELSE 0 END) as other_languages', [ModUsage::OTHER_LANGUAGES])
            ->groupBy('u.game_id', 'u.steam_id', 'u.game_name', 'u.language')
            ->havingRaw('SUM(CASE WHEN u.outcome = ? THEN 0 ELSE u.sessions END) > 0', [$found]);

        match ($sort) {
            'first' => $query->orderBy('first_day', $dir),
            'game' => $query->orderByRaw('COALESCE(MAX(games.name), u.game_name, u.steam_id) ' . $dir),
            'language' => $query->orderBy('u.language', $dir),
            'without' => $query->orderBy('without', $dir),
            'found' => $query->orderBy('found', $dir),
            default => $query->orderBy('last_day', $dir),
        };
        // The same order on every page: ties broken by the demand, then by the row itself.
        $query->orderByDesc('without')->orderBy('u.game_id')->orderBy('u.steam_id')->orderBy('u.game_name')->orderBy('u.language');

        $page = $query->paginate(self::PER_PAGE, ['*'], $pageName)->withQueryString();

        $gameIds = collect($page->items())->pluck('game_id')->filter()->unique()->values();
        $games = Game::whereIn('id', $gameIds)->get()->keyBy('id');
        // Listed today, as game|language — one query for the page shown.
        $listed = Translation::publiclyListed()->whereIn('game_id', $gameIds)
            ->distinct()->get(['game_id', 'target_language'])
            ->map(fn ($t) => $t->game_id . '|' . $t->target_language)
            ->flip();

        return $page->through(fn ($r) => [
            'game' => $r->game_id ? $games->get($r->game_id) : null,
            'steam_id' => $r->steam_id,
            'game_name' => $r->game_name,
            'language' => $r->language,
            'first' => substr((string) $r->first_day, 0, 10),
            'last' => substr((string) $r->last_day, 0, 10),
            'without' => (int) $r->without,
            'found' => (int) $r->found,
            'other_languages' => (int) $r->other_languages > 0,
            'available_now' => $listed->has($r->game_id . '|' . $r->language),
        ]);
    }

    /**
     * The translations of the site in use, with what the copies are to them — every one played in
     * the span, paged and sorted by the server; the most recently played first.
     *
     * ⚠ The game and the language are read from the rows (ModUsage keeps them with each play), so a
     * translation deleted since still says what it was.
     */
    public function inUse(string $sort, string $dir, string $pageName): LengthAwarePaginator
    {
        $sumOf = fn (string $outcome, string $as) => ["SUM(CASE WHEN u.outcome = ? THEN u.sessions ELSE 0 END) as {$as}", [$outcome]];

        $query = DB::table('usage_daily as u')
            ->leftJoin('games', 'games.id', '=', 'u.game_id')
            ->where('u.act', ModUsage::PLAY)
            ->whereNotNull('u.translation_id')
            ->whereBetween('u.date', [$this->span->from->toDateString(), $this->span->to->toDateString()])
            ->select('u.translation_id')
            ->selectRaw('MAX(games.name) as card_name, MAX(u.language) as played_language, MAX(u.date) as last_day')
            ->selectRaw('SUM(u.sessions) as n')
            ->selectRaw(...$sumOf(ModUsage::SAME, 'same'))
            ->selectRaw(...$sumOf(ModUsage::DIFFERS, 'differs'))
            ->selectRaw(...$sumOf(ModUsage::OWN, 'own'))
            ->selectRaw('SUM(CASE WHEN u.signed_in = 1 THEN u.sessions ELSE 0 END) as signed')
            ->groupBy('u.translation_id');

        match ($sort) {
            'translation' => $query->orderBy('u.translation_id', $dir),
            'game' => $query->orderBy('card_name', $dir),
            'language' => $query->orderBy('played_language', $dir),
            'sessions' => $query->orderBy('n', $dir),
            'same', 'differs', 'own', 'signed' => $query->orderBy($sort, $dir),
            default => $query->orderBy('last_day', $dir),
        };
        $query->orderByDesc('n')->orderBy('u.translation_id');

        $page = $query->paginate(self::PER_PAGE, ['*'], $pageName)->withQueryString();

        // Linked only while it exists: a deleted one is named by its number.
        $existing = Translation::whereIn('id', collect($page->items())->pluck('translation_id'))->pluck('id')->flip();

        return $page->through(fn ($r) => [
            'id' => (int) $r->translation_id,
            'exists' => $existing->has($r->translation_id),
            'game' => $r->card_name,
            'language' => $r->played_language,
            'last' => substr((string) $r->last_day, 0, 10),
            'sessions' => (int) $r->n,
            'same' => (int) $r->same,
            'differs' => (int) $r->differs,
            'own' => (int) $r->own,
            'signed_in' => (int) $r->signed,
        ]);
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
