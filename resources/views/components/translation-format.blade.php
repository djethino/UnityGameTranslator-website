@props(['translation', 'plain' => false])

{{--
    The format of the translation's file — « v1 », « v2 »… (App\Support\TranslationFormat). The same
    chip the mod's community list and UGT Manager show on the same row (common Badges.Format), always
    shown (decided 2026-10-22) so the number is a known sight before the day it matters.

    Quiet here, never red: a page has nobody's mod to judge against. The mod and the Manager colour it
    for the one that would read it.

    `plain` for rows carrying text-and-icon rather than chips, as translation-role and
    contributions-badge do.
--}}
@php
    $format = max(1, (int) ($translation->format_version ?? 1));
    $hint = __('translation.format_hint', ['format' => $format]);
@endphp

@if($plain)
    <span {{ $attributes->merge(['class' => 'text-gray-400 text-sm']) }} title="{{ $hint }}">
        <i class="fas fa-file-code"></i> v{{ $format }}
    </span>
@else
    <span {{ $attributes->merge(['class' => 'bg-gray-700 text-gray-300 px-2 py-1 rounded text-xs font-medium']) }}
        title="{{ $hint }}">v{{ $format }}</span>
@endif
