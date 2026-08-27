@extends('layouts.app')

@section('title', __('link.title') . ' - UnityGameTranslator')

{{--
    Where the mod sends somebody to connect their account.

    🔴 **This page was written in English and never translated**, while the rest of the site speaks
    nineteen languages — and it is precisely the page a player reaches FROM THEIR GAME, whatever
    language they play in.

    🔴 **And it listed the providers itself**, so when local accounts arrived they were added to
    /login and not here: somebody with a username and a password had no way in, on the one screen
    the mod points at. The list now lives in ONE component, used by both.
--}}

@section('content')
<div class="max-w-md mx-auto mt-16">
    <div class="bg-gray-800 rounded-lg p-8 border border-gray-700 text-center">
        <h1 class="text-2xl font-bold mb-2">{{ __('link.title') }}</h1>
        <p class="text-gray-400 mb-6">{{ __('link.intro') }}</p>

        @if(session('success'))
            <div class="bg-green-900 border border-green-700 text-green-100 px-4 py-4 rounded mb-6">
                <i class="fas fa-check-circle mr-2"></i>
                {{ session('success') }}
            </div>
            <a href="{{ route('home') }}" class="inline-block bg-purple-600 hover:bg-purple-700 text-white px-6 py-3 rounded-lg">
                <i class="fas fa-home mr-2"></i> {{ __('auth.back_to_home') }}
            </a>
        @else
            @auth
                <form method="POST" action="{{ route('link.validate') }}" class="space-y-6">
                    @csrf

                    <div>
                        <label for="code" class="block text-sm font-medium text-gray-300 mb-2">
                            {{ __('link.code_label') }}
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
                        {{-- 🔴 At the field, because the field is the attack: a code arrives by
                             message with a reason to type it, and typing it hands an access to
                             whoever sent it. Said here, where the hand is, not in a footer. --}}
                        <p class="mt-2 text-xs text-amber-200/90">{{ __('link.phishing') }}</p>
                    </div>

                    {{-- The name of the machine, and the only signal that will still mean something
                         in two years: it needs no client update, it survives a reinstall, and it is
                         what groups fifty lines into three on the Linked devices screen.

                         ⚠ Suggestions through a native <datalist>, never a pre-filled value. A
                         field already reading "Living room PC" gets accepted without a thought on
                         the one day it matters — the day a game is linked at a friend's place. --}}
                    <div class="text-left">
                        <label for="device_label" class="block text-sm font-medium text-gray-300 mb-2">
                            {{ __('link.device_label') }}
                        </label>
                        <input type="text" id="device_label" name="device_label" maxlength="60"
                               list="known-devices" autocomplete="off"
                               placeholder="{{ __('link.device_placeholder') }}"
                               class="w-full px-4 py-3 bg-gray-700 border border-gray-600 rounded-lg text-white focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent">
                        @if($devices->isNotEmpty())
                        <datalist id="known-devices">
                            @foreach($devices as $device)
                                <option value="{{ $device }}"></option>
                            @endforeach
                        </datalist>
                        @endif
                        <p class="text-xs text-gray-500 mt-1">{{ __('link.device_hint') }}</p>
                    </div>

                    <button type="submit" class="w-full bg-purple-600 hover:bg-purple-700 text-white px-6 py-3 rounded-lg font-medium transition-colors">
                        <i class="fas fa-link mr-2"></i> {{ __('link.submit') }}
                    </button>
                </form>

                <div class="mt-8 pt-6 border-t border-gray-700 space-y-2 text-left">
                    <p class="text-gray-400 text-sm">
                        <i class="fas fa-info-circle mr-1"></i>
                        {{ __('link.expires') }}
                    </p>
                    {{-- Stated before, reported after. The code and the name arrive in one POST, so
                         there is no moment in between to ask — but there is one to decide not to. --}}
                    <p class="text-gray-400 text-sm">
                        <i class="fas fa-rotate mr-1"></i>
                        {{ __('link.rule_replaces') }}
                    </p>
                    <p class="text-gray-500 text-sm">
                        <a href="{{ route('profile.connections') }}" class="text-purple-400 hover:text-purple-300">
                            {{ __('connections.page_title') }}
                        </a>
                    </p>
                </div>
            @else
                <div class="bg-blue-900 border border-blue-700 text-blue-100 px-4 py-4 rounded mb-6">
                    <i class="fas fa-info-circle mr-2"></i>
                    {{ __('link.sign_in_first') }}
                </div>

                {{-- ⚠ Comes back HERE once signed in: the code is still on screen in the game, and
                     sending somebody to the home page with it in their hand would make them find
                     this page again by themselves. --}}
                <x-auth-methods :redirect="route('link')" />
            @endauth
        @endif

        <p class="text-gray-500 text-sm mt-8">
            <a href="{{ route('home') }}" class="text-purple-400 hover:text-purple-300">
                <i class="fas fa-arrow-left mr-1"></i> {{ __('auth.back_to_home') }}
            </a>
        </p>
    </div>
</div>
@endsection
