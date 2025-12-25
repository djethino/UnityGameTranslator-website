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
                        <div class="flex items-center gap-3 mb-2">
                            <span class="font-semibold text-lg">{{ $report->translation->game->name }}</span>
                            <span class="bg-blue-900 text-blue-200 px-2 py-0.5 rounded text-sm">
                                {{ $report->translation->source_language }} → {{ $report->translation->target_language }}
                            </span>
                        </div>
                        <p class="text-sm text-gray-400 mb-2">
                            Translation by {{ $report->translation->user->name }}
                        </p>
                        <p class="text-gray-300 mb-3">{{ $report->reason }}</p>
                        <p class="text-sm text-gray-500">
                            Reported by {{ $report->reporter->name }} • {{ $report->created_at->diffForHumans() }}
                        </p>
                        @if($report->reviewer)
                            <p class="text-sm text-gray-500 mt-1">
                                Reviewed by {{ $report->reviewer->name }} • {{ $report->reviewed_at->diffForHumans() }}
                            </p>
                            @if($report->admin_notes)
                                <p class="text-sm text-gray-400 mt-1 italic">Notes: {{ $report->admin_notes }}</p>
                            @endif
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
