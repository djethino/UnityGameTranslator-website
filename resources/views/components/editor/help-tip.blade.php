@props([
    // A screen with no column to pick between (the live editor) has no version to take and
    // nothing held by a column: those lines would describe gestures it does not offer.
    'arbitrates' => true,
    // ⚠ The same two gestures are worded differently where there is one file rather than two
    // versions ("validate this capture" vs "take that version"), so the screen supplies them.
    'selectHint' => null,
    'editHint' => null,
])

@php
    $selectHint ??= __('merge.instructions_select');
    $editHint ??= __('merge.instructions_edit');
@endphp

{{--
    The editors' help, in one place that costs one line of the bar.

    🔴 **It used to be four stacked lines inside the bottom bar.** On a narrow window they took the
    whole width and pushed Save onto a second row — the one control that must never move. And they
    could only ever hold four gestures, so the colours, the rings, the dashes and the wavy
    underlines, which are the part nobody can guess, were explained NOWHERE.

    Folding them into a panel fixes both at once: the bar keeps a constant height at any width, and
    the room freed is unlimited, so the marks can finally be shown rather than described.

    ⚠ **Shown, not described.** Every swatch below carries the REAL class from app.css, so this
    legend cannot drift from what the grid draws — change a colour and this changes with it.

    ⚠ Hover opens it, and so does a click, which is what a touch screen has instead. The trigger is
    a <button> for the keyboard, styled as plain text: it performs nothing, and a control that looks
    like a button but acts on nothing is a promise not kept.
--}}
<div class="relative shrink-0" x-data="{ open: false }"
     @mouseenter="open = true" @mouseleave="open = false">

    <button type="button" @click="open = !open" @click.away="open = false"
            :aria-expanded="open"
            class="text-gray-500 hover:text-gray-300 text-sm transition cursor-help">
        <i class="fas fa-circle-info mr-1"></i>{{ __('merge.help') }}
    </button>

    {{-- Opens UPWARD: this bar is pinned to the bottom of the window, so a panel hanging below it
         would open off screen. --}}
    <div x-show="open" x-cloak x-transition
         class="absolute bottom-full left-0 mb-2 w-80 max-w-[90vw] z-50 p-3 space-y-3
                bg-gray-800 border border-gray-700 rounded-lg shadow-xl text-xs leading-snug">

        <div class="space-y-1">
            <p class="text-gray-300 font-semibold uppercase tracking-wide text-[10px]">{{ __('merge.help_gestures') }}</p>
            <p class="text-gray-400">
                <i class="fas fa-arrow-pointer w-4 text-center mr-1"></i>{{ $selectHint }}
                <span class="tag-A">A</span> <i class="fas fa-arrow-right text-[10px]"></i> <span class="tag-V">V</span>
            </p>
            <p class="text-gray-400"><i class="fas fa-pen w-4 text-center mr-1"></i>{{ $editHint }}</p>
            <p class="text-gray-400"><i class="fas fa-trash w-4 text-center mr-1"></i>{{ __('merge.instructions_delete') }}</p>
            <p class="text-gray-400"><i class="fas fa-keyboard w-4 text-center mr-1"></i>{{ __('merge.instructions_keyboard') }}</p>
        </div>

        <div class="space-y-1 border-t border-gray-700 pt-2">
            <p class="text-gray-300 font-semibold uppercase tracking-wide text-[10px]">{{ __('merge.help_marks') }}</p>

            {{-- The swatch is a real cell: same classes, same rules, so it can never say something
                 the grid does not do. --}}
            @php
                $marks = $arbitrates
                    ? [
                        ['selected-main', __('merge.help_mark_written')],
                        ['selected-main selection-unclaimed', __('merge.help_mark_unclaimed')],
                        ['selected-manual', __('merge.help_mark_manual')],
                        ['edit-set-aside', __('merge.help_mark_aside')],
                        ['tag-changed-cell', __('merge.help_mark_tag')],
                        ['deleted-cell', __('merge.help_mark_deleted')],
                    ]
                    : [
                        ['selected-manual', __('merge.help_mark_manual')],
                        ['tag-changed-cell', __('merge.help_mark_tag')],
                        ['deleted-cell', __('merge.help_mark_deleted')],
                    ];
            @endphp

            {{-- ⚠ Each swatch carries TEXT, not just a background. Half of these marks are worn by
                 the words rather than by the cell — the set-aside one is a strike-through, and an
                 empty rectangle showed nothing at all of what it does. --}}
            @foreach($marks as [$class, $label])
                <p class="flex items-start gap-2 text-gray-400">
                    <span class="{{ $class }} shrink-0 inline-block w-7 rounded-sm mt-0.5 text-center leading-4"
                        ><span class="editor-text">ab</span></span>
                    <span class="min-w-0">{{ $label }}</span>
                </p>
            @endforeach

            @if($arbitrates)
                <p class="flex items-start gap-2 text-gray-400">
                    <span class="shrink-0 inline-block w-7 text-center mt-0.5 leading-4"><span class="diff-word">ab</span></span>
                    <span class="min-w-0">{{ __('merge.help_mark_diff') }}</span>
                </p>
            @endif
        </div>
    </div>
</div>
