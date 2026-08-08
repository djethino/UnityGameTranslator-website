{{--
    The grid's horizontal scrollbar, mirrored on the top edge of the sticky save bar.

    Goes FIRST inside the sticky block, so it rides with it and stays reachable however far down
    the file you are — the real one lives at the bottom of six thousand rows and you had to leave
    the line you were reading to get to it. See js/components/editor-hscroll.js.

    Hidden on three counts, and every one is the same rule: never two bars for one movement.
      - the grid does not overflow — there is nothing to scroll;
      - workbench mode — the box owns the window and its own bar is at the edge of the screen;
      - the end of the grid is in view — its real bar has just appeared above the save bar, and a
        twin a few pixels away only makes you wonder which one is real.

    A scrollbar and nothing else: no arrows, no buttons, no second way to do anything. It is the
    same gesture, brought within reach.
--}}
{{-- Its own panel, matching the save bar below it: with no background the scrollbar floated over
     the table rows and read as something dropped on top of the grid rather than a control
     belonging to the bar it rides with. --}}
<div x-show="hasHScroll && !wide && !hRealBarInView" x-cloak
     x-ref="hProxy"
     {{-- No padding at all, on either axis, and each for its own reason.

          Horizontal: padding widens the scroller without widening the spacer, so the mirror
          would run 24px further than the grid and the two would part company at the end.

          Vertical: a scrollbar is laid inside the BORDER box, underneath the padding — so
          padding-bottom cannot lift it. Any vertical padding only adds room ABOVE, leaving the
          bar stuck to the bottom edge and off-centre in its own panel. With none, the panel is
          the bar. --}}
     class="editor-hscroll overflow-x-auto overflow-y-hidden mb-2
            bg-gray-800 border border-gray-700 rounded-lg"
     aria-hidden="true">
    {{-- h-px, not h-0: with a content height of exactly zero the browser stops reserving room
         for the bar and draws nothing at all — the panel collapses to its two border pixels. --}}
    <div class="h-px" :style="hProxyStyle"></div>
</div>
