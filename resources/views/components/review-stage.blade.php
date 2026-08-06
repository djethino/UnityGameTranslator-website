@props(['translation'])

{{--
    Where a translation stands, as a step rather than a mark.

    It replaces the 0-3 score wherever a reader has to CHOOSE, and the reason is arithmetic
    rather than taste: that score answers "where does each line come from" when the question is
    "has anyone read this". Unreviewed machine output scores 1.0 out of 3, a file reviewed line
    by line stops at 2.0 — so everything crowds into the middle, and three quarters of the real
    catalogue sits in one band.

    Steps also carry no verdict. Every translation begins as raw machine output, because that is
    how the mod works; calling that "Raw AI" on a scale ending at "Excellent" tells a newcomer
    their starting point is worthless. The exact share stays available on hover, for whoever
    wants the number.
--}}
@php
    $stage = $translation->reviewStage();
    $coverage = $translation->reviewCoverage();

    $tone = match ($stage) {
        'reviewed' => 'bg-green-900/60 text-green-300',
        'advanced' => 'bg-blue-900/60 text-blue-300',
        'started' => 'bg-gray-700 text-gray-300',
        'machine' => 'bg-gray-700 text-gray-400',
        default => null,
    };
@endphp

@if($stage)
    <span {{ $attributes->merge(['class' => 'px-2 py-0.5 rounded text-xs ' . $tone]) }}
        title="{{ __('progress.stage_hint', ['percent' => round($coverage * 100)]) }}">
        {{ __('progress.stage.' . $stage) }}
    </span>
@endif
