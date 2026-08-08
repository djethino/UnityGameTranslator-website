{{--
    The grid's horizontal scrollbar, mirrored on the top edge of the sticky save bar.

    Goes FIRST inside the sticky block, so it rides with it and stays reachable however far down
    the file you are — the real one lives at the bottom of six thousand rows and you had to leave
    the line you were reading to get to it. See js/components/editor-hscroll.js.

    Hidden unless the grid really is wider than its box, and hidden in workbench mode where the
    box owns the window and its own bar already sits at the edge of the screen. Two bars for one
    movement would only make you wonder which one is the real one.

    A scrollbar and nothing else: no arrows, no buttons, no second way to do anything. It is the
    same gesture, brought within reach.
--}}
{{-- Its own panel, matching the save bar below it: with no background the scrollbar floated over
     the table rows and read as something dropped on top of the grid rather than a control
     belonging to the bar it rides with. --}}
<div x-show="hasHScroll && !wide" x-cloak
     x-ref="hProxy"
     {{-- No horizontal padding, deliberately: padding widens the scroller without widening the
          spacer, so the mirror would run 24px further than the grid and the two would part
          company at the end of the travel. --}}
     class="editor-hscroll overflow-x-auto overflow-y-hidden mb-2
            bg-gray-800 border border-gray-700 rounded-lg py-1.5"
     aria-hidden="true">
    <div class="h-px" :style="hProxyStyle"></div>
</div>
