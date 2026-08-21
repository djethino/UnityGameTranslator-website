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

import { editorWorkbench } from './editor-workbench.js';
import { editorColumns } from './editor-columns.js';
import { editorTextMode } from './editor-text-mode.js';
import { editorHScroll } from './editor-hscroll.js';
import { editorOffScreen } from './editor-offscreen.js';
import { editorMetadata } from './editor-metadata.js';
import { editorPin } from './editor-pin.js';

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
        // ── Workbench mode (see editor-workbench.js) ──────────────────────
        ...editorWorkbench(),
        // ── Resizable columns (see editor-columns.js) ─────────────────────
        ...editorColumns(),
        // ── Flowing text or line breaks (see editor-text-mode.js) ─────────
        ...editorTextMode(),
        // ── Reachable horizontal scrollbar (see editor-hscroll.js) ────────
        ...editorHScroll(),
        ...editorOffScreen(),
        // ── What a translation carries besides its lines (see editor-metadata.js) ──
        ...editorMetadata(),
        // ── Pinning the reference column (see editor-pin.js) ──────────────
        ...editorPin(),

        // ── Pending work (kept until the page-specific save) ─────────────
        editedValues: {},   // key -> new value
        tagChanges: {},     // key -> { newTag, originalTag, value }
        deletions: {},      // key -> true (marked for removal on save)

        // ── Edit modal ────────────────────────────────────────────────────
        editModal: {
            open: false,
            key: '',
            originalValue: '',
            // What is being edited. 'line' is a translated line; a page may use the same box on
            // something else — see editCell.
            scope: 'line'
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
        // Default sort: capture order, ascending. For existing files the
        // backfilled index IS alphabetical order (deterministic backfill),
        // so nothing jumps for current users — new captures then append in
        // the order they appeared in-game (the point of the feature).
        // Entries without an index sort last; per-session choice persists.
        sortColumn: 'index',
        sortDirection: 'asc',
        // Capture-order index column visibility — a durable preference
        // shared by ALL editors (localStorage, unlike the per-page
        // sessionStorage UI state). Visible by default (discoverability);
        // sorting by index works either way.
        showIndexColumn: true,

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
        _tagCounts: { H: 0, V: 0, A: 0, S: 0, M: 0, total: 0 },

        /**
         * Wire persistence + the memoized filter pipeline. Call from the
         * component's init().
         */
        initEditorCore() {
            this.initEditorWorkbench();
            this.initTextMode();
            this.restoreUiState();
            this.initEditorColumns();
            this.initEditorPin();
            this.initHScroll();
            this.restorePendingState();
            try {
                const storedIndexPref = localStorage.getItem('ugt_editor_show_index');
                if (storedIndexPref !== null) {
                    this.showIndexColumn = storedIndexPref === '1';
                }
            } catch (e) { /* storage blocked: keep default */ }
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
                // Same pass, same dependencies: the quality bar counts the
                // PROJECTED tags (pending edits/validations move the bar)
                this._tagCounts = this._computeTagCounts();
                this._fkVersion++;
            });

            // Track whether the main search bar is on screen (pages mark it
            // with x-ref="searchBar") to toggle the floating compact search
            if (this.$refs.searchBar && 'IntersectionObserver' in window) {
                // threshold 1, not the default 0: the handover must happen as
                // soon as the bar STARTS leaving, not once its last pixel is
                // gone. Replacing in series scrolls the page under the user,
                // and with the default the prev/next/replace buttons slid out
                // of reach while the observer still called the bar "visible" —
                // a dead zone with no controls at either end.
                new IntersectionObserver(entries => {
                    this.searchBarOffscreen = !entries[0].isIntersecting;
                }, { threshold: 1 }).observe(this.$refs.searchBar);
            }
        },

        // ── Deletions (marked, applied by the page-specific save) ────────

        isDeleted(key) {
            return this.deletions[key] === true;
        },

        toggleDelete(key) {
            this.focusRow(key);
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

        // ── Which version a row is on, and how firmly ────────────────────
        //
        // 🔴 **One mechanic for every screen that arbitrates two sides.** The merge view and the
        // merge preview show the same grid, the same columns and the same gesture, and each had
        // written its own answer to "what is this row on": one an object, the other a bare string.
        // So a rule fixed in one went on being wrong in the other — the promotion below was closed
        // on the merge view and stayed open here, marking machine lines human-checked in a player's
        // own file. Nothing about the two screens justified two mechanics; what differs is which
        // columns exist and what a click means there, and that is what the hooks are for.

        /**
         * One selection, with the one flag that decides whether it VALIDATES.
         *
         * 🔴 `auto` is set exactly when the tag kept is `A`, because that is the only tag a save
         * promotes (`TranslationService::resolveMergedTag`). On V, H or S, writing the pick changes
         * no tag — nothing is claimed and nothing is held back. Keeping the rule that narrow is what
         * stops it from becoming a second, half-understood state on rows that never needed one.
         */
        pick(source, value, tag, auto = null) {
            return { source, value, tag, auto: auto === null ? tag === 'A' : auto };
        },

        /**
         * The source a row is on, whatever shape the selection has.
         *
         * ⚠ Tolerates the bare string the merge preview used to store, because a reader may come
         * back to a draft saved before this existed. A pending state that throws is a review
         * somebody loses.
         */
        pickedSource(key) {
            const sel = this.selections?.[key];
            if (sel === undefined || sel === null) return null;
            return typeof sel === 'string' ? sel : sel.source;
        },

        /** Held by the screen rather than claimed by a person — see {@link pick}. */
        isUnclaimed(key) {
            const sel = this.selections?.[key];
            return typeof sel === 'object' && sel !== null && sel.auto === true;
        },

        /**
         * What a row answers when nobody has said anything about it — null where that answer is
         * "nothing", which is a real answer too.
         *
         * 🔴 **Keeping one's own line is only worth recording when it VALIDATES.** Writing it back
         * over itself changes no byte; the one case where it does something is an `A` promoted to
         * `V`, and that is precisely the case that must not happen by itself. So an `A` is held,
         * unclaimed; anything else is left alone, because selecting it would be a no-op wearing the
         * colours of a decision — and re-clicking it a dead click.
         *
         * ⚠ Read twice, defined once: by the defaults when a page opens, and by {@link advancePick}
         * when somebody undoes. Written separately, they drifted the first time either was touched.
         */
        defaultSelection(key) {
            // 🔴 **Nothing to hold where there is nobody to answer.** "Held, not claimed" says a
            // contribution was dealt with without being validated; on a screen with no other side —
            // the merge view opened in edit mode, where somebody is simply correcting their own
            // file — it says nothing at all. Undoing a validation there brought the dashes back on
            // a row nobody had proposed anything about.
            if (!this.arbitratesAnotherSide()) return null;

            const own = this.targetEntry(key);
            if (own === undefined) return null;

            const tag = this.getTag(own);
            if (tag !== 'A') return null;

            return this.pick(this.targetSource(), this.getValue(own), tag);
        },

        // ── What kind of row this is ─────────────────────────────────────
        //
        // 🔴 **One vocabulary, derived from the roles.** The same four situations were named three
        // ways — `catNew`/`catDiff`/`catOther` on the merge view, `localOnly`/`onlineOnly`/
        // `different`/`same` on the comparison, nothing at all in a live edit — and two of those
        // names swap meaning with the direction: on the comparison, "local only" is what the
        // SOURCE adds when publishing and what the TARGET already holds when comparing into the
        // game. Naming the situation instead of the column is what stops that.

        /**
         * Where this row stands between the target and the sources.
         *
         * @returns {'new'|'onlyOnTarget'|'differing'|'same'}
         *   · `new`          — a source has it, the target does not
         *   · `onlyOnTarget` — the target has it, no source does
         *   · `differing`    — both, and at least one source says something else
         *   · `same`         — both, and every source agrees
         *
         * ⚠ **Tag included, never the words alone.** A contribution that only validated a line
         * (A → V) has genuinely diverged, and calling that identical hides the very thing it was
         * made for — the commonest contribution of all.
         */
        rowCategory(key) {
            const own = this.targetEntry(key);
            const ids = this.sourceIds();

            if (own === undefined) {
                return ids.some(id => this.entryOf(key, id) !== undefined) ? 'new' : 'same';
            }

            const value = this.getValue(own);
            const tag = this.getTag(own);
            let seen = false;

            for (const id of ids) {
                const entry = this.entryOf(key, id);
                if (entry === undefined) continue;

                seen = true;
                if (this.getValue(entry) !== value || this.getTag(entry) !== tag) return 'differing';
            }

            return seen ? 'same' : 'onlyOnTarget';
        },

        /**
         * How many rows of each kind, counted once over the whole file.
         *
         * ⚠ Recomputed on demand rather than cached: it is read by the tiles and by the filter bar,
         * and a stale count beside a live grid is worse than a slow one. The loop is over keys, not
         * over rendered rows — the tiles describe the file, not the window onto it.
         */
        get categoryCounts() {
            const counts = { new: 0, onlyOnTarget: 0, differing: 0, same: 0, total: 0 };

            for (const key of this.allKeys) {
                counts[this.rowCategory(key)]++;
                counts.total++;
            }

            return counts;
        },

        /**
         * A pick built from whatever column somebody clicked, or null when that column holds
         * nothing for this key.
         *
         * ⚠ Claimed, always: this is the shape a CLICK produces. What a screen answers on its own
         * goes through {@link defaultSelection}, and the difference between the two is the whole of
         * "held, not claimed".
         */
        pickFrom(key, id) {
            const entry = this.entryOf(key, id);
            if (entry === undefined) return null;

            return this.pick(id, this.getValue(entry), this.getTag(entry), false);
        },

        // ── The two roles every arbitrating screen has ───────────────────
        //
        // 🔴 **Roles, not sides.** These screens were written in their own vocabularies — main /
        // branch, local / online, and one with no second column at all — so every rule had to be
        // restated three times and drifted three times. What actually differs between them is
        // short: who receives, who proposes, and what each may do. Naming those two roles is what
        // lets the rest be written once.
        //
        // ⚠ **The target is the RESULT BEING BUILT, not "the file as it stands".** It starts from
        // what the receiving side holds, takes in what is picked from the sources, is edited, and
        // carries the previewed tag. That is already exactly what the Main column is on the merge
        // view; saying it out loud is what makes the comparison screen able to agree with it.

        /**
         * Page hook: the id of the column the result is built on.
         *
         * ⚠ Not always the column the tag cell describes: the comparison shows the local file's tag
         * whichever way it runs, but publishing builds its result from the SERVER file. Asking one
         * hook both questions held the wrong side the moment somebody published.
         */
        targetSource() { return 'main'; },

        /** Page hook: the entry on the target. Defaults to the one the tag cell describes. */
        targetEntry(key) { return this.entryOnFile(key); },

        /**
         * Page hook: the ids of the columns proposing something — one on the comparison screen, as
         * many as there are contributions on the merge view, none in a live edit.
         */
        sourceIds() { return []; },

        /**
         * Page hook: the entry a given column holds for this key, target included.
         *
         * ⚠ One lookup for every column, so that everything reading "what does this side say" stops
         * needing to know whether it is a branch, an upload or a server file.
         */
        entryOf(key, id) {
            return id === this.targetSource() ? this.targetEntry(key) : undefined;
        },

        /**
         * Is there a second side whose proposal would go unanswered?
         *
         * False on a screen where somebody works on their own file alone. Everything about "held,
         * not claimed" exists to answer a contribution without validating it — with no contribution
         * the state has no subject.
         *
         * ⚠ Derived from the roles rather than declared: a screen with no source has nobody to
         * answer, and that is the whole of it. A page that needs to say otherwise overrides it.
         */
        arbitratesAnotherSide() { return this.sourceIds().length > 0; },

        /**
         * Clicking the column a row is already on. Three states, in the order somebody meets them:
         * held → claimed → back to whatever the row answers on its own.
         *
         * 🔴 The last step is not "blank". Blank and "keep your own line" write the identical file,
         * so a row whose own side holds it has no third state — and blanking it lost the marks that
         * said the row had been answered at all. Where the screen's own side holds nothing, blank IS
         * the answer: on a line only the other side has, it is the only way to refuse it.
         *
         * @returns {boolean} true when it handled the click.
         */
        advancePick(key, source, value, tag) {
            const current = this.selections[key];
            if (this.pickedSource(key) !== source || source === 'manual') return false;

            if (this.isUnclaimed(key)) {
                this.selections[key] = this.pick(source, current.value, current.tag, false);
                this.persistPendingState();
                return true;
            }

            const back = this.defaultSelection(key);

            if (back && (back.source !== source || back.auto !== (current && current.auto))) {
                delete this.editedValues[key];
                this.selections[key] = back;
            } else {
                delete this.selections[key];
                delete this.editedValues[key];
            }

            this.persistPendingState();
            return true;
        },

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
         * E = edit, Delete = toggle deletion.
         *
         * Escape peels one layer at a time, innermost first: the row cursor,
         * then the workbench. Taken together they would drop someone out of
         * full window while all they meant was to put the cursor away.
         *
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
                if (this.cursorActive) {
                    this.cursorActive = false;
                } else if (this.wide) {
                    this.toggleWide();
                }
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
        /**
         * Put the row cursor on this row, and show it.
         *
         * Was setMatchCursor, and it did two things wrong for the one job it has. It looked the
         * row up among the MATCHES, so with no search active it returned immediately and the
         * click moved nothing; and it never set cursorActive, so even when it did move, the
         * outline stayed invisible unless a search happened to be running.
         *
         * The result was a screen that answered a click on a value but not on a key, and only
         * sometimes — which reads as "you have to click in the right place to select a line".
         * A row is a row: clicking anywhere on it points the cursor at it.
         */
        focusRow(key) {
            const index = this.filteredKeys.indexOf(key);
            if (index === -1) return;
            this.currentMatchIndex = index;
            this.cursorActive = true;
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

        // ── Difference highlighting ───────────────────────────────────────
        // Two lines that differ by one word looked exactly like two lines that
        // differ entirely: the reader had to compare them character by
        // character to find out what changed.
        //
        // Word-level, never character-level: values are sentences, often
        // carrying markup like <color=#ff0000>, and a character diff would
        // shred both into confetti. Whitespace stays attached to the token
        // before it, so rebuilding the string is exact.
        //
        // Search and difference are DIFFERENT QUESTIONS, so they get different
        // visual channels rather than competing: search keeps the background
        // (<mark>), difference takes the underline. A word that is both
        // searched and changed shows both — which is why the search pass runs
        // INSIDE each segment rather than over the finished string.

        _tokenize(text) {
            // Keep the separators: "a  b" and "a b" must not compare equal
            return String(text ?? '').split(/(\s+)/).filter(t => t !== '');
        },

        /**
         * Longest common subsequence over tokens, returned as segments.
         * Each segment is {text, same}. O(n*m) — fine for one line of dialogue,
         * and guarded by a length cap below.
         */
        _diffSegments(mine, theirs) {
            const a = this._tokenize(mine);
            const b = this._tokenize(theirs);

            // Pathological lines (a whole paragraph in one key) would make the
            // table crawl. Beyond the cap, say nothing rather than freeze.
            if (a.length > 400 || b.length > 400) {
                return [{ text: String(mine ?? ''), same: true }];
            }

            const lengths = Array.from({ length: a.length + 1 }, () => new Uint16Array(b.length + 1));
            for (let i = a.length - 1; i >= 0; i--) {
                for (let j = b.length - 1; j >= 0; j--) {
                    lengths[i][j] = a[i] === b[j]
                        ? lengths[i + 1][j + 1] + 1
                        : Math.max(lengths[i + 1][j], lengths[i][j + 1]);
                }
            }

            const segments = [];
            const push = (text, same) => {
                const last = segments[segments.length - 1];
                if (last && last.same === same) last.text += text;
                else segments.push({ text, same });
            };

            let i = 0;
            let j = 0;
            while (i < a.length && j < b.length) {
                if (a[i] === b[j]) {
                    push(a[i], true);
                    i++; j++;
                } else if (lengths[i + 1][j] >= lengths[i][j + 1]) {
                    push(a[i], false);
                    i++;
                } else {
                    // Present only on the other side: nothing of ours to mark
                    j++;
                }
            }
            while (i < a.length) { push(a[i], false); i++; }

            return segments;
        },

        /**
         * Render `text` with the words that differ from `other` underlined,
         * search matches still highlighted inside.
         *
         * `other` null or undefined means the line exists on one side only —
         * then NOTHING is marked. Marking every word of a brand new line says
         * nothing: a marker that applies everywhere stops marking.
         */
        highlightDifference(text, other) {
            const value = String(text ?? '');
            if (other === null || other === undefined) return this.highlightValue(text);

            const otherValue = String(other);
            if (value === otherValue) return this.highlightValue(text);

            return this._diffSegments(value, otherValue)
                .map(seg => seg.same
                    ? this.highlightValue(seg.text)
                    : '<span class="diff-word">' + this.highlightValue(seg.text) + '</span>')
                .join('');
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

        /**
         * How good a line is, as one number.
         *
         * 🔴 **The scale belongs to the socle** — `common/Merge.PriorityOf`, read by the mod and by
         * the Manager. PHP holds it too (`TranslationService::priorityOf`), and a test compares
         * that copy with this file line by line, because a barème that decides who wins a merge
         * must not be able to drift between two products.
         *
         * ⚠ **It takes the VALUE, not just the tag.** An `H` with nothing in it is a captured line
         * waiting for a translation — the floor, not the top. Ranking on the letter alone had a
         * blank capture on the Main outrank every real translation a contributor offered.
         *
         * ⚠ **`S` sits WITH `H`, not below it.** A refusal is a person ruling that the line must
         * not be translated; that is a reading, exactly as writing one is. It used to rank with the
         * machine, which let a contribution overwrite a Main's refusal without anybody asked.
         *
         * ⚠ `M` is the mod's own interface, not a line of the game: it is off the ladder entirely
         * and out of every arbitration. See `isGameLine`.
         */
        priorityOf(entry) {
            const tag = this.getTag(entry);
            if (tag === 'H' && !this.getValue(entry)) return 0;

            return { 'H': 3, 'S': 3, 'V': 2, 'A': 1 }[tag] ?? 1;
        },

        /** Is this a line OF THE GAME — the only kind a merge arbitrates? */
        isGameLine(entry) {
            return this.getTag(entry) !== 'M';
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

        /**
         * Ordering index "i": capture order assigned by the mod. Entries
         * without one (legacy files, older mod versions) sort LAST in
         * both directions — they carry no chronological information.
         */
        getOrderIndex(entry) {
            if (entry && typeof entry === 'object' && Number.isInteger(entry.i) && entry.i > 0) {
                return entry.i;
            }
            return Infinity;
        },

        /** Cell text for the index column ('' for entries without one). */
        /**
         * The capture index of a row, wherever it is to be found — target first, then the sources.
         *
         * 🔴 **A row only a source carries still has one**, and the column exists to say in what
         * order the game met these lines. Reading the target alone left every added line blank in a
         * column whose whole job is ordering.
         *
         * ⚠ Target first, deliberately: when both sides hold the line, what the receiving file
         * already records is the one that stays after the merge.
         */
        orderIndexFor(key) {
            const own = this.getOrderIndex(this.targetEntry(key));
            if (own !== Infinity) return own;

            for (const id of this.sourceIds()) {
                const idx = this.getOrderIndex(this.entryOf(key, id));
                if (idx !== Infinity) return idx;
            }

            return Infinity;
        },

        /**
         * What the index column prints — the number, or nothing at all.
         *
         * ⚠ It was written three times, and two of them disagreed on the empty case: one tested
         * `Infinity`, the other `undefined`, so a row with no index showed a blank cell on one
         * screen and the word "undefined" on the other the day the helper changed.
         */
        indexCellText(key) {
            const idx = this.orderIndexFor(key);
            return idx === Infinity || idx === undefined ? '' : String(idx);
        },

        displayIndex(entry) {
            const idx = this.getOrderIndex(entry);
            return idx === Infinity ? '' : String(idx);
        },

        /**
         * Sort value for the index column. Index-less entries must sort LAST
         * in BOTH directions (they carry no chronological information, and in
         * descending order — "newest first" — a block of legacy rows on top
         * would bury the very entries the sort is for), so their sentinel
         * flips with the direction.
         */
        indexSortValue(idx) {
            if (idx !== Infinity && idx !== undefined) return idx;
            return this.sortDirection === 'desc' ? -Infinity : Infinity;
        },

        toggleIndexColumn() {
            this.showIndexColumn = !this.showIndexColumn;
            try {
                localStorage.setItem('ugt_editor_show_index', this.showIndexColumn ? '1' : '0');
            } catch (e) { /* non-essential */ }
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

        // ── The tag a row is leaving, and the one it is going to ─────────
        //
        // 🔴 **One question asked in three vocabularies.** Each editor had its own
        // `mainTagCellClass` / `localTagCellClass` / `entryTagCellClass` and its own
        // `displayMainTag` / `displayLocalTag`, all computing the same two facts from the same
        // core. Three copies of a rule is three chances for it to drift, and the tag cell is
        // where a merge is read: the screens must not be able to disagree about it.
        //
        // A page supplies at most two things — where its rows live (`entryOnFile`) and any
        // projection of its own on top of the core's (`tagAfterSave`). Everything below is
        // derived, once. The live editor overrides neither: the defaults ARE its behaviour.

        /** Page hook: the entry as it sits on file, or undefined when the page holds no such row. */
        entryOnFile(key) { return this.data[key]; },

        /**
         * The tag on file, or null when there is no row to have one.
         *
         * ⚠ null rather than a tag: a row the page does not hold has nothing to change FROM, and
         * `getTag(undefined)` answers 'A', which would mark an absent line as machine-translated.
         */
        tagOnFile(key) {
            const entry = this.entryOnFile(key);
            return entry === undefined ? null : this.getTag(entry);
        },

        /** Page hook: the tag the save will store. Default = the core's projection, nothing more. */
        tagAfterSave(key) {
            const stored = this.tagOnFile(key);
            return stored === null ? null : this.displayTag(key, stored);
        },

        tagWillChange(key) {
            const stored = this.tagOnFile(key);
            return stored !== null && stored !== this.tagAfterSave(key);
        },

        /**
         * A row the page does not hold yet, and that a save would ADD.
         *
         * 🔴 **Its tag is an arrival, not a state, and the difference is the whole point.** Printed
         * on its own, `H` reads as "this line is hand-written" — but the line is not there at all
         * yet; what is true is that it WILL BE one. The cell says so the way it already says it
         * everywhere else, with an arrow, simply with nothing on the left of it: there is nothing
         * to leave.
         *
         * ⚠ Kept apart from `tagWillChange` rather than folded into it. That one answers "does the
         * stored tag change", which needs a stored tag to be about; this answers "is there a tag
         * arriving where there was none". Two questions, and the transition markup reads them
         * separately — one draws a chip and an arrow, the other an arrow alone.
         */
        tagArrives(key) {
            return this.tagOnFile(key) === null && this.tagAfterSave(key) != null;
        },

        /**
         * An arriving row taken exactly as it was offered — nobody has reworded it here.
         *
         * 🔴 **Two things arrive on this screen and they are not the same work.** A line taken from
         * a contribution as it stands, and a line somebody wrote themselves over what was offered.
         * Read side by side in a column of new keys, telling them apart is what says how much of
         * this page is yours — and the chip is where the eye already is.
         *
         * ⚠ Deliberately NOT isCaptureRow, whose faded chip means something else entirely: "an H
         * with nothing in it", a line the mod captured and nobody translated. That one was doing
         * this job by accident, because it read the value on file and found none — which also had
         * it call a contributor's real translation empty. One signal, one meaning.
         */
        tagArrivesUntouched(key) {
            return this.tagArrives(key) && !this.isEdited(key);
        },

        tagCellClass(key) {
            // Both mean "this cell is not what it will be saved as", which is what the frame says.
            return this.tagWillChange(key) || this.tagArrives(key) ? 'tag-changed-cell' : '';
        },

        /** Page hook: this row's tag cannot be opened (a row on its way out). */
        tagCellDisabled(key) { return false; },

        /** Page hook: extra classes for the resulting chip, for a page with something more to say. */
        tagChipExtraClass(key) { return ''; },

        /** Whether a tag's filter checkbox is on (filters are named tagH/tagV/...). */
        tagVisible(tag) {
            return this.filters['tag' + tag] === true;
        },

        // ── Quality progress (shared bar over the whole file) ─────────────

        /**
         * Page hook: the PROJECTED tag of a row for the quality bar, or
         * null when the row is not part of the page's saved file.
         */
        rowQualityTag(key) { return null; },

        _computeTagCounts() {
            const counts = { H: 0, V: 0, A: 0, S: 0, C: 0, total: 0 };
            for (const key of this.allKeys) {
                if (this.isDeleted(key)) continue;
                const tag = this.rowQualityTag(key);
                // M is the mod's own interface, not the game's text. The server keeps no counter
                // for it and neither the mod's bar nor the site's shows it, so counting it here
                // would make this bar disagree with the same file's card.
                if (!tag || counts[tag] === undefined) continue;
                // Captured-only entries (H + empty value, the mod's "collect texts without
                // translating" mode) are work IDENTIFIED, not work done. They used to be dropped
                // from both numerator and denominator, which made a file of two translated lines
                // and eleven captures read "100%" on a bar that was full and green — while the
                // game page called the same file 15% translated. They now hold their own band,
                // the same way the mod's bar and the site's composition bar count them: hiding
                // the untranslated share flatters the result.
                if (tag === 'H') {
                    const value = this.isEdited(key) ? this.editedValues[key] : this.storedValue(key);
                    if (value === '' || value === null || value === undefined) {
                        counts.C++;
                        counts.total++;
                        continue;
                    }
                }
                counts[tag]++;
                counts.total++;
            }
            return counts;
        },

        /**
         * Captured-only row: tag H with an empty (projected) value — typing a
         * translation immediately un-captures it. Used to render the H badge
         * differently so these rows don't read as human-translated.
         */
        isCaptureRow(key) {
            if (this.rowQualityTag(key) !== 'H') return false;

            // 🔴 **A row the page does not hold is an arrival, not a capture.** storedValue reads
            // the file, and on such a row it answers undefined — which the test below counted as
            // empty. Every hand-written line a contribution ADDS was therefore greyed out, reading
            // "nothing has been written here" beside a real translation somebody had typed.
            //
            // ⚠ What this gives up: a contribution offering a genuine capture — an H with nothing
            // in it — shows a full-strength chip. Rare, and the wrong way round is the one that
            // mislabels ordinary work.
            if (this.entryOnFile(key) === undefined && !this.isEdited(key)) return false;

            const value = this.isEdited(key) ? this.editedValues[key] : this.storedValue(key);
            return value === '' || value === null || value === undefined;
        },

        get tagCounts() {
            this._fkVersion;
            return this._tagCounts;
        },

        /** Width (%) of one tag's segment in the quality bar. */
        tagPercent(tag) {
            const counts = this.tagCounts;
            if (counts.total === 0) return 0;
            return (counts[tag] / counts.total) * 100;
        },

        /**
         * Share of human + validated lines over everything the file has MET, captures included.
         * Same denominator as the game page and as the mod's card, so one file cannot answer
         * this question two ways depending on which screen asks it.
         */
        get qualityPercent() {
            const counts = this.tagCounts;
            if (counts.total === 0) return 0;
            return Math.round(((counts.H + counts.V) / counts.total) * 100);
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

        /**
         * Where column widths are remembered.
         *
         * NOT with the rest of the UI state, and this is the whole point. Which tags to show,
         * what to search for and how to sort are ways of READING, so screens share them on
         * purpose. A column width is about one document's content: carried over to a file whose
         * source lines are ten times longer, it pushed the tag and translation columns clean off
         * the screen — with the grab edges out there too, so nothing could be dragged back.
         *
         * Pages that show a single fixed document can leave widthsKey unset.
         */
        _widthsKey() {
            return config.widthsKey || config.persistKey + '_cols';
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
                    replaceValue: this.replaceValue,
                    pinMain: this.pinMain
                }));
                sessionStorage.setItem(this._widthsKey(), JSON.stringify({
                    // Which columns these widths were measured against — see _restoreColumnWidths
                    columns: this._columnOrder().join('|'),
                    columnWidths: this.columnWidths,
                    columnsSized: this.columnsSized,
                    gridWidth: this.gridWidth,
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
                if (typeof state.pinMain === 'boolean') this.pinMain = state.pinMain;
            } catch (e) { /* corrupted state: keep defaults */ }

            // After a frame, never during init: $refs.gridBox has no width yet when Alpine runs
            // this, so every check against the box compared with zero and let anything through —
            // which is how a grid came back sized to 3147px inside a box of 1182 and started
            // scrolling before anyone had touched it.
            requestAnimationFrame(() => this._restoreColumnWidths());
        },

        /**
         * Widths come back only if they still make sense HERE.
         *
         * A single column wider than the box on its own is not a preference anyone expressed: it
         * is a measurement taken on another document, or a drag that went too far. Restoring it
         * hides every column that follows and takes the grab edges off-screen with them, which
         * leaves the reader with no way back — the state that made a translation column simply
         * disappear. When in doubt the automatic layout is a perfectly good answer; a remembered
         * width is a convenience, never something worth breaking the screen for.
         */
        _restoreColumnWidths() {
            try {
                const raw = sessionStorage.getItem(this._widthsKey());
                if (!raw) return;
                const state = JSON.parse(raw);
                const box = (this.$refs.gridBox && this.$refs.gridBox.clientWidth) || 0;
                // No measurable box, no judgement: leaving the automatic layout in place is
                // always safe, restoring a width that cannot be checked is not.
                if (box === 0) return;

                // Widths belong to a COLUMN LAYOUT, not just to a document. Hiding every branch on
                // the merge view leaves a plain key/value grid, and replaying the widths measured
                // when three branches were shown made it overflow with nothing to scroll to —
                // the key alone kept a width that only made sense beside its neighbours. A
                // different set of columns is a different question, so the answer is dropped and
                // the automatic layout, which fills the box exactly, takes over.
                const columns = this._columnOrder().join('|');
                if (state.columns && state.columns !== columns) {
                    return;
                }

                const widths = {};
                if (state.columnWidths && typeof state.columnWidths === 'object') {
                    for (const [col, width] of Object.entries(state.columnWidths)) {
                        // Numbers only: a corrupted or hand-edited entry would otherwise reach
                        // the style attribute as "60undefinedpx" and collapse the column
                        if (typeof width !== 'number' || width <= 0) return;
                        if (box > 0 && width > box) return;
                        widths[col] = width;
                    }
                }

                Object.assign(this.columnWidths, widths);
                if (typeof state.columnsSized === 'boolean') this.columnsSized = state.columnsSized;
                if (typeof state.gridWidth === 'number' && state.gridWidth > 0) this.gridWidth = state.gridWidth;
            } catch (e) { /* corrupted state: automatic layout */ }
        },

        // ── Edit modal ────────────────────────────────────────────────────

        /**
         * Open the edit box on a value.
         *
         * ⚠ <b>scope</b> says what is being edited, and it is not decoration. A merge screen holds
         * more than translated lines: the description a contribution proposes is edited with the
         * same gesture and the same box, but it must NOT land in the lines' edit map — that map
         * becomes a line selection on save, so a description staged there would be published as a
         * translated line named "notes". Anything other than 'line' is handed to the page.
         */
        editCell(key, currentValue, scope = 'line') {
            if (scope === 'line') this.focusRow(key);
            this.editModalValue = scope === 'line'
                ? (this.editedValues[key] ?? currentValue)
                : currentValue;
            this.editModal = {
                open: true,
                key: key,
                originalValue: currentValue,
                scope: scope
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
            const { key, originalValue, scope } = this.editModal;
            if (scope === 'line' || scope === undefined) {
                this.stageEdit(key, this.editModalValue, originalValue);
            } else {
                this.stageScopedEdit(scope, key, this.editModalValue, originalValue);
            }
            this.closeEditModal();
        },

        /** Page hook: the same box was used on something that is not a translated line. */
        stageScopedEdit(scope, key, value, originalValue) {},

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
            this.editModal = { open: false, key: '', originalValue: '', scope: 'line' };
            this.editModalValue = '';

            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape' && this.editModal.open) {
                    this.closeEditModal();
                }
            }, { once: true });
        },

        // ── Tag dropdown (branches/sessions can only set Skip) ───────────

        /**
         * Open the tag menu under the cell that was clicked.
         *
         * 🔴 **The menu is `position: fixed`, so its coordinates are the viewport's — and the page
         * scroll must NOT be added to them.** It was, from the day the feature was written: the
         * menu drifted down by exactly how far the page had been scrolled, which on a long grid
         * put it below the screen entirely. Nothing looked broken; the menu simply did not appear.
         *
         * ⚠ `currentTarget`, not `target`: the button holds several elements (the tag on file, an
         * arrow, the resulting tag), so `target` is whichever one the pointer happened to be over
         * and the menu hung off a five-pixel arrow.
         */
        openTagDropdown(event, key, currentTag, value) {
            event.stopPropagation();

            const anchor = (event.currentTarget || event.target).getBoundingClientRect();
            this.tagDropdown = {
                open: true,
                key: key,
                currentTag: this.hasTagChange(key) ? this.tagChanges[key].newTag : currentTag,
                originalTag: currentTag,
                value: value,
                x: anchor.left,
                y: anchor.bottom + 4
            };

            // A menu anchored to a cell has nothing to say once that cell has moved. Capture, so a
            // scroll inside the grid counts as much as one on the page.
            this._closeTagOnScroll = () => this.closeTagDropdown();
            window.addEventListener('scroll', this._closeTagOnScroll, { capture: true, once: true });

            this.$nextTick(() => this._keepTagDropdownOnScreen(anchor));
        },

        /**
         * Bring the menu back inside the window once its real size is known.
         *
         * ⚠ Measured rather than estimated: it is as tall as the number of tags a screen offers,
         * plus a "cancel" entry that only exists on rows already changed. A row near the bottom
         * opens it upwards instead.
         */
        _keepTagDropdownOnScreen(anchor) {
            const menu = this.$refs.tagMenu;
            if (!menu || !this.tagDropdown.open) return;

            const box = menu.getBoundingClientRect();
            const margin = 8;
            let { x, y } = this.tagDropdown;

            if (x + box.width > window.innerWidth - margin) {
                x = Math.max(margin, window.innerWidth - box.width - margin);
            }

            if (y + box.height > window.innerHeight - margin) {
                const above = anchor.top - box.height - 4;
                y = above >= margin
                    ? above
                    : Math.max(margin, window.innerHeight - box.height - margin);
            }

            this.tagDropdown = Object.assign({}, this.tagDropdown, { x: x, y: y });
        },

        closeTagDropdown() {
            if (this._closeTagOnScroll) {
                window.removeEventListener('scroll', this._closeTagOnScroll, { capture: true });
                this._closeTagOnScroll = null;
            }
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
