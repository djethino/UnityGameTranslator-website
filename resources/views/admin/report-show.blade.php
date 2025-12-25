@extends('layouts.app')

@section('title', 'Review Report - Admin - UnityGameTranslator')

@section('content')
<div class="mb-6">
    <a href="{{ route('admin.reports') }}" class="text-purple-400 hover:text-purple-300">
        <i class="fas fa-arrow-left mr-2"></i> Back to Reports
    </a>
</div>

<div class="max-w-4xl">
    <h1 class="text-3xl font-bold mb-8"><i class="fas fa-flag mr-2"></i> Review Report</h1>

    <div class="bg-gray-800 rounded-lg p-6 border border-gray-700 mb-6">
        <h2 class="text-xl font-semibold mb-4">Reported Translation</h2>

        <div class="grid grid-cols-2 gap-4 mb-4">
            <div>
                <p class="text-gray-400 text-sm">Game</p>
                <p class="font-medium">{{ $report->translation->game->name }}</p>
            </div>
            <div>
                <p class="text-gray-400 text-sm">Languages</p>
                <p class="font-medium">{{ $report->translation->source_language }} → {{ $report->translation->target_language }}</p>
            </div>
            <div>
                <p class="text-gray-400 text-sm">Uploaded by</p>
                <p class="font-medium">{{ $report->translation->user->name }}</p>
            </div>
            <div>
                <p class="text-gray-400 text-sm">Upload Date</p>
                <p class="font-medium">{{ $report->translation->created_at->format('M d, Y H:i') }}</p>
            </div>
            <div>
                <p class="text-gray-400 text-sm">Lines</p>
                <p class="font-medium">{{ number_format($report->translation->line_count) }}</p>
            </div>
            <div>
                <p class="text-gray-400 text-sm">Downloads</p>
                <p class="font-medium">{{ $report->translation->download_count }}</p>
            </div>
        </div>

        <a href="{{ route('translations.download', $report->translation) }}" class="inline-block bg-gray-700 hover:bg-gray-600 text-white px-4 py-2 rounded">
            <i class="fas fa-download mr-1"></i> Download Translation
        </a>
    </div>

    <!-- JSON Preview -->
    @if($jsonContent)
        <div class="bg-gray-800 rounded-lg p-6 border border-gray-700 mb-6">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-xl font-semibold"><i class="fas fa-code mr-2"></i> Translation Content Preview</h2>
                <button type="button" onclick="toggleJsonPreview()" class="text-gray-400 hover:text-white text-sm">
                    <i id="toggleIcon" class="fas fa-chevron-down mr-1"></i> <span id="toggleText">Show</span>
                </button>
            </div>
            <div id="jsonPreview" class="hidden">
                <div class="text-sm text-gray-400 mb-3">
                    {{ count($jsonContent) }} translation entries
                </div>
                <div class="bg-gray-900 rounded-lg p-4 max-h-96 overflow-auto">
                    <table class="w-full text-sm">
                        <thead class="text-gray-400 border-b border-gray-700">
                            <tr>
                                <th class="text-left py-2 pr-4 w-1/2">Original</th>
                                <th class="text-left py-2 w-1/2">Translated</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach(array_slice($jsonContent, 0, 100) as $original => $translated)
                                <tr class="border-b border-gray-800">
                                    <td class="py-2 pr-4 text-gray-300 break-words">{{ Str::limit($original, 100) }}</td>
                                    <td class="py-2 text-white break-words">{{ Str::limit($translated, 100) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    @if(count($jsonContent) > 100)
                        <p class="text-gray-500 text-sm mt-4 text-center">
                            ... and {{ count($jsonContent) - 100 }} more entries (download to see all)
                        </p>
                    @endif
                </div>
            </div>
        </div>
    @endif

    <div class="bg-gray-800 rounded-lg p-6 border border-gray-700 mb-6">
        <h2 class="text-xl font-semibold mb-4">Report Details</h2>

        <div class="mb-4">
            <p class="text-gray-400 text-sm">Reported by</p>
            <p class="font-medium">{{ $report->reporter->name }} ({{ $report->reporter->email }})</p>
        </div>

        <div class="mb-4">
            <p class="text-gray-400 text-sm">Reported on</p>
            <p class="font-medium">{{ $report->created_at->format('M d, Y H:i') }}</p>
        </div>

        <div>
            <p class="text-gray-400 text-sm">Reason</p>
            <p class="bg-gray-750 rounded p-4 mt-2">{{ $report->reason }}</p>
        </div>
    </div>

    @if($report->isPending())
        <div class="bg-gray-800 rounded-lg p-6 border border-gray-700">
            <h2 class="text-xl font-semibold mb-4">Take Action</h2>

            <form action="{{ route('admin.reports.handle', $report) }}" method="POST">
                @csrf

                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-300 mb-2">Admin Notes (optional)</label>
                    <textarea name="admin_notes" rows="3"
                        class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-3 text-white focus:ring-purple-500 focus:border-purple-500"
                        placeholder="Add notes about your decision..."></textarea>
                </div>

                <div class="flex gap-4">
                    <button type="submit" name="action" value="dismiss"
                        class="flex-1 bg-gray-600 hover:bg-gray-500 text-white font-semibold py-3 rounded-lg transition">
                        <i class="fas fa-times mr-2"></i> Dismiss Report
                    </button>
                    <button type="submit" name="action" value="delete_translation"
                        class="flex-1 bg-red-600 hover:bg-red-700 text-white font-semibold py-3 rounded-lg transition"
                        onclick="return confirm('Are you sure? This will permanently delete the translation.')">
                        <i class="fas fa-trash mr-2"></i> Delete Translation
                    </button>
                </div>
            </form>
        </div>
    @else
        <div class="bg-gray-800 rounded-lg p-6 border border-gray-700">
            <h2 class="text-xl font-semibold mb-4">Resolution</h2>
            <p class="text-gray-300">
                <span class="font-medium">Status:</span>
                <span class="{{ $report->status === 'dismissed' ? 'text-gray-400' : 'text-green-400' }}">
                    {{ ucfirst($report->status) }}
                </span>
            </p>
            <p class="text-gray-300 mt-2">
                <span class="font-medium">Reviewed by:</span> {{ $report->reviewer->name }}
            </p>
            <p class="text-gray-300 mt-2">
                <span class="font-medium">Reviewed on:</span> {{ $report->reviewed_at->format('M d, Y H:i') }}
            </p>
            @if($report->admin_notes)
                <p class="text-gray-300 mt-2">
                    <span class="font-medium">Notes:</span> {{ $report->admin_notes }}
                </p>
            @endif
        </div>
    @endif
</div>

<script>
function toggleJsonPreview() {
    const preview = document.getElementById('jsonPreview');
    const icon = document.getElementById('toggleIcon');
    const text = document.getElementById('toggleText');

    if (preview.classList.contains('hidden')) {
        preview.classList.remove('hidden');
        icon.classList.remove('fa-chevron-down');
        icon.classList.add('fa-chevron-up');
        text.textContent = 'Hide';
    } else {
        preview.classList.add('hidden');
        icon.classList.remove('fa-chevron-up');
        icon.classList.add('fa-chevron-down');
        text.textContent = 'Show';
    }
}
</script>
@endsection
