@extends('layouts.app')

@section('title', __('legal.privacy_title') . ' - UnityGameTranslator')

@php
    // 🔴 **Written by hand, and that is the whole point.** This line used to be `now()`, so the page
    // claimed to have been updated on the day you happened to read it — every day, for ever. That
    // makes the promise two sections below ("significant changes are announced") impossible to
    // check, and leaves nobody able to say which version was online on a given date.
    //
    // ⚠ Formatted through Carbon rather than written as a string, so the date reads the way each
    // language writes dates. It is the value that is fixed, not its shape.
    $policyUpdatedOn = \Carbon\Carbon::parse('2026-08-27');
@endphp

@section('content')
<div class="max-w-4xl mx-auto">
    <h1 class="text-3xl font-bold mb-8">{{ __('legal.privacy_title') }}</h1>

    <div class="bg-gray-800 rounded-lg p-6 space-y-8">
        <p class="text-gray-300">{{ __('legal.privacy_intro') }}</p>

        {{-- §1 — Who we are ------------------------------------------------------------------ --}}
        <section>
            <h2 class="text-xl font-semibold text-purple-400 mb-3">{{ __('legal.who_we_are') }}</h2>
            <p class="text-gray-300 mb-3">
                {{ __('legal.who_responsible') }}
                <a href="{{ route('legal.mentions') }}" class="text-purple-400 hover:text-purple-300">{{ __('legal.mentions_title') }}</a>.
            </p>
            <p class="text-gray-300">
                {{ __('legal.who_complaints') }}
                <a href="mailto:support@unitygametranslator.asymptomatikgames.com" class="text-purple-400 hover:text-purple-300">support@unitygametranslator.asymptomatikgames.com</a>.
            </p>
        </section>

        {{-- §2 — What we hold, and how it reached us
             Five ways in, where the page used to describe one. --}}
        <section>
            <h2 class="text-xl font-semibold text-purple-400 mb-4">{{ __('legal.what_we_hold') }}</h2>

            <h3 class="font-semibold text-gray-100 mb-2">{{ __('legal.hold_account') }}</h3>
            <p class="text-gray-300 mb-2">{{ __('legal.hold_account_local') }}</p>
            <p class="text-gray-300 mb-2">{{ __('legal.hold_account_platform') }}</p>
            <p class="text-gray-300 mb-5">{{ __('legal.hold_account_public') }}</p>

            <h3 class="font-semibold text-gray-100 mb-2">{{ __('legal.hold_published') }}</h3>
            <p class="text-gray-300 mb-5">{{ __('legal.hold_published_text') }}</p>

            <h3 class="font-semibold text-gray-100 mb-2">{{ __('legal.hold_connections') }}</h3>
            <p class="text-gray-300 mb-2">{{ __('legal.hold_connections_why') }}</p>
            <p class="text-gray-300 mb-5">{{ __('legal.hold_connections_end') }}</p>

            {{-- 🔴 Added when the Linked devices screen was built, because that screen created a
                 category of personal data this page did not mention: a device name somebody types
                 themselves, and which game each access belongs to. Declaring what is stored is not
                 optional, and a policy that lags behind the schema is the exact failure this
                 rewrite existed to end. --}}
            <h3 class="font-semibold text-gray-100 mb-2">{{ __('legal.hold_accesses') }}</h3>
            <p class="text-gray-300 mb-5">{{ __('legal.hold_accesses_text') }}</p>

            <h3 class="font-semibold text-gray-100 mb-2">{{ __('legal.hold_counting') }}</h3>
            <p class="text-gray-300 mb-2">{{ __('legal.hold_counting_no_cookie') }}</p>
            <p class="text-gray-300 mb-2">{{ __('legal.hold_counting_how') }}</p>
            <p class="text-gray-300 mb-2">{{ __('legal.hold_counting_forgotten') }}</p>
            <p class="text-gray-300 mb-5">{{ __('legal.hold_counting_retention') }}</p>

            <h3 class="font-semibold text-gray-100 mb-2">{{ __('legal.hold_programs') }}</h3>
            <p class="text-gray-300 mb-2">{{ __('legal.hold_programs_mod') }}</p>
            <p class="text-gray-300 mb-2">{{ __('legal.hold_programs_manager') }}</p>
            <p class="text-gray-300">{{ __('legal.hold_programs_never') }}</p>
        </section>

        {{-- §3 — How long
             A table, because the sentence it replaces covered seven different regimes with one
             answer — three of which were "for ever". --}}
        <section>
            <h2 class="text-xl font-semibold text-purple-400 mb-3">{{ __('legal.data_retention') }}</h2>
            {{-- ⚠ Written out rather than looped over a list of suffixes. A key built by
                 concatenation is a key check-translations.py cannot see, and sixteen of them would
                 be sixteen strings nothing guarantees exist in the other nineteen languages. The
                 repetition buys a check that runs on every commit. --}}
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-gray-300">
                    <tbody class="divide-y divide-gray-700">
                        <tr>
                            <td class="py-2 pr-4 align-top">{{ __('legal.keep_account') }}</td>
                            <td class="py-2 align-top text-gray-400">{{ __('legal.keep_account_for') }}</td>
                        </tr>
                        <tr>
                            <td class="py-2 pr-4 align-top">{{ __('legal.keep_content') }}</td>
                            <td class="py-2 align-top text-gray-400">{{ __('legal.keep_content_for') }}</td>
                        </tr>
                        <tr>
                            <td class="py-2 pr-4 align-top">{{ __('legal.keep_accesses') }}</td>
                            <td class="py-2 align-top text-gray-400">{{ __('legal.keep_accesses_for') }}</td>
                        </tr>
                        <tr>
                            <td class="py-2 pr-4 align-top">{{ __('legal.keep_ip') }}</td>
                            <td class="py-2 align-top text-gray-400">{{ __('legal.keep_ip_for') }}</td>
                        </tr>
                        <tr>
                            <td class="py-2 pr-4 align-top">{{ __('legal.keep_views') }}</td>
                            <td class="py-2 align-top text-gray-400">{{ __('legal.keep_views_for') }}</td>
                        </tr>
                        <tr>
                            <td class="py-2 pr-4 align-top">{{ __('legal.keep_totals') }}</td>
                            <td class="py-2 align-top text-gray-400">{{ __('legal.keep_totals_for') }}</td>
                        </tr>
                        <tr>
                            <td class="py-2 pr-4 align-top">{{ __('legal.keep_session') }}</td>
                            <td class="py-2 align-top text-gray-400">{{ __('legal.keep_session_for') }}</td>
                        </tr>
                        <tr>
                            <td class="py-2 pr-4 align-top">{{ __('legal.keep_edit_session') }}</td>
                            <td class="py-2 align-top text-gray-400">{{ __('legal.keep_edit_session_for') }}</td>
                        </tr>
                        <tr>
                            <td class="py-2 pr-4 align-top">{{ __('legal.keep_remember') }}</td>
                            <td class="py-2 align-top text-gray-400">{{ __('legal.keep_remember_for') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        {{-- §3-bis — Why we hold it
             🔴 Kept from the old page, and it had to be: the sections above say what we hold and
             for how long, and two of these purposes — contacting somebody about a report, and
             moderation — appear nowhere else. Naming what is held without naming what it is for
             answers half the question the law asks. --}}
        <section>
            <h2 class="text-xl font-semibold text-purple-400 mb-3">{{ __('legal.data_usage') }}</h2>
            <ul class="text-gray-300 list-disc list-inside space-y-1">
                <li>{{ __('legal.usage_auth') }}</li>
                <li>{{ __('legal.usage_display') }}</li>
                <li>{{ __('legal.usage_contact') }}</li>
                <li>{{ __('legal.usage_moderation') }}</li>
            </ul>
        </section>

        {{-- §4 — Who else sees anything --}}
        <section>
            <h2 class="text-xl font-semibold text-purple-400 mb-3">{{ __('legal.data_sharing') }}</h2>
            <p class="text-gray-300 mb-3">{{ __('legal.sharing_none') }}</p>
            <p class="text-gray-300 mb-2">{{ __('legal.sharing_but') }}</p>
            <ul class="text-gray-300 list-disc list-inside space-y-1 mb-3">
                <li>{{ __('legal.sharing_host') }}</li>
                <li>{{ __('legal.sharing_platform') }}</li>
                <li>{{ __('legal.sharing_images') }}</li>
                <li>{{ __('legal.sharing_github') }}</li>
            </ul>
            <p class="text-gray-400 text-sm">{{ __('legal.sharing_caveat') }}</p>
        </section>

        {{-- §5 — Cookies --}}
        <section>
            <h2 class="text-xl font-semibold text-purple-400 mb-3">{{ __('legal.cookies') }}</h2>
            <p class="text-gray-300 mb-3">{{ __('legal.cookies_text') }}</p>
            <ul class="text-gray-300 list-disc list-inside space-y-1">
                <li><strong>laravel_session:</strong> {{ __('legal.cookie_session') }}</li>
                <li><strong>XSRF-TOKEN:</strong> {{ __('legal.cookie_csrf') }}</li>
                <li><strong>ugt_edit_session:</strong> {{ __('legal.cookie_edit_session') }}</li>
                {{-- The only persistent one, and it was missing from this list entirely. --}}
                <li><strong>remember_web_…:</strong> {{ __('legal.cookie_remember') }}</li>
            </ul>
        </section>

        {{-- §6 — What you can do
             Each right names WHERE it is exercised. "By contacting us" was false twice over: a
             button exists, and deletion is now really one. --}}
        <section>
            <h2 class="text-xl font-semibold text-purple-400 mb-3">{{ __('legal.your_rights') }}</h2>
            <ul class="text-gray-300 list-disc list-inside space-y-2">
                <li>{{ __('legal.right_see') }}</li>
                <li>{{ __('legal.right_correct') }}</li>
                <li>{{ __('legal.right_erase') }}</li>
                <li>{{ __('legal.right_object') }}</li>
                <li>{{ __('legal.right_take') }}</li>
                <li>{{ __('legal.right_complain') }}</li>
            </ul>
            <p class="text-gray-400 text-sm mt-3">{{ __('legal.rights_limit') }}</p>
        </section>

        {{-- §7 — Changes --}}
        <section>
            <h2 class="text-xl font-semibold text-purple-400 mb-3">{{ __('legal.updates') }}</h2>
            <p class="text-gray-300">{{ __('legal.updates_text') }}</p>
        </section>

        <p class="text-gray-500 text-sm">
            {{ __('legal.last_updated') }}: {{ $policyUpdatedOn->translatedFormat('j F Y') }}
        </p>
    </div>
</div>
@endsection
