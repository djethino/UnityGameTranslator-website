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
        // True once the grid's REAL scrollbar has come into view: the mirror stands down rather
        // than sit twenty pixels above an identical bar
        hRealBarInView: false,
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

            // How wide the VISIBLE part of the grid is, published for anything that has to stay
            // where the eye is rather than where the table is: a full-width row's content is
            // centred on the whole table, so "show more" and "nothing found" drifted off screen
            // as soon as you scrolled sideways. Set here because this is where the box is already
            // being measured, on every change that could move it.
            box.style.setProperty('--grid-view-width', box.clientWidth + 'px');

            this.updateHRealBar();
        },

        /**
         * Reaching the end of the grid brings its own scrollbar into view, right above the save
         * bar. Two identical bars a few pixels apart is worse than none: you cannot tell which
         * one is the real one. So the mirror withdraws for exactly as long as the real one is
         * doing its job.
         *
         * Measured against the SAVE BAR's top, not the viewport's bottom, and that matters: the
         * bar is anchored by its bottom, so its top does not move when the mirror above it
         * disappears. Measuring against the mirror itself would have made the threshold jump by
         * the mirror's own height each time it hid — a band in which it would flicker on and off.
         */
        updateHRealBar() {
            const box = this.$refs.gridBox;
            const proxy = this.$refs.hProxy;
            if (!box || !proxy) return;
            const bar = proxy.nextElementSibling;
            const limit = bar ? bar.getBoundingClientRect().top : window.innerHeight;
            this.hRealBarInView = box.getBoundingClientRect().bottom <= limit + 1;
        },

        _hWired: false,

        /**
         * Tables that move sideways WITH the grid and carry no bar of their own.
         *
         * 🔴 Same rule as the mirror above: never two bars for one movement. A screen can hold
         * more than one table on the same columns — the merge puts the file settings and the
         * description on the lines' own grid so that everything reads down the page in one
         * alignment — and giving each its own scrollbar produced three bars that showed
         * different positions of the same columns.
         *
         * Marked in the markup rather than guessed, and scoped to this component's root: another
         * editor on the same page must not be dragged along by this one.
         */
        hFollowers() {
            const box = this.$refs.gridBox;
            const root = box ? box.closest('[x-data]') : null;
            if (!root) return [];
            return root.querySelectorAll('[data-hscroll-follow]');
        },

        /** The last position handed to the followers. See the echo guard in initHScroll. */
        _hPushed: 0,

        syncHFollowers(left) {
            this._hPushed = left;
            for (const follower of this.hFollowers()) {
                // Assigning a value it already holds fires no event, so nothing ping-pongs.
                if (follower.scrollLeft !== left) follower.scrollLeft = left;
            }
        },

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
                            this.syncHFollowers(proxy.scrollLeft);
                        });
                        box.addEventListener('scroll', () => {
                            if (proxy.scrollLeft !== box.scrollLeft) proxy.scrollLeft = box.scrollLeft;
                            this.syncHFollowers(box.scrollLeft);
                        });

                        // Followers push back, so a trackpad swipe over one of them moves
                        // everything rather than sliding it out of line with the rest.
                        for (const follower of this.hFollowers()) {
                            follower.addEventListener('scroll', () => {
                                // 🔴 Tell a gesture from an echo, and do it WITHOUT a timer.
                                //
                                // A follower narrower than the grid — a folded block, a table of
                                // two rows — clamps the position it is handed and reports the
                                // clamped value back as a scroll of its own, which dragged the
                                // grid to the start of the line as soon as you went past that
                                // table's width.
                                //
                                // ⚠ The first version held a flag released on the next animation
                                // frame. A background tab is served no frames at all, so the flag
                                // stayed raised for as long as the tab was away and the follower
                                // could never drive anything again. What we last handed out is a
                                // fact, not a deadline: an echo reports either that value, or the
                                // follower's own maximum when the value was beyond its reach.
                                if (follower.scrollWidth <= follower.clientWidth + 1) return;

                                const max = follower.scrollWidth - follower.clientWidth;
                                if (follower.scrollLeft === this._hPushed) return;
                                if (this._hPushed > max && follower.scrollLeft === max) return;

                                if (box.scrollLeft === follower.scrollLeft) return;

                                box.scrollLeft = follower.scrollLeft;
                                if (proxy.scrollLeft !== follower.scrollLeft) {
                                    proxy.scrollLeft = follower.scrollLeft;
                                }
                                this.syncHFollowers(follower.scrollLeft);
                            });
                        }

                        if ('ResizeObserver' in window) {
                            const observer = new ResizeObserver(() => this.measureHScroll());
                            observer.observe(box);
                            // The grid itself, not only its box: a resized column changes the
                            // width to mirror without changing the box at all
                            const grid = box.querySelector('table');
                            if (grid) observer.observe(grid);
                        }

                        // Whether the real bar has come into view changes with every page
                        // scroll. Coalesced into one frame: this runs on a document that can
                        // hold six thousand rows.
                        let pending = false;
                        const onViewChange = () => {
                            if (pending) return;
                            pending = true;
                            requestAnimationFrame(() => {
                                pending = false;
                                this.updateHRealBar();
                            });
                        };
                        window.addEventListener('scroll', onViewChange, { passive: true });
                        window.addEventListener('resize', onViewChange, { passive: true });
                    }

                    this.measureHScroll();
                });
            });
        },
    };
}
