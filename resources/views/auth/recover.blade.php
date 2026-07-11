@extends('layouts.app')

@section('title', __('auth.recover_title') . ' - UnityGameTranslator')

@section('content')
<div class="max-w-md mx-auto mt-16">
    <div class="glass-card rounded-xl p-8 shadow-2xl">
        <h1 class="text-2xl font-bold text-center mb-2">{{ __('auth.recover_title') }}</h1>
        <p class="text-gray-400 text-center text-sm mb-6">{{ __('auth.recover_intro') }}</p>

        <form method="POST" action="{{ route('local.recover.post') }}" class="space-y-4">
            @csrf
            <div>
                <label class="block text-sm text-gray-300 mb-1" for="rec-username">{{ __('auth.username') }}</label>
                <input id="rec-username" type="text" name="username" required maxlength="24" value="{{ old('username') }}"
                       autocomplete="username"
                       class="w-full bg-gray-800/80 border border-gray-600/50 rounded-lg px-4 py-2.5 text-white focus:outline-none focus:border-purple-500">
            </div>
            <div>
                <label class="block text-sm text-gray-300 mb-1" for="rec-code">{{ __('auth.recovery_code') }}</label>
                <input id="rec-code" type="text" name="recovery_code" required maxlength="32" placeholder="XXXX-XXXX-XXXX"
                       class="w-full bg-gray-800/80 border border-gray-600/50 rounded-lg px-4 py-2.5 text-white font-mono placeholder-gray-600 focus:outline-none focus:border-purple-500">
                @error('recovery_code')<p class="text-red-400 text-sm mt-1">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="block text-sm text-gray-300 mb-1" for="rec-password">{{ __('auth.new_password') }}</label>
                <input id="rec-password" type="password" name="password" required maxlength="200" autocomplete="new-password"
                       class="w-full bg-gray-800/80 border border-gray-600/50 rounded-lg px-4 py-2.5 text-white focus:outline-none focus:border-purple-500">
                <p class="text-gray-500 text-xs mt-1">{{ __('auth.password_min') }}</p>
                @error('password')<p class="text-red-400 text-sm mt-1">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="block text-sm text-gray-300 mb-1" for="rec-password2">{{ __('auth.password_confirm') }}</label>
                <input id="rec-password2" type="password" name="password_confirmation" required maxlength="200" autocomplete="new-password"
                       class="w-full bg-gray-800/80 border border-gray-600/50 rounded-lg px-4 py-2.5 text-white focus:outline-none focus:border-purple-500">
            </div>
            <button type="submit" class="w-full bg-purple-600 hover:bg-purple-700 text-white font-semibold px-4 py-3 rounded-lg transition">
                {{ __('auth.recover_button') }}
            </button>
        </form>

        <p class="text-gray-400 text-xs mt-4">{{ __('auth.recover_note') }}</p>

        <p class="text-gray-500 text-sm mt-6 text-center">
            <a href="{{ route('login') }}" class="text-purple-400 hover:text-purple-300 transition">
                <i class="fas fa-arrow-left mr-1"></i> {{ __('auth.back_to_login') }}
            </a>
        </p>
    </div>
</div>
@endsection
