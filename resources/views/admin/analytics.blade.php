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
            @if ($liveCapacity['streams_peak'] !== null)
                <p class="text-xs text-gray-500 mt-2">
                    Peak since the stream server last started:
                    <span class="text-gray-300">{{ number_format($liveCapacity['streams_peak']) }}</span>
                </p>
            @endif
            @php
                $refusedCap = (int) ($liveCapacity['refused_at_capacity'] ?? 0);
                $refusedIp = (int) ($liveCapacity['refused_per_ip'] ?? 0);
            @endphp
            @if ($refusedCap > 0 || $refusedIp > 0)
                {{-- Only shown once a ceiling has actually bitten: this is the
                     signal the raised limits exist to produce. --}}
                <p class="text-xs text-amber-400 mt-2">
                    Refused: {{ number_format($refusedCap) }} at capacity,
                    {{ number_format($refusedIp) }} per-IP
                </p>
            @endif
            <p class="text-xs text-gray-500 mt-2">
                The ceiling is deliberately roomy: the host documents no limit, so the real one has to
                show itself here. Watch refusals, not the percentage.
            </p>
        @endif
    </div>
</div>

{{-- Shared catalogues: languages, mod loaders, AI models.

     Here because this is the one failure with no symptom. If the catalogue cannot be
     fetched the site keeps working from the copy committed in the repository and stays
     entirely correct — so a source unreachable for months looks identical to one that is
     simply stable. This line is the only place that tells them apart. --}}
@php
    $catalogueStale = collect($catalogue)->max(fn ($d) => $d['days'] ?? PHP_INT_MAX);
    $catalogueNever = collect($catalogue)->every(fn ($d) => $d['at'] === null);
@endphp
<div class="mt-4 bg-gray-800 rounded-lg p-4 border border-gray-700">
    <div class="flex items-center justify-between gap-3 mb-2">
        <h3 class="text-sm font-semibold text-gray-400">
            <i class="fas fa-book mr-1 text-purple-500"></i> Shared catalogues
        </h3>
        {{-- ⚠ The button belongs HERE, beside the staleness it acts on — not in a settings screen.
             This is the only place that says a source has gone quiet, so it is the only place where
             somebody decides to go and fetch it. --}}
        <x-admin.refresh-catalogues />
    </div>

    @if ($catalogueNever)
        <p class="text-sm text-amber-400">
            Never fetched — running on the copy shipped with the code.
        </p>
        <p class="text-xs text-gray-500 mt-1">
            Correct, but frozen at the last deployment. Check that the scheduler runs
            <code class="text-gray-400">catalog:refresh</code>.
        </p>
    @else
        <div class="flex flex-wrap gap-x-6 gap-y-1 text-sm">
            @foreach ($catalogue as $name => $state)
                <span class="text-gray-300">
                    {{ $name }}
                    @if ($state['at'] === null)
                        <span class="text-amber-400">never fetched</span>
                    @elseif ($state['days'] === 0)
                        <span class="text-green-400">today</span>
                    @else
                        <span class="{{ $state['days'] > 7 ? 'text-amber-400' : 'text-gray-500' }}">
                            {{ $state['days'] }}d ago
                        </span>
                    @endif
                </span>
            @endforeach
        </div>

        @if ($catalogueStale > 7)
            <p class="text-xs text-amber-400 mt-2">
                Refreshed daily when it works. More than a week means the source has been
                unreachable and nobody was told — the site is fine, the data is not moving.
            </p>
        @endif
    @endif
</div>

<!-- ─── Over a period ────────────────────────────────────────────────────── -->
{{-- The selector sits HERE, not next to the page title: it drives this section
     and everything below it, and nothing above.

     🔴 **Sticky, because everything it drives is BELOW it and most of it is off screen.** Comparing
     two spans on the version inventory meant scrolling a page and a half up, clicking, and scrolling
     a page and a half back down — so in practice nobody compared. It rides along instead, the same
     way the editors' action bar does (`merge/show.blade.php`).

     ⚠ `top-0` with a background and a full-bleed shadow: a sticky bar with a transparent background
     lets the page scroll through its own text. --}}
<div class="sticky top-0 z-30 -mx-4 px-4 mt-8 mb-3 py-2 bg-gray-900/95 backdrop-blur
            border-b border-gray-800 flex flex-wrap gap-3 justify-between items-center"
     id="period-bar">
    <h2 class="text-lg font-semibold text-gray-300">
        <i class="fas fa-calendar-days mr-2 text-purple-500"></i>
        {{-- "Last 1 days" is not a sentence, and the shortest window is exactly the one somebody
             reaches for when something is happening right now.

             ⚠ Every other span is named by the button the reader just pressed, never re-worded:
             "48 h" up there and "Last 2 days" here would read as two different spans. --}}
        {{ $period === 1 ? 'Yesterday and today' : 'Last ' . $spanLabel }}
        <span class="text-sm font-normal text-gray-500 ml-2">— today included, counted live</span>
    </h2>

    {{-- ⚠ 1 day and the full span are both real answers that used to be unreachable: the smallest
         offer was a week, and anything past a year was silently served as a year while the daily
         aggregates are kept forever. "All" is only offered once there is more than a year to
         show — a duplicate button would just be a second way to ask for the same thing.

         🔴 The list itself lives in AnalyticsPeriods, not here: it stopped being a display filter
         the day the version inventory started using it to decide what reads as extinct. --}}
    <div class="flex gap-2">
        @foreach (\App\Support\AnalyticsPeriods::choices($daysStored, $period) as $days => $label)
            {{-- ⚠ An ordinary link. Where the reader was is remembered by a delegated listener on
                 the bar — see the script at the foot of this file for why the page is reloaded
                 whole rather than patched in place. --}}
            {{-- ⚠ Carries the uploads sub-filter along, for the same reason it carries the period
                 back: touching one control must not silently reset the other. --}}
            <a href="{{ route('admin.analytics', ['period' => $days, 'uploads' => $uploadRole === 'all' ? null : $uploadRole]) }}"
               data-keeps-scroll
               class="px-3 py-1.5 rounded text-sm {{ $period == $days ? 'bg-purple-600' : 'bg-gray-700 hover:bg-gray-600' }}">
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
        {{-- A zero peak is a measurement, not a missing one --}}
        <p class="text-xs text-gray-500 mt-1">
            {{ $peaks['sessions_at'] ? $peaks['sessions_at']->format('d/m H:i') . ' UTC' : 'No session in this period' }}
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
            {{ $peaks['streams_at'] ? $peaks['streams_at']->format('d/m H:i') . ' UTC' : 'No stream in this period' }}
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
        @php
            // What the chart can no longer say by itself once the ceiling is off the plot.
            $observedPeak = max([0, ...$chartPeakSessions, ...$chartPeakStreams]);
            $ceiling = $liveCapacity['streams_max'];
            $ceilingPlotted = $ceiling && $observedPeak >= $ceiling * 0.2;
        @endphp
        <p class="text-xs text-gray-500 mb-4">
            Daily peaks. Sampled every 5 minutes, so a shorter spike can slip through —
            "Sessions refused" above is the exact count.
            @if ($ceiling && !$ceilingPlotted)
                {{-- ⚠ The ceiling is 1000 and real use is a handful, so plotting it flattened
                     every reading onto the baseline. The scale now follows the data and the
                     headroom is said here, where it reads better than off an axis. --}}
                <span class="text-gray-400">
                    Scaled to the readings: the ceiling of {{ number_format($ceiling) }} is far above
                    the highest peak seen here ({{ number_format($observedPeak) }}), so drawing it
                    would flatten the curve. It joins the chart once a peak reaches a fifth of it.
                </span>
            @elseif ($ceiling)
                <span class="text-gray-400">Plotted against the ceiling of {{ number_format($ceiling) }}.</span>
            @endif
        </p>
        {{-- Drawn even when every reading is zero: a flat line under the ceiling
             is the answer to "am I close to saturating", and a far better one
             than a message claiming nothing was measured. The empty state below
             means there is no day to plot at all, not a quiet period. --}}
        @if(count($chartLabels) > 0)
            <div class="h-64">
                <canvas id="concurrencyChart"></canvas>
            </div>
        @else
            <div class="h-64 flex items-center justify-center">
                <p class="text-gray-500 text-sm">Nothing to plot yet — readings start within 5 minutes.</p>
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

{{-- ─── What is running out there ──────────────────────────────────────────────
     Placed after the visitor breakdowns and before the content ones, because it answers the same
     kind of question they do — WHO is on the other end — while being about our software rather
     than about browsers. Full width: it is a list of unknown length, not a fixed set of slices.

     🔴 It answers ONE question: can this be broken yet? Deprecate before reworking a part, drop an
     obsolete API call, stop maintaining a loader adapter. That is a question of RECENCY — which is
     why the figure is no longer the headline and the dates are.

     🔴 Every version keeps its row and the span decides how it READS. The previous version of this
     card filtered the LIST by the span, so a version with no recent call vanished — and nobody
     decides to break something on an absence. Reasoning: analyse/version-inventory-admin.md --}}
<div class="bg-gray-800 rounded-lg p-6 border border-gray-700 mb-6">
    <div class="flex flex-wrap items-baseline justify-between gap-2 mb-1">
        <h2 class="text-lg font-semibold">
            <i class="fas fa-cubes mr-2 text-cyan-400"></i> Mod and Manager versions
        </h2>
        {{-- ⚠ **No Refresh button here, and that is a decision** (2026-08-29). One was added by
             symmetry with the catalogue card and removed on the user's call: the release list is
             fetched hourly on its own, and a button that only shaves off that hour does not earn a
             control on a screen this dense. If the first hours of a release's adoption ever turn
             out to matter enough, `releases:refresh` is a command away. --}}
        <p class="text-xs text-gray-500">
            Counted per version, never per user — no address is stored and no copy can be followed
            from one day to the next.
        </p>
    </div>

    {{-- ⚠ The span is named here, in the card, because it is what draws the line between what is
         still running and what is not. Leaving the reader to remember which button they pressed is
         how the old card became unreadable. --}}
    <p class="text-xs text-gray-500 mb-3">
        Activity and copies over the <span class="text-gray-400">last {{ $spanLabel }}</span>;
        first and last seen are the whole history. Counting started 2026-08-20 — before that every
        build called itself the same thing.
    </p>

    @unless ($releasesKnown)
        {{-- ⚠ Without the published list, nothing can be told apart from an invented number, so
             everything is filed as unrecognised. Saying so beats a table that looks like a
             measurement — this is precisely the failure mode this screen spent the day repairing. --}}
        <p class="text-sm text-amber-400 mt-3">
            The list of published versions has never been fetched, so nothing below can be matched
            to a real release.
        </p>
        <p class="text-xs text-gray-500 mt-1">
            Check that the scheduler runs <code class="text-gray-400">releases:refresh</code> (hourly).
        </p>
    @endunless

    @php
        $anything = collect($clients)->contains(fn ($p) => $p['anything']);
    @endphp

    @unless ($anything)
        <p class="text-gray-500 text-sm mt-3">
            Nothing has ever called. Counting started on 2026-08-20 — before that, every build called
            itself the same thing and nothing was written down.
        </p>
    @else
        @foreach ($clients as $product => $data)
            @continue (!$data['anything'])

            {{-- ⚠ One section per product, and the Product column is gone with them: a chronological
                 order that interleaves mod 0.12.0 and manager 0.1.1 makes no sense, their numbers do
                 not talk about the same thing. --}}
            <h3 class="text-sm font-semibold mt-5 mb-2">
                <span class="px-2 py-0.5 rounded text-xs
                    {{ $product === 'mod' ? 'bg-purple-900 text-purple-200' : 'bg-blue-900 text-blue-200' }}">
                    {{ $product === 'mod' ? 'Mod' : 'Manager' }}
                </span>
                <span class="text-gray-400 ml-2">versions</span>
            </h3>

            @if ($data['versions'])
                @include('admin.partials.version-table', [
                    'lines' => $data['versions'],
                    'data' => $data,
                    'heading' => 'Version',
                    'scale' => 'versions',
                    'divide' => true,
                ])
            @endif

            @if ($data['out_of_reach'])
                {{-- 🔴 Summarised, never listed. These were published before anything was counted
                     and have not called since, so nothing can be known about them — and writing
                     "never" against thirty of them states a measurement that was never taken, while
                     burying the few rows that mean something. --}}
                @php $reach = $data['out_of_reach']; @endphp
                <p class="text-xs text-gray-600 mt-2 pl-1">
                    + {{ count($reach) }} earlier
                    release{{ count($reach) === 1 ? '' : 's' }}
                    ({{ $reach[count($reach) - 1]['name'] }} – {{ $reach[0]['name'] }}),
                    published before counting started on {{ \App\Models\ClientUsageDaily::COUNTING_STARTED }}
                    and silent since. Nothing can be said about whether they are still running.
                </p>
            @endif

            @if ($data['buckets'])
                {{-- ⚠ These have no publication date, so they cannot sit in a chronology. Apart, and
                     named: "before versioning" is the row that decides whether JSON compression can
                     be switched on, and it used to be buried in the middle of the list. --}}
                <h3 class="text-sm font-semibold mt-5 mb-2 text-gray-400">Not tied to a release</h3>
                @include('admin.partials.version-table', [
                    'lines' => $data['buckets'],
                    'data' => $data,
                    'heading' => 'Build',
                    'scale' => 'buckets',
                    'divide' => false,
                ])
            @endif

            @if ($data['adapters'])
                {{-- ⚠ The other maintenance decision, and it is never taken version by version: one
                     does not drop an adapter for 0.12.0 only. Hence a second block rather than five
                     figures on every version row, which is what made the old card unreadable. --}}
                <h3 class="text-sm font-semibold mt-5 mb-2 text-gray-400">
                    Adapters <span class="text-gray-600 font-normal">— all versions together</span>
                </h3>
                @include('admin.partials.version-table', [
                    'lines' => $data['adapters'],
                    'data' => $data,
                    'heading' => 'Adapter',
                    'scale' => 'adapters',
                    'divide' => false,
                ])
            @endif
        @endforeach

        <p class="text-xs text-gray-500 mt-4">
            ⚠ Copies are the busiest single day of the span, not the days added up — the same copy
            calling on ten days is one copy. Treat it as an order of magnitude. Counted once a day per
            copy, so a build that polls often weighs no more than a quiet one.
            <span class="block mt-1">
                ⚠ "Seen" means called this site. A mod running offline never appears — which is the
                right measure for deciding whether an API call can be dropped, since the ones that
                break are the ones that call.
            </span>
        </p>
    @endunless
</div>

{{-- 🔴 **The pair expands together, from one state held here.** Two cards side by side that fold
     independently leave one twice the height of the other, and the reader then reads the gap as
     something being wrong rather than as a choice they just made. Nobody has to think about it:
     asking for the rest of one asks for the rest of both.

     ⚠ An object literal, not a function: Alpine evaluates `x-data` before the inline script at the
     foot of this file has run, so anything named there would be "Undefined variable" — which is
     exactly how the period bar failed silently twice. --}}
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6" x-data="{ expanded: false }">
    <!-- Top Games -->
    {{-- ⚠ "views" here excludes downloads. The stored column counts both (see AnalyticsGame), and
         printing the two raw side by side counted a download twice — measured at 29% of what was
         labelled "views". The ranking is on the two together, and the card says so, because a
         ranking whose criterion is unstated invites the reader to invent one. --}}
    <div class="bg-gray-800 rounded-lg p-6 border border-gray-700">
        <div class="flex flex-wrap items-baseline justify-between gap-2 mb-4">
            <h2 class="text-lg font-semibold"><i class="fas fa-gamepad mr-2 text-purple-400"></i> Top Games</h2>
            {{-- ⚠ The criterion is named, and the figure it ranks on is the one printed large.
                 Ranking on the two together is right — a game nobody browses but everybody
                 downloads is doing well — but with only the two parts shown, neither column
                 decreased down the list and the order read as broken. --}}
            <p class="text-xs text-gray-500">Last {{ $spanLabel }} — ranked on views + downloads</p>
        </div>
        @if($topGames->isNotEmpty())
            <div class="space-y-3">
                @foreach($topGames as $gameStats)
                    {{-- Beyond the fold, the row is hidden rather than absent: the ranking position
                         has to stay the same before and after expanding. --}}
                    <div class="flex justify-between items-center bg-gray-750 rounded p-3"
                         @if($loop->index >= $topRows['visible']) x-show="expanded" x-cloak @endif>
                        <div class="flex items-center gap-3 min-w-0">
                            @if($gameStats->game->cover_url)
                                <img src="{{ $gameStats->game->cover_url }}" alt="" class="w-10 h-10 rounded object-cover shrink-0">
                            @else
                                <div class="w-10 h-10 bg-gray-700 rounded flex items-center justify-center shrink-0">
                                    <i class="fas fa-gamepad text-gray-500"></i>
                                </div>
                            @endif
                            <span class="font-medium truncate">{{ $gameStats->game->name }}</span>
                        </div>
                        <div class="text-right shrink-0 ml-3">
                            <p class="font-semibold">{{ number_format($gameStats->attention) }}</p>
                            <p class="text-xs text-gray-400 whitespace-nowrap">
                                {{ number_format($gameStats->views) }} views ·
                                {{ number_format($gameStats->downloads) }} downloads
                            </p>
                        </div>
                    </div>
                @endforeach
            </div>
            <x-admin.show-more :count="$topGames->count()" :visible="$topRows['visible']" />
            <p class="text-xs text-gray-500 mt-3">
                ⚠ Views and downloads do not overlap: a download is not also counted as a view.
            </p>
        @else
            <p class="text-gray-500 text-sm">Nothing looked at in this period.</p>
        @endif
    </div>

    <!-- Uploads -->
    {{-- 🔴 This card used to ignore the span entirely while sitting in the section the span drives:
         the same five rows whether you asked for 24 h or a year. On a page where everything else
         follows the filter, that reads as "nothing happened".

         ⚠ And it showed Mains and Branches identically. A branch is a proposal attached to somebody
         else's Main, not something published — in the one product where that distinction is
         structural, a list of "uploads" cannot flatten it. Colours and icon are the ones already
         used for these roles (profile/edit.blade.php), not a new set. --}}
    <div class="bg-gray-800 rounded-lg p-6 border border-gray-700">
        <div class="flex flex-wrap items-baseline justify-between gap-2 mb-3">
            <h2 class="text-lg font-semibold"><i class="fas fa-upload mr-2 text-green-400"></i> Uploads</h2>
            <p class="text-xs text-gray-500">
                Last {{ $spanLabel }} —
                {{ number_format($recentUploadsTotal) }} in all{{ $recentUploadsTotal > $recentUploads->count() ? ', newest ' . $recentUploads->count() . ' shown' : '' }}
            </p>
        </div>

        {{-- ⚠ Server-side, so the count beside it is the count of what is being asked for. Filtering
             the ten already fetched would let "Branch" show three while the period holds twelve.

             ⚠ Each link carries the period along. A sub-filter that silently reset the span above it
             would move two things when the reader touched one. --}}
        <div class="flex gap-1.5 mb-4 text-xs">
            @foreach (['all' => 'All', 'main' => 'Main', 'branch' => 'Branch'] as $role => $label)
                <a href="{{ route('admin.analytics', ['period' => $period, 'uploads' => $role]) }}"
                   data-keeps-scroll
                   class="px-2.5 py-1 rounded transition
                          {{ $uploadRole === $role ? 'bg-gray-600 text-gray-100' : 'bg-gray-750 text-gray-400 hover:bg-gray-700' }}">
                    @if($role !== 'all')
                        <i class="fas {{ $role === 'main' ? 'fa-star' : 'fa-code-branch' }} mr-1 {{ $role === 'main' ? 'text-purple-300' : '' }}"></i>
                    @endif{{ $label }}
                </a>
            @endforeach
        </div>
        @if($recentUploads->isNotEmpty())
            <div class="space-y-3">
                @foreach($recentUploads as $translation)
                    @php $isMain = $translation->lineageRole() === 'main'; @endphp
                    <div class="flex justify-between items-center bg-gray-750 rounded p-3"
                         @if($loop->index >= $topRows['visible']) x-show="expanded" x-cloak @endif>
                        <div class="min-w-0">
                            <p class="font-medium truncate">
                                <span class="{{ $isMain ? 'text-purple-300' : 'text-gray-400' }}" title="{{ $isMain ? 'Published' : 'A contribution to somebody else\'s Main' }}">
                                    <i class="fas {{ $isMain ? 'fa-star' : 'fa-code-branch' }} text-xs mr-1"></i>{{ $isMain ? 'Main' : 'Branch' }}
                                </span>
                                <span class="text-gray-500 mx-1">·</span>{{ $translation->game->name ?? 'Unknown' }}
                            </p>
                            <p class="text-sm text-gray-400 truncate">
                                by {{ $translation->user->name ?? '[Deleted]' }}
                                • {{ $translation->source_language }} → {{ $translation->target_language }}
                            </p>
                        </div>
                        <span class="text-sm text-gray-500 shrink-0 ml-3">{{ $translation->created_at->diffForHumans() }}</span>
                    </div>
                @endforeach
            </div>
            <x-admin.show-more :count="$recentUploads->count()" :visible="$topRows['visible']" />
        @else
            <p class="text-gray-500 text-sm">
                @if($uploadRole === 'all')
                    Nothing uploaded in this period.
                @else
                    {{-- ⚠ Names what was filtered out rather than saying "nothing": the reader has
                         just narrowed the list and needs to know that is why it is empty. --}}
                    No {{ $uploadRole === 'main' ? 'Main' : 'Branch' }} uploaded in this period.
                @endif
            </p>
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
    /**
     * Keeps the reader where they were when they change span.
     *
     * 🔴 **A full reload, deliberately, rather than patching the page in place.** Nearly everything
     * below the bar depends on the span — five tiles, three charts, the concurrency peaks, the
     * breakdowns, the top games and the whole version inventory. Refreshing "the parts that depend
     * on the filter" means listing them by hand, and the day somebody adds a sixth tile and forgets
     * it, the screen shows two different spans at once with nothing to say so. Letting the server
     * re-render the page makes that impossible BY CONSTRUCTION.
     *
     * ⚠ So the only thing worth keeping across the reload is the scroll position — which is what
     * made switching span expensive in the first place, since the inventory sits a page and a half
     * below the buttons.
     *
     * ⚠ sessionStorage, not the URL: a scroll offset in the address bar would be shared, bookmarked
     * and mailed around, and it means nothing on anybody else's screen.
     *
     * 🔴 **No Alpine here, and no `load` event either — this script runs after BOTH.** It first used
     * `x-data="periodBar()"` and a listener on `load`: Alpine had already evaluated the attribute by
     * the time this file defined the function ("Undefined variable: periodBar"), and `load` had
     * already fired by the time the listener was added, so nothing was ever saved and nothing was
     * ever restored. One cause, two silent failures. A delegated listener and a readyState check
     * depend on neither.
     */
    (function periodScroll() {
        const KEY = 'ugt.analytics.scroll';

        // ⚠ Delegated on the document and keyed on an attribute, not on one bar: the uploads
        // sub-filter reloads the page for exactly the same reason the span does, and it sits half a
        // page lower — where losing the position costs more, not less. Any filter link added later
        // joins in by carrying `data-keeps-scroll`, with nothing to wire up.
        document.addEventListener('click', (event) => {
            if (!event.target.closest('a[data-keeps-scroll]')) {
                return;
            }
            try {
                sessionStorage.setItem(KEY, String(window.scrollY));
            } catch (e) {
                // Private windows and blocked site data throw here. Losing the position is a small
                // annoyance; a broken filter would not be.
            }
        });

        let saved = null;
        try {
            saved = sessionStorage.getItem(KEY);
            sessionStorage.removeItem(KEY);
        } catch (e) {
            return;
        }

        if (saved === null) {
            return;
        }

        const y = parseInt(saved, 10) || 0;

        // ⚠ The charts size themselves late, so the page is shorter than its final height for a
        // moment and an early scroll is clamped short. Reasserting it a few times over half a
        // second costs nothing and survives whenever the growth actually happens — where waiting on
        // a single event only works if that event has not already gone by.
        const settle = () => {
            window.scrollTo(0, y);
            let tries = 0;
            const timer = setInterval(() => {
                if (Math.abs(window.scrollY - y) > 2 && ++tries < 10) {
                    window.scrollTo(0, y);
                } else {
                    clearInterval(timer);
                }
            }, 50);
        };

        if (document.readyState === 'complete') {
            settle();
        } else {
            window.addEventListener('load', settle);
        }
    })();

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
        hasConcurrencyData: {{ count($chartLabels) > 0 ? 'true' : 'false' }},
        peakSessions: @json($chartPeakSessions),
        peakStreams: @json($chartPeakStreams),
        // Drawn as a flat line so the headroom is visible at a glance, rather
        // than left for the reader to work out from the axis
        streamCeiling: {{ $liveCapacity['streams_max'] !== null ? (int) $liveCapacity['streams_max'] : 'null' }},
    };
</script>
@vite('resources/js/admin-charts.js')
@endsection
