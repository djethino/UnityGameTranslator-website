/**
 * A horizontal scrollbar you can actually reach, in the ordinary page view.
 *
 * A grid's own horizontal scrollbar sits at the BOTTOM of the grid — six thousand rows down.
 * To scroll sideways you first had to scroll to the end of the file, which means leaving the
 * line you were reading. That is the whole reason the workbench exists; but the workbench is a
 * mode you have to enter, and merging should be possible without entering it.
 *
 * So the bar is mirrored where something is already permanently within reach: the top edge of
 * the sticky save bar. A second scroller, empty except for a spacer as wide as the grid, wired
 * to the real one in both directions. Nothing is duplicated except the gesture.
 *
 * It appears ONLY when it is useful: when the grid is actually wider than its box, and not in
 * workbench mode — there the box owns the window and its scrollbar is already at the edge of
 * the screen, so a second one would be two bars for one movement.
 *
 * The spacer's width is measured, not assumed: columns get resized, branches come and go, the
 * capture-order column appears, line breaks make rows taller. A ResizeObserver on the grid keeps
 * the mirror honest instead of a number captured once at load.
 *
 * Requires two x-ref on the page: `gridBox` (the element that scrolls) and `hProxy` (the mirror).
 *
 * PLAIN PROPERTIES, NO GETTERS. This module is spread into the editor core, and a spread
 * EVALUATES a getter and copies the value it returned — the accessor is lost. Written as
 * `get hasHScroll()` it was frozen to false at composition time, the mirror never appeared, and
 * the spacer kept a width of zero so nothing could scroll. composeEditor carries the same warning
 * for the page half; it applies to every module composed this way.
 */
export function editorHScroll() {
    return {
        // Set by measureHScroll, never computed on read
        hasHScroll: false,
        // Spacer width, as a style string: the CSP build binds a property, not an expression
        hProxyStyle: 'width: 0px',

        measureHScroll() {
            const box = this.$refs.gridBox;
            if (!box) return;
            const scrollWidth = box.scrollWidth;
            // +1 absorbs the sub-pixel rounding a zoomed page produces, which would otherwise
            // show a mirror with nothing to scroll
            this.hasHScroll = scrollWidth > box.clientWidth + 1;
            this.hProxyStyle = 'width: ' + scrollWidth + 'px';
        },

        _hWired: false,

        /**
         * Call from the host component's init().
         *
         * Driven by an effect rather than a single $nextTick: at core-init time the refs do not
         * exist yet — the grid is rendered once its data has been fetched — and a one-shot
         * attempt found nothing and gave up without a word. Reading allKeys here means this
         * re-runs when the rows arrive, and again whenever the box changes shape.
         */
        initHScroll() {
            window.Alpine.effect(() => {
                this.allKeys.length;
                this.wide;
                this.showIndexColumn;

                this.$nextTick(() => {
                    const box = this.$refs.gridBox;
                    const proxy = this.$refs.hProxy;
                    if (!box || !proxy) return;

                    if (!this._hWired) {
                        this._hWired = true;

                        // Both directions. No guard flag needed: assigning a scrollLeft that is
                        // already the current value fires no event, so the two cannot ping-pong.
                        proxy.addEventListener('scroll', () => {
                            if (box.scrollLeft !== proxy.scrollLeft) box.scrollLeft = proxy.scrollLeft;
                        });
                        box.addEventListener('scroll', () => {
                            if (proxy.scrollLeft !== box.scrollLeft) proxy.scrollLeft = box.scrollLeft;
                        });

                        if ('ResizeObserver' in window) {
                            const observer = new ResizeObserver(() => this.measureHScroll());
                            observer.observe(box);
                            // The grid itself, not only its box: a resized column changes the
                            // width to mirror without changing the box at all
                            const grid = box.querySelector('table');
                            if (grid) observer.observe(grid);
                        }
                    }

                    this.measureHScroll();
                });
            });
        },
    };
}
