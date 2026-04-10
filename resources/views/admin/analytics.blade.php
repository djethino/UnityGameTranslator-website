@extends('layouts.app')

@section('title', 'Analytics - UnityGameTranslator')

@section('content')
<div class="mb-4">
    <a href="{{ route('admin.dashboard') }}" class="text-gray-400 hover:text-white">
        <i class="fas fa-arrow-left mr-1"></i> Back to Dashboard
    </a>
</div>
<div class="mb-6 flex justify-between items-center">
    <h1 class="text-3xl font-bold"><i class="fas fa-chart-line mr-2"></i> Analytics</h1>

    <div class="flex gap-2">
        <a href="{{ route('admin.analytics', ['period' => 7]) }}"
           class="px-4 py-2 rounded {{ $period == 7 ? 'bg-purple-600' : 'bg-gray-700 hover:bg-gray-600' }}">
            7 days
        </a>
        <a href="{{ route('admin.analytics', ['period' => 30]) }}"
           class="px-4 py-2 rounded {{ $period == 30 ? 'bg-purple-600' : 'bg-gray-700 hover:bg-gray-600' }}">
            30 days
        </a>
        <a href="{{ route('admin.analytics', ['period' => 90]) }}"
           class="px-4 py-2 rounded {{ $period == 90 ? 'bg-purple-600' : 'bg-gray-700 hover:bg-gray-600' }}">
            90 days
        </a>
        <a href="{{ route('admin.analytics', ['period' => 365]) }}"
           class="px-4 py-2 rounded {{ $period == 365 ? 'bg-purple-600' : 'bg-gray-700 hover:bg-gray-600' }}">
            1 year
        </a>
    </div>
</div>

<!-- Global Stats -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
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
        <p class="text-gray-400 text-sm">Total Downloads (All Time)</p>
        <p class="text-2xl font-bold text-yellow-400">{{ number_format($globalStats['total_downloads']) }}</p>
    </div>
</div>

<!-- Period Stats -->
<div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-6">
    <div class="bg-gray-800 rounded-lg p-4 border border-gray-700">
        <p class="text-gray-400 text-sm">Page Views ({{ $period }}d)</p>
        <p class="text-2xl font-bold">{{ number_format($totals['page_views']) }}</p>
        <p class="text-xs text-green-400">+{{ $todayStats['page_views'] }} today</p>
    </div>
    <div class="bg-gray-800 rounded-lg p-4 border border-gray-700">
        <p class="text-gray-400 text-sm">Unique Visitors</p>
        <p class="text-2xl font-bold">{{ number_format($totals['unique_visitors']) }}</p>
        <p class="text-xs text-green-400">+{{ $todayStats['unique_visitors'] }} today</p>
    </div>
    <div class="bg-gray-800 rounded-lg p-4 border border-gray-700">
        <p class="text-gray-400 text-sm">Downloads</p>
        <p class="text-2xl font-bold">{{ number_format($totals['downloads']) }}</p>
    </div>
    <div class="bg-gray-800 rounded-lg p-4 border border-gray-700">
        <p class="text-gray-400 text-sm">Uploads</p>
        <p class="text-2xl font-bold">{{ number_format($totals['uploads']) }}</p>
    </div>
    <div class="bg-gray-800 rounded-lg p-4 border border-gray-700">
        <p class="text-gray-400 text-sm">New Users</p>
        <p class="text-2xl font-bold">{{ number_format($totals['registrations']) }}</p>
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
<div class="mt-6 bg-gray-800 rounded-lg p-4 border border-gray-700">
    <p class="text-sm text-gray-400">
        <i class="fas fa-info-circle mr-2"></i>
        Data is aggregated daily at 2 AM. Today's stats are live from events. Historical data kept forever, individual events purged after 90 days.
        No IP addresses stored, GDPR compliant.
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
    };
</script>
@vite('resources/js/admin-charts.js')
@endsection
