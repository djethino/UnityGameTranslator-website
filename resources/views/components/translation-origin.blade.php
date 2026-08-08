@props(['translation'])

{{--
    Where a fork came from, and how much it was given.

    Forking used to erase this. The mod severs its link with the original — it has to, or it
    would keep offering to merge from a lineage it just left — and severed the provenance with
    it, so a fork reached the site as a brand-new translation. Somebody's three thousand lines
    became somebody else's starting point with nothing to say so.

    The line count is a SNAPSHOT taken at the instant of the fork: the original keeps growing
    afterwards, so asking the question later would answer a different one. It is written once and
    never moves, which is what lets it stay true as the fork grows past it.

    The author's name is read live rather than stored, so a rename follows; the account may also
    be gone, in which case the credit stands without a name rather than not at all.
--}}
@php
    $origin = $translation->hasOrigin() ? $translation : null;
    $author = $origin?->originAuthor;
    $lines = $origin?->origin_resolved_lines;
@endphp

@if($origin)
    <span {{ $attributes->merge(['class' => 'text-xs text-gray-400']) }}>
        <i class="fas fa-code-branch mr-1"></i>@if($author && $lines)
            {{ __('translation.forked_from_with_lines', ['author' => $author->name, 'count' => number_format($lines)]) }}
        @elseif($author)
            {{ __('translation.forked_from', ['author' => $author->name]) }}
        @else
            {{ __('translation.forked_from_removed') }}
        @endif
    </span>
@endif
