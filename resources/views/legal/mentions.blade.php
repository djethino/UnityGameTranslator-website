@extends('layouts.app')

@section('title', __('legal.mentions_title') . ' - UnityGameTranslator')

@section('content')
<div class="max-w-4xl mx-auto">
    <h1 class="text-3xl font-bold mb-8">{{ __('legal.mentions_title') }}</h1>

    <div class="bg-gray-800 rounded-lg p-6 space-y-6">
        <section>
            <h2 class="text-xl font-semibold text-purple-400 mb-3">{{ __('legal.editor') }}</h2>
            <ul class="text-gray-300 space-y-1">
                <li><strong>{{ __('legal.name') }}:</strong> AsymptOmatik</li>
                <li><strong>{{ __('legal.status') }}:</strong> {{ __('legal.individual') }}</li>
                <li><strong>{{ __('legal.location') }}:</strong> Paris, France</li>
                <li><strong>{{ __('legal.email') }}:</strong> <a href="mailto:support@unitygametranslator.asymptomatikgames.com" class="text-purple-400 hover:text-purple-300">support@unitygametranslator.asymptomatikgames.com</a></li>
            </ul>
        </section>

        <section>
            <h2 class="text-xl font-semibold text-purple-400 mb-3">{{ __('legal.publication_director') }}</h2>
            <p class="text-gray-300">AsymptOmatik</p>
        </section>

        <section>
            <h2 class="text-xl font-semibold text-purple-400 mb-3">{{ __('legal.hosting') }}</h2>
            <ul class="text-gray-300 space-y-1">
                <li><strong>{{ __('legal.name') }}:</strong> o2switch</li>
                <li><strong>{{ __('legal.address') }}:</strong> 222 Boulevard Gustave Flaubert, 63000 Clermont-Ferrand, France</li>
                <li><strong>{{ __('legal.website') }}:</strong> <a href="https://www.o2switch.fr" target="_blank" rel="noopener" class="text-purple-400 hover:text-purple-300">www.o2switch.fr</a></li>
            </ul>
        </section>

        <section>
            <h2 class="text-xl font-semibold text-purple-400 mb-3">{{ __('legal.intellectual_property') }}</h2>
            <p class="text-gray-300">{{ __('legal.intellectual_property_text') }}</p>
        </section>
    </div>
</div>
@endsection
