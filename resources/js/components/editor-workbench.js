/**
 * Workbench mode: the grid takes the whole window.
 *
 * Two uses were fighting over one layout. Rereading a file wants an ordinary page, like every
 * other screen on the site. Comparing versions side by side wants a workbench — and the page
 * chrome above the grid runs to some four hundred pixels of title, pickers, statistics, filters
 * and search, which on a laptop leaves the grid a sliver and pushes its horizontal scrollbar off
 * the bottom of the screen. So it is a mode, not a compromise: off by default, one button away.
 *
 * Part of the editor core rather than of any one page, because the three editors — the merge
 * view, the mod comparison, the anonymous edit session — are one application for the user. A
 * mode that existed on one of them, or answered the same gesture differently, would read as
 * three different products.
 *
 * Nothing is remembered between visits: the mode belongs to a task, not to a preference, and
 * finding the site rearranged days later would surprise more than it would help.
 */
export function editorWorkbench() {
    return {
        wide: false,

        toggleWide() {
            this.wide = !this.wide;
            // The page behind must not scroll under a fixed workbench
            document.body.classList.toggle('overflow-hidden', this.wide);
            this.$nextTick(() => this._measureWorkbenchBar());
        },

        /**
         * How tall the strip is, published for the grid to hang from.
         *
         * 🔴 The strip used to be `h-12` and the grid `top-12` — the same number written twice, in
         * five files. Then the strip was allowed to take a second row on a narrow window (see
         * workbench-bar), and a constant would have parked the grid's first line underneath it.
         *
         * ⚠ A custom property rather than a class per height: the value changes with the window,
         * and it is read by four templates that must not each learn to measure.
         */
        _measureWorkbenchBar() {
            const bar = this.$refs.workbenchBar;
            const height = this.wide && bar ? Math.round(bar.getBoundingClientRect().height) : 0;
            document.documentElement.style.setProperty('--wb-bar-h', height ? height + 'px' : '3rem');
        },

        /**
         * Leaving the page while the mode is on must not leave the body locked — a Blade page is
         * replaced, but a back/forward restore can hand back the very same document.
         */
        initEditorWorkbench() {
            window.addEventListener('pagehide', () => {
                document.body.classList.remove('overflow-hidden');
            });

            // The strip grows and shrinks with the window, not only with the mode — a filter row
            // appears the moment it runs out of width.
            if (typeof ResizeObserver !== 'undefined') {
                this.$nextTick(() => {
                    const bar = this.$refs.workbenchBar;
                    if (!bar) return;
                    this._workbenchBarObserver = new ResizeObserver(() => this._measureWorkbenchBar());
                    this._workbenchBarObserver.observe(bar);
                });
            }
        },
    };
}
