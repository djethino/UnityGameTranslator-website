@extends('layouts.app')

@section('title', 'Link Device - UnityGameTranslator')

@section('content')
<div class="max-w-md mx-auto mt-16">
    <div class="bg-gray-800 rounded-lg p-8 border border-gray-700 text-center">
        <h1 class="text-2xl font-bold mb-2">Link Your Game</h1>
        <p class="text-gray-400 mb-6">Enter the code displayed in your Unity game</p>

        @if(session('success'))
            <div class="bg-green-900 border border-green-700 text-green-100 px-4 py-4 rounded mb-6">
                <i class="fas fa-check-circle mr-2"></i>
                {{ session('success') }}
            </div>
            <a href="{{ route('home') }}" class="inline-block bg-purple-600 hover:bg-purple-700 text-white px-6 py-3 rounded-lg">
                <i class="fas fa-home mr-2"></i> Back to Home
            </a>
        @else
            @auth
                <form method="POST" action="{{ route('link.validate') }}" class="space-y-6">
                    @csrf

                    <div>
                        <label for="code" class="block text-sm font-medium text-gray-300 mb-2">
                            Device Code
                        </label>
                        <input
                            type="text"
                            id="code"
                            name="code"
                            placeholder="ABCD-1234"
                            class="w-full px-4 py-3 bg-gray-700 border border-gray-600 rounded-lg text-center text-2xl font-mono uppercase tracking-wider focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent @error('code') border-red-500 @enderror"
                            required
                            autocomplete="off"
                            maxlength="9"
                            pattern="[A-Za-z]{3,4}-?[0-9]{3,4}"
                        >
                        @error('code')
                            <p class="mt-2 text-sm text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    <button type="submit" class="w-full bg-purple-600 hover:bg-purple-700 text-white px-6 py-3 rounded-lg font-medium transition-colors">
                        <i class="fas fa-link mr-2"></i> Link Device
                    </button>
                </form>

                <div class="mt-8 pt-6 border-t border-gray-700">
                    <p class="text-gray-400 text-sm">
                        <i class="fas fa-info-circle mr-1"></i>
                        The code expires in 15 minutes. If it has expired, restart the linking process in your game.
                    </p>
                </div>
            @else
                <div class="bg-blue-900 border border-blue-700 text-blue-100 px-4 py-4 rounded mb-6">
                    <i class="fas fa-info-circle mr-2"></i>
                    Please sign in first to link your device.
                </div>

                <div class="space-y-3">
                    <a href="{{ route('auth.redirect', 'steam') }}" class="block w-full bg-gray-800 hover:bg-gray-900 text-white px-6 py-3 rounded-lg border border-gray-600">
                        <i class="fab fa-steam mr-2"></i> Sign in with Steam
                    </a>
                    <a href="{{ route('auth.redirect', 'discord') }}" class="block w-full bg-indigo-600 hover:bg-indigo-700 text-white px-6 py-3 rounded-lg">
                        <i class="fab fa-discord mr-2"></i> Sign in with Discord
                    </a>
                    <a href="{{ route('auth.redirect', 'github') }}" class="block w-full bg-gray-700 hover:bg-gray-600 text-white px-6 py-3 rounded-lg">
                        <i class="fab fa-github mr-2"></i> Sign in with GitHub
                    </a>
                    <a href="{{ route('auth.redirect', 'google') }}" class="block w-full bg-red-600 hover:bg-red-700 text-white px-6 py-3 rounded-lg">
                        <i class="fab fa-google mr-2"></i> Sign in with Google
                    </a>
                    <a href="{{ route('auth.redirect', 'twitch') }}" class="block w-full bg-purple-600 hover:bg-purple-700 text-white px-6 py-3 rounded-lg">
                        <i class="fab fa-twitch mr-2"></i> Sign in with Twitch
                    </a>
                </div>
            @endauth
        @endif

        <p class="text-gray-500 text-sm mt-8">
            <a href="{{ route('home') }}" class="text-purple-400 hover:text-purple-300">
                <i class="fas fa-arrow-left mr-1"></i> Back to Home
            </a>
        </p>
    </div>
</div>
@endsection
