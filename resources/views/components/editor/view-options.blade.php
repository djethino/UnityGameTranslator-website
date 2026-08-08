{{--
    The two durable display preferences, as icons.

    They are NOT filters — a filter decides which rows you see, these decide how a row is drawn —
    and mixing the two in one flow is what made the merge view's bar wrap at an arbitrary point,
    splitting a group in half. Here they form a group of their own, pinned to the right of the
    bar, so a long language pushes the FILTERS onto a second line and never separates these.

    Icons rather than words, because both are set once and then forgotten (they survive in
    localStorage), so they do not deserve the width their labels were taking. The same two icons
    already say the same two things in the workbench strip — which is why this file is shared by
    both, and why nothing new has to be learnt.

    The checkbox stays: every other switch on this bar is one, and an icon that toggles without
    showing whether it is on would be the odd one out.

    The name lives twice: in `title` for the mouse, and in a screen-reader-only span — an icon on
    its own leaves a checkbox with no name at all for anyone not looking at it.
--}}
<label class="flex items-center gap-1.5 cursor-pointer shrink-0"
       title="{{ __('editor.capture_order') }} — {{ __('editor.capture_order_hint') }}">
    <input type="checkbox" :checked="showIndexColumn" @change="toggleIndexColumn()"
           class="rounded bg-gray-700 border-gray-600 text-gray-500">
    <i class="fas fa-arrow-down-1-9 text-gray-400" aria-hidden="true"></i>
    <span class="sr-only">{{ __('editor.capture_order') }}</span>
</label>
<label class="flex items-center gap-1.5 cursor-pointer shrink-0"
       title="{{ __('editor.line_breaks') }} — {{ __('editor.line_breaks_hint') }}">
    <input type="checkbox" :checked="showLineBreaks" @change="toggleLineBreaks()"
           class="rounded bg-gray-700 border-gray-600 text-gray-500">
    <i class="fas fa-paragraph text-gray-400" aria-hidden="true"></i>
    <span class="sr-only">{{ __('editor.line_breaks') }}</span>
</label>
