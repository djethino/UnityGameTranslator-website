@extends('layouts.app')

@section('title', 'UnityGameTranslator - ' . __('home.hero_description'))

@section('content')
    <!-- Beta Banner -->
    <div class="bg-yellow-900/50 border border-yellow-700 text-yellow-200 px-4 py-3 rounded-lg mb-6 flex items-center justify-center">
        <i class="fas fa-flask mr-2"></i>
        <span>{{ __('home.beta_banner') }}</span>
    </div>

    <!-- Hero Section -->
    <div class="text-center py-12 mb-12">
        <h1 class="text-4xl md:text-5xl font-bold text-white mb-4">
            <i class="fas fa-language text-purple-400 mr-3"></i>UnityGameTranslator
        </h1>
        <p class="text-xl text-gray-300 max-w-3xl mx-auto mb-8">
            {{ __('home.hero_description') }}
        </p>
        <div class="flex flex-wrap justify-center gap-4">
            <a href="{{ route('games.index') }}" class="bg-purple-600 hover:bg-purple-700 text-white font-semibold px-6 py-3 rounded-lg transition flex items-center">
                <i class="fas fa-gamepad mr-2"></i>
                {{ __('home.view_games') }}
            </a>
            <a href="{{ route('docs') }}" class="bg-gray-700 hover:bg-gray-600 text-white font-semibold px-6 py-3 rounded-lg transition flex items-center">
                <i class="fas fa-book mr-2"></i>
                {{ __('home.view_docs') }}
            </a>
        </div>
    </div>

    <!-- How it works -->
    <div class="mb-12">
        <h2 class="text-2xl font-bold text-white text-center mb-8">
            <i class="fas fa-cogs text-purple-400 mr-2"></i>
            {{ __('home.how_it_works') }}
        </h2>
        <div class="grid md:grid-cols-2 gap-6">
            <!-- The Mod -->
            <div class="bg-gray-800 rounded-lg p-6 border border-gray-700">
                <h3 class="text-xl font-bold text-purple-400 mb-3 flex items-center">
                    <i class="fas fa-puzzle-piece mr-2"></i>
                    {{ __('home.the_mod') }}
                </h3>
                <p class="text-gray-300 mb-4">{{ __('home.mod_desc') }}</p>
                <ul class="space-y-2 text-gray-400">
                    <li class="flex items-center">
                        <i class="fas fa-check text-green-400 mr-2"></i>
                        {{ __('home.mod_feature_1') }}
                    </li>
                    <li class="flex items-center">
                        <i class="fas fa-check text-green-400 mr-2"></i>
                        {{ __('home.mod_feature_2') }}
                    </li>
                    <li class="flex items-center">
                        <i class="fas fa-check text-green-400 mr-2"></i>
                        {{ __('home.mod_feature_3') }}
                    </li>
                </ul>
            </div>
            <!-- The Website -->
            <div class="bg-gray-800 rounded-lg p-6 border border-gray-700">
                <h3 class="text-xl font-bold text-purple-400 mb-3 flex items-center">
                    <i class="fas fa-globe mr-2"></i>
                    {{ __('home.the_website') }}
                </h3>
                <p class="text-gray-300 mb-4">{{ __('home.website_desc') }}</p>
                <ul class="space-y-2 text-gray-400">
                    <li class="flex items-center">
                        <i class="fas fa-check text-green-400 mr-2"></i>
                        {{ __('home.website_feature_1') }}
                    </li>
                    <li class="flex items-center">
                        <i class="fas fa-check text-green-400 mr-2"></i>
                        {{ __('home.website_feature_2') }}
                    </li>
                    <li class="flex items-center">
                        <i class="fas fa-check text-green-400 mr-2"></i>
                        {{ __('home.website_feature_3') }}
                    </li>
                </ul>
            </div>
        </div>
    </div>

    <!-- Stats -->
    <div class="bg-gray-800 rounded-lg p-6 border border-gray-700 mb-12">
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
                    @if($translation->game->cover_url)
                    <img src="{{ $translation->game->cover_url }}" alt="{{ $translation->game->name }}" class="w-12 h-16 object-cover rounded">
                    @else
                    <div class="w-12 h-16 bg-gray-700 rounded flex items-center justify-center">
                        <i class="fas fa-gamepad text-gray-500"></i>
                    </div>
                    @endif
                    <div class="flex-1 min-w-0">
                        <h3 class="font-semibold text-white truncate">{{ $translation->game->name }}</h3>
                        <div class="text-sm text-gray-400">
                            {{ strtoupper($translation->source_language) }} → {{ strtoupper($translation->target_language) }}
                        </div>
                        <div class="text-xs text-gray-500 mt-1">
                            {{ $translation->user->name ?? '[Deleted]' }} · {{ $translation->created_at->diffForHumans() }}
                        </div>
                    </div>
                </div>
            </a>
            @endforeach
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
                    @if($game->cover_url)
                    <img src="{{ $game->cover_url }}" alt="{{ $game->name }}" class="w-12 h-16 object-cover rounded">
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
                    </div>
                </div>
            </a>
            @endforeach
        </div>
    </div>
    @endif

    <!-- Search Bar -->
    <div class="bg-gray-800 rounded-lg p-6 border border-gray-700">
        <form action="{{ route('games.index') }}" method="GET" class="flex gap-4">
            <input type="text" name="q" placeholder="{{ __('home.search_games') }}"
                   class="flex-1 bg-gray-700 border border-gray-600 rounded-lg px-4 py-3 text-white placeholder-gray-400 focus:outline-none focus:border-purple-500">
            <button type="submit" class="bg-purple-600 hover:bg-purple-700 text-white font-semibold px-6 py-3 rounded-lg transition">
                <i class="fas fa-search mr-2"></i>
                {{ __('home.search_redirect') }}
            </button>
        </form>
    </div>
@endsection
