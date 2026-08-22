/**
 * How a translation line is SHOWN: as flowing text, or with its line breaks.
 *
 * A game string carries its own line breaks, and they are part of the translation — they decide
 * where the text wraps in a dialogue box. HTML folds them into spaces, so every grid on the site
 * showed a two-line caption as one long line: you could not tell whether a translation kept the
 * original's breaks, added some, or lost them all.
 *
 * Reading them and scanning them are two different jobs, though. Line breaks make each row as
 * tall as its longest value, which is what you want when checking one line and exactly what you
 * do not want when running your eye down five hundred. So it is a switch, and it stays off by
 * default: flowing text is what everyone has been reading until now.
 *
 * EDITING IS NOT CONCERNED. The edit box is a textarea and has always shown, kept and saved the
 * breaks; this only ever changes the grid. A display switch must never be able to alter what is
 * stored.
 *
 * `white-space: pre-wrap` rather than `pre-line`: runs of spaces are kept too. Padding spaces
 * are as deliberate as a break in a game string, and a mode that claims to show the text as it is
 * cannot quietly drop half of it.
 *
 * Kept in localStorage, like the capture-order column: it decides nothing and only shows, so it
 * outlives the sitting.
 *
 * ⚠ **Per VIEW.** It used to be one answer for the whole site, which reads well until you notice
 * the four grids do not hold the same columns — wanting the breaks while arbitrating a merge and
 * not while re-reading a file is an ordinary thing to want. A screen that does not name a view
 * (the admin inspection list) shares the plain one.
 */

const STORAGE_KEY = 'ugt_editor_line_breaks';

export function editorTextMode(view) {
    const storageKey = view ? STORAGE_KEY + '_' + view : STORAGE_KEY;

    return {
        showLineBreaks: false,

        toggleLineBreaks() {
            this.showLineBreaks = !this.showLineBreaks;
            try {
                localStorage.setItem(storageKey, this.showLineBreaks ? '1' : '0');
            } catch (e) { /* storage blocked: the mode still works for this visit */ }
        },

        /**
         * Call from the host component's init(). Deliberately NOT named init(): spread into the
         * editor core it would collide with each page's own init, and the loser of that collision
         * would silently stop wiring its half of the editor.
         */
        initTextMode() {
            try {
                const stored = localStorage.getItem(storageKey);
                if (stored !== null) this.showLineBreaks = stored === '1';
            } catch (e) { /* storage blocked: keep the default */ }
        },
    };
}
