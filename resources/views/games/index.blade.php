@extends('layouts.app')

@section('title', 'Game Translations - Free AI Translation for Unity Games')

@section('description', 'Browse and download free translations for Unity games. Automatic AI translation available. Community-powered game localization with no API costs.')

@section('content')
<div class="mb-8">
    <h1 class="text-3xl font-bold mb-6">{{ __('games.browse') }}</h1>

    <form action="{{ route('games.index') }}" method="GET" class="bg-gray-800 rounded-lg p-6 mb-8">
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-400 mb-2">{{ __('games.search_game') }}</label>
                <input type="text" name="q" value="{{ request('q') }}"
                    placeholder="{{ __('games.game_name_placeholder') }}"
                    class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:ring-purple-500 focus:border-purple-500">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-400 mb-2">{{ __('games.target_language') }}</label>
                <select name="target" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white">
                    <option value="">{{ __('games.all') }}</option>
                    @foreach($targetLanguages as $lang)
                        <option value="{{ $lang }}" {{ request('target') == $lang ? 'selected' : '' }}>{{ $lang }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-400 mb-2">{{ __('games.source_language') }}</label>
                <select name="source" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white">
                    <option value="">{{ __('games.all') }}</option>
                    @foreach($sourceLanguages as $lang)
                        <option value="{{ $lang }}" {{ request('source') == $lang ? 'selected' : '' }}>{{ $lang }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex items-end">
                <button type="submit" class="w-full bg-purple-600 hover:bg-purple-700 text-white px-6 py-2 rounded-lg">
                    <i class="fas fa-search mr-2"></i> {{ __('common.search') }}
                </button>
            </div>
        </div>
    </form>

    @if($games->isEmpty())
        <div class="text-center py-12 text-gray-400">
            <i class="fas fa-gamepad text-6xl mb-4"></i>
            <p class="text-xl">{{ __('home.no_games') }}</p>
            @auth
                <a href="{{ route('translations.create') }}" class="inline-block mt-4 bg-purple-600 hover:bg-purple-700 text-white px-6 py-3 rounded-lg">
                    <i class="fas fa-upload mr-2"></i> {{ __('games.upload_first') }}
                </a>
            @endauth
        </div>
    @else
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6 gap-4">
            @foreach($games as $game)
                <a href="{{ route('games.show', $game) }}" class="bg-gray-800 rounded-lg overflow-hidden hover:bg-gray-750 transition border border-gray-700 hover:border-purple-500 group">
                    <div class="aspect-[3/4] bg-gray-700 relative">
                        @if($game->image_url)
                            <img src="{{ $game->image_url }}" alt="{{ $game->name }}" class="w-full h-full object-cover">
                        @else
                            <div class="w-full h-full flex items-center justify-center">
                                <i class="fas fa-gamepad text-4xl text-gray-500"></i>
                            </div>
                        @endif
                        <div class="absolute bottom-0 left-0 right-0 bg-gradient-to-t from-black/80 to-transparent p-2">
                            <span class="text-xs bg-purple-600 px-2 py-0.5 rounded">{{ $game->translations_count }}</span>
                        </div>
                    </div>
                    <div class="p-3">
                        <h2 class="font-semibold text-sm truncate group-hover:text-purple-400 transition">{{ $game->name }}</h2>
                    </div>
                </a>
            @endforeach
        </div>

        <div class="mt-8">
            {{ $games->withQueryString()->links() }}
        </div>
    @endif
</div>
@endsection
