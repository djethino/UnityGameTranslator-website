<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\Like;
use App\Support\Span;
use App\Support\TranslationFlows;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Collection;

/**
 * What the admin "Flows" screen shows: the events of TranslationFlows over a span, filtered.
 *
 * ⚠ **The filters narrow everything, the TYPE only narrows the list.** The tiles, the chart and the
 * breakdowns are the distribution the type is picked FROM — narrowing them by it would leave one
 * tile lit and six at zero, which says nothing.
 *
 * ⚠ Counts and the chart are asked of the database. The breakdowns read each event's metadata in
 * PHP, and that is deliberate rather than lazy: they look inside JSON (how a game was named, how
 * long a translation lived) where SQL would differ between MariaDB and SQLite. It stays cheap
 * because these are human acts — a publication, a deletion — not page views: two columns of a
 * few thousand rows a year.
 */
class TranslationFlowReport
{
    /**
     * @param Span $span the last N days, or two dates (App\Support\Span)
     * @param array{type?: ?string, game?: ?int, user?: ?int, language?: ?string, translation?: ?int, via?: ?string} $filters
     */
    public function __construct(public readonly Span $span, public readonly array $filters)
    {
    }

    /** Days since the oldest event the screen can show — the ceiling of its span (AnalyticsPeriods). */
    public static function daysStored(): int
    {
        $oldest = AuditLog::whereIn('action', TranslationFlows::actions())->min('created_at');

        return $oldest ? max(1, (int) Carbon::parse($oldest)->diffInDays(now()) + 1) : 1;
    }

    /** How many of each event, under the filters but not the type. */
    public function counts(): array
    {
        $counts = $this->query(withType: false)
            ->selectRaw('action, COUNT(*) as n')
            ->groupBy('action')
            ->pluck('n', 'action');

        return collect(TranslationFlows::actions())
            ->mapWithKeys(fn ($action) => [$action => (int) ($counts[$action] ?? 0)])
            ->all();
    }

    /** Up to this many days, the chart reads in hours: two or three daily bars say nothing. */
    public const HOURLY_UP_TO_DAYS = 3;

    /**
     * One stacked bar per day — or per hour when the span is three days or less — every bucket of
     * the span shown, an empty one included.
     *
     * ⚠ Hours are bucketed in PHP: grouping by hour in SQL is written differently on MariaDB and
     * SQLite, and three days of human acts are few rows. Days stay counted by the database.
     */
    public function daily(): array
    {
        $hourly = $this->span->dayCount() <= self::HOURLY_UP_TO_DAYS;
        $keyOf = fn (Carbon $at) => $hourly ? $at->format('Y-m-d H') : $at->toDateString();

        $buckets = [];
        $start = Carbon::instance($hourly ? $this->span->from->startOfHour() : $this->span->from->startOfDay());
        for ($at = $start; $at->lte($this->span->to); $hourly ? $at->addHour() : $at->addDay()) {
            $buckets[$keyOf($at)] = 0;
        }

        $byAction = [];
        if ($hourly) {
            foreach ($this->query(withType: false)->get(['action', 'created_at']) as $event) {
                $key = $keyOf($event->created_at);
                $byAction[$event->action][$key] = ($byAction[$event->action][$key] ?? 0) + 1;
            }
        } else {
            $rows = $this->query(withType: false)
                ->selectRaw('DATE(created_at) as day, action, COUNT(*) as n')
                ->groupBy('day', 'action')
                ->get();
            foreach ($rows as $row) {
                $byAction[$row->action][Carbon::parse($row->day)->toDateString()] = (int) $row->n;
            }
        }

        return [
            'hourly' => $hourly,
            'labels' => array_map(
                fn ($key) => $hourly
                    ? Carbon::createFromFormat('Y-m-d H', $key)->format('d/m H\h')
                    : Carbon::parse($key)->format('d/m'),
                array_keys($buckets)),
            'datasets' => collect(TranslationFlows::actions())
                ->filter(fn ($action) => isset($byAction[$action]))
                ->map(fn ($action) => [
                    'label' => TranslationFlows::LABELS[$action]['label'],
                    'rgb' => TranslationFlows::LABELS[$action]['rgb'],
                    'data' => array_values(array_replace($buckets, array_intersect_key($byAction[$action], $buckets))),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Events in runs: the same act on the same translation by the same account, one after the
     * other, read as ONE line (2026-10-05: thirteen identical "Update" rows in a row said less than
     * "13 updates, 7,617 → 8,705 lines"). Each run keeps its events, to be opened.
     *
     * ⚠ Only acts that repeat as work goes on — a publication, an edit, a details change. A
     * deletion, a move, a fork or a refusal is always its own line: each is a decision.
     *
     * Takes full events or the light rows `eventRuns` reads (`is_update` selected beside them).
     *
     * @return list<array{events: list<object>}>
     */
    public static function runs(iterable $events): array
    {
        $repeating = [TranslationFlows::PUBLISHED, TranslationFlows::CONTENT_SAVED, TranslationFlows::DETAILS_CHANGED];
        $runs = [];

        foreach ($events as $event) {
            $last = $runs === [] ? null : $runs[count($runs) - 1]['events'][0];
            $joins = $last !== null
                && in_array($event->action, $repeating, true)
                && $event->action === $last->action
                && $event->entity_id !== null && $event->entity_id === $last->entity_id
                && $event->user_id === $last->user_id
                // A new translation is a birth, never folded into the updates that follow it.
                && !($event->action === TranslationFlows::PUBLISHED && !self::isUpdate($event))
                && !($last->action === TranslationFlows::PUBLISHED && !self::isUpdate($last));

            if ($joins) {
                $runs[count($runs) - 1]['events'][] = $event;
            } else {
                $runs[] = ['events' => [$event]];
            }
        }

        return $runs;
    }

    /** The orders the list offers — the column name in the address. Time breaks every tie. */
    public const SORTS = ['when', 'event', 'account'];

    /**
     * The list, one LINE per run, paginated on those lines (2026-10-05: the pages counted events
     * while the screen grouped them, so page 1 showed one line and "20 pages" stood under it).
     *
     * 🔴 Runs are cut over the WHOLE filtered list, never page by page: a run cut at a page break
     * would show as two lines, and the page count would still not be the count of lines. Only the
     * light columns are read for that — the full events are loaded for the page shown.
     *
     * @return array{lines: LengthAwarePaginator, events: int}
     */
    public function eventRuns(int $perPage, int $page, string $sort, string $dir): array
    {
        $dir = $dir === 'asc' ? 'asc' : 'desc';
        $query = $this->query(withType: true);

        match ($sort) {
            'event' => $query->orderBy('action', $dir),
            'account' => $query->orderBy(User::select('name')->whereColumn('users.id', 'audit_logs.user_id'), $dir),
            default => null,
        };
        // Time always last, so a run reads in order whatever the first key, and equal keys never
        // swap between pages.
        $timeDir = $sort === 'when' ? $dir : 'desc';
        $query->orderBy('created_at', $timeDir)->orderBy('id', $timeDir);

        $rows = $query->toBase()->get(['id', 'action', 'entity_id', 'user_id', 'metadata->is_update as is_update']);
        $runs = self::runs($rows);

        $page = max(1, $page);
        $shown = array_slice($runs, ($page - 1) * $perPage, $perPage);
        $full = AuditLog::with('user')
            ->whereIn('id', collect($shown)->flatMap(fn ($run) => collect($run['events'])->pluck('id')))
            ->get()->keyBy('id');

        $items = array_map(fn ($run) => [
            'events' => array_map(fn ($row) => $full[$row->id], $run['events']),
        ], $shown);

        return [
            'lines' => new Paginator($items, count($runs), $perPage, $page, [
                'path' => Paginator::resolveCurrentPath(),
                'query' => request()->query(),
            ]),
            'events' => $rows->count(),
        ];
    }

    /** Whether a publication updated a row — on a full event or a light row. */
    private static function isUpdate(object $event): bool
    {
        $value = $event instanceof AuditLog ? ($event->metadata['is_update'] ?? null) : ($event->is_update ?? null);

        return $value === true || in_array(strtolower((string) $value), ['1', 'true'], true);
    }

    /**
     * What the counts alone cannot say.
     *
     * - `deleted_by`: who deletes (author, admin, report, account);
     * - `lifespans`: how long deleted translations had lived — the publish-then-delete loop;
     * - `refusals`: why publications were turned down;
     * - `naming`: how the game of each NEW translation was named — picked in the list, read from
     *   the game's files, or a name alone — beside how many were moved to another game afterwards;
     * - `games`, `pairs`, `via`: where the activity is, in which languages, through which program.
     */
    public function breakdowns(): array
    {
        $events = $this->query(withType: false)
            ->get(['id', 'action', 'metadata', 'created_at']);

        $of = fn (string $action): Collection => $events->where('action', $action);
        $count = fn (Collection $items, callable $key): array => $items
            ->map($key)->filter(fn ($k) => $k !== null && $k !== '')
            ->countBy()->sortDesc()->all();

        $deleted = $of(TranslationFlows::DELETED);
        $lived = $deleted->map(fn (AuditLog $e) => TranslationFlows::lifespan($e))->filter(fn ($s) => $s !== null)->sort()->values();

        $new = $of(TranslationFlows::PUBLISHED)->filter(fn (AuditLog $e) => empty($e->metadata['is_update']));
        $newMains = $new->filter(fn (AuditLog $e) => empty($e->metadata['is_branch']));

        $games = $events->where('action', '!=', TranslationFlows::REFUSED)
            ->groupBy(fn (AuditLog $e) => $e->metadata['game_id'] ?? null)
            ->filter(fn ($group, $id) => $id !== '' && $id !== null)
            ->map(fn (Collection $group, $id) => [
                'id' => (int) $id,
                // The newest name the events give it — a card may have been renamed since.
                'name' => $group->sortByDesc('created_at')->map(fn ($e) => $e->metadata['game'] ?? $e->metadata['game_name'] ?? null)->filter()->first(),
                'events' => $group->count(),
                'published' => $group->where('action', TranslationFlows::PUBLISHED)->count(),
                'deleted' => $group->where('action', TranslationFlows::DELETED)->count(),
            ])
            ->sortByDesc('events')
            ->values()
            ->all();

        return [
            'deleted_by' => $count($deleted, fn ($e) => $e->metadata['how'] ?? null),
            'lifespans' => [
                'known' => $lived->count(),
                'within_day' => $lived->filter(fn ($s) => $s < 86400)->count(),
                'within_week' => $lived->filter(fn ($s) => $s < 7 * 86400)->count(),
                'median' => $lived->isEmpty() ? null : (int) $lived[intdiv($lived->count(), 2)],
            ],
            'refusals' => $count($of(TranslationFlows::REFUSED), fn ($e) => $e->metadata['code'] ?? null),
            'naming' => [
                'new' => $newMains->count(),
                'ways' => $count($newMains, fn (AuditLog $e) => self::namedBy($e->metadata['sent'] ?? null)),
                'moved' => $of(TranslationFlows::GAME_CHANGED)->count(),
            ],
            'games' => $games,
            'pairs' => $count($new, fn ($e) => isset($e->metadata['source_language'], $e->metadata['target_language'])
                ? $e->metadata['source_language'] . ' → ' . $e->metadata['target_language']
                : null),
            'via' => $count($events, fn ($e) => $e->metadata['via'] ?? null),
        ];
    }

    /** How the game of a new translation was named, from what its publication recorded. */
    public static function namedBy(?array $sent): string
    {
        return match (true) {
            $sent === null => 'Not recorded',
            !empty($sent['game_pick']) => 'Picked in the list',
            !empty($sent['game_read']) => 'Read from the game, not picked',
            !empty($sent['game_name']) || !empty($sent['steam_id']) => 'Name or Steam id only',
            default => 'Nothing sent',
        };
    }

    private function query(bool $withType): Builder
    {
        $f = $this->filters;

        return AuditLog::query()
            ->whereIn('action', $withType && !empty($f['type'])
                ? [$f['type']]
                : TranslationFlows::actions())
            ->whereBetween('created_at', [$this->span->from, $this->span->to])
            // A game is where an event is filed — or, for a move, either end of it.
            ->when(!empty($f['game']), fn ($q) => $q->where(fn ($g) => $g
                ->where('metadata->game_id', (int) $f['game'])
                ->orWhere('metadata->from->id', (int) $f['game'])
                ->orWhere('metadata->to->id', (int) $f['game'])))
            ->when(!empty($f['user']), fn ($q) => $q->where('user_id', (int) $f['user']))
            ->when(!empty($f['language']), fn ($q) => $q->where('metadata->target_language', $f['language']))
            ->when(!empty($f['translation']), fn ($q) => $q
                ->where('entity_type', 'Translation')
                ->where('entity_id', (int) $f['translation']))
            ->when(!empty($f['via']), fn ($q) => $q->where('metadata->via', $f['via']))
            // A word typed in the list's box: a translation number, a game, or an account.
            ->when(!empty($f['search']), function ($q) use ($f) {
                $term = trim($f['search']);
                $like = '%' . Like::escape($term) . '%';
                $q->where(function ($w) use ($term, $like) {
                    if (ctype_digit(ltrim($term, '#'))) {
                        $w->orWhere('entity_id', (int) ltrim($term, '#'));
                    }
                    $w->orWhere('metadata->game', 'like', $like)
                      ->orWhere('metadata->game_name', 'like', $like)
                      ->orWhereHas('user', fn ($u) => $u->where('name', 'like', $like));
                });
            });
    }

}
