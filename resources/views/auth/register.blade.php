@extends('layouts.app')

@section('title', __('auth.register_title') . ' - UnityGameTranslator')

@section('content')
<div class="max-w-md mx-auto mt-16">
    <div class="glass-card rounded-xl p-8 shadow-2xl">
        <h1 class="text-2xl font-bold text-center mb-2">{{ __('auth.register_title') }}</h1>
        <p class="text-gray-400 text-center text-sm mb-6">{{ __('auth.register_intro') }}</p>

        <div class="bg-blue-900/50 border border-blue-700 text-blue-100 rounded-lg p-4 mb-6 text-sm">
            <i class="fas fa-shield-halved mr-1"></i>
            {{ __('auth.register_privacy') }}
        </div>

        <div class="bg-yellow-900/40 border border-yellow-700 text-yellow-100 rounded-lg p-4 mb-6 text-sm">
            <i class="fas fa-triangle-exclamation mr-1"></i>
            {{ __('auth.register_warning') }}
        </div>

        <form method="POST" action="{{ route('local.register.post') }}" class="space-y-4">
            @csrf
            <div>
                <label class="block text-sm text-gray-300 mb-1" for="reg-username">{{ __('auth.username') }}</label>
                <input id="reg-username" type="text" name="username" required maxlength="24" value="{{ old('username') }}"
                       autocomplete="username" placeholder="{{ __('auth.username_placeholder') }}"
                       class="w-full bg-gray-800/80 border border-gray-600/50 rounded-lg px-4 py-2.5 text-white placeholder-gray-500 focus:outline-none focus:border-purple-500">
                <p class="text-gray-500 text-xs mt-1">{{ __('auth.username_format') }}</p>
                @error('username')<p class="text-red-400 text-sm mt-1">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="block text-sm text-gray-300 mb-1" for="reg-password">{{ __('auth.password') }}</label>
                <input id="reg-password" type="password" name="password" required maxlength="200"
                       autocomplete="new-password"
                       class="w-full bg-gray-800/80 border border-gray-600/50 rounded-lg px-4 py-2.5 text-white focus:outline-none focus:border-purple-500">
                <p class="text-gray-500 text-xs mt-1">{{ __('auth.password_min') }}</p>
                @error('password')<p class="text-red-400 text-sm mt-1">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="block text-sm text-gray-300 mb-1" for="reg-password2">{{ __('auth.password_confirm') }}</label>
                <input id="reg-password2" type="password" name="password_confirmation" required maxlength="200"
                       autocomplete="new-password"
                       class="w-full bg-gray-800/80 border border-gray-600/50 rounded-lg px-4 py-2.5 text-white focus:outline-none focus:border-purple-500">
            </div>
            <button type="submit" class="w-full bg-purple-600 hover:bg-purple-700 text-white font-semibold px-4 py-3 rounded-lg transition">
                {{ __('auth.register_button') }}
            </button>
        </form>

        <p class="text-gray-500 text-sm mt-6 text-center">
            <a href="{{ route('login') }}" class="text-purple-400 hover:text-purple-300 transition">
                <i class="fas fa-arrow-left mr-1"></i> {{ __('auth.back_to_login') }}
            </a>
        </p>
    </div>
</div>
@endsection
