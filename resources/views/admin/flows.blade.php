@extends('layouts.app')

@section('title', 'Translation flows - Admin - UnityGameTranslator')

{{-- What happens to translations — App\Support\TranslationFlows writes the events, and
     App\Services\TranslationFlowReport reads them. English only, like the rest of what is added to
     the admin (user, 2026-10-05: "c'est pas une page utilisateur").

     ⚠ Built from the analytics page's pieces, not beside them: the same span bar
     (AnalyticsPeriods), the same cards, "Show more", the same chart file. A second way of writing
     a span or a card would make two admin screens read differently for no reason. --}}

@section('content')
@use('App\Support\TranslationFlows')
@php
    // Every link on this screen keeps what is already filtered and changes one thing. The page
    // number never survives: a page 4 of another filter is a page of nothing.
    $link = fn (array $change) => route('admin.flows', array_filter(
        array_merge(request()->except('page'), $change),
        fn ($v) => $v !== null && $v !== ''
    ));
    $typeSlug = $filters['type'] ? array_search($filters['type'], TranslationFlows::SLUGS, true) : null;

    // The filters in force, each with the link that removes it.
    $chips = array_filter([
        $filterGame || $filters['game'] ? ['Game: ' . ($filterGame?->name ?? '#' . $filters['game']), 'game'] : null,
        $filters['user'] ? ['Account: ' . ($filterUser?->name ?? '#' . $filters['user']), 'user'] : null,
        $filters['language'] ? ['Language: ' . $filters['language'], 'language'] : null,
        $filters['translation'] ? ['Translation #' . $filters['translation'], 'translation'] : null,
        $filters['via'] ? ['Through: ' . TranslationFlows::VIA[$filters['via']], 'via'] : null,
        $filters['search'] ? ['Search: ' . $filters['search'], 'search'] : null,
    ]);
@endphp

<div class="mb-4">
    <a href="{{ route('admin.dashboard') }}" class="text-gray-400 hover:text-white">
        <i class="fas fa-arrow-left mr-1"></i> Back to Dashboard
    </a>
</div>
<div class="mb-6">
    <h1 class="text-3xl font-bold"><i class="fas fa-code-branch mr-2"></i> Translation flows</h1>
    <p class="text-gray-500 text-sm mt-1">
        What happens to translations: published, edited, moved, deleted, refused. Times are UTC.
    </p>
</div>

@if($chips)
    <div class="flex flex-wrap items-center gap-2 mb-4">
        <span class="text-sm text-gray-400">Filtered by</span>
        @foreach($chips as [$said, $key])
            <a href="{{ $link([$key => null]) }}"
               class="inline-flex items-center gap-2 bg-gray-700 hover:bg-gray-600 rounded-full px-3 py-1 text-sm"
               title="Remove this filter">
                {{ $said }} <i class="fas fa-xmark text-gray-400"></i>
            </a>
        @endforeach
        <a href="{{ route('admin.flows', $span->query() + array_filter(['type' => $typeSlug])) }}"
           class="text-sm text-purple-400 hover:text-purple-300 ml-2">Clear all</a>
    </div>
@endif

{{-- The span bar of the analytics page — the same component, the same offers and words. ⚠ Not its
     "Yesterday and today": that one reads whole days, this one counts back from now, so "24 h" is
     exactly 24 hours. The other filters ride along. --}}
<x-admin.span-bar :span="$span" :daysStored="$daysStored" route="admin.flows"
    :keep="request()->only(['type', 'game', 'user', 'language', 'translation', 'via', 'search', 'sort', 'dir'])"
    :title="$span->label()"
    :note="$span->includesToday() ? 'up to now' : null" />

{{-- One tile per kind of event. A tile is also the filter of the list below; pressed again, it lets
     go. The tiles keep counting every kind — they are what a kind is picked from. --}}
<div class="grid grid-cols-2 md:grid-cols-4 xl:grid-cols-7 gap-4 mb-6">
    @foreach(TranslationFlows::SLUGS as $slug => $action)
        @php
            // ⚠ A block, never the inline `@php(...)`: Blade mis-pairs the inline form with the
            // blocks of the same file and the view no longer compiles.
            $style = TranslationFlows::LABELS[$action];
        @endphp
        <a href="{{ $link(['type' => $typeSlug === $slug ? null : $slug]) }}"
           class="bg-gray-800 rounded-lg p-4 border transition hover:border-gray-500
                  {{ $typeSlug === $slug ? 'border-purple-500' : 'border-gray-700' }}">
            <p class="text-gray-400 text-sm"><i class="fas {{ $style['icon'] }} {{ $style['class'] }} mr-1"></i> {{ $style['label'] }}</p>
            <p class="text-2xl font-bold {{ $counts[$action] > 0 ? $style['class'] : 'text-gray-600' }}">{{ number_format($counts[$action]) }}</p>
        </a>
    @endforeach
</div>

<div class="bg-gray-800 rounded-lg p-6 border border-gray-700 mb-6">
    <h2 class="text-lg font-semibold mb-4"><i class="fas fa-chart-column mr-2 text-purple-400"></i> {{ $daily['hourly'] ? 'Per hour' : 'Per day' }}</h2>
    @if(count($daily['datasets']) > 0)
        <div class="h-64">
            <canvas id="flowsChart"></canvas>
        </div>
    @else
        <div class="h-64 flex items-center justify-center">
            <p class="text-gray-500 text-sm">Nothing happened in this period.</p>
        </div>
    @endif
</div>

{{-- ─── What the counts alone cannot say ─────────────────────────────────── --}}
<div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
    {{-- Who deletes, and how soon: a translation published and deleted the same day, over and over,
         is somebody fighting the tool, not changing their mind. --}}
    <div class="bg-gray-800 rounded-lg p-6 border border-gray-700">
        <h2 class="text-lg font-semibold mb-4"><i class="fas fa-trash mr-2 text-red-400"></i> Deletions</h2>
        @if($breakdowns['deleted_by'])
            <div class="space-y-2 mb-4">
                @foreach($breakdowns['deleted_by'] as $how => $count)
                    <div class="flex justify-between">
                        <span>Deleted {{ TranslationFlows::DELETED_BY[$how] ?? $how }}</span>
                        <span class="text-gray-400">{{ number_format($count) }}</span>
                    </div>
                @endforeach
            </div>
            @php
                $lived = $breakdowns['lifespans'];
            @endphp
            @if($lived['known'] > 0)
                <div class="border-t border-gray-700 pt-3 space-y-2 text-sm">
                    <div class="flex justify-between"><span class="text-gray-400">Deleted within a day of publishing</span><span>{{ $lived['within_day'] }}</span></div>
                    <div class="flex justify-between"><span class="text-gray-400">Within a week</span><span>{{ $lived['within_week'] }}</span></div>
                    <div class="flex justify-between"><span class="text-gray-400">Median life</span><span>{{ TranslationFlows::duration($lived['median']) }}</span></div>
                    @if($lived['known'] < array_sum($breakdowns['deleted_by']))
                        <p class="text-xs text-gray-500">Of {{ $lived['known'] }} deletions — older ones did not record when the translation was published.</p>
                    @endif
                </div>
            @endif
        @else
            <p class="text-gray-500 text-sm">No deletion in this period.</p>
        @endif
    </div>

    <div class="bg-gray-800 rounded-lg p-6 border border-gray-700">
        <h2 class="text-lg font-semibold mb-4"><i class="fas fa-ban mr-2 text-orange-400"></i> Refused publications</h2>
        @if($breakdowns['refusals'])
            <div class="space-y-2">
                @foreach($breakdowns['refusals'] as $code => $count)
                    <div class="flex justify-between gap-3">
                        <span title="{{ $code }}">{{ TranslationFlows::REFUSALS[$code] ?? $code }}</span>
                        <span class="text-gray-400">{{ number_format($count) }}</span>
                    </div>
                @endforeach
            </div>
        @else
            <p class="text-gray-500 text-sm">No publication refused in this period.</p>
        @endif
    </div>

    {{-- How well games are identified: how each NEW translation named its game, and how many were
         moved to another game afterwards — a move is a game that was named wrong. --}}
    <div class="bg-gray-800 rounded-lg p-6 border border-gray-700">
        <h2 class="text-lg font-semibold mb-1"><i class="fas fa-gamepad mr-2 text-yellow-400"></i> Game naming</h2>
        <p class="text-xs text-gray-500 mb-4">How new translations named their game.</p>
        @if($breakdowns['naming']['new'] > 0)
            <div class="space-y-2">
                @foreach($breakdowns['naming']['ways'] as $way => $count)
                    <div class="flex justify-between"><span>{{ $way }}</span><span class="text-gray-400">{{ number_format($count) }}</span></div>
                @endforeach
            </div>
        @else
            <p class="text-gray-500 text-sm">No new translation in this period.</p>
        @endif
        <div class="border-t border-gray-700 pt-3 mt-4 flex justify-between text-sm">
            <span class="text-gray-400">Moved to another game</span>
            <a href="{{ $link(['type' => 'moved']) }}" class="hover:text-purple-400">{{ number_format($breakdowns['naming']['moved']) }}</a>
        </div>
    </div>
</div>

<div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6" x-data="{ expanded: false }">
    <div class="bg-gray-800 rounded-lg p-6 border border-gray-700">
        <h2 class="text-lg font-semibold mb-4"><i class="fas fa-fire mr-2 text-purple-400"></i> Most active games</h2>
        @if($breakdowns['games'])
            <div class="space-y-2">
                @foreach(array_slice($breakdowns['games'], 0, $topRows['max']) as $game)
                    <div class="flex justify-between items-center gap-3"
                         @if($loop->index >= $topRows['visible']) x-show="expanded" x-cloak @endif>
                        <a href="{{ $link(['game' => $game['id']]) }}" class="truncate hover:text-purple-400" title="Only this game">
                            {{ $game['name'] ?? '#' . $game['id'] }}
                        </a>
                        <span class="text-gray-400 text-sm whitespace-nowrap" title="Events — published / deleted">
                            {{ $game['events'] }}
                            <span class="text-gray-600">({{ $game['published'] }} / {{ $game['deleted'] }})</span>
                        </span>
                    </div>
                @endforeach
            </div>
            <x-admin.show-more :count="min(count($breakdowns['games']), $topRows['max'])" :visible="$topRows['visible']" />
        @else
            <p class="text-gray-500 text-sm">No game in this period.</p>
        @endif
    </div>

    <div class="bg-gray-800 rounded-lg p-6 border border-gray-700">
        <h2 class="text-lg font-semibold mb-1"><i class="fas fa-language mr-2 text-green-400"></i> Language pairs</h2>
        <p class="text-xs text-gray-500 mb-4">Of new translations.</p>
        @if($breakdowns['pairs'])
            <div class="space-y-2">
                @foreach(array_slice($breakdowns['pairs'], 0, $topRows['max'], true) as $pair => $count)
                    <div class="flex justify-between" @if($loop->index >= $topRows['visible']) x-show="expanded" x-cloak @endif>
                        <span class="truncate">{{ $pair }}</span>
                        <span class="text-gray-400">{{ number_format($count) }}</span>
                    </div>
                @endforeach
            </div>
            <x-admin.show-more :count="min(count($breakdowns['pairs']), $topRows['max'])" :visible="$topRows['visible']" />
        @else
            <p class="text-gray-500 text-sm">No new translation in this period.</p>
        @endif
    </div>

    <div class="bg-gray-800 rounded-lg p-6 border border-gray-700">
        <h2 class="text-lg font-semibold mb-4"><i class="fas fa-plug mr-2 text-blue-400"></i> Through</h2>
        @if($breakdowns['via'])
            <div class="space-y-2">
                @foreach($breakdowns['via'] as $via => $count)
                    <div class="flex justify-between">
                        <a href="{{ $link(['via' => $via]) }}" class="hover:text-purple-400">{{ TranslationFlows::VIA[$via] ?? $via }}</a>
                        <span class="text-gray-400">{{ number_format($count) }}</span>
                    </div>
                @endforeach
            </div>
        @else
            <p class="text-gray-500 text-sm">Recorded since 2026-10-05.</p>
        @endif
    </div>
</div>

{{-- ─── The events ───────────────────────────────────────────────────────── --}}
{{-- One LINE per run (TranslationFlowReport::eventRuns): the count, the pages and the rows all
     count lines, so a page holds what it says. Searched and sorted by the server, over the whole
     filtered list — never the fifty rows already on screen. --}}
<div class="flex flex-wrap justify-between items-end gap-3 mb-3">
    <h2 class="text-lg font-semibold">
        <i class="fas fa-list mr-2 text-gray-400"></i>
        {{ $typeSlug ? TranslationFlows::LABELS[$filters['type']]['label'] : 'All events' }}
        <span class="text-sm font-normal text-gray-500 ml-1">
            {{ number_format($eventCount) }} {{ Str::plural('event', $eventCount) }}@if($lines->total() !== $eventCount), {{ number_format($lines->total()) }} {{ Str::plural('line', $lines->total()) }}@endif
        </span>
    </h2>
    <form action="{{ route('admin.flows') }}" method="GET" class="flex items-center gap-2">
        @foreach(request()->except(['search', 'page']) as $name => $value)
            @if(is_string($value))
                <input type="hidden" name="{{ $name }}" value="{{ $value }}">
            @endif
        @endforeach
        <input type="search" name="search" value="{{ $filters['search'] }}" placeholder="Game, account or #number"
               class="w-64 bg-gray-700 border border-gray-600 rounded-lg px-3 py-1.5 text-sm text-white focus:ring-purple-500 focus:border-purple-500">
        <button type="submit" class="bg-purple-600 hover:bg-purple-700 text-white px-3 py-1.5 rounded-lg text-sm">
            <i class="fas fa-search mr-1"></i> Search
        </button>
    </form>
</div>
<p class="text-xs text-gray-500 mb-2">A game, an account or a language in the list filters on it.</p>

@if($lines->isNotEmpty())
    <x-admin.scroll-table>
        <table class="w-full text-sm">
            <thead class="text-gray-400 text-left">
                <tr>
                    <x-admin.sortable-th column="when" label="When (UTC)" default="when" />
                    <x-admin.sortable-th column="event" label="Event" default="when" first="asc" />
                    <th class="py-3 px-4">Translation</th>
                    <th class="py-3 px-4">Game</th>
                    <th class="py-3 px-4">Language</th>
                    <x-admin.sortable-th column="account" label="Account" default="when" first="asc" />
                    <th class="py-3 px-4">Through</th>
                    <th class="py-3 px-4">What</th>
                </tr>
            </thead>
            {{-- A run opens on its events, oldest to newest whatever the order of the list. --}}
            @foreach($lines as $run)
                @php
                    $byTime = collect($run['events'])->sortBy(fn ($e) => [$e->created_at->timestamp, $e->id])->values();
                    $oldest = $byTime->first();
                    $newest = $byTime->last();
                    $count = $byTime->count();
                @endphp
                <tbody x-data="{ open: false }">
                    @include('admin.partials.flow-row', [
                        'event' => $newest,
                        'when' => $count === 1 ? $newest->created_at->format('Y-m-d H:i')
                            : $oldest->created_at->format('Y-m-d H:i') . ' → '
                              . $newest->created_at->format($newest->created_at->isSameDay($oldest->created_at) ? 'H:i' : 'Y-m-d H:i'),
                        'what' => TranslationFlows::describeRun($run['events']),
                        'opens' => $count > 1 ? $count : null,
                        'nested' => false,
                    ])
                    @if($count > 1)
                        @foreach($byTime as $event)
                            @include('admin.partials.flow-row', [
                                'event' => $event,
                                'when' => $event->created_at->format('Y-m-d H:i'),
                                'what' => TranslationFlows::describe($event),
                                'opens' => null,
                                'nested' => true,
                            ])
                        @endforeach
                    @endif
                </tbody>
            @endforeach
        </table>
        @if($lines->hasPages())
            <x-slot:below>
                <div class="px-6 py-4 border-t border-gray-700">{{ $lines->links() }}</div>
            </x-slot:below>
        @endif
    </x-admin.scroll-table>
@else
    <div class="bg-gray-800 rounded-lg border border-gray-700 px-6 py-6 text-gray-500 text-sm">Nothing matches in this period.</div>
@endif

<div class="mt-6 bg-gray-800 rounded-lg p-4 border border-gray-700 text-sm text-gray-400 space-y-2">
    <p>
        <i class="fas fa-clock mr-2"></i>
        <strong>Where the events come from.</strong>
        Each one is written when it happens, in the site's journal. Edits on the site, forks made on the
        site, details changes and refused publications are recorded since 2026-10-05; older events
        may lack the program, the languages or the game's number — shown as "—".
    </p>
    <p>
        <i class="fas fa-shield-halved mr-2"></i>
        <strong>What is kept.</strong>
        Events are kept for good: they are the history of the content. Their IP address and browser
        are erased after 12 months and never shown here.
    </p>
</div>

<script nonce="{{ $cspNonce }}">
    window.__flowsData = {
        hasData: {{ count($daily['datasets']) > 0 ? 'true' : 'false' }},
        labels: @json($daily['labels']),
        datasets: @json($daily['datasets']),
    };
</script>
@vite('resources/js/admin-charts.js')
@endsection
