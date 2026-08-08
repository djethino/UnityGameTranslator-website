@props(['translation'])

{{--
    How much of what this file has ENCOUNTERED is translated.

    Captured lines are texts the mod met in game and nobody has translated yet: known, counted,
    pending work. Unlike the rest of the game, whose size nobody knows, this is a fact the file
    carries about itself — which makes it the only completeness measure that needs no rival
    translation to compare against.

    It exists because the review badge was lying. Two lines translated out of thirteen
    encountered were shown as "Fully reviewed", the loudest badge on the site sitting on the
    emptiest file in the catalogue: reviewing and translating are two different jobs, and the
    stage judged the first while the second had barely started. Below TRANSLATION_FLOOR the
    stage now stays quiet and this figure takes its place.

    Silent at 100%, like game coverage: a number is worth showing only when it says something,
    and "everything encountered is translated" is the normal state of the catalogue.
--}}
@php
    $completeness = $translation->completeness();
    $percent = $completeness === null ? null : (int) round($completeness * 100);
    $pending = $translation->capture_count;
@endphp

@if($translation->isCaptureOnly())
    {{-- Silent here, because the "Captured only" badge is already saying it — every screen that
         shows this component shows that one too (games/show, dashboard, mine).

         Worth a line of its own because the arithmetic is right and the reading is wrong: this
         file scores 0% translated, which reads as a translation doing badly when no translation
         has been attempted at all. An amber "0% translated" beside a grey "Captured only" gave
         the same fact two colours, one of which means "under way". --}}
@elseif($percent !== null && $percent < 100)
    <span {{ $attributes->merge(['class' => 'px-2 py-0.5 rounded text-xs bg-amber-900/50 text-amber-300']) }}
        title="{{ __('progress.completeness_hint', ['pending' => number_format($pending)]) }}">
        <i class="fas fa-hourglass-half mr-1"></i>{{ __('progress.completeness', ['percent' => $percent]) }}
    </span>
@endif
