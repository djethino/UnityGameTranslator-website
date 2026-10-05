<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Support\TranslationFlows;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
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
    private Carbon $since;

    /**
     * @param array{type?: ?string, game?: ?int, user?: ?int, language?: ?string, translation?: ?int, via?: ?string} $filters
     */
    public function __construct(public readonly int $period, public readonly array $filters)
    {
        $this->since = now()->subDays($period);
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

    /** One stacked bar per day: every day of the span, an empty day included. */
    public function daily(): array
    {
        $rows = $this->query(withType: false)
            ->selectRaw('DATE(created_at) as day, action, COUNT(*) as n')
            ->groupBy('day', 'action')
            ->get();

        $days = [];
        for ($day = $this->since->copy()->startOfDay(); $day->lte(now()); $day->addDay()) {
            $days[$day->toDateString()] = 0;
        }

        $byAction = [];
        foreach ($rows as $row) {
            $byAction[$row->action][Carbon::parse($row->day)->toDateString()] = (int) $row->n;
        }

        return [
            'labels' => array_map(fn ($d) => Carbon::parse($d)->format('d/m'), array_keys($days)),
            'datasets' => collect(TranslationFlows::actions())
                ->filter(fn ($action) => isset($byAction[$action]))
                ->map(fn ($action) => [
                    'label' => TranslationFlows::LABELS[$action]['label'],
                    'rgb' => TranslationFlows::LABELS[$action]['rgb'],
                    'data' => array_values(array_replace($days, array_intersect_key($byAction[$action], $days))),
                ])
                ->values()
                ->all(),
        ];
    }

    /** The events themselves, newest first, the type included. */
    public function events(int $perPage = 50): LengthAwarePaginator
    {
        return $this->query(withType: true)
            ->with('user')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
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
            ->where('created_at', '>=', $this->since)
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
            ->when(!empty($f['via']), fn ($q) => $q->where('metadata->via', $f['via']));
    }
}
