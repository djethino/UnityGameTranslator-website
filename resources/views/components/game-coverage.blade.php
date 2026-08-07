@props(['translation', 'gameMax' => null])

{{--
    How much of the game this translation reaches.

    A line count says nothing on its own: three thousand lines is a lot or a little depending on
    the game, and the game's real amount of text is unknowable — it is captured as players meet
    it. What CAN be measured is the other translations of the same game: the same lines turn up
    as people get further, whatever language they translate into, so the largest of them is an
    honest lower bound.

    Silent at 100%, and that is not modesty. The yardstick is the furthest anyone has got, not
    the game's true size, so a file that sets the yardstick would be claiming to cover a whole
    game on the strength of being alone at the front. It is also, by construction, what every
    game with a single translation would show.

    Pass $gameMax on a page listing several translations — the caller already knows it, and
    letting each card ask the database its own MAX is how a page ends up with fifty queries.
--}}
@php
    $coverage = $translation->gameCoverage($gameMax);
    $percent = $coverage === null ? null : (int) round($coverage * 100);
@endphp

@if($percent !== null && $percent < 100)
    <span {{ $attributes->merge(['class' => 'text-xs text-gray-400']) }}
        title="{{ __('progress.game_coverage_hint') }}">
        <i class="fas fa-map-location-dot mr-1"></i>{{ __('progress.game_coverage', ['percent' => $percent]) }}
    </span>
@endif
