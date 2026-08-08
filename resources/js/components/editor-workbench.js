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
        },

        /**
         * Leaving the page while the mode is on must not leave the body locked — a Blade page is
         * replaced, but a back/forward restore can hand back the very same document.
         */
        initEditorWorkbench() {
            window.addEventListener('pagehide', () => {
                document.body.classList.remove('overflow-hidden');
            });
        },
    };
}
