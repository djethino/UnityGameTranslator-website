@extends('layouts.app')

@section('title', 'Analytics - UnityGameTranslator')

@section('content')
<div class="mb-4">
    <a href="{{ route('admin.dashboard') }}" class="text-gray-400 hover:text-white">
        <i class="fas fa-arrow-left mr-1"></i> Back to Dashboard
    </a>
</div>
<div class="mb-6">
    <h1 class="text-3xl font-bold"><i class="fas fa-chart-line mr-2"></i> Analytics</h1>
    <p class="text-gray-500 text-sm mt-1">
        Times are UTC — a "day" here starts and ends at midnight UTC, whatever your visitors' clocks say.
    </p>
</div>

{{-- Three sections, three different clocks. They used to look identical, which
     made it anyone's guess whether a number was all-time, live, or windowed. --}}

<!-- ─── All time ─────────────────────────────────────────────────────────── -->
<h2 class="text-lg font-semibold text-gray-300 mb-3">
    <i class="fas fa-infinity mr-2 text-gray-500"></i> All time
</h2>
<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
    <div class="bg-gray-800 rounded-lg p-4 border border-gray-700">
        <p class="text-gray-400 text-sm">Total Users</p>
        <p class="text-2xl font-bold text-blue-400">{{ number_format($globalStats['total_users']) }}</p>
    </div>
    <div class="bg-gray-800 rounded-lg p-4 border border-gray-700">
        <p class="text-gray-400 text-sm">Total Translations</p>
        <p class="text-2xl font-bold text-green-400">{{ number_format($globalStats['total_translations']) }}</p>
    </div>
    <div class="bg-gray-800 rounded-lg p-4 border border-gray-700">
        <p class="text-gray-400 text-sm">Games with Translations</p>
        <p class="text-2xl font-bold text-purple-400">{{ number_format($globalStats['total_games']) }}</p>
    </div>
    <div class="bg-gray-800 rounded-lg p-4 border border-gray-700">
        <p class="text-gray-400 text-sm">Total Downloads</p>
        <p class="text-2xl font-bold text-yellow-400">{{ number_format($globalStats['total_downloads']) }}</p>
        {{-- Counter carried by each translation, not a sum of tracked events:
             it predates analytics and counts downloads the events never saw,
             so it will not match the period figure below. --}}
        <p class="text-xs text-gray-500 mt-1">From translation counters, not from tracked events.</p>
    </div>
</div>

<!-- ─── Right now ────────────────────────────────────────────────────────── -->
<!-- Live edit capacity: the one metric that scales with CONCURRENCY, not traffic.
     An open stream holds one of the host's concurrent request slots for its whole
     life, so this saturates long before bandwidth or storage — and it takes the
     rest of the site with it. Watch the headroom, not the raw number. -->
<h2 class="text-lg font-semibold text-gray-300 mb-3">
    <i class="fas fa-bolt mr-2 text-cyan-500"></i> Right now
    <span class="text-sm font-normal text-gray-500 ml-2">— at the moment this page was loaded</span>
</h2>
@php
    $capBar = function (?int $used, ?int $max) {
        if ($max === null || $max <= 0 || $used === null) {
            return null;
        }
        $pct = min(100, (int) round($used / $max * 100));
        return [
            'pct' => $pct,
            'color' => $pct >= 80 ? 'bg-red-500' : ($pct >= 50 ? 'bg-yellow-500' : 'bg-green-500'),
        ];
    };
    $sessionBar = $capBar($liveCapacity['sessions'], $liveCapacity['sessions_max']);
    $streamBar = $capBar($liveCapacity['streams'], $liveCapacity['streams_max']);
@endphp
<div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
    <div class="bg-gray-800 rounded-lg p-4 border border-gray-700">
        <div class="flex items-baseline justify-between">
            <p class="text-gray-400 text-sm">Live edit sessions</p>
            <p class="text-xs text-gray-500">{{ $sessionBar['pct'] ?? 0 }}% of cap</p>
        </div>
        <p class="text-2xl font-bold text-cyan-400">
            {{ number_format($liveCapacity['sessions']) }}
            <span class="text-base font-normal text-gray-500">/ {{ number_format($liveCapacity['sessions_max']) }}</span>
        </p>
        @if ($sessionBar)
            <div class="mt-2 h-1.5 w-full bg-gray-700 rounded overflow-hidden">
                <div class="h-full {{ $sessionBar['color'] }}" style="width: {{ $sessionBar['pct'] }}%"></div>
            </div>
        @endif
        <p class="text-xs text-gray-500 mt-2">Sessions alive server-side. At the cap, new ones are refused.</p>
    </div>

    <div class="bg-gray-800 rounded-lg p-4 border border-gray-700">
        <div class="flex items-baseline justify-between">
            <p class="text-gray-400 text-sm">Open SSE streams</p>
            @if ($streamBar)
                <p class="text-xs text-gray-500">{{ $streamBar['pct'] }}% of cap</p>
            @endif
        </div>
        @if ($liveCapacity['streams'] === null)
            <p class="text-2xl font-bold text-gray-600">—</p>
            <p class="text-xs text-gray-500 mt-2">
                Stream server unreachable, or SSE_HEALTH_URL not set.
            </p>
        @else
            <p class="text-2xl font-bold text-cyan-400">
                {{ number_format($liveCapacity['streams']) }}
                <span class="text-base font-normal text-gray-500">/ {{ number_format($liveCapacity['streams_max']) }}</span>
            </p>
            @if ($streamBar)
                <div class="mt-2 h-1.5 w-full bg-gray-700 rounded overflow-hidden">
                    <div class="h-full {{ $streamBar['color'] }}" style="width: {{ $streamBar['pct'] }}%"></div>
                </div>
            @endif
            <p class="text-xs text-gray-500 mt-2">
                Each one holds a host request slot. Sustained above half means it is time to move this server.
            </p>
        @endif
    </div>
</div>

<!-- ─── Over a period ────────────────────────────────────────────────────── -->
{{-- The selector sits HERE, not next to the page title: it drives this section
     and everything below it, and nothing above. --}}
<div class="mt-8 mb-3 flex flex-wrap gap-3 justify-between items-center">
    <h2 class="text-lg font-semibold text-gray-300">
        <i class="fas fa-calendar-days mr-2 text-purple-500"></i> Last {{ $period }} days
        <span class="text-sm font-normal text-gray-500 ml-2">— today included, counted live</span>
    </h2>

    <div class="flex gap-2">
        @foreach ([7 => '7 days', 30 => '30 days', 90 => '90 days', 365 => '1 year'] as $days => $label)
            <a href="{{ route('admin.analytics', ['period' => $days]) }}"
               class="px-4 py-2 rounded {{ $period == $days ? 'bg-purple-600' : 'bg-gray-700 hover:bg-gray-600' }}">
                {{ $label }}
            </a>
        @endforeach
    </div>
</div>

<div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-6">
    <div class="bg-gray-800 rounded-lg p-4 border border-gray-700">
        <p class="text-gray-400 text-sm">Page Views</p>
        <p class="text-2xl font-bold">{{ number_format($totals['page_views']) }}</p>
        <p class="text-xs text-green-400">+{{ number_format($todayStats['page_views']) }} today</p>
    </div>
    <div class="bg-gray-800 rounded-lg p-4 border border-gray-700">
        {{-- NOT unique visitors over the period: this adds up each day's unique
             count, so a daily regular is counted once per day. Naming it what it
             is beats quietly overstating the audience. A true period-unique
             count is only possible over the 90 days of raw events we keep. --}}
        <p class="text-gray-400 text-sm">Daily Visitors <span class="text-gray-600">(summed)</span></p>
        <p class="text-2xl font-bold">{{ number_format($totals['unique_visitors']) }}</p>
        <p class="text-xs text-green-400">+{{ number_format($todayStats['unique_visitors']) }} today</p>
    </div>
    <div class="bg-gray-800 rounded-lg p-4 border border-gray-700">
        <p class="text-gray-400 text-sm">Downloads</p>
        <p class="text-2xl font-bold">{{ number_format($totals['downloads']) }}</p>
        <p class="text-xs text-green-400">+{{ number_format($todayStats['downloads']) }} today</p>
    </div>
    <div class="bg-gray-800 rounded-lg p-4 border border-gray-700">
        <p class="text-gray-400 text-sm">Uploads</p>
        <p class="text-2xl font-bold">{{ number_format($totals['uploads']) }}</p>
        <p class="text-xs text-green-400">+{{ number_format($todayStats['uploads']) }} today</p>
    </div>
    <div class="bg-gray-800 rounded-lg p-4 border border-gray-700">
        <p class="text-gray-400 text-sm">New Users</p>
        <p class="text-2xl font-bold">{{ number_format($totals['registrations']) }}</p>
        <p class="text-xs text-green-400">+{{ number_format($todayStats['registrations']) }} today</p>
    </div>
</div>

{{-- Concurrency history. The live gauge above only tells the truth to whoever
     happens to be looking; this is what answers "did I ever come close?" --}}
@php
    $peakPct = fn(int $used, ?int $max) => ($max && $max > 0) ? min(100, (int) round($used / $max * 100)) : null;
    $sessionsPeakPct = $peakPct($peaks['sessions'], $liveCapacity['sessions_max']);
    $streamsPeakPct = $peakPct($peaks['streams'], $liveCapacity['streams_max']);
@endphp
<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
    <div class="bg-gray-800 rounded-lg p-4 border border-gray-700">
        <p class="text-gray-400 text-sm">Peak edit sessions</p>
        <p class="text-2xl font-bold text-cyan-400">
            {{ number_format($peaks['sessions']) }}
            @if ($sessionsPeakPct !== null)
                <span class="text-base font-normal text-gray-500">/ {{ number_format($liveCapacity['sessions_max']) }}</span>
            @endif
        </p>
        <p class="text-xs text-gray-500 mt-1">
            {{ $peaks['sessions_at'] ? $peaks['sessions_at']->format('d/m H:i') . ' UTC' : 'No reading yet' }}
        </p>
    </div>
    <div class="bg-gray-800 rounded-lg p-4 border border-gray-700">
        <p class="text-gray-400 text-sm">Peak SSE streams</p>
        <p class="text-2xl font-bold {{ ($streamsPeakPct ?? 0) >= 80 ? 'text-red-400' : (($streamsPeakPct ?? 0) >= 50 ? 'text-yellow-400' : 'text-cyan-400') }}">
            {{ number_format($peaks['streams']) }}
            @if ($streamsPeakPct !== null)
                <span class="text-base font-normal text-gray-500">/ {{ number_format($liveCapacity['streams_max']) }}</span>
            @endif
        </p>
        <p class="text-xs text-gray-500 mt-1">
            {{ $peaks['streams_at'] ? $peaks['streams_at']->format('d/m H:i') . ' UTC' : 'No reading yet' }}
        </p>
    </div>
    <div class="bg-gray-800 rounded-lg p-4 border border-gray-700">
        <p class="text-gray-400 text-sm">Edit sessions started</p>
        <p class="text-2xl font-bold">{{ number_format($peaks['started']) }}</p>
        <p class="text-xs text-gray-500 mt-1">Counted exactly, as they happen.</p>
    </div>
    <div class="bg-gray-800 rounded-lg p-4 border border-gray-700 {{ $peaks['refused'] > 0 ? 'border-red-600' : '' }}">
        <p class="text-gray-400 text-sm">Sessions refused</p>
        <p class="text-2xl font-bold {{ $peaks['refused'] > 0 ? 'text-red-400' : 'text-gray-500' }}">
            {{ number_format($peaks['refused']) }}
        </p>
        <p class="text-xs {{ $peaks['refused'] > 0 ? 'text-red-400' : 'text-gray-500' }} mt-1">
            {{ $peaks['refused'] > 0 ? 'The cap turned users away — raise it or move the server.' : 'Nobody was turned away.' }}
        </p>
    </div>
</div>

<!-- Charts -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
    <!-- Traffic Chart -->
    <div class="bg-gray-800 rounded-lg p-6 border border-gray-700">
        <h2 class="text-lg font-semibold mb-4"><i class="fas fa-chart-area mr-2 text-purple-400"></i> Traffic</h2>
        @if(count($chartLabels) > 0)
            <div class="h-64">
                <canvas id="trafficChart"></canvas>
            </div>
        @else
            <div class="h-64 flex items-center justify-center">
                <p class="text-gray-500 text-sm">No traffic data yet. Data is aggregated daily at 2 AM.</p>
            </div>
        @endif
    </div>

    <!-- Downloads Chart -->
    <div class="bg-gray-800 rounded-lg p-6 border border-gray-700">
        <h2 class="text-lg font-semibold mb-4"><i class="fas fa-download mr-2 text-green-400"></i> Downloads</h2>
        @if(count($chartLabels) > 0 && array_sum($chartDownloads) > 0)
            <div class="h-64">
                <canvas id="downloadsChart"></canvas>
            </div>
        @else
            <div class="h-64 flex items-center justify-center">
                <p class="text-gray-500 text-sm">No downloads yet for this period.</p>
            </div>
        @endif
    </div>

    <!-- Concurrency: daily peaks against the ceiling -->
    <div class="bg-gray-800 rounded-lg p-6 border border-gray-700 lg:col-span-2">
        <h2 class="text-lg font-semibold mb-1"><i class="fas fa-gauge-high mr-2 text-cyan-400"></i> Live edit concurrency</h2>
        <p class="text-xs text-gray-500 mb-4">
            Daily peaks against the stream ceiling. Sampled every 5 minutes, so a shorter spike can slip
            through — "Sessions refused" above is the exact count.
        </p>
        @if(count($chartLabels) > 0 && (array_sum($chartPeakSessions) > 0 || array_sum($chartPeakStreams) > 0))
            <div class="h-64">
                <canvas id="concurrencyChart"></canvas>
            </div>
        @else
            <div class="h-64 flex items-center justify-center">
                <p class="text-gray-500 text-sm">No concurrency readings yet — the first one lands within 5 minutes.</p>
            </div>
        @endif
    </div>
</div>

@php
    $hasDeviceData = ($allDevices['desktop'] ?? 0) + ($allDevices['mobile'] ?? 0) + ($allDevices['tablet'] ?? 0) > 0;
    $hasBrowserData = !empty($allBrowsers) && array_sum($allBrowsers) > 0;
@endphp

<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-6">
    <!-- Devices -->
    <div class="bg-gray-800 rounded-lg p-6 border border-gray-700">
        <h2 class="text-lg font-semibold mb-4"><i class="fas fa-desktop mr-2 text-blue-400"></i> Devices</h2>
        @if($hasDeviceData)
            <div class="h-48">
                <canvas id="devicesChart"></canvas>
            </div>
        @else
            <div class="h-48 flex items-center justify-center">
                <p class="text-gray-500 text-sm">No device data yet</p>
            </div>
        @endif
    </div>

    <!-- Browsers -->
    <div class="bg-gray-800 rounded-lg p-6 border border-gray-700">
        <h2 class="text-lg font-semibold mb-4"><i class="fas fa-globe mr-2 text-orange-400"></i> Browsers</h2>
        @if($hasBrowserData)
            <div class="h-48">
                <canvas id="browsersChart"></canvas>
            </div>
        @else
            <div class="h-48 flex items-center justify-center">
                <p class="text-gray-500 text-sm">No browser data yet</p>
            </div>
        @endif
    </div>

    <!-- Top Countries -->
    <div class="bg-gray-800 rounded-lg p-6 border border-gray-700">
        <h2 class="text-lg font-semibold mb-4"><i class="fas fa-flag mr-2 text-red-400"></i> Top Countries</h2>
        @if(count($topCountries) > 0)
            <div class="space-y-2">
                @foreach($topCountries as $country => $count)
                    <div class="flex justify-between items-center">
                        <span class="flex items-center gap-2">
                            <span class="fi fi-{{ strtolower($country) }}"></span>
                            {{ $country }}
                        </span>
                        <span class="text-gray-400">{{ number_format($count) }}</span>
                    </div>
                @endforeach
            </div>
        @else
            <p class="text-gray-500 text-sm">No data yet</p>
        @endif
    </div>

    <!-- Top Referrers -->
    <div class="bg-gray-800 rounded-lg p-6 border border-gray-700">
        <h2 class="text-lg font-semibold mb-4"><i class="fas fa-link mr-2 text-cyan-400"></i> Top Referrers</h2>
        @if(count($topReferrers) > 0)
            <div class="space-y-2">
                @foreach($topReferrers as $referrer => $count)
                    <div class="flex justify-between items-center">
                        <span class="truncate" title="{{ $referrer }}">{{ $referrer }}</span>
                        <span class="text-gray-400">{{ number_format($count) }}</span>
                    </div>
                @endforeach
            </div>
        @else
            <p class="text-gray-500 text-sm">No external referrers</p>
        @endif
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <!-- Top Games -->
    <div class="bg-gray-800 rounded-lg p-6 border border-gray-700">
        <h2 class="text-lg font-semibold mb-4"><i class="fas fa-gamepad mr-2 text-purple-400"></i> Top Games</h2>
        @if($topGames->isNotEmpty())
            <div class="space-y-3">
                @foreach($topGames as $gameStats)
                    @if($gameStats->game)
                        <div class="flex justify-between items-center bg-gray-750 rounded p-3">
                            <div class="flex items-center gap-3">
                                @if($gameStats->game->cover_url)
                                    <img src="{{ $gameStats->game->cover_url }}" alt="" class="w-10 h-10 rounded object-cover">
                                @else
                                    <div class="w-10 h-10 bg-gray-700 rounded flex items-center justify-center">
                                        <i class="fas fa-gamepad text-gray-500"></i>
                                    </div>
                                @endif
                                <span class="font-medium">{{ $gameStats->game->name }}</span>
                            </div>
                            <div class="text-right text-sm">
                                <p>{{ number_format($gameStats->views) }} <span class="text-gray-400">views</span></p>
                                <p>{{ number_format($gameStats->downloads) }} <span class="text-gray-400">downloads</span></p>
                            </div>
                        </div>
                    @endif
                @endforeach
            </div>
        @else
            <p class="text-gray-500 text-sm">No game data yet</p>
        @endif
    </div>

    <!-- Recent Uploads -->
    <div class="bg-gray-800 rounded-lg p-6 border border-gray-700">
        <h2 class="text-lg font-semibold mb-4"><i class="fas fa-upload mr-2 text-green-400"></i> Recent Uploads</h2>
        @if($recentUploads->isNotEmpty())
            <div class="space-y-3">
                @foreach($recentUploads as $translation)
                    <div class="flex justify-between items-center bg-gray-750 rounded p-3">
                        <div>
                            <p class="font-medium">{{ $translation->game->name ?? 'Unknown' }}</p>
                            <p class="text-sm text-gray-400">
                                by {{ $translation->user->name ?? '[Deleted]' }}
                                • {{ $translation->source_language }} → {{ $translation->target_language }}
                            </p>
                        </div>
                        <span class="text-sm text-gray-500">{{ $translation->created_at->diffForHumans() }}</span>
                    </div>
                @endforeach
            </div>
        @else
            <p class="text-gray-500 text-sm">No recent uploads</p>
        @endif
    </div>
</div>

<!-- Info box -->
<div class="mt-6 bg-gray-800 rounded-lg p-4 border border-gray-700 text-sm text-gray-400 space-y-2">
    <p>
        <i class="fas fa-clock mr-2"></i>
        <strong>Where the numbers come from.</strong>
        Past days are aggregated once a night, at 02:00 UTC, from the raw events of the day before.
        Today is counted live on every load, so the period totals always include it.
        Concurrency peaks are sampled every 5 minutes; sessions started and refused are counted one by one.
    </p>
    <p>
        <i class="fas fa-shield-halved mr-2"></i>
        <strong>What is kept.</strong>
        Daily aggregates are kept indefinitely, individual events for 90 days.
        No IP address is ever stored — visitors are counted through a salted daily hash that cannot be reversed.
    </p>
</div>

<script nonce="{{ $cspNonce }}">
    window.__analyticsData = {
        chartLabels: @json($chartLabels),
        chartPageViews: @json($chartPageViews),
        chartVisitors: @json($chartVisitors),
        chartDownloads: @json($chartDownloads),
        hasTrafficData: {{ count($chartLabels) > 0 ? 'true' : 'false' }},
        hasDownloadData: {{ (count($chartLabels) > 0 && array_sum($chartDownloads) > 0) ? 'true' : 'false' }},
        hasDeviceData: {{ $hasDeviceData ? 'true' : 'false' }},
        devices: {
            desktop: {{ $allDevices['desktop'] ?? 0 }},
            mobile: {{ $allDevices['mobile'] ?? 0 }},
            tablet: {{ $allDevices['tablet'] ?? 0 }}
        },
        hasBrowserData: {{ $hasBrowserData ? 'true' : 'false' }},
        browserLabels: @json(array_keys($allBrowsers)),
        browserValues: @json(array_values($allBrowsers)),
        hasConcurrencyData: {{ (count($chartLabels) > 0 && (array_sum($chartPeakSessions) > 0 || array_sum($chartPeakStreams) > 0)) ? 'true' : 'false' }},
        peakSessions: @json($chartPeakSessions),
        peakStreams: @json($chartPeakStreams),
        // Drawn as a flat line so the headroom is visible at a glance, rather
        // than left for the reader to work out from the axis
        streamCeiling: {{ $liveCapacity['streams_max'] !== null ? (int) $liveCapacity['streams_max'] : 'null' }},
    };
</script>
@vite('resources/js/admin-charts.js')
@endsection
