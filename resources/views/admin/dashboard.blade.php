@extends('layouts.app')

@section('title', __('admin.dashboard') . ' - UnityGameTranslator')

@section('content')
{{-- ⚠ No buttons in this header (decided 2026-09-30). Analytics and Announcements sat here, the
     first in the site's primary purple, reading as the screen's main action while they only lead
     to a section — exactly what the cards below do. Every section is a card now, each with its
     figure. --}}
<div class="mb-6">
    <h1 class="text-3xl font-bold"><i class="fas fa-shield-alt mr-2"></i> {{ __('admin.dashboard') }}</h1>
</div>

<div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
    <div class="bg-gray-800 rounded-lg p-6 border border-gray-700 flex flex-col">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-gray-200 font-semibold">{{ __('admin.pending_reports') }}</p>
                <p class="text-3xl font-bold text-yellow-400">{{ $pendingReports }}</p>
            </div>
            <i class="fas fa-flag text-4xl text-yellow-400 opacity-50"></i>
        </div>
        <a href="{{ route('admin.reports') }}" class="text-purple-400 hover:text-purple-300 text-sm mt-auto pt-4 self-start">
            {{ __('admin.view_all') }} <i class="fas fa-arrow-right ml-1"></i>
        </a>
    </div>

    <div class="bg-gray-800 rounded-lg p-6 border border-gray-700 flex flex-col">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-gray-200 font-semibold">{{ __('admin.total_translations') }}</p>
                <p class="text-3xl font-bold text-green-400">{{ $totalTranslations }}</p>
            </div>
            <i class="fas fa-file-alt text-4xl text-green-400 opacity-50"></i>
        </div>
        <a href="{{ route('admin.translations.index') }}" class="text-purple-400 hover:text-purple-300 text-sm mt-auto pt-4 self-start">
            {{ __('admin.manage_translations') }} <i class="fas fa-arrow-right ml-1"></i>
        </a>
    </div>

    <div class="bg-gray-800 rounded-lg p-6 border border-gray-700 flex flex-col">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-gray-200 font-semibold">{{ __('admin.users') }}</p>
                <p class="text-3xl font-bold text-blue-400">{{ $totalUsers }}</p>
                @if($bannedUsers > 0)
                    <p class="text-sm text-red-400 mt-1">{{ trans_choice('admin.banned', $bannedUsers, ['count' => $bannedUsers]) }}</p>
                @endif
            </div>
            <i class="fas fa-users text-4xl text-blue-400 opacity-50"></i>
        </div>
        <a href="{{ route('admin.users') }}" class="text-purple-400 hover:text-purple-300 text-sm mt-auto pt-4 self-start">
            {{ __('admin.manage_users') }} <i class="fas fa-arrow-right ml-1"></i>
        </a>
    </div>

    {{-- Second row: the catalogue and the site itself — what an admin looks after rather than
         answers to. --}}

    {{--
        The one place a name a machine declared can be corrected. Every guard around unity_name
        refuses a bad value at the door; none of them could repair one already stored.
    --}}
    <div class="bg-gray-800 rounded-lg p-6 border border-gray-700 flex flex-col">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-gray-200 font-semibold">{{ __('nav.games') }}</p>
                <p class="text-3xl font-bold text-yellow-400">{{ $totalGames }}</p>
            </div>
            <i class="fas fa-gamepad text-4xl text-yellow-400 opacity-50"></i>
        </div>
        <a href="{{ route('admin.games') }}" class="text-purple-400 hover:text-purple-300 text-sm mt-auto pt-4 self-start">
            {{ __('admin.manage_games') }} <i class="fas fa-arrow-right ml-1"></i>
        </a>
    </div>

    <div class="bg-gray-800 rounded-lg p-6 border border-gray-700 flex flex-col">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-gray-200 font-semibold">{{ __('admin.visitors_today') }}</p>
                <p class="text-3xl font-bold text-purple-400">{{ number_format($visitorsToday) }}</p>
                <p class="text-sm text-gray-500 mt-1">{{ trans_choice('admin.page_views', $pageViewsToday, ['count' => number_format($pageViewsToday)]) }}</p>
            </div>
            <i class="fas fa-chart-line text-4xl text-purple-400 opacity-50"></i>
        </div>
        <a href="{{ route('admin.analytics') }}" class="text-purple-400 hover:text-purple-300 text-sm mt-auto pt-4 self-start">
            {{ __('admin.view_analytics') }} <i class="fas fa-arrow-right ml-1"></i>
        </a>
    </div>

    {{-- Whether a banner is shown to everybody right now, and which: what to know before
         publishing another one. --}}
    <div class="bg-gray-800 rounded-lg p-6 border border-gray-700 flex flex-col">
        <div class="flex items-center justify-between gap-4">
            <div class="min-w-0">
                <p class="text-gray-200 font-semibold">{{ __('admin.announcements') }}</p>
                @if($banner)
                    {{-- Plain text, not a figure: the card's title stays what the eye reads first. --}}
                    <p class="text-amber-300 truncate mt-1" title="{{ $banner->title }}">{{ $banner->title }}</p>
                    <p class="text-sm text-gray-500 mt-1">{{ __('admin.banner_since', ['date' => $banner->published_at->isoFormat('LL')]) }}</p>
                @else
                    <p class="text-gray-400 mt-1">{{ __('admin.no_banner') }}</p>
                @endif
            </div>
            <i class="fas fa-bullhorn text-4xl text-amber-300 opacity-50 flex-shrink-0"></i>
        </div>
        <a href="{{ route('admin.announcements') }}" class="text-purple-400 hover:text-purple-300 text-sm mt-auto pt-4 self-start">
            {{ __('admin.manage_announcements') }} <i class="fas fa-arrow-right ml-1"></i>
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
                        {{-- The name is escaped here and the sentence is ours, so the link can go
                             inside it without letting a name write markup. --}}
                        {!! __('admin.reported_by', ['user' => '<a href="' . e(route('admin.users.show', $report->reporter)) . '" class="hover:text-purple-400 hover:underline">' . e($report->reporter->name) . '</a>']) !!} • {{ $report->created_at->diffForHumans() }}
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
