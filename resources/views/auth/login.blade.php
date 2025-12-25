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
            <a href="{{ route('auth.redirect', 'steam') }}" class="block w-full bg-gray-800 hover:bg-gray-900 text-white px-6 py-3 rounded-lg border border-gray-600">
                <i class="fab fa-steam mr-2"></i> {{ __('auth.continue_with', ['provider' => 'Steam']) }}
            </a>
            <a href="{{ route('auth.redirect', 'epicgames') }}" class="flex items-center justify-center w-full bg-black hover:bg-gray-900 text-white px-6 py-3 rounded-lg border border-gray-600">
                <svg class="w-5 h-5 mr-2" viewBox="0 0 24 24" fill="currentColor"><path d="M3.537 0C2.165 0 1.66.506 1.66 1.879V18.44a4.262 4.262 0 00.04.576c.02.126.044.25.073.373a2.396 2.396 0 00.21.553 2.01 2.01 0 00.153.247c.06.08.125.158.194.232a1.616 1.616 0 00.293.263c.077.058.158.113.242.165.083.05.17.096.26.139.088.041.18.078.275.111.095.033.192.06.292.083.098.023.199.04.303.052.103.013.209.02.317.02h18.06c.108 0 .214-.007.317-.02.104-.012.205-.029.303-.052.1-.023.197-.05.292-.083.094-.033.186-.07.274-.111.09-.043.177-.089.26-.139.084-.052.165-.107.242-.165a1.616 1.616 0 00.293-.263c.069-.074.134-.152.194-.232a2.01 2.01 0 00.153-.247 2.396 2.396 0 00.21-.553c.029-.123.053-.247.073-.373.02-.126.033-.253.04-.576V1.879C23.34.506 22.835 0 21.463 0H3.537zm13.147 3.508h2.316v10.317h-2.316V3.508zm-9.443.008h5.893v1.962H9.557v2.375h3.207v1.846H9.557v2.376h3.577v1.962H7.241V3.516zM3.757 3.508h2.316v10.317H3.757V3.508z"/></svg>
                {{ __('auth.continue_with', ['provider' => 'Epic Games']) }}
            </a>
            <a href="{{ route('auth.redirect', 'discord') }}" class="block w-full bg-indigo-600 hover:bg-indigo-700 text-white px-6 py-3 rounded-lg">
                <i class="fab fa-discord mr-2"></i> {{ __('auth.continue_with', ['provider' => 'Discord']) }}
            </a>
            <a href="{{ route('auth.redirect', 'twitch') }}" class="block w-full bg-purple-600 hover:bg-purple-700 text-white px-6 py-3 rounded-lg">
                <i class="fab fa-twitch mr-2"></i> {{ __('auth.continue_with', ['provider' => 'Twitch']) }}
            </a>
            <a href="{{ route('auth.redirect', 'github') }}" class="block w-full bg-gray-700 hover:bg-gray-600 text-white px-6 py-3 rounded-lg">
                <i class="fab fa-github mr-2"></i> {{ __('auth.continue_with', ['provider' => 'GitHub']) }}
            </a>
            <a href="{{ route('auth.redirect', 'google') }}" class="block w-full bg-red-600 hover:bg-red-700 text-white px-6 py-3 rounded-lg">
                <i class="fab fa-google mr-2"></i> {{ __('auth.continue_with', ['provider' => 'Google']) }}
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
