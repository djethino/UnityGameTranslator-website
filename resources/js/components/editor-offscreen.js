/**
 * Telling a reader that the thing they are looking for is off to one side.
 *
 * A translation grid is routinely wider than the window: four contributions on a merge screen
 * come to twice it. So the cell that carries a row's answer is very often outside the box —
 * measured on a real lineage, the box ended at 1444px and the third contribution began at 1450.
 * Two rows, both settled, one of them looking untouched. That is not a small annoyance: it is the
 * reading that makes somebody conclude the screen forgot half their work.
 *
 * This module answers one question — WHERE is a given column, relative to what can be seen — and
 * offers to go there. It knows nothing about selections, merges or branches, which is why it can
 * be shared: a screen that has no notion of a chosen side simply never asks.
 *
 * ⚠ **The frozen block is measured, not declared.** Which columns are stuck to the left changes
 * with the pin, with the capture-order column, and with every drag of the key column's edge. So
 * the edge of the scrolling area is read from the elements themselves: the rightmost header that
 * is actually sticky. Nothing here has to be told when the pin moves.
 *
 * ⚠ **Published only when the answer changes.** This drives a marker in a window of two hundred
 * rendered rows; assigning on every scroll event would re-render all of them continuously for a
 * value that only changes when a column crosses an edge.
 *
 * Requires `gridBox` as an x-ref, like the rest of the core.
 */
export function editorOffScreen() {
    return {
        /** data-col -> 'left' | 'right'. A column that can be seen is absent. */
        offSide: {},

        /**
         * Where the scrolling area starts, and which columns are moored there.
         *
         * ⚠ A frozen column can never be off screen: it is drawn where it is pinned, whatever the
         * scroll. Reporting it as "left" — which measuring alone does, since its right edge is by
         * definition at the edge of the scrolling area — would put a "go left" mark on every row
         * pointing at a column already under the reader's eyes.
         */
        _frozenColumns(box, table) {
            let edge = box.getBoundingClientRect().left;
            const moored = new Set();

            for (const th of table.querySelectorAll('thead th')) {
                const style = getComputedStyle(th);
                // A sticky cell with no left offset is stuck to the TOP, not to the side: the
                // whole header row is, and taking it for a frozen column would put the edge of
                // the scrolling area past the last column of the grid.
                if (style.position !== 'sticky' || style.left === 'auto') continue;
                edge = Math.max(edge, th.getBoundingClientRect().right);
                if (th.dataset.col) moored.add(th.dataset.col);
            }

            return { edge, moored };
        },

        refreshOffScreenSides() {
            const box = this.$refs.gridBox;
            const table = box && box.querySelector('table.editor-grid');
            if (!table) return;

            const { edge: viewLeft, moored } = this._frozenColumns(box, table);
            const viewRight = box.getBoundingClientRect().right;

            const next = {};
            for (const th of table.querySelectorAll('thead th[data-col]')) {
                if (moored.has(th.dataset.col)) continue;

                const rect = th.getBoundingClientRect();
                if (rect.right <= viewLeft + 1) next[th.dataset.col] = 'left';
                else if (rect.left >= viewRight - 1) next[th.dataset.col] = 'right';
            }

            if (JSON.stringify(this.offSide) !== JSON.stringify(next)) this.offSide = next;
        },

        /** What the mark says when somebody rests on it. Set by the page: it is translated. */
        offScreenHint: '',

        /**
         * The column a chosen source lives in.
         *
         * ⚠ A rewording shows in the Main's own column, because that is where it will be written
         * — so it points there, not at the contribution it was written over.
         */
        columnOfSource(source) {
            if (source === undefined || source === null || source === '') return null;
            if (source === 'main' || source === 'manual') return 'main';
            if (typeof source === 'number') return 'branch-' + source;
            if (String(source).startsWith('branch_')) return 'branch-' + String(source).slice(7);
            return 'branch-' + source;
        },

        /** '«' or '»' when this row's answer is out of sight that way, empty when it can be seen. */
        answerArrow(source) {
            const col = this.columnOfSource(source);
            const side = col ? this.offSide[col] : null;
            return side === 'left' ? '«' : side === 'right' ? '»' : '';
        },

        // 🔴 Shown or not, as a BOOLEAN. The templates asked `answerArrow(x) === 'left'`, which
        // the Alpine CSP build's restricted parser cannot evaluate — and an expression it cannot
        // parse does not throw, it comes out falsey. Every mark rendered, every one hidden,
        // nothing in the console. A bare call is the grammar the whole project uses.
        answerLeft(source) { return this.answerArrow(source) === '«'; },
        answerRight(source) { return this.answerArrow(source) === '»'; },

        /**
         * The mark's icon and its colour, in one class.
         *
         * ⚠ A FontAwesome chevron rather than a guillemet: it is the glyph this interface already
         * uses to mean "there is more this way", and it is a solid shape where « is two thin
         * strokes that vanish over dense text.
         *
         * ⚠ Brighter tints than the selection rings they stand for. A ring is read on a calm
         * background; this floats over game text full of colour codes, and the tint that works
         * behind a cell is not the one that survives on top of one.
         */
        answerIconClass(source) {
            const arrow = this.answerArrow(source);
            if (!arrow) return '';
            const icon = arrow === '«' ? 'fa-chevron-left' : 'fa-chevron-right';
            const colour = source === 'manual' ? 'text-fuchsia-300'
                : source === 'main' ? 'text-emerald-300' : 'text-sky-300';
            return icon + ' ' + colour;
        },

        /** Go to the answer. The mark then disappears, which is its own confirmation. */
        goToAnswer(source) {
            const col = this.columnOfSource(source);
            if (col) this.scrollColumnIntoView(col);
        },

        /**
         * Bring a column into view, by the shortest move that makes it readable.
         *
         * ⚠ "Readable" is not "inside the box": a column can be inside it and still be hidden
         * behind the frozen block. The target is the edge of the SCROLLING area, which is what
         * _frozenRightEdge answers.
         *
         * ⚠ Smooth, unless the reader has asked for less movement. A jump of two thousand pixels
         * with no travel leaves you wondering whether the table changed or you did.
         */
        scrollColumnIntoView(col) {
            const box = this.$refs.gridBox;
            const table = box && box.querySelector('table.editor-grid');
            const th = table && table.querySelector('thead th[data-col="' + CSS.escape(col) + '"]');
            if (!th) return;

            const { edge: viewLeft } = this._frozenColumns(box, table);
            const boxRect = box.getBoundingClientRect();
            const rect = th.getBoundingClientRect();

            let delta = 0;
            if (rect.left < viewLeft) delta = rect.left - viewLeft;
            else if (rect.right > boxRect.right) delta = rect.right - boxRect.right;
            if (!delta) return;

            const still = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            box.scrollTo({ left: box.scrollLeft + delta, behavior: still ? 'auto' : 'smooth' });
        },
    };
}
