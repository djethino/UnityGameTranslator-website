@props(['translation'])

{{--
    The format of the translation's file — « v1 », « v2 »… (App\Support\TranslationFormat). The same
    chip the mod's community list and UGT Manager show on the same row (common Badges.Format), always
    shown (decided 2026-10-22) so the number is a known sight before the day it matters.

    Quiet here, never red: a page has nobody's mod to judge against. The mod and the Manager colour it
    for the one that would read it.
--}}
@php
    $format = max(1, (int) ($translation->format_version ?? 1));
@endphp

<span {{ $attributes->merge(['class' => 'bg-gray-700 text-gray-300 px-2 py-1 rounded text-xs font-medium']) }}
    title="{{ __('translation.format_hint', ['format' => $format]) }}">v{{ $format }}</span>
