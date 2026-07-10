/**
 * Shared core for the client-side translation editors: merge-preview
 * (Compare flow) and edit-session (anonymous live edit). They are ONE
 * application for the user — features and UX must stay aligned, so any
 * behavior that makes sense for both lives here, once.
 *
 * The pages keep a thin inline Alpine component (nonce'd script) that
 * spreads editorCore() and adds page-specific state/logic. Inline scripts
 * can't import bundled modules, so app.js exposes this factory on
 * window.UGT.
 *
 * Alpine CSP build constraints (apply to the PAGE templates, not here):
 *  - no property assignments in inline expressions (obj.prop = x throws)
 *  - x-model only on top-level identifiers (hence editModalValue, replaceValue)
 *  - x-html is prohibited entirely — use x-safe-html (custom directive in
 *    app.js) with the highlight helpers below, which escape everything
 *  - nested mutations happen inside these JS methods, which is fine
 */

/**
 * Normalize line endings to Unix format (\n). Order matters: \r\n first,
 * then \r, otherwise \r\n would become \n\n. Keys must stay consistent
 * across platforms.
 */
export function normalizeLineEndings(text) {
    if (typeof text !== 'string') return text;
    return text.replace(/\r\n/g, '\n').replace(/\r/g, '\n');
}

/**
 * Compose a page component on top of the shared core.
 *
 * NOT a spread: `{ ...core }` would EVALUATE the core's getters
 * (filteredKeys & co) at composition time, before the page half exists,
 * and copy their values instead of the accessors. Property descriptors
 * keep getters as getters; page members override core ones on collision.
 */
export function composeEditor(config, page) {
    return Object.defineProperties(editorCore(config), Object.getOwnPropertyDescriptors(page));
}

/**
 * Shared editor state + behaviors.
 *
 * config:
 *  - persistKey  : sessionStorage key for UI state (search/filters/sort)
 *  - pendingKey  : sessionStorage key for PENDING work (edits/tags/deletions).
 *                  Defaults to persistKey + '_pending'. Pages whose persistKey
 *                  is shared across documents (edit sessions, translations)
 *                  MUST scope this one per document: restored pending edits
 *                  from another file would show up as ghost modifications
 *  - filters     : default filter map (page-specific names allowed)
 *
 * The consuming component must define (used by the core):
 *  - rowPassesFilters(key)          page-specific category/tag filtering
 *  - rowMatchesSearch(key, query)   respecting this.searchScope, and matching
 *                                   pending edited values on their OLD value
 *                                   too (an edit must not hide its row)
 *  - rowSortValue(key, column)      value for non-'key' sort columns, based
 *                                   on STORED values (rows must not jump
 *                                   around while an edit is pending)
 *  - storedValue(key)               the row's STORED editable value (the one
 *                                   the edit modal opens on) — used by
 *                                   replace and the placeholder guard
 *  - allKeys                        array of keys to list
 */
export function editorCore(config) {
    const pendingKey = config.pendingKey || (config.persistKey + '_pending');

    return {
        // ── Pending work (kept until the page-specific save) ─────────────
        editedValues: {},   // key -> new value
        tagChanges: {},     // key -> { newTag, originalTag, value }
        deletions: {},      // key -> true (marked for removal on save)

        // ── Edit modal ────────────────────────────────────────────────────
        editModal: {
            open: false,
            key: '',
            originalValue: ''
        },
        // Top-level on purpose: the Alpine CSP build prohibits property
        // assignments in inline expressions, so x-model can't target
        // editModal.value
        editModalValue: '',

        // ── Tag dropdown (Skip only, same rule everywhere) ────────────────
        tagDropdown: {
            open: false,
            key: '',
            currentTag: '',
            originalTag: '',
            value: '',
            x: 0,
            y: 0
        },

        // ── Search / filters / sort (persisted across refreshes) ─────────
        filters: { ...config.filters },
        searchQuery: '',
        // Debounced copy actually used by the filtering pipeline: on large
        // RPG files (tens of thousands of keys) re-filtering on every
        // keystroke is perceptible — waiting for a typing pause is not
        _debouncedQuery: '',
        _debounceTimer: null,
        searchScope: 'both', // 'both' | 'keys' | 'values'
        sortColumn: 'key',
        sortDirection: 'asc',

        // ── Search navigation + replace ───────────────────────────────────
        currentMatchIndex: 0,
        replaceOpen: false,
        // Top-level on purpose (same CSP constraint as editModalValue)
        replaceValue: '',
        // True when the main search bar scrolled off-screen — the compact
        // floating search (partials/editor-floating-search) shows instead,
        // so prev/next navigation never strands the user without controls
        searchBarOffscreen: false,
        // Row cursor visible without a search (keyboard review via arrows)
        cursorActive: false,

        // ── Windowed rendering (large files: thousands of rows) ──────────
        // 200 rows ≈ 10+ screens of long text: re-rendering the window on
        // filter/search changes is the dominant cost on real files, and it
        // scales linearly with this number
        displayLimit: 200,

        // ── filteredKeys memoization (see the getter) ─────────────────────
        _fkVersion: 0,
        _fkCache: [],

        /**
         * Wire persistence + the memoized filter pipeline. Call from the
         * component's init().
         */
        initEditorCore() {
            this.restoreUiState();
            this.restorePendingState();
            this._debouncedQuery = this.searchQuery;
            this.$watch('searchQuery', () => {
                this.persistUiState();
                clearTimeout(this._debounceTimer);
                this._debounceTimer = setTimeout(() => {
                    this._debouncedQuery = this.searchQuery;
                }, 200);
            });
            this.$watch('searchScope', () => this.persistUiState());
            // New search: the match cursor restarts from the first result
            this.$watch('_debouncedQuery', () => { this.currentMatchIndex = 0; });

            // Alpine does NOT memoize getters: every template consumer of
            // filteredKeys (x-for, counters, ...) would re-run the full
            // filter + sort — 4 times per interaction on a 20k-key file.
            // This effect owns the heavy compute instead: it re-runs once
            // per actual dependency change (its reads are tracked), and
            // consumers only subscribe to the version bump.
            // (Self-trigger on _fkVersion++ is filtered out by the
            // reactivity engine: an effect's own writes don't re-queue it.)
            window.Alpine.effect(() => {
                this._fkCache = this._computeFilteredKeys();
                this._fkVersion++;
            });

            // Track whether the main search bar is on screen (pages mark it
            // with x-ref="searchBar") to toggle the floating compact search
            if (this.$refs.searchBar && 'IntersectionObserver' in window) {
                new IntersectionObserver(entries => {
                    this.searchBarOffscreen = !entries[0].isIntersecting;
                }).observe(this.$refs.searchBar);
            }
        },

        // ── Deletions (marked, applied by the page-specific save) ────────

        isDeleted(key) {
            return this.deletions[key] === true;
        },

        toggleDelete(key) {
            this.setMatchCursor(key);
            if (this.deletions[key]) {
                delete this.deletions[key];
            } else {
                this.deletions[key] = true;
                // A deleted key can't also carry an edit or a tag change
                delete this.editedValues[key];
                delete this.tagChanges[key];
                this.onDeleteToggled(key);
            }
            this.persistPendingState();
        },

        /** Page hook: called when a key gets marked for deletion. */
        onDeleteToggled(key) {},

        // ── Per-row revert (the floating bar's "cancel all", row-sized) ──

        /** The row carries pending user work (edit, tag change, deletion). */
        rowHasPending(key) {
            return this.isEdited(key) || this.hasTagChange(key) || this.isDeleted(key);
        },

        /** Revert every pending change on this row. */
        revertRow(key) {
            delete this.editedValues[key];
            delete this.tagChanges[key];
            delete this.deletions[key];
            this.onRowReverted(key);
            this.persistPendingState();
        },

        /** Page hook: extra per-row state to drop on revert (e.g. selections). */
        onRowReverted(key) {},

        // ── Pending-state persistence (survives F5 until the save) ───────

        persistPendingState() {
            try {
                sessionStorage.setItem(pendingKey, JSON.stringify({
                    editedValues: this.editedValues,
                    tagChanges: this.tagChanges,
                    deletions: this.deletions,
                    extra: this.pendingExtraState()
                }));
            } catch (e) { /* storage full/blocked: non-essential */ }
        },

        restorePendingState() {
            try {
                const raw = sessionStorage.getItem(pendingKey);
                if (!raw) return;
                const state = JSON.parse(raw);
                if (state.editedValues && typeof state.editedValues === 'object') this.editedValues = state.editedValues;
                if (state.tagChanges && typeof state.tagChanges === 'object') this.tagChanges = state.tagChanges;
                if (state.deletions && typeof state.deletions === 'object') this.deletions = state.deletions;
                this.restorePendingExtra(state.extra);
            } catch (e) { /* corrupted state: keep defaults */ }
        },

        /** Call after a successful save: pending work is done. */
        clearPendingState() {
            this.editedValues = {};
            this.tagChanges = {};
            this.deletions = {};
            try {
                sessionStorage.removeItem(pendingKey);
            } catch (e) { /* non-essential */ }
        },

        /** Page hooks: extra pending state (e.g. merge selections). */
        pendingExtraState() { return null; },
        restorePendingExtra(extra) {},

        // ── Filtering pipeline ────────────────────────────────────────────

        /**
         * Memoized: the actual compute lives in the initEditorCore effect,
         * which re-runs once per real dependency change. Reading _fkVersion
         * here subscribes each consumer to those recomputes.
         */
        get filteredKeys() {
            this._fkVersion;
            return this._fkCache;
        },

        _computeFilteredKeys() {
            const query = this._debouncedQuery.toLowerCase().trim();

            const keys = this.allKeys.filter(key => {
                if (!this.rowPassesFilters(key)) return false;
                if (query && !this.rowMatchesSearch(key, query)) return false;
                return true;
            });

            const col = this.sortColumn;
            const dir = this.sortDirection === 'asc' ? 1 : -1;
            // Sort values are computed ONCE per key (Schwartzian transform):
            // computing lowercase strings inside the comparator — O(n log n)
            // times — dominated the sort cost on large files
            const decorated = keys.map(key =>
                [key, col === 'key' ? key.toLowerCase() : this.rowSortValue(key, col)]
            );
            decorated.sort((a, b) => {
                if (a[1] < b[1]) return -1 * dir;
                if (a[1] > b[1]) return 1 * dir;
                return 0;
            });

            return decorated.map(entry => entry[0]);
        },

        /** Rows actually rendered (windowed). */
        get visibleKeys() {
            return this.filteredKeys.slice(0, this.displayLimit);
        },

        /** Rows hidden by the window (0 = everything is shown). */
        get hiddenCount() {
            return Math.max(0, this.filteredKeys.length - this.displayLimit);
        },

        showMore() {
            this.displayLimit += 200;
        },

        // ── Row cursor: search navigation + keyboard review ──────────────
        // One cursor over the filtered rows. With a search active it is the
        // match cursor (n/m counter, Enter/Shift+Enter); the arrow keys use
        // the same cursor to review rows without a query. Navigation is per
        // ROW, not per occurrence: the unit of work in a translation editor
        // is the line.

        get hasQuery() {
            return this._debouncedQuery.trim() !== '';
        },

        /**
         * Rows matching the active search. The filter pipeline already
         * includes the query, so when one is active the filtered rows ARE
         * the matches.
         */
        get matchKeys() {
            return this.hasQuery ? this.filteredKeys : [];
        },

        get matchCount() {
            return this.matchKeys.length;
        },

        /** Clamped cursor: the list can shrink under it (filter change). */
        get safeMatchIndex() {
            return Math.min(this.currentMatchIndex, Math.max(0, this.filteredKeys.length - 1));
        },

        /** The row the cursor points at, when the cursor is visible. */
        get cursorKey() {
            if (!this.hasQuery && !this.cursorActive) return undefined;
            return this.filteredKeys[this.safeMatchIndex];
        },

        get matchCounterText() {
            if (!this.hasQuery) return '';
            return this.matchCount === 0 ? '0/0' : (this.safeMatchIndex + 1) + '/' + this.matchCount;
        },

        /** Stronger highlight on the row the cursor points at. */
        isCurrentMatchRow(index) {
            if (this.filteredKeys.length === 0 || index !== this.safeMatchIndex) return false;
            return (this.hasQuery && this.matchCount > 0) || this.cursorActive;
        },

        /** Enter = next, Shift+Enter = previous (IDE convention). */
        onSearchEnter(event) {
            if (event.shiftKey) {
                this.prevMatch();
            } else {
                this.nextMatch();
            }
        },

        nextMatch() {
            if (this.filteredKeys.length === 0) return;
            this.cursorActive = true;
            this.currentMatchIndex = (this.safeMatchIndex + 1) % this.filteredKeys.length;
            this.scrollToCurrentMatch();
        },

        prevMatch() {
            if (this.filteredKeys.length === 0) return;
            this.cursorActive = true;
            this.currentMatchIndex = (this.safeMatchIndex - 1 + this.filteredKeys.length) % this.filteredKeys.length;
            this.scrollToCurrentMatch();
        },

        /** Arrow keys: clamped at the edges (no wrap — more natural). */
        moveCursor(delta) {
            if (this.filteredKeys.length === 0) return;
            if (!this.cursorActive && !this.hasQuery) {
                // First arrow press reveals the cursor where it stands
                // instead of skipping a row the user never saw selected
                this.cursorActive = true;
                this.scrollToCurrentMatch();
                return;
            }
            this.cursorActive = true;
            this.currentMatchIndex = Math.min(
                Math.max(this.safeMatchIndex + delta, 0),
                this.filteredKeys.length - 1
            );
            this.scrollToCurrentMatch();
        },

        /**
         * Keyboard review (bound with @keydown.window on each editor's
         * root): ↑↓ move the cursor, V = the page's validate action,
         * E = edit, Delete = toggle deletion, Escape hides the cursor.
         * Form fields and open overlays always keep their keys.
         */
        handleEditorKeydown(event) {
            const tag = (event.target.tagName || '').toLowerCase();
            if (tag === 'input' || tag === 'textarea' || tag === 'select') return;
            if (this.editModal.open || this.tagDropdown.open) return;
            if (event.ctrlKey || event.metaKey || event.altKey) return;

            if (event.key === 'ArrowDown') {
                event.preventDefault();
                this.moveCursor(1);
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                this.moveCursor(-1);
            } else if (event.key === 'v' || event.key === 'V') {
                const key = this.cursorKey;
                if (key !== undefined) this.cursorPrimaryAction(key);
            } else if (event.key === 'e' || event.key === 'E') {
                const key = this.cursorKey;
                if (key !== undefined) {
                    event.preventDefault();
                    this.editCell(key, this.storedValue(key));
                }
            } else if (event.key === 'Delete') {
                const key = this.cursorKey;
                if (key !== undefined) this.toggleDelete(key);
            } else if (event.key === 'Escape') {
                this.cursorActive = false;
            }
        },

        /** Page hook: what V does on the cursor row (validate gestures). */
        cursorPrimaryAction(key) {},

        scrollToCurrentMatch() {
            // Only displayLimit rows are rendered: extend the window when
            // the cursor moves beyond it
            if (this.safeMatchIndex >= this.displayLimit) {
                this.displayLimit = this.safeMatchIndex + 200;
            }
            this.$nextTick(() => {
                const row = document.querySelector('[data-row-index="' + this.safeMatchIndex + '"]');
                if (row) row.scrollIntoView({ block: 'center', behavior: 'smooth' });
            });
        },

        /**
         * Move the search cursor onto a row the user just interacted with
         * (click, edit, delete) — "next" then resumes from there, IDE
         * style: clicking in the buffer moves the find caret. No scrolling,
         * the row is already on screen.
         */
        setMatchCursor(key) {
            if (!this.hasQuery) return;
            const index = this.matchKeys.indexOf(key);
            if (index !== -1) this.currentMatchIndex = index;
        },

        // ── Page-level scroll shortcuts (floating bar) ────────────────────

        scrollToTop() {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        },

        scrollToBottom() {
            window.scrollTo({ top: document.documentElement.scrollHeight, behavior: 'smooth' });
        },

        // ── Search highlighting ───────────────────────────────────────────
        // Values are arbitrary content: everything goes through escapeHtml
        // and only our own <mark> tags are injected.

        escapeHtml(text) {
            return String(text)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
        },

        _highlight(text) {
            const value = String(text ?? '');
            const query = this._debouncedQuery.toLowerCase().trim();
            if (!query) return this.escapeHtml(value);
            const lower = value.toLowerCase();
            let html = '';
            let pos = 0;
            let idx = lower.indexOf(query);
            while (idx !== -1) {
                html += this.escapeHtml(value.slice(pos, idx))
                    + '<mark class="search-mark">' + this.escapeHtml(value.slice(idx, idx + query.length)) + '</mark>';
                pos = idx + query.length;
                idx = lower.indexOf(query, pos);
            }
            return html + this.escapeHtml(value.slice(pos));
        },

        /** Highlight helpers honoring the search scope. */
        highlightValue(text) {
            return this.searchScope === 'keys' ? this.escapeHtml(String(text ?? '')) : this._highlight(text);
        },

        highlightKey(text) {
            return this.searchScope === 'values' ? this.escapeHtml(String(text ?? '')) : this._highlight(text);
        },

        // ── Replace (single-row only, on purpose) ─────────────────────────
        // A replacement is a HUMAN edit: the user navigates match by match
        // with the row in front of them, so it stages through the same path
        // as the edit modal (→ H on save, M/S preserved). Replace-all is
        // deliberately absent: it would stamp H on rows nobody read.

        get replaceDisabled() {
            // Keys are the game's source texts — never replaceable
            return !this.hasQuery || this.searchScope === 'keys' || this.matchCount === 0;
        },

        toggleReplace() {
            this.replaceOpen = !this.replaceOpen;
            this.persistUiState();
        },

        /**
         * Replace every occurrence of the query in the current match row's
         * VALUE (case-insensitive match, literal replacement), stage it as
         * a manual edit, then advance. Rows matching on their key only, or
         * marked for deletion, are skipped.
         */
        replaceCurrent() {
            if (this.replaceDisabled) return;
            const key = this.matchKeys[this.safeMatchIndex];
            if (key === undefined) return;

            if (this.isDeleted(key)) {
                this.nextMatch();
                return;
            }

            const query = this._debouncedQuery.toLowerCase().trim();
            const stored = this.storedValue(key);
            const base = String(this.editedValues[key] ?? stored);
            if (!base.toLowerCase().includes(query)) {
                this.nextMatch();
                return;
            }

            const lower = base.toLowerCase();
            let result = '';
            let pos = 0;
            let idx = lower.indexOf(query);
            while (idx !== -1) {
                result += base.slice(pos, idx) + this.replaceValue;
                pos = idx + query.length;
                idx = lower.indexOf(query, pos);
            }
            result += base.slice(pos);

            this.stageEdit(key, result, stored);
            this.nextMatch();
        },

        // ── Placeholder guard (non-blocking) ──────────────────────────────
        // [!v*N] placeholders carry the game's dynamic numbers: an edit or
        // replacement that alters them silently breaks those values
        // in-game. Warn, never block.

        _placeholderSignature(text) {
            const matches = String(text ?? '').match(/\[!v\*\d+\]/g);
            return matches ? matches.sort().join('') : '';
        },

        /** A pending edit changed the row's placeholders. */
        hasPlaceholderWarning(key) {
            if (!this.isEdited(key)) return false;
            return this._placeholderSignature(this.storedValue(key))
                !== this._placeholderSignature(this.editedValues[key]);
        },

        /** Live warning while typing in the edit modal. */
        get editModalPlaceholderMismatch() {
            if (!this.editModal.open) return false;
            return this._placeholderSignature(this.editModal.originalValue)
                !== this._placeholderSignature(this.editModalValue);
        },

        // ── Value / tag accessors ({v, t} objects or legacy strings) ─────

        getValue(entry) {
            if (entry === null || entry === undefined) return '';
            if (typeof entry === 'object') return entry.v || '';
            return String(entry);
        },

        getTag(entry) {
            if (entry === null || entry === undefined) return 'A';
            if (typeof entry === 'object') return entry.t || 'A';
            return 'A';
        },

        isEdited(key) {
            return this.editedValues[key] !== undefined;
        },

        hasTagChange(key) {
            return key in this.tagChanges;
        },

        /**
         * Tag the save will PRODUCE for a row — previewed live in the tag
         * cell, before anything is saved: an explicit tag change wins, a
         * pending manual edit shows H (unless the stored tag is M/S, which
         * every save endpoint preserves), otherwise the stored tag.
         * Pages layer their own rules on top (e.g. merge selections
         * promoting A → V).
         */
        displayTag(key, storedTag) {
            if (this.tagChanges[key]) return this.tagChanges[key].newTag;
            if (this.isEdited(key) && storedTag !== 'M' && storedTag !== 'S') return 'H';
            return storedTag;
        },

        /** Whether a tag's filter checkbox is on (filters are named tagH/tagV/...). */
        tagVisible(tag) {
            return this.filters['tag' + tag] === true;
        },

        // ── Filters / sort / persistence ─────────────────────────────────

        toggleFilter(name) {
            this.filters[name] = !this.filters[name];
            this.persistUiState();
        },

        toggleSort(column) {
            if (this.sortColumn === column) {
                this.sortDirection = this.sortDirection === 'asc' ? 'desc' : 'asc';
            } else {
                this.sortColumn = column;
                this.sortDirection = 'asc';
            }
            this.persistUiState();
        },

        getSortIcon(column) {
            if (this.sortColumn !== column) {
                return 'fa-sort text-gray-600';
            }
            return this.sortDirection === 'asc' ? 'fa-sort-up text-purple-400' : 'fa-sort-down text-purple-400';
        },

        persistUiState() {
            try {
                sessionStorage.setItem(config.persistKey, JSON.stringify({
                    searchQuery: this.searchQuery,
                    searchScope: this.searchScope,
                    filters: this.filters,
                    sortColumn: this.sortColumn,
                    sortDirection: this.sortDirection,
                    replaceOpen: this.replaceOpen,
                    replaceValue: this.replaceValue
                }));
            } catch (e) { /* storage full/blocked: non-essential */ }
        },

        restoreUiState() {
            try {
                const raw = sessionStorage.getItem(config.persistKey);
                if (!raw) return;
                const state = JSON.parse(raw);
                if (typeof state.searchQuery === 'string') this.searchQuery = state.searchQuery;
                if (['both', 'keys', 'values'].includes(state.searchScope)) this.searchScope = state.searchScope;
                if (state.filters && typeof state.filters === 'object') {
                    for (const name of Object.keys(this.filters)) {
                        if (typeof state.filters[name] === 'boolean') {
                            this.filters[name] = state.filters[name];
                        }
                    }
                }
                if (typeof state.sortColumn === 'string') this.sortColumn = state.sortColumn;
                if (['asc', 'desc'].includes(state.sortDirection)) this.sortDirection = state.sortDirection;
                if (typeof state.replaceOpen === 'boolean') this.replaceOpen = state.replaceOpen;
                if (typeof state.replaceValue === 'string') this.replaceValue = state.replaceValue;
            } catch (e) { /* corrupted state: keep defaults */ }
        },

        // ── Edit modal ────────────────────────────────────────────────────

        editCell(key, currentValue) {
            this.setMatchCursor(key);
            this.editModalValue = this.editedValues[key] ?? currentValue;
            this.editModal = {
                open: true,
                key: key,
                originalValue: currentValue
            };

            this.$nextTick(() => {
                const textarea = document.getElementById('editModalTextarea');
                if (textarea) {
                    textarea.focus();
                    textarea.setSelectionRange(textarea.value.length, textarea.value.length);
                }
            });
        },

        saveEditModal() {
            this.stageEdit(this.editModal.key, this.editModalValue, this.editModal.originalValue);
            this.closeEditModal();
        },

        /**
         * Stage an edit (shared by the modal and replace). Setting the
         * value back to the stored original removes the pending edit.
         */
        stageEdit(key, value, originalValue) {
            if (value !== originalValue) {
                this.editedValues[key] = value;
                // Editing a key cancels a pending deletion of it
                delete this.deletions[key];
                this.onEditStaged(key);
            } else {
                delete this.editedValues[key];
                this.onEditUnstaged(key);
            }
            this.persistPendingState();
        },

        /** Page hooks: an edit was staged / reverted to the original value. */
        onEditStaged(key) {},
        onEditUnstaged(key) {},

        closeEditModal() {
            this.editModal = { open: false, key: '', originalValue: '' };
            this.editModalValue = '';

            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape' && this.editModal.open) {
                    this.closeEditModal();
                }
            }, { once: true });
        },

        // ── Tag dropdown (branches/sessions can only set Skip) ───────────

        openTagDropdown(event, key, currentTag, value) {
            event.stopPropagation();
            const rect = event.target.getBoundingClientRect();
            this.tagDropdown = {
                open: true,
                key: key,
                currentTag: this.hasTagChange(key) ? this.tagChanges[key].newTag : currentTag,
                originalTag: currentTag,
                value: value,
                x: rect.left,
                y: rect.bottom + window.scrollY
            };
        },

        closeTagDropdown() {
            this.tagDropdown = { open: false, key: '', currentTag: '', originalTag: '', value: '', x: 0, y: 0 };
        },

        /**
         * Explicit tag change: S (skip) everywhere, A (invalidate — send back
         * to AI) where the page offers it. Setting the original tag back
         * removes the pending change.
         */
        setTag(newTag) {
            const { key, originalTag, value } = this.tagDropdown;
            if (newTag === originalTag) {
                delete this.tagChanges[key];
            } else {
                this.tagChanges[key] = { newTag: newTag, originalTag: originalTag, value: value };
            }
            this.persistPendingState();
            this.closeTagDropdown();
        },

        cancelTagChange(key) {
            delete this.tagChanges[key];
            this.persistPendingState();
        },

        cancelAndCloseTagDropdown(key) {
            this.cancelTagChange(key);
            this.closeTagDropdown();
        }
    };
}
