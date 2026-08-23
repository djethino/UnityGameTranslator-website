@props([
    // Each is the Alpine expression to run, or null where the screen has no such action.
    'suggest' => null,
    'cancel' => null,
    'cancelLabel' => null,
    'download' => null,
    'downloadLabel' => null,
    // The help panel wants the screen's own wording for its first two gestures.
    'arbitrates' => true,
    'selectHint' => null,
    'editHint' => null,
    // Small type, one row, no jumping to top/bottom: the workbench strip is 48px tall and the
    // grid it sits above already fills the window.
    'compact' => false,
])

{{--
    Everything the bottom bar offers besides Save, in one place — because the WORKBENCH shows none
    of it.

    🔴 **The workbench grid is `fixed … z-50`; the bottom bar is `z-40`.** So the bar is not merely
    scrolled away, it is COVERED: measured with `elementFromPoint`, `Suggest the rest`, `Cancel`,
    `Download`, `Select all …`, the scroll-to-top pair and the help panel all report `covered` the
    moment the workbench is on. They are in the DOM, they look present to any test that reads the
    markup, and nobody can click them.

    ⚠ This is the same lesson the strip's own header already records — "anything not reported here
    is simply unreachable while it is on" — which had been applied to the search and to Save, and
    to nothing else. The help panel added on 2026-08-22 walked straight into it.

    ⚠ **Not a duplicated action.** The two bars are mutually exclusive: one is shown exactly when
    the other is not. What would be a duplicate is a second PATH to the same act with different
    guards — and there is none here, because the guards (`undecidedCount > 0`, `totalChanges > 0`)
    live in this one component and are rendered from it in both places.
--}}
<div class="flex items-center {{ $compact ? 'gap-2' : 'gap-4' }} shrink-0">
    <x-editor.help-tip :arbitrates="$arbitrates" :select-hint="$selectHint" :edit-hint="$editHint"
                       :compact="$compact" />

    {{ $slot }}

    {{-- ⚠ In the strip the words are hidden below xl, never the buttons: the strip is one row that
         must not become two, and an icon with its label a hover away is readable where a control
         pushed onto a second line is simply missed. Outside the strip there is room, so the words
         stay. --}}
    @php $word = $compact ? 'hidden xl:inline' : ''; @endphp

    @if($suggest)
        {{-- Shown while something is left to answer: a button that does nothing teaches nothing. --}}
        <button type="button" @click="{{ $suggest }}"
            x-show="undecidedCount > 0" x-cloak title="{{ __('merge.suggest_rest') }}"
            class="text-gray-400 hover:text-white whitespace-nowrap {{ $compact ? 'text-xs' : 'text-sm' }} transition shrink-0">
            <i class="fas fa-wand-magic-sparkles mr-1"></i><span class="{{ $word }}">{{ __('merge.suggest_rest') }}</span>
        </button>
    @endif

    @if($cancel)
        <button type="button" @click="{{ $cancel }}" x-show="totalChanges > 0" x-cloak
            title="{{ $cancelLabel ?? __('merge.cancel_all') }}"
            class="text-gray-400 hover:text-white whitespace-nowrap {{ $compact ? 'text-xs' : 'text-sm' }} transition shrink-0">
            <i class="fas fa-times mr-1"></i><span class="{{ $word }}">{{ $cancelLabel ?? __('merge.cancel_all') }}</span>
        </button>
    @endif

    @if($download)
        <button type="button" @click="{{ $download }}" title="{{ $downloadLabel }}"
            class="bg-blue-600 hover:bg-blue-700 rounded-lg text-white transition shrink-0 whitespace-nowrap
                   {{ $compact ? 'px-2 py-1 text-xs' : 'px-4 py-2' }}">
            <i class="fas fa-download mr-1"></i><span class="{{ $word }}">{{ $downloadLabel }}</span>
        </button>
    @endif

    {{-- ⚠ Compact only, and that is not an inconsistency. In the bottom bar this pair BRACKETS the
         row — one at each end — which is a layout the bar owns and this group cannot reproduce
         from the middle of it; dropped in here it landed between Help and Save. The workbench
         strip is a single row with no ends to bracket, and it had no way back to the top at all: a
         scrolling DIV gives no keyboard shortcut home the way a page does. --}}
    @if($compact)
        <div class="flex flex-row gap-2 shrink-0">
            <button type="button" @click="scrollToTop()"
                class="text-gray-500 hover:text-white transition" title="{{ __('merge.scroll_top') }}">
                <i class="fas fa-angles-up"></i>
            </button>
            <button type="button" @click="scrollToBottom()"
                class="text-gray-500 hover:text-white transition" title="{{ __('merge.scroll_bottom') }}">
                <i class="fas fa-angles-down"></i>
            </button>
        </div>
    @endif
</div>
