@props(['translation', 'plain' => false])

{{--
    Whether this lineage takes contributions — its Main's own decision.

    🔴 Same nature as "Complete" beside it: a DECLARATION, not something measured on the file.
    Only the Main can change it, and without it a reader discovers the answer at the moment they
    try to contribute — after the work.

    ⚠ Both states are shown, and neither is a reproach. Keeping a translation open to contributions
    is work nobody agreed to by publishing; "Solo work" says how somebody works, not that they
    turned anybody down.

    ⚠ Only on a Main. A branch does not lead a lineage and does not decide this; putting the chip
    on its card would show somebody else's decision as if it were theirs.

    `plain` is for the rows that carry text-and-icon rather than chips (my translations). The
    WORDS never change between the two — a reader meeting the same fact on two pages must not
    have to work out that it is the same fact.
--}}
@php
    $open = (bool) $translation->accepts_branches;
    $text = $open ? __('translation.accepts_contributions') : __('translation.solo_work');
    $tip = $open ? __('translation.accepts_contributions_hint') : __('translation.solo_work_hint');
    $icon = $open ? 'fa-code-branch' : 'fa-user';
@endphp

@if($translation->lineageRole() === 'main')
    @if($plain)
        <span class="{{ $open ? 'text-gray-400' : 'text-gray-500' }} text-sm" title="{{ $tip }}">
            <i class="fas {{ $icon }}"></i> {{ $text }}
        </span>
    @else
        <span class="bg-gray-700 {{ $open ? 'text-gray-300' : 'text-gray-400' }} px-2 py-1 rounded text-xs"
              title="{{ $tip }}">
            <i class="fas {{ $icon }}"></i> {{ $text }}
        </span>
    @endif
@endif
