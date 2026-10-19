@extends('layouts.app')

@section('title', 'Mod usage - Admin - UnityGameTranslator')

{{-- How the mod is used — App\Services\ModUsage keeps it, App\Services\ModUsageReport reads it.
     English only, like the rest of what is added to the admin.

     🔴 Only what the mod's calls already said (user, 2026-10-19: "on ne piste pas nos
     utilisateurs"): no call was added for this screen, the Manager counts only for the games where the
     mod is installed (never the rest of its list of games), and a session
     cannot be followed past its day. What it cannot know is said in the notes at the bottom.

     ⚠ Built from the Flows screen's pieces — the span bar, the tiles, the cards, "Show more", the
     same stacked-bar chart (admin-charts.js, __stackedBars). --}}

@section('content')
@use('App\Services\ModUsage')
@php
    // Sessions that did one of the acts counted here — about a game.
    $counted = array_sum(array_column($sessions, 'sessions'));
    $signedIn = array_sum(array_column($sessions, 'signed_in'));
    // Copies that called for something else only — signing in, notifications, publishing.
    $otherOnly = max(0, $copies - $counted);
@endphp

<div class="mb-4">
    <a href="{{ route('admin.dashboard') }}" class="text-gray-400 hover:text-white">
        <i class="fas fa-arrow-left mr-1"></i> Back to Dashboard
    </a>
</div>
<div class="mb-6">
    <h1 class="text-3xl font-bold"><i class="fas fa-gamepad mr-2"></i> Mod usage</h1>
    <p class="text-gray-500 text-sm mt-1">
        What the mod asks the site, counted. A session is one copy of the mod on one day. Days are UTC.
    </p>
</div>

<x-admin.span-bar :span="$span" :daysStored="$daysStored" route="admin.usage"
    :title="$span->label()"
    :note="$span->includesToday() ? 'today counted up to now' : null" />

{{-- The same boundary and words as the Analytics screen (ModUsage::foldOldDays). --}}
@if($span->from->lessThan(\App\Models\AnalyticsGame::foldedBefore()))
    <p class="text-xs text-gray-500 mb-4">Before {{ \App\Models\AnalyticsGame::foldedBefore()->toDateString() }}, counted per whole month — and not drawn per day.</p>
@endif

{{-- What each session did at most, best first. The first tile is the whole they are part of. --}}
<div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-4 mb-6">
    <div class="bg-gray-800 rounded-lg p-4 border border-gray-700">
        <p class="text-gray-400 text-sm" title="Every copy that called the site, on the days usage was counted"><i class="fas fa-plug mr-1 text-purple-400"></i> Mod sessions</p>
        <p class="text-2xl font-bold {{ $copies > 0 ? 'text-purple-400' : 'text-gray-600' }}">{{ number_format($copies) }}</p>
        @if($otherOnly > 0)
            <p class="text-xs text-gray-500 mt-1" title="Signing in, notifications, publishing…">{{ number_format($otherOnly) }} for something else only</p>
        @endif
    </div>
    @foreach(ModUsage::SESSION_OUTCOMES as $outcome => $style)
        <div class="bg-gray-800 rounded-lg p-4 border border-gray-700">
            <p class="text-gray-400 text-sm">{{ $style['label'] }}</p>
            <p class="text-2xl font-bold {{ $sessions[$outcome]['sessions'] > 0 ? $style['class'] : 'text-gray-600' }}">{{ number_format($sessions[$outcome]['sessions']) }}</p>
            @if($sessions[$outcome]['sessions'] > 0)
                <p class="text-xs text-gray-500 mt-1">{{ number_format($sessions[$outcome]['signed_in']) }} signed in</p>
            @endif
        </div>
    @endforeach
</div>

<div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6" x-data="{ expanded: false }">
    <div class="bg-gray-800 rounded-lg p-6 border border-gray-700 md:col-span-2">
        <div class="flex flex-wrap justify-between items-baseline gap-2 mb-4">
            <h2 class="text-lg font-semibold"><i class="fas fa-chart-column mr-2 text-purple-400"></i> Sessions per day</h2>
            <p class="text-sm text-gray-500">
                {{ number_format($counted) }} {{ Str::plural('session', $counted) }} about a game ·
                {{ number_format($counted - $signedIn) }} without an account ·
                {{ number_format($signedIn) }} signed in ·
                {{ number_format($takes['mod']) }} taken in game ·
                {{ number_format($takes['manager']) }} installed by the Manager
            </p>
        </div>
        @if(count($daily['datasets']) > 0)
            <div class="h-64">
                <canvas id="usageChart"></canvas>
            </div>
        @else
            <div class="h-64 flex items-center justify-center">
                <p class="text-gray-500 text-sm">No session in this period.</p>
            </div>
        @endif
    </div>

    {{-- Bounded by the catalogue's languages, so a card is enough. --}}
    <div class="bg-gray-800 rounded-lg p-6 border border-gray-700">
        <h2 class="text-lg font-semibold mb-1"><i class="fas fa-language mr-2 text-green-400"></i> Languages asked</h2>
        <p class="text-xs text-gray-500 mb-4">Sessions that looked, and how many found one.</p>
        @if($languages)
            <div class="space-y-2">
                @foreach(array_slice($languages, 0, $topRows['max'], true) as $language => $tally)
                    <div class="flex justify-between" @if($loop->index >= $topRows['visible']) x-show="expanded" x-cloak @endif>
                        <span class="truncate">{{ $language }}</span>
                        <span class="text-gray-400 text-sm whitespace-nowrap" title="Looked / found">
                            {{ number_format($tally['looks']) }} <span class="text-gray-600">/ {{ number_format($tally['found']) }}</span>
                        </span>
                    </div>
                @endforeach
            </div>
            <x-admin.show-more :count="min(count($languages), $topRows['max'])" :visible="$topRows['visible']" />
        @else
            <p class="text-gray-500 text-sm">No look in this period.</p>
        @endif
    </div>
</div>

{{-- ─── Every game, one row each ───────────────────────────────────────────
     The overview (user, 2026-10-20: "les jeux les plus joués et/ou demandés avec ou sans trad"): one
     row per game with all the page knows of it, so each new question is a sort. The tables below
     stay for the detail — per language, per translation, per Manager. Parameters gsort, gdir, gpage;
     the dashboard's card opens it on `interest`. --}}
<h2 class="text-lg font-semibold mb-1"><i class="fas fa-gamepad mr-2 text-purple-400"></i> Games
    <span class="text-sm font-normal text-gray-500 ml-1">{{ number_format($gamesTable->total()) }} {{ Str::plural('game', $gamesTable->total()) }}</span>
</h2>
<p class="text-xs text-gray-500 mb-3">
    <strong>Interest</strong>: sessions that looked for it, plus sessions playing a translation of it.
    <strong>Without / With</strong>: looks that found no translation in their language / found one.
    <strong>Seen</strong>: Manager copies per day with the mod in it, of which <strong>Local only</strong> hold a
    translation never published. <strong>Listed</strong>: translations on the site now (languages on hover).
    The first day seen is on hover of the last.
</p>
@if($gamesTable->isNotEmpty())
    <div class="mb-6">
    <x-admin.scroll-table>
        <table class="w-full text-sm">
            <thead class="text-gray-400 text-left">
                <tr>
                    <x-admin.sortable-th column="game" label="Game" default="last" first="asc" sortParam="gsort" dirParam="gdir" pageParam="gpage" />
                    <x-admin.sortable-th column="interest" label="Interest" default="last" align="right" pad="px-2" sortParam="gsort" dirParam="gdir" pageParam="gpage" />
                    <x-admin.sortable-th column="looked" label="Looked for" default="last" align="right" pad="px-2" sortParam="gsort" dirParam="gdir" pageParam="gpage" />
                    <x-admin.sortable-th column="without" label="Without" default="last" align="right" pad="px-2" sortParam="gsort" dirParam="gdir" pageParam="gpage" />
                    <x-admin.sortable-th column="found" label="With" default="last" align="right" pad="px-2" sortParam="gsort" dirParam="gdir" pageParam="gpage" />
                    <x-admin.sortable-th column="playing" label="Playing" default="last" align="right" pad="px-2" sortParam="gsort" dirParam="gdir" pageParam="gpage" />
                    <x-admin.sortable-th column="taken" label="Taken" default="last" align="right" pad="px-2" sortParam="gsort" dirParam="gdir" pageParam="gpage" />
                    <x-admin.sortable-th column="installed" label="Via Manager" default="last" align="right" pad="px-2" sortParam="gsort" dirParam="gdir" pageParam="gpage" />
                    <x-admin.sortable-th column="seen" label="Seen" default="last" align="right" pad="px-2" sortParam="gsort" dirParam="gdir" pageParam="gpage" />
                    <x-admin.sortable-th column="local" label="Local only" default="last" align="right" pad="px-2" sortParam="gsort" dirParam="gdir" pageParam="gpage" />
                    <x-admin.sortable-th column="listed" label="Listed" default="last" align="right" pad="px-2" sortParam="gsort" dirParam="gdir" pageParam="gpage" />
                    <x-admin.sortable-th column="last" label="Last seen" default="last" pad="px-2" sortParam="gsort" dirParam="gdir" pageParam="gpage" />
                </tr>
            </thead>
            <tbody>
                {{-- ⚠ Fitted to the screen (user, 2026-10-20: horizontal scrolling "pas pratique"):
                     the listed languages and the first day ride in tooltips — this table is the
                     overview, and the tables below carry both in full. --}}
                @foreach($gamesTable as $row)
                    <tr class="border-t border-gray-700">
                        {{-- A minimum width: twelve columns would otherwise fold the name on three lines. --}}
                        <td class="py-2 px-4 min-w-32">@include('admin.partials.usage-game', ['row' => $row])</td>
                        @foreach(['interest', 'looked', 'without', 'found', 'playing', 'taken', 'installed', 'seen', 'local'] as $count)
                            <td class="py-2 px-2 text-right {{ $row[$count] > 0 ? '' : 'text-gray-600' }}">{{ number_format($row[$count]) }}</td>
                        @endforeach
                        <td class="py-2 px-2 text-right">
                            @if($row['listed'] > 0)
                                <span title="{{ implode(', ', $row['languages']) }}" class="underline decoration-dotted decoration-gray-500">{{ $row['listed'] }}</span>
                            @else
                                <span class="text-gray-600" title="{{ $row['game'] ? 'No translation listed' : 'Not on the site' }}">—</span>
                            @endif
                        </td>
                        <td class="py-2 px-2 whitespace-nowrap" title="First seen {{ $row['first'] }}">{{ $row['last'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        @if($gamesTable->hasPages())
            <x-slot:below>
                <div class="px-6 py-4 border-t border-gray-700" data-keeps-scroll>{{ $gamesTable->links() }}</div>
            </x-slot:below>
        @endif
    </x-admin.scroll-table>
    </div>
@else
    <div class="bg-gray-800 rounded-lg border border-gray-700 px-6 py-6 text-gray-500 text-sm mb-6">No game in this period.</div>
@endif

{{-- ─── Which translations players want ─────────────────────────────────────
     Every game and language looked for without an answer over the span, as a table: the demand is
     read over periods (user, 2026-10-19), sorted and paged by the server like every admin list.
     Its own parameters (wsort, wdir, wpage), so sorting it never resets the table below. --}}
<h2 class="text-lg font-semibold mb-1"><i class="fas fa-magnifying-glass mr-2 text-orange-400"></i> Looked for, not found
    <span class="text-sm font-normal text-gray-500 ml-1">{{ number_format($wanted->total()) }} {{ Str::plural('row', $wanted->total()) }}</span>
</h2>
<p class="text-xs text-gray-500 mb-3">
    Games looked for with no translation in the language asked. <strong>Without</strong>: sessions that
    found none. <strong>With</strong>: sessions that found one in the same period — published since.
</p>
@if($wanted->isNotEmpty())
    <div class="mb-6">
    <x-admin.scroll-table>
        <table class="w-full text-sm">
            <thead class="text-gray-400 text-left">
                <tr>
                    <x-admin.sortable-th column="game" label="Game" default="last" first="asc" sortParam="wsort" dirParam="wdir" pageParam="wpage" />
                    <x-admin.sortable-th column="language" label="Language" default="last" first="asc" sortParam="wsort" dirParam="wdir" pageParam="wpage" />
                    <th class="py-3 px-4">Now</th>
                    <x-admin.sortable-th column="without" label="Without" default="last" align="right" pad="px-2" sortParam="wsort" dirParam="wdir" pageParam="wpage" />
                    <x-admin.sortable-th column="found" label="With" default="last" align="right" pad="px-2" sortParam="wsort" dirParam="wdir" pageParam="wpage" />
                    <x-admin.sortable-th column="first" label="First asked" default="last" pad="px-2" sortParam="wsort" dirParam="wdir" pageParam="wpage" />
                    <x-admin.sortable-th column="last" label="Last asked" default="last" pad="px-2" sortParam="wsort" dirParam="wdir" pageParam="wpage" />
                </tr>
            </thead>
            <tbody>
                @foreach($wanted as $row)
                    <tr class="border-t border-gray-700">
                        <td class="py-2 px-4">
                            @include('admin.partials.usage-game', ['row' => $row])
                        </td>
                        <td class="py-2 px-4">{{ $row['language'] !== '' ? $row['language'] : '—' }}</td>
                        <td class="py-2 px-4 whitespace-nowrap">
                            @if($row['available_now'])
                                <span class="text-green-400">Listed</span>
                            @elseif($row['other_languages'])
                                <span class="text-gray-400">Other languages</span>
                            @elseif(!$row['game'])
                                <span class="text-gray-500">Not on the site</span>
                            @else
                                <span class="text-gray-500">None</span>
                            @endif
                        </td>
                        <td class="py-2 px-2 text-right">{{ number_format($row['without']) }}</td>
                        <td class="py-2 px-2 text-right {{ $row['found'] > 0 ? '' : 'text-gray-600' }}">{{ number_format($row['found']) }}</td>
                        <td class="py-2 px-2 whitespace-nowrap">{{ $row['first'] }}</td>
                        <td class="py-2 px-2 whitespace-nowrap">{{ $row['last'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        @if($wanted->hasPages())
            <x-slot:below>
                <div class="px-6 py-4 border-t border-gray-700" data-keeps-scroll>{{ $wanted->links() }}</div>
            </x-slot:below>
        @endif
    </x-admin.scroll-table>
    </div>
@else
    <div class="bg-gray-800 rounded-lg border border-gray-700 px-6 py-6 text-gray-500 text-sm mb-6">Every game looked for had a translation in the language asked.</div>
@endif

{{-- ─── Where the mod is installed and used, as the Manager reports it ───────
     Only games whose translation file or confirmed card the Manager read in the mod's own files
     (ModUsage::HELD) — never the rest of its library. Its own parameters (hsort, hdir, hpage). --}}
<h2 class="text-lg font-semibold mb-1"><i class="fas fa-box-open mr-2 text-cyan-400"></i> Games with the mod, seen by the Manager
    <span class="text-sm font-normal text-gray-500 ml-1">{{ number_format($held->total()) }} {{ Str::plural('game', $held->total()) }}</span>
</h2>
<p class="text-xs text-gray-500 mb-3">
    Games where the mod has run, reported by the Manager. <strong>Seen</strong>: Manager copies, one per day — not
    game launches. <strong>Local only</strong>: a translation made in the game and never published.
    <strong>On the site</strong>: its translation is a lineage the site has. <strong>No file</strong>: a game
    confirmed, nothing translated yet. No language: the Manager does not send it.
</p>
@if($held->isNotEmpty())
    <div class="mb-6">
    <x-admin.scroll-table>
        <table class="w-full text-sm">
            <thead class="text-gray-400 text-left">
                <tr>
                    <x-admin.sortable-th column="game" label="Game" default="last" first="asc" sortParam="hsort" dirParam="hdir" pageParam="hpage" />
                    <th class="py-3 px-4">Translation</th>
                    <x-admin.sortable-th column="seen" label="Seen" default="last" align="right" pad="px-2" sortParam="hsort" dirParam="hdir" pageParam="hpage" />
                    <x-admin.sortable-th column="local" label="Local only" default="last" align="right" pad="px-2" sortParam="hsort" dirParam="hdir" pageParam="hpage" />
                    <x-admin.sortable-th column="site" label="On the site" default="last" align="right" pad="px-2" sortParam="hsort" dirParam="hdir" pageParam="hpage" />
                    <x-admin.sortable-th column="nofile" label="No file" default="last" align="right" pad="px-2" sortParam="hsort" dirParam="hdir" pageParam="hpage" />
                    <x-admin.sortable-th column="first" label="First seen" default="last" pad="px-2" sortParam="hsort" dirParam="hdir" pageParam="hpage" />
                    <x-admin.sortable-th column="last" label="Last seen" default="last" pad="px-2" sortParam="hsort" dirParam="hdir" pageParam="hpage" />
                </tr>
            </thead>
            <tbody>
                @foreach($held as $row)
                    <tr class="border-t border-gray-700">
                        <td class="py-2 px-4">
                            @include('admin.partials.usage-game', ['row' => $row])
                        </td>
                        <td class="py-2 px-4">
                            @if($row['translation_id'])
                                <a href="{{ route('admin.translations.show', $row['translation_id']) }}" class="text-purple-400 hover:text-purple-300">#{{ $row['translation_id'] }}</a>
                            @else
                                <span class="text-gray-600">—</span>
                            @endif
                        </td>
                        <td class="py-2 px-2 text-right">{{ number_format($row['seen']) }}</td>
                        <td class="py-2 px-2 text-right {{ $row['local'] > 0 ? '' : 'text-gray-600' }}">{{ number_format($row['local']) }}</td>
                        <td class="py-2 px-2 text-right {{ $row['site'] > 0 ? '' : 'text-gray-600' }}">{{ number_format($row['site']) }}</td>
                        <td class="py-2 px-2 text-right {{ $row['nofile'] > 0 ? '' : 'text-gray-600' }}">{{ number_format($row['nofile']) }}</td>
                        <td class="py-2 px-2 whitespace-nowrap">{{ $row['first'] }}</td>
                        <td class="py-2 px-2 whitespace-nowrap">{{ $row['last'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        @if($held->hasPages())
            <x-slot:below>
                <div class="px-6 py-4 border-t border-gray-700" data-keeps-scroll>{{ $held->links() }}</div>
            </x-slot:below>
        @endif
    </x-admin.scroll-table>
    </div>
@else
    <div class="bg-gray-800 rounded-lg border border-gray-700 px-6 py-6 text-gray-500 text-sm mb-6">No game with the mod reported by a Manager in this period.</div>
@endif

{{-- ─── The translations of the site taken and in use ─────────────────────── --}}
<h2 class="text-lg font-semibold mb-1"><i class="fas fa-play mr-2 text-green-400"></i> Translations taken and played
    <span class="text-sm font-normal text-gray-500 ml-1">{{ number_format($inUse->total()) }} {{ Str::plural('translation', $inUse->total()) }}</span>
</h2>
<p class="text-xs text-gray-500 mb-3">
    <strong>Taken</strong>: by the mod, in the game. <strong>Via Manager</strong>: written into a game where
    the Manager installed the mod. <strong>Sessions</strong>: playing it. <strong>Same</strong>: the copy is the published file. <strong>Differs</strong>:
    edited by its player, or older than the published one — the site cannot tell which. Its author
    playing it is not counted.
</p>
@if($inUse->isNotEmpty())
    <x-admin.scroll-table>
        <table class="w-full text-sm">
            <thead class="text-gray-400 text-left">
                <tr>
                    <x-admin.sortable-th column="translation" label="Translation" default="last" sortParam="usort" dirParam="udir" pageParam="upage" />
                    <x-admin.sortable-th column="game" label="Game" default="last" first="asc" sortParam="usort" dirParam="udir" pageParam="upage" />
                    <x-admin.sortable-th column="language" label="Language" default="last" first="asc" sortParam="usort" dirParam="udir" pageParam="upage" />
                    <x-admin.sortable-th column="taken" label="Taken" default="last" align="right" pad="px-2" sortParam="usort" dirParam="udir" pageParam="upage" />
                    <x-admin.sortable-th column="installed" label="Via Manager" default="last" align="right" pad="px-2" sortParam="usort" dirParam="udir" pageParam="upage" />
                    <x-admin.sortable-th column="sessions" label="Sessions" default="last" align="right" pad="px-2" sortParam="usort" dirParam="udir" pageParam="upage" />
                    <x-admin.sortable-th column="same" label="Same" default="last" align="right" pad="px-2" sortParam="usort" dirParam="udir" pageParam="upage" />
                    <x-admin.sortable-th column="differs" label="Differs" default="last" align="right" pad="px-2" sortParam="usort" dirParam="udir" pageParam="upage" />
                    <x-admin.sortable-th column="signed" label="Signed in" default="last" align="right" pad="px-2" sortParam="usort" dirParam="udir" pageParam="upage" />
                    <x-admin.sortable-th column="last" label="Last seen" default="last" pad="px-2" sortParam="usort" dirParam="udir" pageParam="upage" />
                </tr>
            </thead>
            <tbody>
                @foreach($inUse as $row)
                    <tr class="border-t border-gray-700">
                        <td class="py-2 px-4">
                            @if($row['exists'])
                                <a href="{{ route('admin.translations.show', $row['id']) }}" class="text-purple-400 hover:text-purple-300">#{{ $row['id'] }}</a>
                            @else
                                <span class="text-gray-500" title="Deleted since">#{{ $row['id'] }}</span>
                            @endif
                        </td>
                        <td class="py-2 px-4">{{ $row['game'] ?? '—' }}</td>
                        <td class="py-2 px-4">{{ $row['language'] !== '' ? $row['language'] : '—' }}</td>
                        <td class="py-2 px-2 text-right {{ $row['taken'] > 0 ? '' : 'text-gray-600' }}">{{ number_format($row['taken']) }}</td>
                        <td class="py-2 px-2 text-right {{ $row['installed'] > 0 ? '' : 'text-gray-600' }}">{{ number_format($row['installed']) }}</td>
                        <td class="py-2 px-2 text-right">{{ number_format($row['sessions']) }}</td>
                        <td class="py-2 px-2 text-right">{{ number_format($row['same']) }}</td>
                        <td class="py-2 px-2 text-right">{{ number_format($row['differs']) }}</td>
                        <td class="py-2 px-2 text-right">{{ number_format($row['signed_in']) }}</td>
                        <td class="py-2 px-2 whitespace-nowrap">{{ $row['last'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        @if($inUse->hasPages())
            <x-slot:below>
                <div class="px-6 py-4 border-t border-gray-700" data-keeps-scroll>{{ $inUse->links() }}</div>
            </x-slot:below>
        @endif
    </x-admin.scroll-table>
@else
    <div class="bg-gray-800 rounded-lg border border-gray-700 px-6 py-6 text-gray-500 text-sm">No translation of the site taken or played in this period.</div>
@endif

<div class="mt-6 bg-gray-800 rounded-lg p-4 border border-gray-700 text-sm text-gray-400 space-y-2">
    <p>
        <i class="fas fa-clock mr-2"></i>
        <strong>Where the figures come from.</strong>
        Only calls the mod already makes: looking for its game's translations (first launch, or when its
        window opens), taking one, and checking the one it holds. From the Manager, only what concerns a game
        where the mod is installed — the translations it writes there, and the games where the mod has run
        (it read a translation file or a confirmed game in the mod's files) — never the rest of its list of games. Recorded since 2026-10-19.
    </p>
    <p>
        <i class="fas fa-eye-slash mr-2"></i>
        <strong>What cannot be seen.</strong>
        A copy that never looks online, works offline, or translates without publishing, says nothing
        here — and that is on purpose. Lines translated by a player are seen only when they publish
        (Translation flows).
    </p>
    <p>
        <i class="fas fa-shield-halved mr-2"></i>
        <strong>What is kept.</strong>
        A session is named by the site's daily fingerprint, whose key is destroyed two days later. The
        night after, sessions are counted and forgotten: only totals per day remain, and they name
        nobody. A span adds days, so one copy playing every day of a week is seven sessions.
    </p>
</div>

<script nonce="{{ $cspNonce }}">
    window.__stackedBars = [{
        canvas: 'usageChart',
        labels: @json($daily['labels']),
        datasets: @json($daily['datasets']),
    }];
</script>
@vite('resources/js/admin-charts.js')
@endsection
