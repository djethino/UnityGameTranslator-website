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

        <x-auth-methods :redirect="request('redirect')" />

        <p class="text-gray-500 text-sm mt-8">
            <a href="{{ route('home') }}" class="text-purple-400 hover:text-purple-300 transition">
                <i class="fas fa-arrow-left mr-1"></i> {{ __('auth.back_to_home') }}
            </a>
        </p>
    </div>
</div>
@endsection
