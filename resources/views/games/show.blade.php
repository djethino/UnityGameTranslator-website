@extends('layouts.app')

@php
    // SEO language lists: native names are what speakers actually search for
    // ("traduction", "Übersetzung"...); the "Native (English)" combo also catches
    // English-style queries like ":game french translation".
    $seoLanguageNames = config('language-names', []);
    $seoNativeLanguages = $targetLanguages->map(fn ($lang) => $seoLanguageNames[$lang] ?? $lang)->values();
    $seoComboLanguages = $targetLanguages->map(function ($lang) use ($seoLanguageNames) {
        $native = $seoLanguageNames[$lang] ?? $lang;
        return $native === $lang ? $lang : $native . ' (' . $lang . ')';
    })->values();
    $seoTitleLanguages = $seoNativeLanguages->take(3)->implode(', ') . ($seoNativeLanguages->count() > 3 ? '…' : '');
    $seoDescription = $targetLanguages->isEmpty()
        ? __('seo.game_description_nolang', ['game' => $game->name])
        : __('seo.game_description', ['game' => $game->name, 'languages' => $seoComboLanguages->take(5)->implode(', ')]);
@endphp

@section('title', $targetLanguages->isEmpty()
    ? __('seo.game_title_nolang', ['game' => $game->name])
    : __('seo.game_title', ['game' => $game->name, 'languages' => $seoTitleLanguages]))

@section('description', $seoDescription)

@section('og_type', 'article')

@section('og_image', $game->image_url ?? '')

@push('head')
<script type="application/ld+json">
{
    "@@context": "https://schema.org",
    "@@type": "VideoGame",
    "name": {!! json_encode($game->name, JSON_UNESCAPED_UNICODE) !!},
    "image": "{{ $game->image_url ?? '' }}",
    "description": {!! json_encode($seoDescription, JSON_UNESCAPED_UNICODE) !!},
    "url": "{{ route('games.show', $game) }}",
    "offers": {
        "@@type": "Offer",
        "price": "0",
        "priceCurrency": "USD",
        "availability": "https://schema.org/InStock"
    }
}
</script>
<script type="application/ld+json">
{
    "@@context": "https://schema.org",
    "@@type": "BreadcrumbList",
    "itemListElement": [
        {
            "@@type": "ListItem",
            "position": 1,
            "name": "{{ __('nav.home') }}",
            "item": "{{ url('/') }}"
        },
        {
            "@@type": "ListItem",
            "position": 2,
            "name": "{{ __('nav.games') }}",
            "item": "{{ route('games.index') }}"
        },
        {
            "@@type": "ListItem",
            "position": 3,
            "name": {!! json_encode($game->name, JSON_UNESCAPED_UNICODE) !!},
            "item": "{{ route('games.show', $game) }}"
        }
    ]
}
</script>
@endpush

@section('content')
{{-- Breadcrumbs --}}
<nav aria-label="Breadcrumb" class="mb-6">
    <ol class="flex items-center text-sm text-gray-400 flex-wrap gap-1">
        <li>
            <a href="{{ url('/') }}" class="hover:text-purple-400 transition">
                <i class="fas fa-home"></i>
            </a>
        </li>
        <li class="mx-2 text-gray-600">/</li>
        <li>
            <a href="{{ route('games.index') }}" class="hover:text-purple-400 transition">{{ __('nav.games') }}</a>
        </li>
        <li class="mx-2 text-gray-600">/</li>
        <li class="text-white truncate max-w-[200px] sm:max-w-none" title="{{ $game->name }}">{{ $game->name }}</li>
    </ol>
</nav>

<div class="flex flex-col sm:flex-row sm:justify-between sm:items-start gap-4 mb-8">
    <div class="flex items-center gap-4 sm:gap-6">
        @if($game->image_url)
            <img src="{{ $game->image_url }}" alt="{{ $game->name }}" class="w-20 h-28 sm:w-24 sm:h-32 object-cover rounded-lg shadow-lg flex-shrink-0">
        @else
            <div class="w-20 h-28 sm:w-24 sm:h-32 bg-gray-700 rounded-lg flex items-center justify-center flex-shrink-0">
                <i class="fas fa-gamepad text-2xl sm:text-3xl text-gray-400"></i>
            </div>
        @endif
        <div class="min-w-0">
            <h1 class="glitch-text text-2xl sm:text-3xl font-bold break-words">{{ $game->name }}</h1>
            <p class="text-gray-400 mt-1 text-sm sm:text-base">{{ trans_choice('home.translations_count', count($translationGroups), ['count' => count($translationGroups)]) }}</p>
        </div>
    </div>
    @auth
        <a href="{{ route('translations.create') }}?game={{ $game->id }}" class="bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded-lg transition text-center sm:text-left flex-shrink-0">
            <i class="fas fa-upload mr-2"></i> {{ __('games.upload_translation') }}
        </a>
    @else
        <a href="{{ route('login') }}?redirect={{ urlencode(url()->current()) }}&action=upload" class="bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded-lg transition text-center sm:text-left flex-shrink-0">
            <i class="fas fa-upload mr-2"></i> {{ __('games.upload_translation') }}
        </a>
    @endauth
</div>

@if($targetLanguages->isNotEmpty())
    {{-- Localized indexable intro: names the game and every available language natively --}}
    <p class="text-gray-400 text-sm sm:text-base mb-8 max-w-3xl">
        {{ __('seo.game_intro', ['game' => $game->name, 'languages' => $seoComboLanguages->implode(', ')]) }}
    </p>
@endif

<!-- Filters -->
{{-- Selects apply on change, like the game list and the editors' filters: one gesture, one
     result. The button stays for anyone without JavaScript. --}}
<form action="{{ route('games.show', $game) }}" method="GET" class="bg-gray-800 rounded-lg p-4 mb-8 flex flex-wrap gap-4 items-end"
    data-auto-submit>
    {{-- The same two filters as the game list, and for the same reason they carry their flags:
         a native `<option>` cannot hold one.

         🔴 **The flags are named from the VALUE, not from the label.** This page writes languages
         in their native spelling ("Français"), and <x-language-mark> resolves a flag from the
         CATALOGUE name ("French") — left to itself it would silently draw nothing on every entry.
         The value behind each label is that catalogue name, so the flag is looked up there and the
         label stays native, which is what this page is for. --}}
    @php
        $flagOf = fn ($langs) => collect($langs)
            ->mapWithKeys(fn ($lang) => [$lang => \App\Services\CatalogStore::languageMark($lang)['flag']])
            ->all();
    @endphp
    <div class="min-w-[11rem]">
        <label class="block text-sm text-gray-400 mb-1">{{ __('games.target_language') }}</label>
        <x-language-select
            name="target"
            :choices="collect($targetLanguages)->mapWithKeys(fn ($lang) => [$lang => $seoLanguageNames[$lang] ?? $lang])->all()"
            :selected="request('target')"
            :flags="$flagOf($targetLanguages)"
            :marks="false"
            :empty="__('games.all')" />
    </div>
    <div class="min-w-[11rem]">
        <label class="block text-sm text-gray-400 mb-1">{{ __('games.source_language') }}</label>
        <x-language-select
            name="source"
            :choices="collect($sourceLanguages)->mapWithKeys(fn ($lang) => [$lang => $seoLanguageNames[$lang] ?? $lang])->all()"
            :selected="request('source')"
            :flags="$flagOf($sourceLanguages)"
            :marks="false"
            :empty="__('games.all')" />
    </div>
    {{-- Note: type filter removed - type is now computed from HVASM stats --}}
    <div>
        <label class="block text-sm text-gray-400 mb-1">{{ __('games.sort_by') }}</label>
        <select name="sort" class="bg-gray-700 border border-gray-600 rounded px-3 py-2 text-white">
            <option value="score" {{ request('sort', 'score') == 'score' ? 'selected' : '' }}>{{ __('games.sort.score') }}</option>
            <option value="quality" {{ request('sort') == 'quality' ? 'selected' : '' }}>{{ __('games.sort.quality') }}</option>
            <option value="votes" {{ request('sort') == 'votes' ? 'selected' : '' }}>{{ __('games.sort.votes') }}</option>
            <option value="date" {{ request('sort') == 'date' ? 'selected' : '' }}>{{ __('games.sort.date') }}</option>
            <option value="lines" {{ request('sort') == 'lines' ? 'selected' : '' }}>{{ __('games.sort.lines') }}</option>
            <option value="downloads" {{ request('sort') == 'downloads' ? 'selected' : '' }}>{{ __('games.sort.downloads') }}</option>
        </select>
    </div>
    @if($highlightLanguage)
        <label class="flex items-center gap-2 text-sm text-gray-300 cursor-pointer whitespace-nowrap pb-2">
            {{-- An unchecked box sends nothing at all, so without this the option could be
                 turned on but never off --}}
            <input type="hidden" name="lang_first" value="0">
            <input type="checkbox" name="lang_first" value="1" {{ $languageFirst ? 'checked' : '' }}
                class="rounded bg-gray-700 border-gray-600 text-purple-600">
            <span>{{ __('games.sort.language_first', ['language' => $seoLanguageNames[$highlightLanguage] ?? $highlightLanguage]) }}</span>
        </label>
    @endif

    {{-- Nothing left to click once the selects apply on change. Kept in the markup and hidden
         by the script that makes it redundant: without JavaScript it is the only way to
         filter, so removing it outright would strand those visitors. --}}
    <button type="submit" data-hide-when-auto
        class="bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded">
        <i class="fas fa-filter"></i> {{ __('games.filter') }}
    </button>
</form>

@if(empty($translationGroups))
    <div class="text-center py-12 text-gray-400">
        <p class="text-xl">{{ __('games.no_translations') }}</p>
        @auth
            <a href="{{ route('translations.create') }}?game={{ $game->id }}" class="inline-block mt-4 bg-purple-600 hover:bg-purple-700 text-white px-6 py-3 rounded-lg">
                <i class="fas fa-upload mr-2"></i> {{ __('games.be_first') }}
            </a>
        @else
            <a href="{{ route('login') }}?redirect={{ urlencode(url()->current()) }}&action=upload" class="inline-block mt-4 bg-purple-600 hover:bg-purple-700 text-white px-6 py-3 rounded-lg">
                <i class="fas fa-upload mr-2"></i> {{ __('games.be_first') }}
            </a>
        @endauth
    </div>
@else
    <div class="space-y-6">
        @foreach($translationGroups as $index => $group)
            @php
                $translation = $group['primary'];
                $versions = $group['versions'];
                $forks = $group['forks'];
                $hasVersionHistory = $versions->count() > 1;
                $hasForks = $forks->count() > 0;
            @endphp

            @if($translation)
            @php $hasSettings = $translation->hasSettings() || $translation->getEffectiveResourcesUrl(); @endphp
            {{-- Anchored so a Main can point at the forks that grew out of it: they live in
                 their own group further down the page, having left the lineage. --}}
            <div id="translation-{{ $translation->id }}" class="bg-gray-800 rounded-lg border border-gray-700 overflow-hidden scroll-mt-8" x-data="{ showVersions: false, showForks: false, showSettings: false }">
                <!-- Main Translation Card -->
                <div class="p-6">
                    <div class="flex justify-between items-start gap-4">
                        <!-- Left: Info -->
                        <div class="flex-1 min-w-0">
                            <!-- Badges row -->
                            <div class="flex items-center gap-2 mb-3 flex-wrap">
                                <span class="bg-blue-900 text-blue-200 px-3 py-1 rounded text-sm font-medium inline-flex items-center gap-1">
                                    <span>@langflag($translation->source_language)</span>
                                    <span>{{ $translation->source_language }}</span>
                                    <span class="mx-1">→</span>
                                    <span>@langflag($translation->target_language)</span>
                                    <span>{{ $translation->target_language }}</span>
                                </span>

                                {{-- Review stage rather than the old type badge. "AI corrected"
                                     covered everything from one reviewed line to all of them,
                                     and "Human" demanded that more than half be TYPED by hand —
                                     a measure of method, when the reader is asking whether
                                     anyone has read the thing. --}}
                                @if($translation->effective_lines === 0 && ($translation->capture_count ?? 0) > 0)
                                    <span class="bg-gray-700 text-gray-300 px-2 py-1 rounded text-xs" title="{{ __('progress.capture_only_desc') }}">
                                        <i class="fas fa-camera"></i> {{ __('progress.capture_only') }}
                                    </span>
                                @else
                                    <x-review-stage :translation="$translation" class="px-2 py-1" />
                                @endif

                                <x-translation-completeness :translation="$translation" class="px-2 py-1" />

                                <x-game-coverage :translation="$translation" />

                                <x-translation-badges :translation="$translation"
                                    :game-max="$gameMaxResolved" :peer-count="$publicTranslationCount" />

                                @if($translation->isComplete())
                                    <span class="bg-green-900 text-green-200 px-2 py-1 rounded text-xs">
                                        <i class="fas fa-check"></i> {{ __('translation.complete') }}
                                    </span>
                                @else
                                    <span class="bg-yellow-900 text-yellow-200 px-2 py-1 rounded text-xs">
                                        <i class="fas fa-clock"></i> {{ __('translation.in_progress') }}
                                    </span>
                                @endif

                                @if($hasVersionHistory)
                                    <span class="bg-gray-700 text-gray-300 px-2 py-1 rounded text-xs">
                                        <i class="fas fa-layer-group"></i> v{{ $versions->count() }}
                                    </span>
                                @endif

                                {{-- External assets: needed BEFORE downloading (custom fonts,
                                     replacement images live outside this file), so it belongs
                                     with the badges, not buried in a panel --}}
                                @if($translation->getEffectiveResourcesUrl())
                                    <button type="button" @click="showSettings = true"
                                        class="bg-cyan-900/70 text-cyan-200 px-2 py-1 rounded text-xs hover:bg-cyan-800/70 transition"
                                        title="{{ __('file_settings.resources_badge_title') }}">
                                        <i class="fas fa-link"></i> {{ __('file_settings.resources_badge') }}
                                    </button>
                                @endif
                            </div>

                            <!-- Meta info -->
                            <div class="text-gray-400 text-sm flex items-center gap-4 flex-wrap mb-2">
                                <span class="flex items-center gap-1">
                                    @if($translation->user->avatar)
                                        <img src="{{ $translation->user->avatar }}" class="w-5 h-5 rounded-full">
                                    @endif
                                    <span class="font-medium text-gray-300">{{ $translation->user->name }}</span>
                                </span>
                                {{-- Two distinct facts: when it appeared, and whether it is
                                     still being worked on. Showing only one of them made an
                                     abandoned translation look like a fresh one. --}}
                                <span title="{{ __('translation.published_on', ['date' => $translation->created_at->isoFormat('LL')]) }}">
                                    <i class="fas fa-calendar mr-1"></i> {{ __('translation.published_on', ['date' => $translation->created_at->isoFormat('LL')]) }}
                                </span>
                                @if($translation->hasBeenUpdatedSincePublication())
                                    <span title="{{ $translation->contentChangedAt()->isoFormat('LLL') }}">
                                        <i class="fas fa-pen mr-1"></i> {{ __('translation.updated_on', ['date' => $translation->contentChangedAt()->isoFormat('LL')]) }}
                                    </span>
                                @endif
                                <span><i class="fas fa-file-alt mr-1"></i> {{ number_format($translation->line_count) }} {{ __('translation.lines', ['count' => '']) }}</span>
                                <span><i class="fas fa-download mr-1"></i> {{ $group['total_downloads'] }}</span>
                                {{-- Whose work this was built on. A fork leaves its lineage, so
                                     nothing else on this page can say it. --}}
                                <x-translation-origin :translation="$translation" />
                            </div>

                            {{-- Independent translations started from this one. Real forks, not
                                 the branches shown further down: a fork carries its own uuid and
                                 owner, so it never appears in this group and was invisible from
                                 here — the Main had no way to know it had been taken up. --}}
                            @if($translation->publicForks->isNotEmpty())
                                <div class="text-sm text-gray-400 mb-2">
                                    <i class="fas fa-code-branch mr-1"></i>{{ __('translation.taken_up_by') }}
                                    @foreach($translation->publicForks as $fork)
                                        <a href="#translation-{{ $fork->id }}" class="text-purple-400 hover:text-purple-300 underline underline-offset-2">{{ $fork->user->name }}</a>{{ !$loop->last ? ',' : '' }}
                                    @endforeach
                                </div>
                            @endif

                            <!-- Progress bar -->
                            <div class="mt-2 mb-3">
                                <x-progress-bar :translation="$translation" />
                                <x-quality-legend :translation="$translation" />
                            </div>

                            <!-- Notes -->
                            @if($translation->notes)
                                <div class="mt-3 text-sm text-gray-400 bg-gray-750 rounded p-3 border-l-2 border-purple-500">
                                    <i class="fas fa-quote-left text-purple-500 mr-2"></i>{{ $translation->notes }}
                                </div>
                            @endif
                        </div>

                        <!-- Right: Actions -->
                        <div class="flex flex-col items-end gap-3">
                            <!-- Vote buttons -->
                            <x-vote-buttons :translation="$translation" />

                            <!-- Action buttons -->
                            <div class="flex gap-2">
                                {{-- Look before you take. Offered only where the content is
                                     actually readable by whoever is looking — same rule as the
                                     download, asked of the same method, so the button can never
                                     be the one that answers 403. --}}
                                @if($translation->isReadableBy(auth()->user()))
                                    <a href="{{ route('translations.view', $translation) }}"
                                        class="bg-gray-700 hover:bg-gray-600 text-white px-3 py-2 rounded text-sm"
                                        title="{{ __('translation.view_content') }}">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                @endif
                                @auth
                                    <button type="button" data-report-id="{{ $translation->id }}" class="report-btn bg-red-600 hover:bg-red-700 text-white px-3 py-2 rounded text-sm" title="{{ __('translation.report') }}">
                                        <i class="fas fa-flag"></i>
                                    </button>
                                @else
                                    <a href="{{ route('login') }}?redirect={{ urlencode(url()->current()) }}&action=report" class="bg-red-600 hover:bg-red-700 text-white px-3 py-2 rounded text-sm" title="{{ __('translation.report') }}">
                                        <i class="fas fa-flag"></i>
                                    </a>
                                @endauth
                                <a href="{{ route('translations.download', $translation) }}" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded font-medium">
                                    <i class="fas fa-download mr-1"></i> {{ __('translation.download') }}
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Expandable Sections Toggle -->
                @if($hasVersionHistory || $hasForks || $hasSettings)
                <div class="border-t border-gray-700 px-6 py-3 bg-gray-750 flex gap-4 flex-wrap">
                    @if($hasSettings)
                        @include('partials.translation-settings-toggle', ['translation' => $translation])
                    @endif
                    @if($hasVersionHistory)
                        <button @click="showVersions = !showVersions" class="flex items-center gap-2 text-sm text-gray-400 hover:text-white transition">
                            <i class="fas fa-history"></i>
                            <span>{{ $versions->count() - 1 }} older version{{ $versions->count() - 1 > 1 ? 's' : '' }}</span>
                            <i class="fas fa-chevron-down text-xs transition-transform" :class="showVersions && 'rotate-180'"></i>
                        </button>
                    @endif
                    @if($hasForks)
                        <button @click="showForks = !showForks" class="flex items-center gap-2 text-sm text-gray-400 hover:text-white transition">
                            <i class="fas fa-code-branch"></i>
                            {{-- Said "community fork", which is the wrong word — these are
                                 branches — and pluralised with an English "s" on a site that
                                 speaks nineteen languages. The count sits in parentheses rather
                                 than inside the sentence: no language has to agree with it. --}}
                            <span>{{ __('translation.contributions') }} ({{ $forks->count() }})</span>
                            <i class="fas fa-chevron-down text-xs transition-transform" :class="showForks && 'rotate-180'"></i>
                        </button>
                    @endif
                </div>
                @endif

                <!-- What this file carries beyond its lines (Expandable) -->
                @if($hasSettings)
                <div x-show="showSettings" x-collapse class="border-t border-gray-700">
                    <div class="px-6 py-4 bg-gray-850">
                        <div class="flex items-center gap-2 mb-3">
                            <h4 class="text-sm font-medium text-gray-300">{{ __('file_settings.section_title') }}</h4>
                            <span class="text-xs text-gray-500" title="{{ __('file_settings.section_hint') }}">
                                <i class="fas fa-info-circle"></i>
                            </span>
                        </div>
                        @include('partials.translation-settings-detail', ['translation' => $translation])
                        <p class="mt-3 text-xs text-gray-500 italic">{{ __('file_settings.section_hint') }}</p>
                    </div>
                </div>
                @endif

                <!-- Version History (Expandable) -->
                @if($hasVersionHistory)
                <div x-show="showVersions" x-collapse class="border-t border-gray-700">
                    <div class="px-6 py-4 bg-gray-850">
                        <h4 class="text-sm font-medium text-gray-400 mb-3">
                            <i class="fas fa-history mr-2"></i> Version History
                        </h4>
                        <div class="space-y-2">
                            @foreach($versions->skip(1) as $vIndex => $version)
                                <div class="flex items-center justify-between p-3 bg-gray-800 rounded-lg border border-gray-700">
                                    <div class="flex items-center gap-4 text-sm">
                                        <span class="bg-gray-700 text-gray-400 px-2 py-1 rounded text-xs">v{{ $versions->count() - $vIndex - 1 }}</span>
                                        <span class="text-gray-400" title="{{ __('translation.published_on', ['date' => $version->created_at->isoFormat('LL')]) }}">{{ $version->created_at->isoFormat('LL') }}</span>
                                        @if($version->hasBeenUpdatedSincePublication())
                                            <span class="text-gray-400" title="{{ $version->contentChangedAt()->isoFormat('LLL') }}">
                                                <i class="fas fa-pen text-xs mr-1"></i>{{ $version->contentChangedAt()->isoFormat('LL') }}
                                            </span>
                                        @endif
                                        <span class="text-gray-400">{{ number_format($version->line_count) }} lines</span>
                                        @if($version->type)
                                            <span class="text-gray-400">
                                                @if($version->type === 'ai')
                                                    <i class="fas fa-robot"></i>
                                                @elseif($version->type === 'human')
                                                    <i class="fas fa-user"></i>
                                                @else
                                                    <i class="fas fa-user-edit"></i>
                                                @endif
                                            </span>
                                        @endif
                                        @if($version->notes)
                                            <span class="text-gray-400 truncate max-w-xs" title="{{ $version->notes }}">{{ Str::limit($version->notes, 50) }}</span>
                                        @endif
                                    </div>
                                    <div class="flex items-center gap-3">
                                        <span class="text-sm {{ $version->vote_count > 0 ? 'text-green-400' : ($version->vote_count < 0 ? 'text-red-400' : 'text-gray-500') }}">
                                            {{ $version->vote_count >= 0 ? '+' : '' }}{{ $version->vote_count }}
                                        </span>
                                        <a href="{{ route('translations.download', $version) }}" class="text-blue-400 hover:text-blue-300">
                                            <i class="fas fa-download"></i>
                                        </a>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
                @endif

                <!-- Community Forks (Expandable) -->
                @if($hasForks)
                <div x-show="showForks" x-collapse class="border-t border-gray-700">
                    <div class="px-6 py-4 bg-gray-850">
                        {{-- These are BRANCHES, and calling them forks guaranteed the confusion:
                             a fork leaves the lineage with its own uuid and stands on its own,
                             a branch is a contribution waiting for the Main to take it.

                             Kept public on purpose. The CONTENT of a branch is private — nobody
                             but the Main can download it — but its EXISTENCE is worth showing:
                             a Main gone quiet with three contributions pending does not tell the
                             same story as a Main gone quiet alone, and the people helping
                             deserve to be named. --}}
                        <h4 class="text-sm font-medium text-gray-400 mb-3">
                            <i class="fas fa-code-branch mr-2"></i>{{ __('translation.contributions') }}
                        </h4>
                        <div class="space-y-2">
                            @foreach($forks as $fork)
                                <div class="flex items-center justify-between p-3 bg-gray-800 rounded-lg border border-gray-700">
                                    <div class="flex items-center gap-4 text-sm">
                                        <span class="flex items-center gap-1">
                                            @if($fork->user->avatar)
                                                <img src="{{ $fork->user->avatar }}" class="w-5 h-5 rounded-full">
                                            @endif
                                            <span class="text-gray-300">{{ $fork->user->name }}</span>
                                        </span>
                                        {{-- For a fork, "still alive?" matters more than "born when?" --}}
                                        <span class="text-gray-400" title="{{ __('translation.published_on', ['date' => $fork->created_at->isoFormat('LL')]) }}">{{ $fork->created_at->isoFormat('LL') }}</span>
                                        @if($fork->hasBeenUpdatedSincePublication())
                                            <span class="text-gray-400" title="{{ $fork->contentChangedAt()->isoFormat('LLL') }}">
                                                <i class="fas fa-pen text-xs mr-1"></i>{{ $fork->contentChangedAt()->isoFormat('LL') }}
                                            </span>
                                        @endif
                                        <span class="text-gray-400">{{ __('translation.lines', ['count' => number_format($fork->line_count)]) }}</span>
                                        @if($fork->type)
                                            @if($fork->type === 'ai')
                                                <span class="bg-blue-800 text-blue-200 px-1.5 py-0.5 rounded text-xs"><i class="fas fa-robot"></i></span>
                                            @elseif($fork->type === 'human')
                                                <span class="bg-green-800 text-green-200 px-1.5 py-0.5 rounded text-xs"><i class="fas fa-user"></i></span>
                                            @else
                                                <span class="bg-purple-800 text-purple-200 px-1.5 py-0.5 rounded text-xs"><i class="fas fa-user-edit"></i></span>
                                            @endif
                                        @endif
                                        @if($fork->notes)
                                            <span class="text-gray-400 truncate max-w-xs" title="{{ $fork->notes }}">{{ Str::limit($fork->notes, 50) }}</span>
                                        @endif
                                    </div>
                                    {{-- Neither a download nor a vote here. The download answered
                                         403 to everyone but the Main owner, and canBeVotedBy
                                         refuses a branch outright — a test says so. Offering a
                                         control that replies 403 is worse than offering none,
                                         which is the same rule that took the arrows away from an
                                         author on their own work. Reporting stays: an abusive
                                         contribution has to be reportable by whoever sees it. --}}
                                    <div class="flex items-center gap-3">
                                        {{-- Same rule as above, and here it does the real work:
                                             this list holds branches, which nobody but the Main
                                             owner may read — and public forks, which read like
                                             any other translation. The method decides, not the
                                             list an entry happens to land in. --}}
                                        @if($fork->isReadableBy(auth()->user()))
                                            <a href="{{ route('translations.view', $fork) }}"
                                                class="text-gray-400 hover:text-white"
                                                title="{{ __('translation.view_content') }}">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                        @endif
                                        @auth
                                            <button type="button" data-report-id="{{ $fork->id }}" class="report-btn text-red-400 hover:text-red-300">
                                                <i class="fas fa-flag"></i>
                                            </button>
                                        @else
                                            <a href="{{ route('login') }}?redirect={{ urlencode(url()->current()) }}&action=report" class="text-red-400 hover:text-red-300">
                                                <i class="fas fa-flag"></i>
                                            </a>
                                        @endauth
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
                @endif
            </div>
            @endif
        @endforeach
    </div>

    {{-- What happens after the download.

         The page used to stop right here: someone who had just found the translation for their
         game was left holding a JSON file, with nothing saying it belongs to a mod that has to
         be installed first — and the only link to that mod was a generic button in the footer.
         The journey broke exactly where the visitor was most willing to go on.

         It also fills the void that short pages left below the list. --}}
    <div class="mt-10 bg-gray-800/60 border border-gray-700 rounded-lg p-6">
        <h2 class="text-lg font-semibold text-white mb-4">
            <i class="fas fa-circle-play mr-2 text-purple-400"></i>{{ __('games.how_to_play_title') }}
        </h2>
        <ol class="space-y-3 text-gray-300 mb-5">
            <li class="flex gap-3">
                <span class="flex-none w-6 h-6 rounded-full bg-purple-600/30 text-purple-300 text-sm flex items-center justify-center">1</span>
                <span>{{ __('games.how_to_play_step1') }}</span>
            </li>
            <li class="flex gap-3">
                <span class="flex-none w-6 h-6 rounded-full bg-purple-600/30 text-purple-300 text-sm flex items-center justify-center">2</span>
                <span>{{ __('games.how_to_play_step2') }}</span>
            </li>
        </ol>
        <div class="flex flex-wrap gap-3">
            <a href="{{ route('docs') }}#quick-start" class="bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded-lg">
                <i class="fas fa-download mr-2"></i>{{ __('footer.download_mod') }}
            </a>
            <a href="{{ route('docs') }}#installation" class="bg-gray-700 hover:bg-gray-600 text-white px-4 py-2 rounded-lg">
                <i class="fas fa-book mr-2"></i>{{ __('docs.installation') }}
            </a>
        </div>
        <p class="text-sm text-gray-500 mt-4">{{ __('games.how_to_play_manual') }}</p>
    </div>
@endif

@auth
{{-- The report dialog lives in x-report-modal, shared with the merge view: what a report
     looks like must not depend on the page it is raised from. --}}
<x-report-modal />
<script nonce="{{ $cspNonce }}">
(function() {
    // Opening and closing the report dialog belongs to x-report-modal above.

    // Vote buttons
    document.querySelectorAll('.vote-btn[data-vote-id]').forEach(function(btn) {
        btn.addEventListener('click', function() {
            vote(parseInt(this.dataset.voteId), parseInt(this.dataset.voteValue));
        });
    });

    async function vote(translationId, value) {
        try {
            var response = await fetch('/vote/' + translationId, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({ value: value })
            });

            if (!response.ok) throw new Error('Vote failed');

            var data = await response.json();

            // Update vote count display
            var countEl = document.getElementById('vote-count-' + translationId);
            var count = data.vote_count;
            // Same rule as the component that rendered it: no sign on zero
            countEl.textContent = (count > 0 ? '+' : '') + count;
            countEl.className = countEl.className.replace(/text-(green|red|gray)-\d+/g, '');
            countEl.classList.add(count > 0 ? 'text-green-400' : (count < 0 ? 'text-red-400' : 'text-gray-400'));

            // Update button states
            var upvoteBtn = document.getElementById('upvote-' + translationId);
            var downvoteBtn = document.getElementById('downvote-' + translationId);

            if (upvoteBtn) {
                upvoteBtn.className = upvoteBtn.className.replace(/text-(green|gray)-\d+/g, '');
                upvoteBtn.classList.add(data.user_vote === 1 ? 'text-green-400' : 'text-gray-400');
            }
            if (downvoteBtn) {
                downvoteBtn.className = downvoteBtn.className.replace(/text-(red|gray)-\d+/g, '');
                downvoteBtn.classList.add(data.user_vote === -1 ? 'text-red-400' : 'text-gray-400');
            }
        } catch (error) {
            console.error('Vote error:', error);
        }
    }
})();
</script>
@endauth

<style>
    .bg-gray-750 { background-color: rgb(42, 48, 60); }
    .bg-gray-850 { background-color: rgb(30, 34, 43); }
</style>
@endsection
