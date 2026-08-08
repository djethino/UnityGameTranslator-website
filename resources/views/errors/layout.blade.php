@extends('layouts.app')

{{--
    Shared body for every error page.

    There was no error view at all: Laravel's bare page answered with a code on an empty
    background — no header, no footer, no search, no way back. On a site whose whole promise is
    "find your game", a removed game or a stale shared link ended the visit there.

    An error page owes the reader three things: what happened, that it is not their fault, and
    somewhere to go next. The search field is that somewhere — most people arriving here were
    looking for a game, and the catalogue is one query away.
--}}

@section('title', $title)

@section('content')
<div class="max-w-2xl mx-auto px-4 py-16 text-center">
    <div class="text-7xl font-bold text-purple-500/80 mb-2">{{ $code }}</div>
    <h1 class="text-2xl font-semibold text-white mb-3">{{ $title }}</h1>
    <p class="text-gray-400 mb-8">{{ $message }}</p>

    <form action="{{ route('games.index') }}" method="GET" class="flex gap-2 mb-8">
        <input type="text" name="q" aria-label="{{ __('games.search_game') }}"
            placeholder="{{ __('games.game_name_placeholder') }}"
            class="flex-1 bg-gray-700 text-white px-4 py-2 rounded border border-gray-600 focus:border-purple-500 focus:outline-none">
        <button type="submit" class="bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded">
            <i class="fas fa-search"></i>
        </button>
    </form>

    <div class="flex flex-wrap gap-3 justify-center">
        <a href="{{ route('games.index') }}" class="bg-gray-700 hover:bg-gray-600 text-white px-4 py-2 rounded">
            <i class="fas fa-gamepad mr-2"></i>{{ __('nav.games') }}
        </a>
        <a href="{{ route('docs') }}" class="bg-gray-700 hover:bg-gray-600 text-white px-4 py-2 rounded">
            <i class="fas fa-book mr-2"></i>{{ __('nav.docs') }}
        </a>
        <a href="{{ route('home') }}" class="bg-gray-700 hover:bg-gray-600 text-white px-4 py-2 rounded">
            <i class="fas fa-arrow-left mr-2"></i>{{ __('errors.back_home') }}
        </a>
    </div>
</div>
@endsection
