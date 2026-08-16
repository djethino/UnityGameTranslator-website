@props(['user', 'link' => false])

{{--
    A person, written the way the whole ecosystem writes one: @name, and @name (you) when it is
    the reader.

    🔴 **The same rule as UnityGameTranslator.Common.People**, which the mod and the manager use.
    The site cannot consume that library — it is PHP — so the rule is re-keyed here. That is
    exactly the trap that left the "Solo work" chip missing from this site for a day: a decision
    taken in the shared library does not travel here on its own.

    🔴 **"(you)" is a word, never a colour.** A colour cannot be read by somebody who does not know
    there is something to read, and it has to survive a screenshot and a colour-blind reader. The
    colour here keeps saying what it says everywhere else on this site — grey for text, purple for
    "you can click this" — and never who somebody is.

    ⚠ An anonymous reader is not "not the author": they have no name here, so nothing is marked.
    auth()->id() is null then and the comparison is simply false.
--}}
@php
    $name = $user?->name;
    $isYou = $user && auth()->check() && auth()->id() === $user->id;
@endphp

<span {{ $attributes->merge(['class' => $link ? 'text-purple-400 hover:text-purple-300' : '']) }}>
    @if($name)
        {{ '@' . $name }}@if($isYou) <span class="text-gray-400">{{ __('translation.you_marker') }}</span>@endif
    @else
        {{ __('translation.author_unknown') }}
    @endif
</span>
