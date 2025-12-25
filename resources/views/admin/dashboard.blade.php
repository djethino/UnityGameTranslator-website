@extends('layouts.app')

@section('title', __('admin.dashboard') . ' - UnityGameTranslator')

@section('content')
<div class="mb-6 flex justify-between items-center">
    <h1 class="text-3xl font-bold"><i class="fas fa-shield-alt mr-2"></i> {{ __('admin.dashboard') }}</h1>
    <a href="{{ route('admin.analytics') }}" class="bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded-lg">
        <i class="fas fa-chart-line mr-2"></i> Analytics
    </a>
</div>

<div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
    <div class="bg-gray-800 rounded-lg p-6 border border-gray-700">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-gray-400 text-sm">{{ __('admin.pending_reports') }}</p>
                <p class="text-3xl font-bold text-yellow-400">{{ $pendingReports }}</p>
            </div>
            <i class="fas fa-flag text-4xl text-yellow-400 opacity-50"></i>
        </div>
        <a href="{{ route('admin.reports') }}" class="text-purple-400 hover:text-purple-300 text-sm mt-4 inline-block">
            {{ __('admin.view_all') }} <i class="fas fa-arrow-right ml-1"></i>
        </a>
    </div>

    <div class="bg-gray-800 rounded-lg p-6 border border-gray-700">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-gray-400 text-sm">{{ __('admin.total_translations') }}</p>
                <p class="text-3xl font-bold text-green-400">{{ $totalTranslations }}</p>
            </div>
            <i class="fas fa-file-alt text-4xl text-green-400 opacity-50"></i>
        </div>
    </div>

    <div class="bg-gray-800 rounded-lg p-6 border border-gray-700">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-gray-400 text-sm">{{ __('admin.users') }}</p>
                <p class="text-3xl font-bold text-blue-400">{{ $totalUsers }}</p>
                @if($bannedUsers > 0)
                    <p class="text-sm text-red-400 mt-1">{{ $bannedUsers }} {{ __('admin.banned') }}</p>
                @endif
            </div>
            <i class="fas fa-users text-4xl text-blue-400 opacity-50"></i>
        </div>
        <a href="{{ route('admin.users') }}" class="text-purple-400 hover:text-purple-300 text-sm mt-4 inline-block">
            {{ __('admin.manage_users') }} <i class="fas fa-arrow-right ml-1"></i>
        </a>
    </div>
</div>

@if($recentReports->isNotEmpty())
<div class="bg-gray-800 rounded-lg p-6 border border-gray-700">
    <h2 class="text-xl font-semibold mb-4"><i class="fas fa-clock mr-2"></i> {{ __('admin.recent_reports') }}</h2>
    <div class="space-y-3">
        @foreach($recentReports as $report)
            <div class="flex justify-between items-center bg-gray-750 rounded p-4">
                <div>
                    <p class="font-medium">{{ $report->translation->game->name }}</p>
                    <p class="text-sm text-gray-400">
                        {{ __('admin.reported_by', ['user' => $report->reporter->name]) }} • {{ $report->created_at->diffForHumans() }}
                    </p>
                    <p class="text-sm text-gray-500 mt-1">{{ Str::limit($report->reason, 100) }}</p>
                </div>
                <a href="{{ route('admin.reports.show', $report) }}" class="bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded">
                    {{ __('admin.review') }}
                </a>
            </div>
        @endforeach
    </div>
</div>
@endif
@endsection
