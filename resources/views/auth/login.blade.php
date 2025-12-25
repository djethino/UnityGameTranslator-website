@extends('layouts.app')

@section('title', __('auth.sign_in') . ' - UnityGameTranslator')

@section('content')
<div class="max-w-md mx-auto mt-16">
    <div class="bg-gray-800 rounded-lg p-8 border border-gray-700 text-center">
        <h1 class="text-2xl font-bold mb-6">{{ __('auth.sign_in') }}</h1>

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

        <p class="text-gray-400 mb-8">{{ __('auth.choose_method') }}</p>

        <div class="space-y-4">
            <a href="{{ route('auth.redirect', 'google') }}" class="block w-full bg-red-600 hover:bg-red-700 text-white px-6 py-3 rounded-lg">
                <i class="fab fa-google mr-2"></i> {{ __('auth.continue_with', ['provider' => 'Google']) }}
            </a>
            <a href="{{ route('auth.redirect', 'discord') }}" class="block w-full bg-indigo-600 hover:bg-indigo-700 text-white px-6 py-3 rounded-lg">
                <i class="fab fa-discord mr-2"></i> {{ __('auth.continue_with', ['provider' => 'Discord']) }}
            </a>
            <a href="{{ route('auth.redirect', 'github') }}" class="block w-full bg-gray-700 hover:bg-gray-600 text-white px-6 py-3 rounded-lg">
                <i class="fab fa-github mr-2"></i> {{ __('auth.continue_with', ['provider' => 'GitHub']) }}
            </a>
            <a href="{{ route('auth.redirect', 'twitch') }}" class="block w-full bg-purple-600 hover:bg-purple-700 text-white px-6 py-3 rounded-lg">
                <i class="fab fa-twitch mr-2"></i> {{ __('auth.continue_with', ['provider' => 'Twitch']) }}
            </a>
            <a href="{{ route('auth.redirect', 'steam') }}" class="block w-full bg-gray-800 hover:bg-gray-900 text-white px-6 py-3 rounded-lg border border-gray-600">
                <i class="fab fa-steam mr-2"></i> {{ __('auth.continue_with', ['provider' => 'Steam']) }}
            </a>
            <a href="{{ route('auth.redirect', 'epicgames') }}" class="flex items-center justify-center w-full bg-black hover:bg-gray-900 text-white px-6 py-3 rounded-lg border border-gray-600">
                <img src="https://www.epicgames.com/favicon.ico" alt="" class="w-5 h-5 mr-2"> {{ __('auth.continue_with', ['provider' => 'Epic Games']) }}
            </a>
        </div>

        <p class="text-gray-500 text-sm mt-8">
            <a href="{{ route('home') }}" class="text-purple-400 hover:text-purple-300">
                <i class="fas fa-arrow-left mr-1"></i> {{ __('auth.back_to_home') }}
            </a>
        </p>
    </div>
</div>
@endsection
