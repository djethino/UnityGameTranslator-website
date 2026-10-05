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
        <a href="{{ route('admin.flows', array_filter(['period' => request('period'), 'type' => $typeSlug])) }}"
           class="text-sm text-purple-400 hover:text-purple-300 ml-2">Clear all</a>
    </div>
@endif

{{-- The span bar of the analytics page, same offers and same words. --}}
<div class="sticky top-[var(--site-bar-offset,0px)] transition-[top] duration-200 z-30 -mx-4 px-4 mb-3 py-2 bg-gray-900/95 backdrop-blur
            border-b border-gray-800 flex flex-wrap gap-3 justify-between items-center">
    <h2 class="text-lg font-semibold text-gray-300">
        <i class="fas fa-calendar-days mr-2 text-purple-500"></i>
        {{ $period === 1 ? 'Yesterday and today' : 'Last ' . $spanLabel }}
        <span class="text-sm font-normal text-gray-500 ml-2">— today included</span>
    </h2>
    <div class="flex flex-wrap gap-2">
        @foreach (\App\Support\AnalyticsPeriods::choices($daysStored, $period) as $days => $label)
            <a href="{{ $link(['period' => $days]) }}"
               class="px-3 py-1.5 rounded text-sm {{ $period == $days ? 'bg-purple-600' : 'bg-gray-700 hover:bg-gray-600' }}">
                {{ $label }}
            </a>
        @endforeach
    </div>
</div>

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
    <h2 class="text-lg font-semibold mb-4"><i class="fas fa-chart-column mr-2 text-purple-400"></i> Per day</h2>
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
<div class="bg-gray-800 rounded-lg border border-gray-700 overflow-hidden">
    <div class="px-6 pt-5 pb-3 flex flex-wrap justify-between items-baseline gap-2">
        <h2 class="text-lg font-semibold">
            <i class="fas fa-list mr-2 text-gray-400"></i>
            {{ $typeSlug ? TranslationFlows::LABELS[$filters['type']]['label'] : 'All events' }}
            <span class="text-sm font-normal text-gray-500 ml-1">{{ number_format($events->total()) }}</span>
        </h2>
        <p class="text-xs text-gray-500">A game, an account or a language in the list filters on it.</p>
    </div>
    @if($events->isNotEmpty())
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-gray-400 text-left">
                    <tr>
                        <th class="py-2 px-4">When (UTC)</th>
                        <th class="py-2 px-4">Event</th>
                        <th class="py-2 px-4">Translation</th>
                        <th class="py-2 px-4">Game</th>
                        <th class="py-2 px-4">Language</th>
                        <th class="py-2 px-4">Account</th>
                        <th class="py-2 px-4">Through</th>
                        <th class="py-2 px-4">What</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($events as $event)
                        @php
                            $m = $event->metadata ?? [];
                            $style = TranslationFlows::LABELS[$event->action];
                            $gameId = $m['game_id'] ?? $m['to']['id'] ?? null;
                            $gameName = $m['game'] ?? $m['game_name'] ?? $m['to']['name'] ?? null;
                        @endphp
                        <tr class="border-t border-gray-700 align-top">
                            <td class="py-2 px-4 whitespace-nowrap text-gray-400">{{ $event->created_at->format('Y-m-d H:i') }}</td>
                            <td class="py-2 px-4 whitespace-nowrap">
                                <i class="fas {{ $style['icon'] }} {{ $style['class'] }} mr-1"></i> {{ $style['label'] }}
                            </td>
                            <td class="py-2 px-4 whitespace-nowrap">
                                @if($event->entity_id)
                                    @if($translations->has($event->entity_id))
                                        <a href="{{ route('admin.translations.show', $event->entity_id) }}" class="text-purple-400 hover:text-purple-300">#{{ $event->entity_id }}</a>
                                    @else
                                        <span class="text-gray-500" title="No longer exists">#{{ $event->entity_id }}</span>
                                    @endif
                                    <a href="{{ $link(['translation' => $event->entity_id]) }}" class="text-gray-600 hover:text-gray-300 ml-1" title="Only this translation">
                                        <i class="fas fa-filter text-xs"></i>
                                    </a>
                                @else
                                    <span class="text-gray-600">—</span>
                                @endif
                            </td>
                            <td class="py-2 px-4">
                                @if($gameId)
                                    <a href="{{ $link(['game' => $gameId]) }}" class="hover:text-purple-400">{{ $gameName ?? '#' . $gameId }}</a>
                                @else
                                    <span class="{{ $gameName ? '' : 'text-gray-600' }}">{{ $gameName ?? '—' }}</span>
                                @endif
                            </td>
                            <td class="py-2 px-4 whitespace-nowrap">
                                @if(!empty($m['target_language']))
                                    <a href="{{ $link(['language' => $m['target_language']]) }}" class="hover:text-purple-400">{{ $m['target_language'] }}</a>
                                @else
                                    <span class="text-gray-600">—</span>
                                @endif
                            </td>
                            <td class="py-2 px-4 whitespace-nowrap">
                                <x-admin.user-link :user="$event->user" />
                                @if($event->user_id)
                                    <a href="{{ $link(['user' => $event->user_id]) }}" class="text-gray-600 hover:text-gray-300 ml-1" title="Only this account">
                                        <i class="fas fa-filter text-xs"></i>
                                    </a>
                                @endif
                            </td>
                            <td class="py-2 px-4 whitespace-nowrap text-gray-400">
                                {{ isset($m['via']) ? (TranslationFlows::VIA[$m['via']] ?? $m['via']) . (isset($m['version']) ? ' ' . $m['version'] : '') : '—' }}
                            </td>
                            <td class="py-2 px-4">{{ TranslationFlows::describe($event) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="px-6 py-4">{{ $events->links() }}</div>
    @else
        <p class="px-6 pb-6 text-gray-500 text-sm">Nothing matches in this period.</p>
    @endif
</div>

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
