@extends('layouts.app')

@section('title', __('legal.terms_title') . ' - UnityGameTranslator')

@section('content')
<div class="max-w-4xl mx-auto">
    <h1 class="text-3xl font-bold mb-8">{{ __('legal.terms_title') }}</h1>

    <div class="bg-gray-800 rounded-lg p-6 space-y-6">
        <section>
            <h2 class="text-xl font-semibold text-purple-400 mb-3">{{ __('legal.terms_acceptance') }}</h2>
            <p class="text-gray-300">{{ __('legal.terms_acceptance_text') }}</p>
        </section>

        <section>
            <h2 class="text-xl font-semibold text-purple-400 mb-3">{{ __('legal.service_description') }}</h2>
            <p class="text-gray-300">{{ __('legal.service_description_text') }}</p>
        </section>

        <section>
            <h2 class="text-xl font-semibold text-purple-400 mb-3">{{ __('legal.user_content') }}</h2>
            <p class="text-gray-300 mb-3">{{ __('legal.user_content_intro') }}</p>
            <ul class="text-gray-300 list-disc list-inside space-y-1">
                <li>{{ __('legal.content_ownership') }}</li>
                <li>{{ __('legal.content_license') }}</li>
                <li>{{ __('legal.content_responsibility') }}</li>
            </ul>
        </section>

        <section>
            <h2 class="text-xl font-semibold text-purple-400 mb-3">{{ __('legal.prohibited_content') }}</h2>
            <p class="text-gray-300 mb-3">{{ __('legal.prohibited_intro') }}</p>
            <ul class="text-gray-300 list-disc list-inside space-y-1">
                <li>{{ __('legal.prohibited_illegal') }}</li>
                <li>{{ __('legal.prohibited_harmful') }}</li>
                <li>{{ __('legal.prohibited_spam') }}</li>
                <li>{{ __('legal.prohibited_malware') }}</li>
                <li>{{ __('legal.prohibited_copyright') }}</li>
            </ul>
        </section>

        <section>
            <h2 class="text-xl font-semibold text-purple-400 mb-3">{{ __('legal.moderation') }}</h2>
            <p class="text-gray-300">{{ __('legal.moderation_text') }}</p>
        </section>

        <section>
            <h2 class="text-xl font-semibold text-purple-400 mb-3">{{ __('legal.disclaimer') }}</h2>
            <p class="text-gray-300">{{ __('legal.disclaimer_text') }}</p>
        </section>

        <section>
            <h2 class="text-xl font-semibold text-purple-400 mb-3">{{ __('legal.liability') }}</h2>
            <p class="text-gray-300">{{ __('legal.liability_text') }}</p>
        </section>

        <section>
            <h2 class="text-xl font-semibold text-purple-400 mb-3">{{ __('legal.termination') }}</h2>
            <p class="text-gray-300">{{ __('legal.termination_text') }}</p>
        </section>

        <section>
            <h2 class="text-xl font-semibold text-purple-400 mb-3">{{ __('legal.governing_law') }}</h2>
            <p class="text-gray-300">{{ __('legal.governing_law_text') }}</p>
        </section>

        <p class="text-gray-500 text-sm">{{ __('legal.last_updated') }}: {{ now()->format('d/m/Y') }}</p>
    </div>
</div>
@endsection
