/**
 * Freezing the reference column, so a comparison always has something to compare against.
 *
 * The key column is frozen because a line has to keep its identity while the values scroll past.
 * On a merge screen there is a second thing you never want to lose: the version you are
 * arbitrating FOR. With three branches side by side, reading the third one means the Main has
 * long left the screen — and the whole point of the row is the difference between them.
 *
 * So the reference column can be pinned, on demand: a pin on its header, off by default, because
 * freezing a wide column costs screen width and only pays off when the grid is wide enough to
 * scroll.
 *
 * WHY THE OFFSETS ARE MEASURED AND NOT WRITTEN DOWN. The key column is frozen at a fixed left
 * offset because the index column beside it has a pinned width. Nothing else does: the key column
 * is resizable, so where the next frozen column starts depends on where the person last dragged
 * an edge. A static class cannot say "just after the key", and a class recomputed per render would
 * fight the resize. The offsets are therefore measured from the header cells — whose WIDTHS are
 * stable no matter how far the grid is scrolled, unlike their positions — and written into one
 * stylesheet rule per column, exactly as editor-columns.js does for widths.
 *
 * Its own stylesheet element, on purpose: two modules writing the same one would overwrite each
 * other's rules on every update.
 */

const STYLE_ELEMENT_ID = 'editor-pin-offsets';

export function editorPin() {
    return {
        // Whether the reference column (tag + value) travels with the key column.
        // Persisted with the rest of the UI state: it is an arrangement of the work in progress,
        // not a durable preference about the site.
        pinMain: false,

        togglePinMain() {
            this.pinMain = !this.pinMain;
            this.persistUiState();
            this.$nextTick(() => this.remeasurePinOffsets());
        },

        /**
         * Measure now, and again once the browser has finished with the layout.
         *
         * Pinning changes the columns' own borders and backgrounds, so the widths read in the
         * same tick as the class was added are the ones from BEFORE it. A five-pixel gap opened
         * between the frozen columns and closed later, when anything else happened to trigger a
         * fresh measurement — which is exactly the "sometimes live, sometimes afterwards" of it.
         */
        remeasurePinOffsets() {
            this.applyPinOffsets();
            requestAnimationFrame(() => this.applyPinOffsets());
        },

        /**
         * Where each pinned column starts: the sum of the widths of the frozen columns before it.
         *
         * Measured on the HEADER cells and by width, never by position: a sticky cell reports the
         * place it is currently stuck to, so reading its left edge while the grid is scrolled
         * sideways would feed the layout its own displacement and walk the columns off screen.
         */
        applyPinOffsets() {
            const box = this.$refs.gridBox;
            const style = this._pinStyleElement();
            if (!this.pinMain || !box) {
                style.textContent = '';
                return;
            }

            const widthOf = (selector) => {
                const cell = box.querySelector('thead ' + selector);
                if (!cell) return 0;
                // An x-show'd column is still in the DOM; a hidden one occupies nothing
                if (cell.offsetParent === null && cell.getClientRects().length === 0) return 0;
                return cell.getBoundingClientRect().width;
            };

            const indexWidth = this.showIndexColumn ? widthOf('th.sticky.left-0') : 0;
            const keyWidth = widthOf('th[data-col="key"]');
            const tagWidth = widthOf('th[data-col="' + this.pinTagCol + '"]');

            const tagLeft = Math.round(indexWidth + keyWidth);
            const valueLeft = Math.round(tagLeft + tagWidth);

            // The RULES are written once and never touched again; only the two numbers move,
            // as inherited custom properties on the box.
            //
            // Rewriting the stylesheet on every mouse move was tearing the frozen block apart
            // mid-drag: the header followed the edge while the BODY cells stayed at a stale
            // offset and the next column rode over them. Replacing a stylesheet's text destroys
            // and rebuilds its rules, and a sticky element being re-anchored by a rule that no
            // longer exists for an instant is not something a compositor is obliged to keep up
            // with. A custom property is a value change on an element — the offset moves without
            // the rule ever going away.
            const rules =
                `.editor-grid.pin-main [data-col="${this.pinTagCol}"]{left:var(--pin-tag-left,0px)}\n`
                + `.editor-grid.pin-main [data-col="${this.pinValueCol}"]{left:var(--pin-value-left,0px)}`;
            if (style.textContent !== rules) {
                style.textContent = rules;
            }

            // 🔴 On the component ROOT, not on the box.
            //
            // A page can hold more than one grid on the same columns — the merge screen puts the
            // file settings and the description on the lines' own grid so that everything reads
            // down the page in one alignment — and each lives in its own scroll box. A custom
            // property set on one box does not reach the others: they inherit from their
            // ancestors, not from a sibling. So their frozen cells fell back to `left: 0` and
            // stuck to the edge of their own box while the lines stuck 264px further in, which
            // reads as the pin working on one table and being broken on the next.
            const root = box.closest('[x-data]') || box;
            root.style.setProperty('--pin-tag-left', tagLeft + 'px');
            root.style.setProperty('--pin-value-left', valueLeft + 'px');
        },

        _pinStyleElement() {
            let style = document.getElementById(STYLE_ELEMENT_ID);
            if (!style) {
                style = document.createElement('style');
                style.id = STYLE_ELEMENT_ID;
                document.head.appendChild(style);
            }
            return style;
        },

        /** Which columns the pin covers. Overridden by the page: Main here, Local on the mod's. */
        pinTagCol: 'mainTag',
        pinValueCol: 'main',

        /**
         * editor-columns tells us a width changed — including DURING a drag, so the frozen block
         * follows the edge instead of waiting for the mouse to come up.
         *
         * It has to be told rather than observed: the width map is mutated key by key, and an
         * effect that only reads the map subscribes to nothing. Without this, shrinking the key
         * column left the pinned columns stuck at their old offset, sitting on top of the column
         * next to them.
         */
        onColumnsResized() {
            this.applyPinOffsets();
        },

        /** Call from the host component's init(), after restoreUiState. */
        initEditorPin() {
            // Re-measured whenever anything that moves those edges changes: the rows arriving,
            // the index column appearing, a column being resized, the window changing shape.
            window.Alpine.effect(() => {
                this.allKeys.length;
                this.showIndexColumn;
                this.columnWidths;
                this.pinMain;
                this.$nextTick(() => this.remeasurePinOffsets());
            });

            window.addEventListener('resize', () => this.remeasurePinOffsets(), { passive: true });
        },
    };
}
