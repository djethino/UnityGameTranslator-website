@extends('layouts.app')

@section('title', 'Reports - Admin - UnityGameTranslator')

@section('content')
<div class="flex justify-between items-center mb-8">
    <h1 class="text-3xl font-bold"><i class="fas fa-flag mr-2"></i> Reports</h1>
    <a href="{{ route('admin.dashboard') }}" class="text-purple-400 hover:text-purple-300">
        <i class="fas fa-arrow-left mr-1"></i> Back to Dashboard
    </a>
</div>

<div class="mb-6">
    <div class="flex gap-2">
        <a href="{{ route('admin.reports', ['status' => 'pending']) }}"
           class="px-4 py-2 rounded {{ request('status', 'pending') == 'pending' ? 'bg-yellow-600' : 'bg-gray-700 hover:bg-gray-600' }}">
            Pending
        </a>
        <a href="{{ route('admin.reports', ['status' => 'reviewed']) }}"
           class="px-4 py-2 rounded {{ request('status') == 'reviewed' ? 'bg-green-600' : 'bg-gray-700 hover:bg-gray-600' }}">
            Reviewed
        </a>
        <a href="{{ route('admin.reports', ['status' => 'dismissed']) }}"
           class="px-4 py-2 rounded {{ request('status') == 'dismissed' ? 'bg-gray-600' : 'bg-gray-700 hover:bg-gray-600' }}">
            Dismissed
        </a>
    </div>
</div>

@if($reports->isEmpty())
    <div class="text-center py-12 text-gray-400">
        <i class="fas fa-check-circle text-6xl mb-4 text-green-400"></i>
        <p class="text-xl">No reports to show.</p>
    </div>
@else
    <div class="space-y-4">
        @foreach($reports as $report)
            <div class="bg-gray-800 rounded-lg p-5 border border-gray-700">
                <div class="flex justify-between items-start">
                    <div class="flex-1">
                        @if($report->isAboutGame())
                            {{-- A game card (2026-10-05): what is wrong with it, and — for the adult
                                 mark — what the stores said when it was sent. --}}
                            <div class="flex flex-wrap items-center gap-3 mb-2">
                                <span class="font-semibold text-lg">{{ $report->game->name }}</span>
                                <span class="bg-amber-900/60 text-amber-200 px-2 py-0.5 rounded text-sm">
                                    <i class="fas fa-gamepad mr-1"></i> {{ \App\Models\Report::GameKindLabels[$report->kind] ?? $report->kind }}
                                </span>
                            </div>
                            @if($report->stores_answer)
                                <p class="text-sm text-gray-400 mb-2">
                                    When sent: {{ \App\Models\Report::StoresAnswerLabels[$report->stores_answer] ?? $report->stores_answer }}.
                                    Now: {{ $report->game->adult ? 'marked for adults only' : 'not marked' }}{{ $report->game->adultSource() ? ' (' . $report->game->adultSource() . ')' : '' }}.
                                </p>
                            @endif
                        @else
                            <div class="flex items-center gap-3 mb-2">
                                <span class="font-semibold text-lg">{{ $report->translation->game->name }}</span>
                                <span class="bg-blue-900 text-blue-200 px-2 py-0.5 rounded text-sm">
                                    @langflag($report->translation->source_language) {{ $report->translation->source_language }} → @langflag($report->translation->target_language) {{ $report->translation->target_language }}
                                </span>
                                {{-- Visible before opening anything: a queue of reports on public
                                     Mains and one on somebody's unpublished branch do not call for
                                     the same attention. --}}
                                <x-translation-role :translation="$report->translation" />
                            </div>
                            <p class="text-sm text-gray-400 mb-2">
                                Translation by <x-admin.user-link :user="$report->translation->user" />
                            </p>
                        @endif
                        @if($report->reason !== '')
                            <p class="text-gray-300 mb-3">{{ $report->reason }}</p>
                        @endif
                        <p class="text-sm text-gray-500">
                            Reported by <x-admin.user-link :user="$report->reporter" /> • {{ $report->created_at->diffForHumans() }}
                        </p>
                        @if($report->reviewer)
                            <p class="text-sm text-gray-500 mt-1">
                                Reviewed by <x-admin.user-link :user="$report->reviewer" /> • {{ $report->reviewed_at->diffForHumans() }}
                            </p>
                        @endif
                        {{-- Notes also when nobody reviewed it: a report the stores settled says so. --}}
                        @if($report->admin_notes)
                            <p class="text-sm text-gray-400 mt-1 italic">Notes: {{ $report->admin_notes }}</p>
                        @endif
                    </div>
                    @if($report->isPending())
                        <a href="{{ route('admin.reports.show', $report) }}" class="bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded">
                            Review
                        </a>
                    @endif
                </div>
            </div>
        @endforeach
    </div>

    <div class="mt-8">
        {{ $reports->withQueryString()->links() }}
    </div>
@endif
@endsection
