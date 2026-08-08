@props([
    'language',
    // finished | progress | capture — see Translation::languageStatesForGames
    'state' => 'progress',
    'name' => null,
    // The language the reader is browsing in: worth marking, not worth shouting about
    'highlight' => false,
])

@php
    // Localised by default rather than by the caller: every page that shows a flag wants the
    // language named in the reader's own language, and one page passing it while another forgot
    // is how "Français" and "French" ended up side by side on two screens of the same site.
    $name = $name ?? (config('language-names', [])[$language] ?? $language);

    $dot = match ($state) {
        'finished' => 'bg-green-500',
        'capture' => 'bg-gray-500',
        default => 'bg-amber-400',
    };
    $label = $name . ' — ' . __('games.flag.' . $state);
@endphp

{{--
    A flag, and how far that language has got.

    The flag alone says a language exists, which reads as "this game can be played in it" —
    a promise a file of collected-but-untranslated text does not keep. A dot costs three pixels
    and turns the promise into a fact: green is finished, amber is under way, grey is text
    collected and waiting for someone.

    The colours are the site's own: the same green as a human line, the same grey as the captured
    segment of every quality bar. Nothing new to learn.
--}}
<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 text-[11px] px-1 py-0.5 rounded leading-none '
        . ($highlight ? 'bg-purple-600 ring-1 ring-purple-300' : 'bg-black/60')]) }}
    title="{{ $label }}">
    @langflag($language)
    <span class="w-1.5 h-1.5 rounded-full {{ $dot }}" aria-hidden="true"></span>
    <span class="sr-only">{{ $label }}</span>
</span>
