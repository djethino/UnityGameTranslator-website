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
 *  - x-model only on top-level identifiers (hence editModalValue)
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
 *  - allKeys                        array of keys to list
 */
export function editorCore(config) {
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

        // ── Windowed rendering (large files: thousands of rows) ──────────
        displayLimit: 500,

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
        },

        // ── Deletions (marked, applied by the page-specific save) ────────

        isDeleted(key) {
            return this.deletions[key] === true;
        },

        toggleDelete(key) {
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

        // ── Pending-state persistence (survives F5 until the save) ───────

        persistPendingState() {
            try {
                sessionStorage.setItem(config.persistKey + '_pending', JSON.stringify({
                    editedValues: this.editedValues,
                    tagChanges: this.tagChanges,
                    deletions: this.deletions,
                    extra: this.pendingExtraState()
                }));
            } catch (e) { /* storage full/blocked: non-essential */ }
        },

        restorePendingState() {
            try {
                const raw = sessionStorage.getItem(config.persistKey + '_pending');
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
                sessionStorage.removeItem(config.persistKey + '_pending');
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
            this.displayLimit += 500;
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
                    sortDirection: this.sortDirection
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
            } catch (e) { /* corrupted state: keep defaults */ }
        },

        // ── Edit modal ────────────────────────────────────────────────────

        editCell(key, currentValue) {
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
            const { key, originalValue } = this.editModal;
            const value = this.editModalValue;

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

            this.closeEditModal();
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
