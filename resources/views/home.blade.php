@extends('layouts.app')

@section('title', 'UnityGameTranslator - ' . __('home.hero_description'))

@push('head')
<script type="application/ld+json">
{
    "@@context": "https://schema.org",
    "@@type": "WebSite",
    "name": "UnityGameTranslator",
    "url": "{{ url('/') }}",
    "description": "{{ __('home.hero_description') }}",
    "inLanguage": "{{ app()->getLocale() }}",
    "potentialAction": {
        "@@type": "SearchAction",
        "target": "{{ route('games.index') }}?q={search_term_string}",
        "query-input": "required name=search_term_string"
    }
}
</script>
<script type="application/ld+json">
{
    "@@context": "https://schema.org",
    "@@type": "Organization",
    "name": "UnityGameTranslator",
    "url": "{{ url('/') }}",
    "logo": "{{ asset('logo.svg') }}",
    "sameAs": [
        "https://github.com/djethino/UnityGameTranslator"
    ]
}
</script>
@endpush

@section('content')
    <!-- Alpha Banner -->
    <div class="bg-yellow-900/50 border border-yellow-700 text-yellow-200 px-4 py-3 rounded-lg mb-6 flex items-center justify-center">
        <i class="fas fa-flask mr-2"></i>
        <span>{{ __('home.alpha_banner') }}</span>
    </div>

    <!-- Hero Section -->
    <div class="text-center py-12 mb-12 relative">
        <!-- Gradient Background -->
        <div class="absolute inset-0 bg-gradient-to-b from-purple-900/20 via-transparent to-transparent rounded-3xl -z-10"></div>
        <h1 class="text-4xl md:text-5xl font-bold text-white mb-4">
            <i class="fas fa-language text-purple-400 mr-3"></i>{{ __('home.hero_title') }}
        </h1>
        <p class="text-xl text-gray-300 max-w-3xl mx-auto mb-6">
            {{ __('home.hero_description') }}
        </p>
        <!-- Key features tags -->
        <div class="flex flex-wrap justify-center gap-2 mb-8">
            <span class="px-3 py-1 bg-gray-800 border border-gray-700 rounded-full text-sm text-gray-300">
                <i class="fas fa-robot text-purple-400 mr-1"></i>{{ __('home.tag_local_ai') }}
            </span>
            <span class="px-3 py-1 bg-gray-800 border border-gray-700 rounded-full text-sm text-gray-300">
                <i class="fas fa-users text-purple-400 mr-1"></i>{{ __('home.tag_community') }}
            </span>
            <span class="px-3 py-1 bg-gray-800 border border-gray-700 rounded-full text-sm text-gray-300">
                <i class="fas fa-shield-alt text-purple-400 mr-1"></i>{{ __('home.tag_privacy') }}
            </span>
            <span class="px-3 py-1 bg-gray-800 border border-gray-700 rounded-full text-sm text-gray-300">
                <i class="fab fa-github text-purple-400 mr-1"></i>{{ __('home.tag_opensource') }}
            </span>
        </div>
        <div class="flex flex-wrap justify-center gap-4">
            <a href="https://github.com/djethino/UnityGameTranslator/releases/latest" target="_blank" class="bg-purple-600 hover:bg-purple-700 text-white font-semibold px-6 py-3 rounded-lg transition-all duration-200 hover:-translate-y-0.5 flex items-center">
                <i class="fas fa-download mr-2"></i>
                {{ __('home.download_mod') }}
            </a>
            <a href="{{ route('games.index') }}" class="bg-gray-700 hover:bg-gray-600 text-white font-semibold px-6 py-3 rounded-lg transition-all duration-200 hover:-translate-y-0.5 flex items-center">
                <i class="fas fa-gamepad mr-2"></i>
                {{ __('home.view_games') }}
            </a>
            <a href="{{ route('docs') }}" class="bg-gray-800 hover:bg-gray-700 text-white font-semibold px-6 py-3 rounded-lg transition-all duration-200 hover:-translate-y-0.5 flex items-center border border-gray-600">
                <i class="fas fa-book mr-2"></i>
                {{ __('home.view_docs') }}
            </a>
        </div>
    </div>

    <!-- Search Bar -->
    <div class="bg-gray-800 rounded-lg p-6 border border-gray-700 mb-12">
        <form action="{{ route('games.index') }}" method="GET" class="flex flex-col sm:flex-row gap-4">
            <input type="text" name="q" placeholder="{{ __('home.search_games') }}"
                   class="flex-1 bg-gray-700 border border-gray-600 rounded-lg px-4 py-3 text-white placeholder-gray-400 focus:outline-none focus:border-purple-500">
            <button type="submit" class="bg-purple-600 hover:bg-purple-700 text-white font-semibold px-6 py-3 rounded-lg transition">
                <i class="fas fa-search mr-2"></i>
                {{ __('home.search_redirect') }}
            </button>
        </form>
    </div>

    <!-- How it works - 3 columns -->
    <div class="mb-12">
        <h2 class="text-2xl font-bold text-white text-center mb-8">
            <i class="fas fa-cogs text-purple-400 mr-2"></i>
            {{ __('home.how_it_works') }}
        </h2>
        <div class="grid md:grid-cols-3 gap-6">
            <!-- The Mod -->
            <div class="bg-gray-800 rounded-lg p-6 border border-gray-700 flex flex-col">
                <div class="text-center mb-4">
                    <div class="inline-flex items-center justify-center w-12 h-12 rounded-full bg-purple-600/20 mb-3">
                        <i class="fas fa-puzzle-piece text-purple-400 text-xl"></i>
                    </div>
                    <h3 class="text-xl font-bold text-white">{{ __('home.the_mod') }}</h3>
                    <p class="text-sm text-purple-400">{{ __('home.mod_subtitle') }}</p>
                </div>
                <ul class="space-y-2 text-gray-400 text-sm flex-grow">
                    <li class="flex items-start">
                        <i class="fas fa-check text-green-400 mr-2 mt-0.5"></i>
                        <span>{{ __('home.mod_feature_overlay') }}</span>
                    </li>
                    <li class="flex items-start">
                        <i class="fas fa-check text-green-400 mr-2 mt-0.5"></i>
                        <span>{{ __('home.mod_feature_ai') }}</span>
                    </li>
                    <li class="flex items-start">
                        <i class="fas fa-check text-green-400 mr-2 mt-0.5"></i>
                        <span>{{ __('home.mod_feature_detect') }}</span>
                    </li>
                    <li class="flex items-start">
                        <i class="fas fa-check text-green-400 mr-2 mt-0.5"></i>
                        <span>{{ __('home.mod_feature_platform') }}</span>
                    </li>
                    <li class="flex items-start">
                        <i class="fas fa-check text-green-400 mr-2 mt-0.5"></i>
                        <span>{{ __('home.mod_feature_privacy') }}</span>
                    </li>
                    <li class="flex items-start">
                        <i class="fas fa-check text-green-400 mr-2 mt-0.5"></i>
                        <span>{{ __('home.mod_feature_login') }}</span>
                    </li>
                    <li class="flex items-start">
                        <i class="fas fa-check text-green-400 mr-2 mt-0.5"></i>
                        <span>{{ __('home.mod_feature_numbers') }}</span>
                    </li>
                </ul>
                <!-- Screenshot -->
                <div class="mt-4 rounded-lg overflow-hidden border border-gray-700">
                    <img src="{{ asset('images/screenshots/ModWizard1.png') }}"
                         alt="{{ __('home.mod_screenshot_alt') }}"
                         class="w-full h-auto"
                         loading="lazy">
                </div>
            </div>

            <!-- Community (center) -->
            <div class="bg-gray-800 rounded-lg p-6 border border-purple-500/50 flex flex-col">
                <div class="text-center mb-4">
                    <div class="inline-flex items-center justify-center w-12 h-12 rounded-full bg-purple-600/20 mb-3">
                        <i class="fas fa-users text-purple-400 text-xl"></i>
                    </div>
                    <h3 class="text-xl font-bold text-white">{{ __('home.community_title') }}</h3>
                    <p class="text-sm text-purple-400">{{ __('home.community_subtitle') }}</p>
                </div>
                <ul class="space-y-2 text-gray-400 text-sm flex-grow">
                    <li class="flex items-start">
                        <i class="fas fa-download text-blue-400 mr-2 mt-0.5"></i>
                        <span>{{ __('home.community_download') }}</span>
                    </li>
                    <li class="flex items-start">
                        <i class="fas fa-upload text-green-400 mr-2 mt-0.5"></i>
                        <span>{{ __('home.community_upload') }}</span>
                    </li>
                    <li class="flex items-start">
                        <i class="fas fa-sync text-cyan-400 mr-2 mt-0.5"></i>
                        <span>{{ __('home.community_sync') }}</span>
                    </li>
                    <li class="flex items-start">
                        <i class="fas fa-code-branch text-yellow-400 mr-2 mt-0.5"></i>
                        <span>{{ __('home.community_merge') }}</span>
                    </li>
                    <li class="flex items-start">
                        <i class="fas fa-code-fork text-orange-400 mr-2 mt-0.5"></i>
                        <span>{{ __('home.community_branch') }}</span>
                    </li>
                    <li class="flex items-start">
                        <i class="fas fa-bell text-purple-400 mr-2 mt-0.5"></i>
                        <span>{{ __('home.community_notify') }}</span>
                    </li>
                    <li class="flex items-start">
                        <i class="fab fa-github text-gray-400 mr-2 mt-0.5"></i>
                        <span>{{ __('home.community_opensource') }}</span>
                    </li>
                </ul>
                <!-- Quality Tags Section -->
                <div class="mt-4 p-4 bg-gray-900 rounded-lg border border-gray-700">
                    <h4 class="text-sm font-semibold text-white mb-3 flex items-center">
                        <i class="fas fa-certificate text-yellow-400 mr-2"></i>
                        {{ __('home.quality_title') }}
                    </h4>
                    <div class="flex justify-center gap-2 mb-3">
                        <span class="px-2 py-1 rounded text-xs font-bold bg-green-600 text-white" title="Human">H</span>
                        <span class="px-2 py-1 rounded text-xs font-bold bg-blue-600 text-white" title="Validated">V</span>
                        <span class="px-2 py-1 rounded text-xs font-bold bg-orange-600 text-white" title="AI">A</span>
                        <span class="px-2 py-1 rounded text-xs font-bold bg-gray-600 text-white" title="Skip">S</span>
                    </div>
                    <p class="text-xs text-gray-400 text-center">{{ __('home.quality_desc') }}</p>
                    <p class="text-xs text-gray-500 text-center mt-2">{{ __('home.quality_capture') }}</p>
                </div>
            </div>

            <!-- The Website -->
            <div class="bg-gray-800 rounded-lg p-6 border border-gray-700 flex flex-col">
                <div class="text-center mb-4">
                    <div class="inline-flex items-center justify-center w-12 h-12 rounded-full bg-purple-600/20 mb-3">
                        <i class="fas fa-globe text-purple-400 text-xl"></i>
                    </div>
                    <h3 class="text-xl font-bold text-white">{{ __('home.the_website') }}</h3>
                    <p class="text-sm text-purple-400">{{ __('home.website_subtitle') }}</p>
                </div>
                <ul class="space-y-2 text-gray-400 text-sm flex-grow">
                    <li class="flex items-start">
                        <i class="fas fa-search text-blue-400 mr-2 mt-0.5"></i>
                        <span>{{ __('home.website_feature_browse') }}</span>
                    </li>
                    <li class="flex items-start">
                        <i class="fas fa-edit text-cyan-400 mr-2 mt-0.5"></i>
                        <span>{{ __('home.website_feature_edit') }}</span>
                    </li>
                    <li class="flex items-start">
                        <i class="fas fa-check-circle text-green-400 mr-2 mt-0.5"></i>
                        <span>{{ __('home.website_feature_validate') }}</span>
                    </li>
                    <li class="flex items-start">
                        <i class="fas fa-star text-yellow-400 mr-2 mt-0.5"></i>
                        <span>{{ __('home.website_feature_vote') }}</span>
                    </li>
                    <li class="flex items-start">
                        <i class="fas fa-code-branch text-orange-400 mr-2 mt-0.5"></i>
                        <span>{{ __('home.website_feature_fork') }}</span>
                    </li>
                    <li class="flex items-start">
                        <i class="fas fa-object-group text-purple-400 mr-2 mt-0.5"></i>
                        <span>{{ __('home.website_feature_merge') }}</span>
                    </li>
                    <li class="flex items-start">
                        <i class="fas fa-language text-gray-400 mr-2 mt-0.5"></i>
                        <span>{{ __('home.website_feature_languages') }}</span>
                    </li>
                </ul>
                <!-- Screenshot -->
                <div class="mt-4 rounded-lg overflow-hidden border border-gray-700">
                    <img src="{{ asset('images/screenshots/WebHumanEditAndValidation.png') }}"
                         alt="{{ __('home.website_screenshot_alt') }}"
                         class="w-full h-auto"
                         loading="lazy">
                </div>
            </div>
        </div>
    </div>

    <!-- Stats (hidden when numbers are low) -->
    @if($stats['games'] >= 10 && $stats['translations'] >= 25 && $stats['users'] >= 50)
    <div class="bg-gray-800/80 backdrop-blur-sm rounded-lg p-6 border border-gray-700 mb-12">
        <div class="grid grid-cols-3 gap-4 text-center">
            <div>
                <div class="text-3xl font-bold text-purple-400">{{ number_format($stats['games']) }}</div>
                <div class="text-gray-400">{{ __('home.stats_games') }}</div>
            </div>
            <div>
                <div class="text-3xl font-bold text-purple-400">{{ number_format($stats['translations']) }}</div>
                <div class="text-gray-400">{{ __('home.stats_translations') }}</div>
            </div>
            <div>
                <div class="text-3xl font-bold text-purple-400">{{ number_format($stats['users']) }}</div>
                <div class="text-gray-400">{{ __('home.stats_users') }}</div>
            </div>
        </div>
    </div>
    @endif

    <!-- Popular Games -->
    @if($popularGames->count() > 0)
    <div class="mb-12">
        <h2 class="text-2xl font-bold text-white mb-6 flex items-center">
            <i class="fas fa-fire text-purple-400 mr-2"></i>
            {{ __('home.popular_games') }}
        </h2>
        <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-4">
            @foreach($popularGames as $game)
            <a href="{{ route('games.show', $game) }}" class="bg-gray-800 rounded-lg p-4 border border-gray-700 hover:border-purple-500 transition block">
                <div class="flex items-start space-x-3">
                    @if($game->image_url)
                    <img src="{{ $game->image_url }}" alt="{{ $game->name }}" class="w-12 h-16 object-cover rounded">
                    @else
                    <div class="w-12 h-16 bg-gray-700 rounded flex items-center justify-center">
                        <i class="fas fa-gamepad text-gray-500"></i>
                    </div>
                    @endif
                    <div class="flex-1 min-w-0">
                        <h3 class="font-semibold text-white truncate">{{ $game->name }}</h3>
                        <div class="text-sm text-gray-400">
                            {{ trans_choice('home.translations_count', $game->translations_count, ['count' => $game->translations_count]) }}
                        </div>
                        @if(!empty($game->target_languages))
                        <div class="flex flex-wrap gap-1 mt-1">
                            @php
                                $maxFlags = 5;
                                $languages = $game->target_languages;
                                $displayLanguages = array_slice($languages, 0, $maxFlags);
                                $remaining = count($languages) - $maxFlags;
                            @endphp
                            @foreach($displayLanguages as $lang)
                                <span class="text-base" title="{{ $lang }}">@langflag($lang)</span>
                            @endforeach
                            @if($remaining > 0)
                                <span class="text-xs text-gray-500 ml-1">+{{ $remaining }}</span>
                            @endif
                        </div>
                        @endif
                    </div>
                </div>
            </a>
            @endforeach
        </div>
    </div>
    @endif

    <!-- Latest Translations -->
    @if($latestTranslations->count() > 0)
    <div class="mb-12">
        <h2 class="text-2xl font-bold text-white mb-6 flex items-center">
            <i class="fas fa-clock text-purple-400 mr-2"></i>
            {{ __('home.latest_translations') }}
        </h2>
        <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-4">
            @foreach($latestTranslations as $translation)
            <a href="{{ route('games.show', $translation->game) }}" class="bg-gray-800 rounded-lg p-4 border border-gray-700 hover:border-purple-500 transition block">
                <div class="flex items-start space-x-3">
                    @if($translation->game->image_url)
                    <img src="{{ $translation->game->image_url }}" alt="{{ $translation->game->name }}" class="w-12 h-16 object-cover rounded">
                    @else
                    <div class="w-12 h-16 bg-gray-700 rounded flex items-center justify-center">
                        <i class="fas fa-gamepad text-gray-500"></i>
                    </div>
                    @endif
                    <div class="flex-1 min-w-0">
                        <h3 class="font-semibold text-white truncate">{{ $translation->game->name }}</h3>
                        <div class="text-sm text-gray-400 flex items-center gap-1">
                            <span>@langflag($translation->source_language)</span>
                            <i class="fas fa-arrow-right text-xs text-gray-600"></i>
                            <span>@langflag($translation->target_language)</span>
                        </div>
                        <div class="text-xs text-gray-500 mt-1">
                            {{ $translation->user->name ?? '[Deleted]' }} · {{ $translation->updated_at->diffForHumans() }}
                        </div>
                        <div class="mt-2">
                            <x-progress-bar :translation="$translation" />
                            <div class="flex items-center gap-2 text-xs text-gray-500 mt-1">
                                <span class="flex items-center gap-1">
                                    <span class="w-1.5 h-1.5 bg-green-500 rounded-full"></span>
                                    {{ $translation->human_count }}
                                </span>
                                <span class="flex items-center gap-1">
                                    <span class="w-1.5 h-1.5 bg-blue-500 rounded-full"></span>
                                    {{ $translation->validated_count }}
                                </span>
                                <span class="flex items-center gap-1">
                                    <span class="w-1.5 h-1.5 bg-orange-500 rounded-full"></span>
                                    {{ $translation->ai_count }}
                                </span>
                                <span class="text-gray-600">•</span>
                                <span>{{ number_format($translation->line_count) }} {{ __('my_translations.lines') }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </a>
            @endforeach
        </div>
    </div>
    @endif
@endsection
