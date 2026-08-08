/**
 * Resizable columns for the editor grids.
 *
 * A translation grid holds one thing per column and they are never the same size: a key column
 * of short identifiers next to a value column of full sentences, or four versions of the same
 * paragraph to compare word by word. The browser's own share-out is a guess made from the first
 * rows, and no single guess fits every file — so the person reading gets to move the edge.
 *
 * Widths are applied through ONE stylesheet rule per column, not through inline styles on the
 * header cell. Two reasons, both of them things that were tried:
 *
 *  - a table lays a column out to fit ALL its cells, so constraining only the <th> constrains
 *    nothing: the widest row wins and the edge springs back;
 *  - the rows are rendered by x-for over a window of two hundred, and the branch headers by
 *    another over data fetched after the page. Anything applied element by element has to be
 *    re-applied every time that window scrolls or a column appears. A rule matching
 *    [data-col="..."] applies itself, including to cells that do not exist yet.
 *
 * The rule is rewritten directly during the drag rather than through reactive state: a drag
 * fires a mousemove every few milliseconds, and routing that through Alpine would re-render the
 * whole window of rows on each one. The value only reaches the component — and storage — when
 * the edge is dropped.
 *
 * Shared by the three editors, like the rest of the core: an edge that moves on one grid and
 * not on the next reads as a broken screen rather than as a screen without the feature.
 */

/** Below this a column is a sliver that holds nothing — and cannot be grabbed back. */
const MIN_COLUMN_WIDTH = 60;

const STYLE_ELEMENT_ID = 'editor-column-widths';

export function editorColumns() {
    return {
        // col id -> width in pixels. Persisted with the rest of the UI state.
        columnWidths: {},
        // Live drag, deliberately off the reactive path (see the module comment)
        _resize: null,

        /** Rewrite the stylesheet from a width map. */
        _writeColumnWidths(widths) {
            let style = document.getElementById(STYLE_ELEMENT_ID);
            if (!style) {
                style = document.createElement('style');
                style.id = STYLE_ELEMENT_ID;
                document.head.appendChild(style);
            }
            style.textContent = Object.entries(widths)
                .map(([col, width]) => `.editor-grid [data-col="${CSS.escape(col)}"]`
                    + `{width:${width}px;min-width:${width}px;max-width:${width}px}`)
                .join('\n');
        },

        applyColumnWidths() {
            this._writeColumnWidths(this.columnWidths);
        },

        /**
         * Hook: a column's width has just changed.
         *
         * Announced rather than watched, because the width map is mutated key by key and an
         * Alpine effect that merely reads the map subscribes to nothing — it would go on holding
         * the old numbers. Anything whose layout is measured FROM these widths has to be told.
         * editor-pin listens: its frozen columns start where the key column ends, so shrinking
         * the key left the pinned block stuck at its old offset, overlapping the column beside it.
         */
        onColumnsResized() {},

        startColumnResize(event) {
            const handle = event.target.closest('[data-resize-col]');
            if (!handle) return;
            const col = handle.dataset.resizeCol;
            // Measured on a real cell of that column rather than on the header the handle sits
            // in: a branch header spans the tag and the value, and the pair is what moves
            const cell = document.querySelector(`.editor-grid [data-col="${CSS.escape(col)}"]`);
            if (!cell) return;
            event.preventDefault();

            this._resize = {
                col,
                startX: event.clientX,
                startWidth: cell.getBoundingClientRect().width,
                width: null,
            };

            // On the document, not on the handle: the pointer outruns a six-pixel strip long
            // before the drag is over, and a resize that stops when the cursor slips off the
            // edge is worse than no resize at all
            document.addEventListener('mousemove', this._onColumnResizeMove);
            document.addEventListener('mouseup', this._onColumnResizeEnd);
            // Every column here is a text column: without this the drag selects paragraphs on
            // its way across
            document.body.classList.add('select-none', 'cursor-col-resize');
        },

        _onColumnResizeMove: null,   // bound in initEditorColumns
        _onColumnResizeEnd: null,

        /**
         * Double-click on the edge gives the column back to the browser — the way out of a drag
         * that went wrong, without hunting for the pixel it started at.
         */
        resetColumnWidth(event) {
            const handle = event.target.closest('[data-resize-col]');
            if (!handle) return;
            delete this.columnWidths[handle.dataset.resizeCol];
            this.applyColumnWidths();
            this.persistUiState();
        },

        initEditorColumns() {
            this._onColumnResizeMove = (event) => {
                if (!this._resize) return;
                this._resize.width = Math.max(
                    MIN_COLUMN_WIDTH,
                    Math.round(this._resize.startWidth + (event.clientX - this._resize.startX))
                );
                // Written straight to the stylesheet: the component (and the two hundred rows
                // watching it) only hears about it on drop
                this._writeColumnWidths({ ...this.columnWidths, [this._resize.col]: this._resize.width });
                this.onColumnsResized();
            };

            this._onColumnResizeEnd = () => {
                if (this._resize) {
                    if (this._resize.width !== null) {
                        this.columnWidths[this._resize.col] = this._resize.width;
                        this.persistUiState();
                    }
                    this._resize = null;
                    this.applyColumnWidths();
                    this.onColumnsResized();
                }
                document.removeEventListener('mousemove', this._onColumnResizeMove);
                document.removeEventListener('mouseup', this._onColumnResizeEnd);
                document.body.classList.remove('select-none', 'cursor-col-resize');
            };

            // After restoreUiState, which is what fills columnWidths
            this.$nextTick(() => this.applyColumnWidths());
        },
    };
}
