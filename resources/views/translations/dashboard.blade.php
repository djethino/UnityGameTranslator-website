@extends('layouts.app')

@section('title', __('dashboard.title') . ' - ' . $translation->game->name)

@section('content')
<div class="container mx-auto px-4 py-8">
    {{-- Header --}}
    <div class="mb-6">
        <div class="flex items-center gap-4 mb-2">
            <a href="{{ route('translations.mine') }}" class="text-purple-400 hover:text-purple-300">
                <i class="fas fa-arrow-left mr-1"></i> {{ __('dashboard.back_to_translations') }}
            </a>
        </div>
        <div class="flex items-center gap-4">
            @if($translation->game->image_url)
                <img src="{{ $translation->game->image_url }}" alt="{{ $translation->game->name }}" class="w-16 h-20 object-cover rounded">
            @else
                <div class="w-16 h-20 bg-gray-700 rounded flex items-center justify-center">
                    <i class="fas fa-gamepad text-gray-500 text-2xl"></i>
                </div>
            @endif
            <div>
                <h1 class="text-2xl font-bold text-white">{{ $translation->game->name }}</h1>
                <p class="text-gray-400">
                    @langflag($translation->source_language) {{ $translation->source_language }}
                    <i class="fas fa-arrow-right text-xs mx-1"></i>
                    @langflag($translation->target_language) {{ $translation->target_language }}
                </p>
                <div class="flex items-center gap-3 mt-1">
                    @if($isMain)
                        <span class="bg-green-900 text-green-300 px-2 py-0.5 rounded text-sm">
                            <i class="fas fa-crown mr-1"></i> {{ __('dashboard.main_owner') }}
                        </span>
                    @else
                        <span class="bg-blue-900 text-blue-300 px-2 py-0.5 rounded text-sm">
                            <i class="fas fa-code-branch mr-1"></i> {{ __('dashboard.branch') }}
                        </span>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Error messages --}}
    @if($errors->any())
    <div class="mb-6 bg-red-900/50 border border-red-600 rounded-lg p-4 text-red-300">
        <ul class="list-disc list-inside">
            @foreach($errors->all() as $error)
            <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
    @endif

    {{-- Stats Cards --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <div class="bg-gray-800 rounded-lg p-4 border border-gray-700">
            <div class="text-3xl font-bold text-white">{{ number_format($translation->line_count) }}</div>
            <div class="text-sm text-gray-400">{{ __('dashboard.lines') }}</div>
        </div>
        <div class="bg-gray-800 rounded-lg p-4 border border-gray-700">
            <div class="text-3xl font-bold text-white">{{ number_format($translation->download_count) }}</div>
            <div class="text-sm text-gray-400">{{ __('dashboard.downloads') }}</div>
        </div>
        <div class="bg-gray-800 rounded-lg p-4 border border-gray-700">
            <div class="text-3xl font-bold text-white">{{ $translation->vote_score }}</div>
            <div class="text-sm text-gray-400">{{ __('dashboard.votes') }}</div>
        </div>
        @if($isMain)
        <div class="bg-gray-800 rounded-lg p-4 border border-gray-700">
            <div class="text-3xl font-bold text-purple-400">{{ $branches->count() }}</div>
            <div class="text-sm text-gray-400">{{ __('dashboard.branches') }}</div>
        </div>
        @else
        <div class="bg-gray-800 rounded-lg p-4 border border-gray-700">
            <div class="text-3xl font-bold text-blue-400">{{ $diffStats ? ($diffStats['different'] + $diffStats['branch_only']) : 0 }}</div>
            <div class="text-sm text-gray-400">{{ __('dashboard.contributions') }}</div>
        </div>
        @endif
    </div>

    {{-- Quality Progress Bar --}}
    <div class="bg-gray-800 rounded-lg p-4 border border-gray-700 mb-8">
        <div class="flex items-center justify-between mb-2">
            <h3 class="text-sm font-medium text-white">{{ __('progress.quality_distribution') }}</h3>
            <span class="text-xs text-gray-400" title="{{ __('progress.quality_tooltip') }}">
                {{ __('progress.quality_score') }}: {{ number_format($translation->quality_score, 1) }}/3.0
            </span>
        </div>
        <x-progress-bar :translation="$translation" class="mb-2" />
        <div class="flex items-center justify-between text-xs text-gray-400">
            <div class="flex items-center gap-4">
                <span class="flex items-center gap-1">
                    <span class="w-2 h-2 bg-green-500 rounded-full"></span>
                    {{ __('progress.human') }} ({{ $translation->human_count }})
                </span>
                <span class="flex items-center gap-1">
                    <span class="w-2 h-2 bg-blue-500 rounded-full"></span>
                    {{ __('progress.validated') }} ({{ $translation->validated_count }})
                </span>
                <span class="flex items-center gap-1">
                    <span class="w-2 h-2 bg-orange-500 rounded-full"></span>
                    {{ __('progress.ai') }} ({{ $translation->ai_count }})
                </span>
            </div>
            @if(($translation->capture_count ?? 0) > 0)
            <span class="text-gray-500">
                {{ __('progress.capture_only') }}: {{ $translation->capture_count }}
            </span>
            @endif
        </div>
    </div>

    @if($isMain)
        {{-- ========== MAIN VIEW ========== --}}

        {{-- Merge Section --}}
        @if($branches->isNotEmpty())
        <div class="bg-gray-800 rounded-lg border border-gray-700 mb-6">
            <div class="p-4 border-b border-gray-700 flex justify-between items-center">
                <div>
                    <h2 class="text-lg font-semibold text-white">
                        <i class="fas fa-code-merge mr-2 text-purple-400"></i>{{ __('dashboard.merge_contributions') }}
                    </h2>
                    @if($totalLinesToMerge > 0)
                    <p class="text-sm text-yellow-400 mt-1">
                        <i class="fas fa-exclamation-circle mr-1"></i>
                        {{ __('dashboard.lines_to_review', ['count' => $totalLinesToMerge]) }}
                    </p>
                    @else
                    <p class="text-sm text-green-400 mt-1">
                        <i class="fas fa-check-circle mr-1"></i>
                        {{ __('dashboard.all_merged') }}
                    </p>
                    @endif
                </div>
                <a href="{{ route('translations.merge', $translation->file_uuid) }}"
                   class="bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded-lg transition">
                    <i class="fas fa-code-merge mr-2"></i>{{ __('dashboard.open_merge_view') }}
                </a>
            </div>

            {{-- Branches List --}}
            <div class="divide-y divide-gray-700">
                @foreach($branches as $branch)
                @php $stats = $branchStats[$branch->id] ?? null; @endphp
                <div class="p-4 flex justify-between items-center">
                    <div>
                        <span class="font-medium text-white">{{ $branch->user->name }}</span>
                        <span class="text-gray-500 text-sm ml-2">
                            {{ $branch->updated_at->diffForHumans() }}
                        </span>
                    </div>
                    <div class="flex items-center gap-4 text-sm">
                        @if($stats)
                        <span class="text-gray-400">
                            {{ number_format($stats['same']) }} {{ __('dashboard.same') }}
                        </span>
                        @if($stats['different'] > 0)
                        <span class="text-yellow-400">
                            {{ $stats['different'] }} {{ __('dashboard.different') }}
                        </span>
                        @endif
                        @if($stats['branch_only'] > 0)
                        <span class="text-green-400">
                            +{{ $stats['branch_only'] }} {{ __('dashboard.new_keys') }}
                        </span>
                        @endif
                        @endif
                    </div>
                </div>
                @endforeach
            </div>
        </div>
        @else
        <div class="bg-gray-800 rounded-lg border border-gray-700 p-6 mb-6 text-center">
            <i class="fas fa-users text-4xl text-gray-600 mb-3"></i>
            <p class="text-gray-400">{{ __('dashboard.no_branches') }}</p>
            <p class="text-sm text-gray-500 mt-1">{{ __('dashboard.no_branches_desc') }}</p>
            <a href="{{ route('translations.merge', $translation->file_uuid) }}"
               class="inline-block mt-4 bg-gray-700 hover:bg-gray-600 text-white px-4 py-2 rounded-lg transition">
                <i class="fas fa-edit mr-2"></i>{{ __('dashboard.edit_translations') }}
            </a>
        </div>
        @endif

    @else
        {{-- ========== BRANCH VIEW ========== --}}

        {{-- Main Info --}}
        @if($mainTranslation)
        <div class="bg-gray-800 rounded-lg border border-gray-700 mb-6 p-4">
            <h2 class="text-lg font-semibold text-white mb-3">
                <i class="fas fa-crown mr-2 text-yellow-400"></i>{{ __('dashboard.contributing_to') }}
            </h2>
            <div class="flex items-center gap-3">
                <div class="flex-1">
                    <p class="text-white">{{ __('dashboard.main_by', ['name' => $mainTranslation->user->name]) }}</p>
                    <p class="text-sm text-gray-400">
                        {{ number_format($mainTranslation->line_count) }} {{ __('dashboard.lines') }} •
                        {{ number_format($mainTranslation->download_count) }} {{ __('dashboard.downloads') }}
                    </p>
                </div>
                <a href="{{ route('games.show', $mainTranslation->game) }}"
                   class="text-purple-400 hover:text-purple-300 text-sm">
                    {{ __('dashboard.view_game_page') }} <i class="fas fa-external-link-alt ml-1"></i>
                </a>
            </div>
        </div>

        {{-- Comparison Stats --}}
        @if($diffStats)
        <div class="bg-gray-800 rounded-lg border border-gray-700 mb-6">
            <div class="p-4 border-b border-gray-700">
                <h2 class="text-lg font-semibold text-white">
                    <i class="fas fa-chart-bar mr-2 text-blue-400"></i>{{ __('dashboard.your_contributions') }}
                </h2>
            </div>
            <div class="p-4 grid grid-cols-2 md:grid-cols-4 gap-4">
                <div class="text-center">
                    <div class="text-2xl font-bold text-gray-400">{{ number_format($diffStats['same']) }}</div>
                    <div class="text-xs text-gray-500">{{ __('dashboard.same') }}</div>
                </div>
                <div class="text-center">
                    <div class="text-2xl font-bold text-yellow-400">{{ number_format($diffStats['different']) }}</div>
                    <div class="text-xs text-gray-500">{{ __('dashboard.different') }}</div>
                </div>
                <div class="text-center">
                    <div class="text-2xl font-bold text-green-400">{{ number_format($diffStats['branch_only']) }}</div>
                    <div class="text-xs text-gray-500">{{ __('dashboard.new_keys') }}</div>
                </div>
                <div class="text-center">
                    <div class="text-2xl font-bold text-red-400">{{ number_format($diffStats['main_only']) }}</div>
                    <div class="text-xs text-gray-500">{{ __('dashboard.missing') }}</div>
                </div>
            </div>
            <div class="p-4 border-t border-gray-700 flex justify-center">
                <a href="{{ route('translations.merge-preview', $translation) }}"
                   class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition">
                    <i class="fas fa-code-compare mr-2"></i>{{ __('dashboard.compare_with_main') }}
                </a>
            </div>
        </div>
        @endif
        @else
        <div class="bg-yellow-900/30 border border-yellow-700 rounded-lg p-4 mb-6">
            <p class="text-yellow-300">
                <i class="fas fa-exclamation-triangle mr-2"></i>
                {{ __('dashboard.main_not_found') }}
            </p>
        </div>
        @endif

        {{-- Convert to Fork Section --}}
        <div class="bg-gray-800 rounded-lg border border-gray-700 p-4">
            <h2 class="text-lg font-semibold text-white mb-2">
                <i class="fas fa-code-branch mr-2 text-orange-400"></i>{{ __('dashboard.become_independent') }}
            </h2>
            <p class="text-gray-400 text-sm mb-4">{{ __('dashboard.convert_to_fork_desc') }}</p>

            <div class="bg-orange-900/20 border border-orange-700 rounded-lg p-3 mb-4">
                <p class="text-orange-300 text-sm">
                    <i class="fas fa-exclamation-triangle mr-1"></i>
                    <strong>{{ __('dashboard.warning') }}:</strong> {{ __('dashboard.convert_warning') }}
                </p>
            </div>

            <form action="{{ route('translations.convert-to-fork', $translation) }}" method="POST"
                  onsubmit="return confirm('{{ __('dashboard.convert_confirm') }}')">
                @csrf
                <button type="submit" class="bg-orange-600 hover:bg-orange-700 text-white px-4 py-2 rounded-lg transition">
                    <i class="fas fa-download mr-2"></i>{{ __('dashboard.convert_and_download') }}
                </button>
            </form>
            <p class="text-xs text-gray-500 mt-2">{{ __('dashboard.convert_instructions') }}</p>
        </div>
    @endif

    {{-- Quick Actions --}}
    <div class="mt-6 flex flex-wrap gap-3">
        <a href="{{ route('translations.download', $translation) }}"
           class="bg-gray-700 hover:bg-gray-600 text-white px-4 py-2 rounded-lg transition">
            <i class="fas fa-download mr-2"></i>{{ __('dashboard.download_file') }}
        </a>
        @if($isMain)
        <a href="{{ route('translations.edit', $translation) }}"
           class="bg-gray-700 hover:bg-gray-600 text-white px-4 py-2 rounded-lg transition">
            <i class="fas fa-edit mr-2"></i>{{ __('dashboard.edit_metadata') }}
        </a>
        @endif
        <a href="{{ route('games.show', $translation->game) }}"
           class="bg-gray-700 hover:bg-gray-600 text-white px-4 py-2 rounded-lg transition">
            <i class="fas fa-gamepad mr-2"></i>{{ __('dashboard.view_game_page') }}
        </a>
    </div>
</div>
@endsection
