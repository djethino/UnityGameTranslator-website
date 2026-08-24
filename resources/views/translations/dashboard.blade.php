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
                <div class="flex items-center gap-3 mt-1 flex-wrap">
                    {{-- Through the component, like every other screen. This header used to write
                         the role itself — a green crown for a Main, blue for a Branch — and knew
                         nothing of a Fork, so somebody leading a lineage they started from
                         another's work was shown the same badge as somebody who started alone. --}}
                    <x-translation-role :translation="$translation" />
                </div>

                {{-- Where it came from, when it came from somebody. Its own line rather than a
                     chip, exactly as on the read-only view — it is a credit, not a state. --}}
                <x-translation-origin :translation="$translation" class="mt-1 block" />
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
            <div class="text-3xl font-bold text-white">{{ $translation->vote_count }}</div>
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

    {{-- Resources URL --}}
    @php $effectiveUrl = $translation->getEffectiveResourcesUrl(); @endphp
    @if($effectiveUrl)
    <div class="bg-gray-800 rounded-lg p-4 border border-amber-700/50 mb-6">
        <div class="flex items-center gap-2 mb-2">
            <i class="fas fa-external-link-alt text-amber-400"></i>
            <h3 class="text-sm font-semibold text-amber-400">{{ __('dashboard.external_resources') }}</h3>
        </div>
        <a href="{{ $effectiveUrl }}" target="_blank" rel="nofollow noopener noreferrer"
            class="text-blue-400 hover:text-blue-300 break-all text-sm">
            {{ Str::limit($effectiveUrl, 100) }}
        </a>
        <p class="text-xs text-gray-500 mt-2">
            <i class="fas fa-exclamation-triangle mr-1"></i>
            {{ __('dashboard.external_resources_disclaimer') }}
        </p>
    </div>
    @endif

    {{-- Quality Progress Bar --}}
    <div class="bg-gray-800 rounded-lg p-4 border border-gray-700 mb-8">
        <div class="flex items-center justify-between mb-2 gap-3 flex-wrap">
            <div class="flex items-center gap-3">
                <h3 class="text-sm font-medium text-white">{{ __('progress.quality_distribution') }}</h3>
                {{-- Next to the bar, because a full bar does NOT mean finished: the bar says what
                     the file is made of, only this says the author considers it done. Shown on
                     the game page and in the list, missing exactly here. --}}
                @if($translation->isComplete())
                    <span class="text-green-400 text-xs"><i class="fas fa-check"></i> {{ __('translation.complete') }}</span>
                @else
                    <span class="text-yellow-400 text-xs"><i class="fas fa-clock"></i> {{ __('translation.in_progress') }}</span>
                @endif

                {{-- Beside it, as on the game page: the two things the author DECLARED about their
                     own work, neither of which can be read off the bar. Missing exactly here —
                     the one screen where they can be changed — so an anonymous visitor had more
                     to go on than the owner. --}}
                <x-contributions-badge :translation="$translation" plain size="text-xs" />
            </div>
            {{-- The step, and what is left to read. No mark: this is the author's own screen,
                 and a grade motivates nobody — the remaining count does, because it moves as
                 they work and tells them what to do next. A file with nothing translated yet
                 has no stage at all, which is what its author needs to hear instead of 0.0/3. --}}
            <div class="flex items-center gap-2">
                @if($translation->effective_lines > 0)
                    <x-review-stage :translation="$translation" />
                    @if($translation->ai_count > 0)
                        <span class="text-xs text-gray-400">
                            {{ __('progress.left_to_review', ['count' => number_format($translation->ai_count)]) }}
                        </span>
                    @endif
                    <x-translation-completeness :translation="$translation" />
                    <x-game-coverage :translation="$translation" />
                @else
                    <span class="text-xs text-gray-400" title="{{ __('progress.capture_only_desc') }}">
                        <i class="fas fa-camera mr-1"></i>{{ __('progress.capture_only') }}
                    </span>
                @endif
            </div>
        </div>
        <x-progress-bar :translation="$translation" class="mb-2" />
        <x-quality-legend :translation="$translation" />
    </div>

    {{-- What this file carries beyond its lines.
         Read-only here, deliberately: fonts, image replacements and exclusions are edited in
         the mod, which is the only place that can see the game they apply to. The owner still
         had LESS information than an anonymous visitor, who gets all of this on the game page
         — the one screen where you can act on your translation was the poorest of them all. --}}
    @if($translation->hasSettings())
    <div class="bg-gray-800 rounded-lg p-4 border border-gray-700 mb-8">
        <div class="flex items-center gap-2 mb-3">
            <h3 class="text-sm font-medium text-white">{{ __('file_settings.section_title') }}</h3>
            <span class="text-xs text-gray-500" title="{{ __('file_settings.section_hint') }}">
                <i class="fas fa-info-circle"></i>
            </span>
        </div>
        @include('partials.translation-settings-detail', ['translation' => $translation])
        <p class="mt-3 text-xs text-gray-500 italic">{{ __('file_settings.section_hint') }}</p>
    </div>
    @endif

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
                        <x-user-mention :user="$branch->user" class="font-medium text-white" />
                        <span class="text-gray-500 text-sm ml-2">
                            {{-- When this branch last changed, not when it was last voted on --}}
                            {{ $branch->contentChangedAt()->diffForHumans() }}
                        </span>
                    </div>
                    <div class="flex items-center gap-4 text-sm">
                        @if($stats)
                        <span class="text-gray-400">
                            {{ trans_choice('dashboard.same_count', $stats['same'], ['count' => number_format($stats['same'])]) }}
                        </span>
                        @if($stats['different'] > 0)
                        <span class="text-yellow-400">
                            {{ trans_choice('dashboard.different_count', $stats['different']) }}
                        </span>
                        @endif
                        @if($stats['branch_only'] > 0)
                        <span class="text-green-400">
                            +{{ trans_choice('dashboard.new_keys_count', $stats['branch_only']) }}
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
               class="inline-block mt-4 bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded-lg transition">
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
                        {{ trans_choice('dashboard.lines_count', $mainTranslation->line_count, ['count' => number_format($mainTranslation->line_count)]) }} •
                        {{ trans_choice('dashboard.downloads_count', $mainTranslation->download_count, ['count' => number_format($mainTranslation->download_count)]) }}
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
            {{-- The button that used to sit here opened the merge preview, which compares the
                 mod's LOCAL file with the online one — the mod opens it, pushing its content
                 with a token. Reached from the site there is no local file, so it always ended
                 on "local file not found". A control that cannot work from where it is offered
                 is worse than none, which is the rule that already took away the download and
                 the vote on a contribution. --}}
            <div class="p-4 border-t border-gray-700 text-center">
                <p class="text-sm text-gray-400">
                    <i class="fas fa-gamepad mr-2 text-purple-400"></i>{{ __('dashboard.compare_in_mod') }}
                </p>
                <p class="text-xs text-gray-500 mt-1">{{ __('dashboard.compare_in_mod_how') }}</p>
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

        {{-- 🔴 The Main closed after this branch was made. Said before the fork section rather
             than instead of it: that section is an opportunity somebody may take; this is a fact
             they have to know, because nothing they do as a branch can work any more. --}}
        @if($translation->isFrozenBranch())
        <div class="bg-red-900/20 border border-red-700 rounded-lg p-4">
            <h2 class="text-lg font-semibold text-white mb-2">
                <i class="fas fa-lock mr-2 text-red-400"></i>{{ __('dashboard.branch_frozen_title') }}
            </h2>
            <p class="text-red-200 text-sm">{{ __('dashboard.branch_frozen_body') }}</p>
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
                  data-confirm="{{ __('dashboard.convert_confirm') }}">
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
           class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition">
            <i class="fas fa-download mr-2"></i>{{ __('dashboard.download_file') }}
        </a>
        {{-- Not @if($isMain): a contributor describes their contribution and links the fonts it
             needs, exactly as they correct its lines. Only whether it is finished stays the
             Main's to say, and the form leaves that out on a branch. --}}
        <a href="{{ route('translations.edit', $translation) }}"
           class="bg-orange-600 hover:bg-orange-700 text-white px-4 py-2 rounded-lg transition">
            <i class="fas fa-edit mr-2"></i>{{ __('dashboard.edit_metadata') }}
        </a>
        <a href="{{ route('games.show', $translation->game) }}"
           class="bg-gray-700 hover:bg-gray-600 text-white px-4 py-2 rounded-lg transition">
            <i class="fas fa-gamepad mr-2"></i>{{ __('dashboard.view_game_page') }}
        </a>
    </div>
</div>
@endsection
