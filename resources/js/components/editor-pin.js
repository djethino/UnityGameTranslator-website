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
            const rules = this._pinRules();
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

        /**
         * Everything the frozen pair looks like, built from the two column NAMES the page gives.
         *
         * 🔴 **Written here rather than in the stylesheet, and that is the whole point.** These
         * rules used to list the columns they applied to — `mainTag, main, localTag, local`. Then
         * the comparison screen started swapping its target with the direction (publishing freezes
         * the server's column, not the player's), and a hand-written list cannot know a name nobody
         * has added to it. Its failure is silence: the pin turns on, reports itself on, and freezes
         * nothing — with no rule missing from any file a reader would open.
         *
         * ⚠ The selection tint is keyed on `selected-<column>`, the class every editor's
         * `getCellClass` produces. Same single source: name the column, get its colour.
         *
         * ⚠ Rebuilt only when the two names change — never during a drag, where replacing a
         * stylesheet's text tore the frozen block apart (see applyPinOffsets).
         */
        _pinRules() {
            const tag = `.editor-grid.pin-main [data-col="${this.pinTagCol}"]`;
            const value = `.editor-grid.pin-main [data-col="${this.pinValueCol}"]`;
            const both = (where) =>
                `.editor-grid.pin-main ${where} [data-col="${this.pinTagCol}"],`
                + `.editor-grid.pin-main ${where} [data-col="${this.pinValueCol}"]`;
            // The tint class the page's own getCellClass puts on a chosen cell.
            const held = '.selected-' + this.pinValueCol;
            const edge = '4px 0 8px -4px rgba(0,0,0,0.6)';

            return [
                // Frozen, and opaque: a translucent cell would let the scrolled columns through.
                `${both('thead')}{position:sticky;z-index:30;background-color:rgb(17 24 39)}`,
                `${both('tbody')}{position:sticky;z-index:10;background-color:rgb(31 41 55)}`,
                `${tag}{left:var(--pin-tag-left,0px)}`,
                `${value}{left:var(--pin-value-left,0px)}`,

                // The edge of the frozen block moves with it: the key column stops being the last
                // frozen thing, so the shadow that says "content passes behind here" belongs on the
                // reference column. A box-shadow, and it has to be — an element sticking out to the
                // right ENLARGES the scrollable area, and invented eight pixels of scroll on a grid
                // where nothing overflowed. Which means sharing box-shadow with the selection
                // rings, so each state below restates the edge.
                `${value}{border-right:1px solid rgb(55 65 81);box-shadow:${edge}}`,
                `${value}${held}{box-shadow:inset 0 0 0 2px rgb(34 197 94),${edge}}`,
                `${value}.selected-manual{box-shadow:inset 0 0 0 2px rgb(168 85 247),${edge}}`,
                `${value}.deleted-cell{box-shadow:inset 0 0 0 2px rgb(239 68 68),${edge}}`,

                // Flattened tints, for the reason given in app.css: there is no row behind a frozen
                // column, only the columns scrolling past it.
                `.editor-grid.pin-main tbody [data-col="${this.pinValueCol}"]${held}`
                    + `{background-color:rgb(26 62 50)!important}`,
                `.editor-grid.pin-main tbody [data-col="${this.pinValueCol}"].selected-manual`
                    + `{background-color:rgb(60 35 95)!important}`,
                // A row on its way out, on both cells of the pair — the tag included, or half the
                // frozen block would go on looking ordinary.
                `.editor-grid.pin-main tbody [data-col="${this.pinTagCol}"].deleted-cell,`
                    + `.editor-grid.pin-main tbody [data-col="${this.pinValueCol}"].deleted-cell`
                    + `{background-color:rgb(79 35 42)!important}`,

                // An unclaimed hold: the hue keeps saying which column is held, the wash and the
                // dashes say how firmly. Restated at this specificity because these selectors carry
                // `!important` — and pinning is what people do to read a long merge.
                `.editor-grid.pin-main tbody [data-col="${this.pinValueCol}"]${held}.selection-unclaimed`
                    + `{background-color:rgb(23 44 39)!important}`,
                `${value}${held}.selection-unclaimed`
                    + `{box-shadow:${edge};outline:2px dashed rgb(34 197 94);outline-offset:-2px}`,

                `.editor-grid.pin-main tbody [data-col="${this.pinTagCol}"].tag-changed-cell`
                    + `{background-color:rgb(48 37 79)!important}`,
            ].join('\n');
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
