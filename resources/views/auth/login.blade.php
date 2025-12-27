@extends('layouts.app')

@section('title', __('auth.sign_in') . ' - UnityGameTranslator')

@section('content')
<div class="max-w-md mx-auto mt-16 relative">
    <div class="glass-card rounded-xl p-8 text-center shadow-2xl">
        <!-- Logo -->
        <div class="mb-6">
            <img src="/logo.svg" alt="UnityGameTranslator" class="w-20 h-20 mx-auto mb-4">
            <h1 class="text-2xl font-bold">UnityGameTranslator</h1>
        </div>

        <p class="text-gray-300 text-lg mb-2">{{ __('auth.sign_in') }}</p>

        @if(request('action'))
            <div class="bg-blue-900 border border-blue-700 text-blue-100 px-4 py-3 rounded mb-6 text-left">
                <i class="fas fa-info-circle mr-2"></i>
                @switch(request('action'))
                    @case('vote')
                        {{ __('auth.login_to_vote') }}
                        @break
                    @case('report')
                        {{ __('auth.login_to_report') }}
                        @break
                    @case('upload')
                        {{ __('auth.login_to_upload') }}
                        @break
                @endswitch
            </div>
        @endif

        <p class="text-gray-400 mb-6">{{ __('auth.choose_method') }}</p>

        @php
            // Pass redirect parameter to OAuth links if present
            $redirectParam = request('redirect') ? '?redirect=' . urlencode(request('redirect')) : '';
        @endphp

        <!-- Gaming platforms -->
        <div class="grid grid-cols-2 gap-3 mb-4">
            <a href="{{ route('auth.redirect', 'steam') }}{{ $redirectParam }}" class="flex items-center justify-center gap-2 bg-gray-800/80 hover:bg-gray-700 text-white px-4 py-3 rounded-lg border border-gray-600/50 transition hover:-translate-y-0.5">
                <i class="fab fa-steam text-lg"></i>
                <span class="text-sm font-medium">Steam</span>
            </a>
            <a href="{{ route('auth.redirect', 'epicgames') }}{{ $redirectParam }}" class="flex items-center justify-center gap-2 bg-black/80 hover:bg-gray-900 text-white px-4 py-3 rounded-lg border border-gray-600/50 transition hover:-translate-y-0.5">
                <img src="https://cdn.simpleicons.org/epicgames/white" alt="" class="w-4 h-4">
                <span class="text-sm font-medium">Epic</span>
            </a>
        </div>

        <!-- Other providers -->
        <div class="grid grid-cols-2 gap-3">
            <a href="{{ route('auth.redirect', 'discord') }}{{ $redirectParam }}" class="flex items-center justify-center gap-2 bg-indigo-600/90 hover:bg-indigo-600 text-white px-4 py-3 rounded-lg transition hover:-translate-y-0.5">
                <i class="fab fa-discord text-lg"></i>
                <span class="text-sm font-medium">Discord</span>
            </a>
            <a href="{{ route('auth.redirect', 'twitch') }}{{ $redirectParam }}" class="flex items-center justify-center gap-2 bg-purple-600/90 hover:bg-purple-600 text-white px-4 py-3 rounded-lg transition hover:-translate-y-0.5">
                <i class="fab fa-twitch text-lg"></i>
                <span class="text-sm font-medium">Twitch</span>
            </a>
            <a href="{{ route('auth.redirect', 'github') }}{{ $redirectParam }}" class="flex items-center justify-center gap-2 bg-gray-700/90 hover:bg-gray-600 text-white px-4 py-3 rounded-lg transition hover:-translate-y-0.5">
                <i class="fab fa-github text-lg"></i>
                <span class="text-sm font-medium">GitHub</span>
            </a>
            <a href="{{ route('auth.redirect', 'google') }}{{ $redirectParam }}" class="flex items-center justify-center gap-2 bg-red-600/90 hover:bg-red-600 text-white px-4 py-3 rounded-lg transition hover:-translate-y-0.5">
                <i class="fab fa-google text-lg"></i>
                <span class="text-sm font-medium">Google</span>
            </a>
        </div>

        <p class="text-gray-500 text-sm mt-8">
            <a href="{{ route('home') }}" class="text-purple-400 hover:text-purple-300 transition">
                <i class="fas fa-arrow-left mr-1"></i> {{ __('auth.back_to_home') }}
            </a>
        </p>
    </div>
</div>
@endsection
