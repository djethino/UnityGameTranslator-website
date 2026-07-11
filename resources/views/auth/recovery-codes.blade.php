@extends('layouts.app')

@section('title', __('auth.codes_title') . ' - UnityGameTranslator')

@section('content')
<div class="max-w-md mx-auto mt-16">
    <div class="glass-card rounded-xl p-8 shadow-2xl">
        <h1 class="text-2xl font-bold text-center mb-2">
            <i class="fas fa-key mr-2 text-yellow-400"></i>{{ __('auth.codes_title') }}
        </h1>
        <p class="text-gray-300 text-sm text-center mb-6">{{ __('auth.codes_intro') }}</p>

        <div class="bg-red-900/40 border border-red-700 text-red-100 rounded-lg p-4 mb-6 text-sm">
            <i class="fas fa-triangle-exclamation mr-1"></i>
            {{ __('auth.codes_warning') }}
        </div>

        <div class="bg-gray-900 rounded-lg p-4 mb-6 font-mono text-center text-gray-100 space-y-1 select-all">
            @foreach($codes as $code)
                <div>{{ $code }}</div>
            @endforeach
        </div>

        <p class="text-gray-400 text-xs text-center mb-6">{{ __('auth.codes_hint') }}</p>

        <a href="{{ route('home') }}" class="block w-full bg-purple-600 hover:bg-purple-700 text-white font-semibold text-center px-4 py-3 rounded-lg transition">
            {{ __('auth.codes_saved') }}
        </a>
    </div>
</div>
@endsection
