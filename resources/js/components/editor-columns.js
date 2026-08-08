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
        // Whether the grid has switched to honouring those widths exactly (see _enterSizedMode)
        columnsSized: false,
        // Total width the grid takes in that mode
        gridWidth: 0,
        // Columns whose width was only PHOTOGRAPHED when the grid switched to exact widths, as
        // opposed to dragged by someone. They have no handle of their own (the tag columns), so
        // without telling the two apart the grid could never be given back to the browser.
        _snapshotCols: null,
        // Live drag, deliberately off the reactive path (see the module comment)
        _resize: null,

        /** Rewrite the stylesheet from a width map. */
        _writeColumnWidths(widths, gridWidth) {
            let style = document.getElementById(STYLE_ELEMENT_ID);
            if (!style) {
                style = document.createElement('style');
                style.id = STYLE_ELEMENT_ID;
                document.head.appendChild(style);
            }

            const rules = Object.entries(widths)
                .map(([col, width]) => `.editor-grid [data-col="${CSS.escape(col)}"]`
                    + `{width:${width}px;min-width:${width}px;max-width:${width}px}`);

            // The grid stops sizing itself to its box and takes exactly what its columns add up
            // to. Without this, dragging one edge would squeeze its neighbours to keep the total
            // at 100%.
            if (gridWidth) {
                rules.push(`.editor-grid.cols-sized{table-layout:fixed;width:${Math.round(gridWidth)}px}`);
            }

            style.textContent = rules.join('\n');
        },

        applyColumnWidths() {
            this._writeColumnWidths(this.columnWidths, this.columnsSized ? this.gridWidth : 0);
        },

        /**
         * Take the grid off the automatic layout, without moving anything.
         *
         * A width declared on a table cell under `table-layout: auto` is only a MINIMUM, and
         * max-width does not apply to cells at all. Measured live: pinning the key column to the
         * 488px it already occupied made it 647px, because the table still had spare room and
         * went on handing the surplus around. The edge therefore jumped away from the cursor
         * before the drag had begun — and only when the grid fit on screen, which is why
         * selecting a branch appeared to cure it: an overflowing table has no surplus to hand out.
         *
         * Honouring a width means `table-layout: fixed`. Switching to it would itself rearrange
         * every column, so the current widths are photographed first: the grid enters the new
         * mode looking exactly as it did. Columns sized by a class (the index at 4rem, the tag
         * columns at 3rem) keep theirs — fixed layout reads those too.
         */
        _enterSizedMode() {
            if (this.columnsSized) return;

            const table = (this.$refs.gridBox || document).querySelector('table.editor-grid');
            if (!table) return;

            this._snapshotCols = new Set();
            table.querySelectorAll('thead th[data-col]').forEach((th) => {
                const col = th.dataset.col;
                if (this.columnWidths[col] === undefined) {
                    this.columnWidths[col] = Math.round(th.getBoundingClientRect().width);
                    this._snapshotCols.add(col);
                }
            });

            this.gridWidth = Math.round(table.getBoundingClientRect().width);
            this.columnsSized = true;
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

        /** The addressable columns, left to right as the header lays them out. */
        _columnOrder() {
            const table = (this.$refs.gridBox || document).querySelector('table.editor-grid');
            if (!table) return [];
            return Array.from(table.querySelectorAll('thead th[data-col]'), (th) => th.dataset.col);
        },

        /**
         * A column meant to take up slack: one the person can resize themselves.
         *
         * The criterion is the handle, and it says exactly the right thing — a column offered for
         * dragging is a column whose width is a matter of taste, so it is also one that may be
         * stretched. A tag column has none: it holds a badge, and it should still hold a badge
         * after someone narrows the key beside it.
         */
        _isFlexible(col) {
            const root = this.$refs.gridBox || document;
            return !!root.querySelector(`thead [data-resize-col="${CSS.escape(col)}"]`);
        },

        /**
         * Where the width taken or given by a drag comes from and goes to.
         *
         * A data grid is not a spreadsheet: there is nothing past the last column, so the grid
         * must never end short of its box. Narrowing a column therefore STRETCHES the ones to its
         * right — which is what the automatic layout used to do on its own, and what was lost the
         * moment widths started being declared. A filler column was tried instead and was exactly
         * the spreadsheet answer: a phantom column that belongs to no data.
         *
         * Widening is the opposite and stops at a floor: the right-hand columns give ground until
         * they reach MIN_COLUMN_WIDTH, after which the grid simply grows past its box and scrolls.
         * Nothing is ever squeezed out of existence to keep a total.
         *
         * Distributed from the widths PHOTOGRAPHED at mousedown, never from the live ones: taking
         * a share of a share at every mouse move compounds its own rounding and the columns drift
         * over a long drag.
         */
        _distribute(newWidth) {
            const drag = this._resize;
            const widths = { ...drag.baseWidths, [drag.col]: newWidth };
            const fixedPart = drag.baseGridWidth
                - Object.values(drag.baseWidths).reduce((sum, w) => sum + w, 0);

            let total = fixedPart + Object.values(widths).reduce((sum, w) => sum + w, 0);

            // Short of the box: the flexible columns to the right take up the slack, each in
            // proportion to what it already occupies — a wide value column absorbs most of it and
            // a narrow one is not doubled in size to satisfy an arithmetic mean.
            const slack = drag.boxWidth - total;
            if (slack > 0 && drag.rightOf.length) {
                const base = drag.rightOf.reduce((sum, col) => sum + (drag.baseWidths[col] || 0), 0);
                let given = 0;
                drag.rightOf.forEach((col, index) => {
                    // The last one takes what rounding left over, so the sum lands on the box
                    const share = index === drag.rightOf.length - 1
                        ? slack - given
                        : Math.round(slack * ((drag.baseWidths[col] || 0) / (base || 1)));
                    given += share;
                    widths[col] = (drag.baseWidths[col] || 0) + share;
                });
                total = drag.boxWidth;
            }

            return { widths, gridWidth: total };
        },

        startColumnResize(event) {
            const handle = event.target.closest('[data-resize-col]');
            if (!handle) return;
            const col = handle.dataset.resizeCol;
            // Measured on a real cell of that column rather than on the header the handle sits
            // in: a branch header spans the tag and the value, and the pair is what moves
            const cell = document.querySelector(`.editor-grid [data-col="${CSS.escape(col)}"]`);
            if (!cell) return;
            event.preventDefault();

            // BEFORE measuring, or the first width read would be the one the automatic layout is
            // about to abandon
            this._enterSizedMode();

            const order = this._columnOrder();
            this._resize = {
                col,
                startX: event.clientX,
                startWidth: cell.getBoundingClientRect().width,
                width: null,
                // Photographed once, at the start: distributing from the LIVE widths would
                // compound its own rounding at every mouse move and drift across a long drag
                baseWidths: { ...this.columnWidths },
                baseGridWidth: this.gridWidth,
                // What gives and takes room: the columns to the right that ARE resizable. A
                // column with no handle — the tag columns, a badge wide — was never meant to
                // stretch, and sharing the slack equally with one made it grow a hundred pixels.
                rightOf: order.slice(order.indexOf(col) + 1).filter((c) => this._isFlexible(c)),
                // What the grid must never be narrower than
                boxWidth: (this.$refs.gridBox && this.$refs.gridBox.clientWidth) || 0,
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

            // Once no DELIBERATE width is left, the grid goes back to arranging itself — which is
            // what clearing the last one asked for. The photographed widths go with it: they were
            // only there to keep the switch from moving anything, and several of them have no
            // handle to clear them individually.
            const snapshot = this._snapshotCols || new Set();
            const deliberate = Object.keys(this.columnWidths).filter((col) => !snapshot.has(col));
            if (deliberate.length === 0) {
                Object.keys(this.columnWidths).forEach((col) => delete this.columnWidths[col]);
                this._snapshotCols = null;
                this.columnsSized = false;
                this.gridWidth = 0;
            }
            this.applyColumnWidths();
            this.onColumnsResized();
            this.persistUiState();
        },

        initEditorColumns() {
            this._onColumnResizeMove = (event) => {
                if (!this._resize) return;
                this._resize.width = Math.max(
                    MIN_COLUMN_WIDTH,
                    Math.round(this._resize.startWidth + (event.clientX - this._resize.startX))
                );
                const { widths, gridWidth } = this._distribute(this._resize.width);
                // Written straight to the stylesheet: the component (and the two hundred rows
                // watching it) only hears about it on drop
                this._writeColumnWidths(widths, gridWidth);
                this.onColumnsResized();
            };

            this._onColumnResizeEnd = () => {
                if (this._resize) {
                    if (this._resize.width !== null) {
                        const { widths, gridWidth } = this._distribute(this._resize.width);
                        Object.entries(widths).forEach(([col, width]) => {
                            this.columnWidths[col] = width;
                        });
                        this.gridWidth = gridWidth;
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
