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

            {{-- The one moment naming is worth offering: it just worked, the access exists, and the
                 screen behind this link SHOWS what would be named. Offered, never demanded — the
                 form no longer asks, and this must not ask either. --}}
            <p class="text-sm mt-6">
                <a href="{{ route('profile.connections') }}" class="text-purple-400 hover:text-purple-300">
                    <i class="fas fa-laptop mr-1"></i> {{ __('connections.page_title') }}
                </a>
            </p>
        @elseif(auth()->check() && $pending)
            {{-- 🔴 The second step: what this code stands for, before anything is linked. A person
                 handed a code by somebody else sees a game they do not own, a program they are not
                 in, or a code created long before they started — and has a way out that is not
                 the Link button. Every fact shown is what the program declared when it asked for
                 the code; nothing is guessed. --}}
            @php
                $program = match ($pending->client_kind) {
                    'mod' => __('connections.client_mod'),
                    'manager' => __('connections.client_manager'),
                    'other' => __('connections.client_other'),
                    default => __('connections.client_unknown'),
                };
                $minutes = max(1, (int) $pending->created_at->diffInMinutes(now()));
            @endphp

            <div class="text-left bg-gray-900/60 border border-gray-700 rounded-lg p-4 mb-4">
                <p class="text-sm text-gray-400 mb-3">{{ __('link.confirm_title') }}</p>
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between gap-4">
                        <dt class="text-gray-400">{{ __('link.confirm_program') }}</dt>
                        <dd class="text-gray-100 text-right">
                            <i class="fas {{ $pending->client_kind === 'manager' ? 'fa-toolbox' : ($pending->client_kind === 'mod' ? 'fa-gamepad' : 'fa-circle-question') }} mr-1 text-gray-500"></i>
                            {{ $program }}@if($pending->client_version) {{ $pending->client_version }}@endif
                        </dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-gray-400">{{ __('link.confirm_game') }}</dt>
                        <dd class="text-gray-100 text-right">
                            @if($pending->game_name)
                                {{ $pending->game_name }}
                            @else
                                <span class="text-gray-400">{{ __('connections.game_not_recorded') }}</span>
                            @endif
                        </dd>
                    </div>
                    <div class="flex justify-end">
                        <dd class="text-gray-400">{{ __('link.confirm_created', ['minutes' => $minutes]) }}</dd>
                    </div>
                </dl>
            </div>

            <p class="text-xs text-amber-200/90 mb-6 text-left">{{ __('link.confirm_warning') }}</p>

            <form method="POST" action="{{ route('link.validate') }}" class="space-y-3">
                @csrf
                <input type="hidden" name="code" value="{{ $pending->user_code }}">
                <input type="hidden" name="confirm" value="1">
                <button type="submit" class="w-full bg-purple-600 hover:bg-purple-700 text-white px-6 py-3 rounded-lg font-medium transition-colors">
                    <i class="fas fa-link mr-2"></i> {{ __('link.submit') }}
                </button>
            </form>
            <p class="mt-3">
                <a href="{{ route('link', ['cancel' => 1]) }}" class="text-gray-400 hover:text-white text-sm">
                    {{ __('common.cancel') }}
                </a>
            </p>
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
                        @if($expired ?? false)
                            {{-- The code ran out while its details were on screen. --}}
                            <p class="mt-2 text-sm text-red-400">{{ __('link.invalid_code') }}</p>
                        @endif
                        {{-- 🔴 At the field, because the field is the attack: a code arrives by
                             message with a reason to type it, and typing it hands an access to
                             whoever sent it. Said here, where the hand is, not in a footer. --}}
                        <p class="mt-2 text-xs text-amber-200/90">{{ __('link.phishing') }}</p>
                    </div>

                    {{-- 🔴 **There was a "Which device is this?" field here, and it is gone.**
                         It asked somebody arriving with a code in hand to invent a name for a
                         thing the page never showed them — jargon, on a blank field, with the list
                         of already-named machines necessarily EMPTY the one time it would have
                         helped: the first link. And it was optional without ever saying so.

                         What made it pointless is that the machine now says which it is on its own
                         (both the mod and the Manager send it), which is what groups the accesses
                         and what makes the cap fire. See the controller.

                         ⚠ Naming did not disappear, it moved to where it means something: the
                         Linked devices screen shows the group, says "One machine, not named yet",
                         and explains that naming it covers every program on it. There you can see
                         what you are naming. --}}
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
