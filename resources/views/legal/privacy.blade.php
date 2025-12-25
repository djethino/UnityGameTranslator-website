@extends('layouts.app')

@section('title', __('legal.privacy_title') . ' - UnityGameTranslator')

@section('content')
<div class="max-w-4xl mx-auto">
    <h1 class="text-3xl font-bold mb-8">{{ __('legal.privacy_title') }}</h1>

    <div class="bg-gray-800 rounded-lg p-6 space-y-6">
        <p class="text-gray-300">{{ __('legal.privacy_intro') }}</p>

        <section>
            <h2 class="text-xl font-semibold text-purple-400 mb-3">{{ __('legal.data_collected') }}</h2>
            <p class="text-gray-300 mb-3">{{ __('legal.data_collected_intro') }}</p>
            <ul class="text-gray-300 list-disc list-inside space-y-1">
                <li>{{ __('legal.data_name') }}</li>
                <li>{{ __('legal.data_email') }}</li>
                <li>{{ __('legal.data_avatar') }}</li>
                <li>{{ __('legal.data_provider_id') }}</li>
            </ul>
            <p class="text-gray-300 mt-3">{{ __('legal.data_user_content') }}</p>
            <ul class="text-gray-300 list-disc list-inside space-y-1">
                <li>{{ __('legal.data_translations') }}</li>
                <li>{{ __('legal.data_votes') }}</li>
                <li>{{ __('legal.data_reports') }}</li>
            </ul>
        </section>

        <section>
            <h2 class="text-xl font-semibold text-purple-400 mb-3">{{ __('legal.data_usage') }}</h2>
            <ul class="text-gray-300 list-disc list-inside space-y-1">
                <li>{{ __('legal.usage_auth') }}</li>
                <li>{{ __('legal.usage_display') }}</li>
                <li>{{ __('legal.usage_contact') }}</li>
                <li>{{ __('legal.usage_moderation') }}</li>
            </ul>
        </section>

        <section>
            <h2 class="text-xl font-semibold text-purple-400 mb-3">{{ __('legal.data_sharing') }}</h2>
            <p class="text-gray-300">{{ __('legal.data_sharing_text') }}</p>
        </section>

        <section>
            <h2 class="text-xl font-semibold text-purple-400 mb-3">{{ __('legal.data_retention') }}</h2>
            <p class="text-gray-300">{{ __('legal.data_retention_text') }}</p>
        </section>

        <section>
            <h2 class="text-xl font-semibold text-purple-400 mb-3">{{ __('legal.cookies') }}</h2>
            <p class="text-gray-300 mb-3">{{ __('legal.cookies_text') }}</p>
            <ul class="text-gray-300 list-disc list-inside space-y-1">
                <li><strong>laravel_session:</strong> {{ __('legal.cookie_session') }}</li>
                <li><strong>XSRF-TOKEN:</strong> {{ __('legal.cookie_csrf') }}</li>
            </ul>
        </section>

        <section>
            <h2 class="text-xl font-semibold text-purple-400 mb-3">{{ __('legal.your_rights') }}</h2>
            <p class="text-gray-300 mb-3">{{ __('legal.your_rights_intro') }}</p>
            <ul class="text-gray-300 list-disc list-inside space-y-1">
                <li>{{ __('legal.right_access') }}</li>
                <li>{{ __('legal.right_rectification') }}</li>
                <li>{{ __('legal.right_deletion') }}</li>
                <li>{{ __('legal.right_portability') }}</li>
            </ul>
            <p class="text-gray-300 mt-3">{{ __('legal.rights_contact') }} <a href="mailto:support@unitygametranslator.asymptomatikgames.com" class="text-purple-400 hover:text-purple-300">support@unitygametranslator.asymptomatikgames.com</a></p>
        </section>

        <section>
            <h2 class="text-xl font-semibold text-purple-400 mb-3">{{ __('legal.updates') }}</h2>
            <p class="text-gray-300">{{ __('legal.updates_text') }}</p>
        </section>

        <p class="text-gray-500 text-sm">{{ __('legal.last_updated') }}: {{ now()->format('d/m/Y') }}</p>
    </div>
</div>
@endsection
