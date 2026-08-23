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
 *  - view        : which SCREEN this is — 'merge', 'edit', 'preview', 'live'. Every storage key
 *                  below is built from it, so two screens can never read each other's state.
 *                  A merge and an edit of the same file are two screens, not two settings of one
 *  - scope       : what identifies the DOCUMENT (uuid, translation id…), for column widths only
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
/**
 * The id of the WORK SESSION this page is part of.
 *
 * 🔴 **Survives a refresh, never a reopening — and that is the whole rule.** Closing a page without
 * applying is a cancel in anybody's head: coming back later to decisions made in a previous sitting,
 * silently restored and possibly far down the list, is how somebody presses Save on work they no
 * longer remember doing. A refresh is the opposite: nothing was decided, the page simply came back.
 *
 * `history.state` draws exactly that line — the browser keeps it across F5 and gives a fresh entry
 * nothing. Verified in Chrome rather than assumed.
 *
 * ⚠ `?w=` wins over the stored state, for screens whose own controls NAVIGATE: the merge view
 * changes which contributions it shows through a GET form, so without carrying the id in it, hiding
 * one contribution would throw away everything decided about the others.
 *
 * ⚠ Not `crypto.randomUUID()`: it does not exist outside a secure context, and the development site
 * is plain http — so it would work in production and be missing exactly where it gets written.
 */
export function workSessionId(search) {
    const fromUrl = new URLSearchParams(search ?? window.location.search).get('w');
    if (fromUrl && /^[a-z0-9]{6,32}$/.test(fromUrl)) return fromUrl;

    try {
        if (window.history.state?.ugtWork) return window.history.state.ugtWork;
    } catch (e) { /* history blocked: fall through to a fresh one */ }

    const bytes = new Uint32Array(2);
    window.crypto.getRandomValues(bytes);
    const id = bytes[0].toString(36) + bytes[1].toString(36);

    try {
        // Merged, never replaced: the entry may already carry somebody else's state.
        window.history.replaceState({ ...(window.history.state || {}), ugtWork: id }, '');
    } catch (e) { /* history blocked: the id lives for this page only, which still beats sharing */ }

    return id;
}

export function editorCore(config) {
    // 🔴 **Three scopes, and each one is a decision.** See workSessionId above for the first.
    //
    // | what | kept for | why |
    // |---|---|---|
    // | how you read + what you decided | this SITTING, on this VIEW | a merge and an edit of the same file are two screens, not two settings of one; and reopening either is a new sitting |
    // | column widths | this VIEW, on this DOCUMENT | measured against this file's content: carried to a file with source lines ten times longer, they pushed the translation column off screen, grab edges included |
    // | show the index column, show line breaks | this VIEW | a way of reading a grid, worth keeping — but the grids do not hold the same columns, so one answer for all four was one answer too few |
    const view = config.view;
    const workKey = 'ugt_work_' + view + '_' + workSessionId();
    const persistKey = workKey + '_ui';
    const pendingKey = workKey + '_pending';
    const widthsKey = 'ugt_cols_' + view + (config.scope ? '_' + config.scope : '');

    return {
        // ── Workbench mode (see editor-workbench.js) ──────────────────────
        ...editorWorkbench(),
        // ── Resizable columns (see editor-columns.js) ─────────────────────
        ...editorColumns(),
        // ── Flowing text or line breaks (see editor-text-mode.js) ─────────
        ...editorTextMode(config.view),
        // ── Reachable horizontal scrollbar (see editor-hscroll.js) ────────
        ...editorHScroll(),
        ...editorOffScreen(),
        // ── What a translation carries besides its lines (see editor-metadata.js) ──
        ...editorMetadata(),
        // ── Pinning the reference column (see editor-pin.js) ──────────────
        ...editorPin(),

        // ── Is this page still describing the file as it is? ─────────────
        //
        // 🔴 **A page left open goes stale in two different ways, and neither used to be visible
        // until Save.** The file can be rewritten underneath — another tab, another machine, the mod
        // uploading captures, a contribution updated by its author — and a comparison's session
        // expires on its own (15 minutes, 2 hours once opened). The per-line guard at save time
        // protects the FILE; it does nothing for the person who spent an hour reading a state that
        // no longer exists.
        //
        // ⚠ **Asked when the page comes back into view, never on a timer.** These screens are not
        // connected to anything live — the live editor is, and polls for that reason. Here the only
        // moment worth asking is the moment somebody returns to the page.

        /** The file moved under this page. */
        stale: false,
        /** The session that authorises writing is gone — reloading will not bring it back. */
        sessionLost: false,
        /** The mark the last answer carried, or null until the first one establishes it. */
        _freshMark: null,

        /** Page hook: where to ask. Null on a screen with nothing to check. */
        freshnessUrl() { return null; },

        /** Page hook: the answer reduced to one comparable string — see the pages for what counts. */
        freshnessMark(state) { return JSON.stringify(state); },

        initFreshness() {
            document.addEventListener('visibilitychange', () => {
                if (document.visibilityState === 'visible') this.checkFreshness();
            });
        },

        /**
         * One request, and nothing to say when it fails.
         *
         * ⚠ **The first answer establishes the mark rather than reporting a change** — same shape as
         * the live editor's first poll. Without it, opening a page would announce that it is already
         * out of date.
         *
         * ⚠ **Silent on a network error**: a hiccup in the Wi-Fi is not news about the file, and an
         * alarm that cries wolf is worse than no alarm. Only a definite answer speaks.
         *
         * ⚠ Stops asking once it knows: the message stays until the page is reloaded, and asking
         * again could only say the same thing.
         */
        checkFreshness() {
            const url = this.freshnessUrl();
            if (!url || this.stale || this.sessionLost) return;

            fetch(url, { headers: { Accept: 'application/json' } })
                .then((response) => {
                    // The comparison's own guard already answers this on a dead token, with the
                    // words to match — see resolveMergePreviewPaths.
                    if (response.status === 410) {
                        this.sessionLost = true;
                        return null;
                    }
                    return response.ok ? response.json() : null;
                })
                .then((state) => {
                    if (!state) return;

                    const mark = this.freshnessMark(state);
                    if (this._freshMark === null) {
                        this._freshMark = mark;
                        return;
                    }
                    if (mark !== this._freshMark) this.stale = true;
                })
                .catch(() => { /* network hiccup: not news about the file */ });
        },

        /**
         * The grid's geometry just changed — everything measured FROM it has to be told.
         *
         * 🔴 **Defined here because TWO modules need it and a hook has one implementation.** The
         * modules are spread into this object, so the last one to define a name wins: the pin did,
         * silently, and the off-screen marks never heard about a resize. Dragging a column left the
         * arrows describing the layout as it was before the drag — pointing at a contribution now in
         * plain sight, or silent about one that had just left it.
         *
         * ⚠ Announced rather than observed: the width map is mutated key by key, so an effect that
         * merely reads it subscribes to nothing.
         */
        onColumnsResized() {
            this.applyPinOffsets();
            this.refreshOffScreenSides();
        },

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
            this._forgetStaleSittings();
            this.initFreshness();
            this.initEditorWorkbench();
            this.initTextMode();
            this.restoreUiState();
            this.initEditorColumns();
            this.initEditorPin();
            this.initHScroll();
            this.restorePendingState();
            try {
                const storedIndexPref = localStorage.getItem(this._indexPrefKey());
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
         * The filter box that governs a category: `catNew`, `catDiffering`, `catSame`,
         * `catOnlyOnTarget`.
         *
         * 🔴 **Derived, so a box cannot mean something the counts do not.** The boxes used to carry
         * their own names — `catNew`/`catDiff`/`catOther` here, `localOnly`/`onlineOnly`/`different`
         * /`same` there — and the mapping between the two lived in a method each screen wrote for
         * itself. Two vocabularies for four situations is two chances to disagree about which rows
         * a box hides, on the screens where hiding a row is how somebody misses a contribution.
         *
         * ⚠ **Prefixed rather than the bare category.** `filters.new` reads as the `new` OPERATOR to
         * a restricted expression parser, and this site runs @alpinejs/csp — where an expression it
         * cannot parse does not throw, it evaluates to nothing. The prefix also matches `tagH`,
         * `tagV`… so every filter in the object is named the same way.
         *
         * Page hook: a screen that does not tell two situations apart folds one onto the other —
         * see the merge view, where a line no contribution mentions is not news.
         */
        categoryFilter(category) {
            return 'cat' + category.charAt(0).toUpperCase() + category.slice(1);
        },

        /** The filter box governing a row. */
        rowCategoryFilter(key) {
            return this.categoryFilter(this.rowCategory(key));
        },

        // ── Reading a row: which column holds it, and how firmly ─────────

        /**
         * Page hook: the tint a column answers to, without its `selected-` prefix.
         *
         * ⚠ It is a COLOUR, not an identifier: the merge view paints every contribution the same
         * (`branch`), whichever one it is. Default = the column's own name, which is what a screen
         * with two named sides wants.
         */
        cellTone(source) { return source; },

        /**
         * What a cell looks like: whose version this row is on, and whether somebody said so.
         *
         * 🔴 One rule, one place. It was written twice, and the copies had drifted: the comparison
         * tested `source === 'local'` for a rewording, which was the target only one way round —
         * so publishing painted a hand-written line as an ordinary pick, in the colour of the
         * column rather than the colour of "somebody typed this".
         *
         * ⚠ **A rewording belongs to the TARGET.** It is written there and nowhere else, so that is
         * the only column that can wear its colour.
         *
         * ⚠ The tint says WHICH column is held; the modifier says how firmly. Two facts, two
         * classes, rather than four names — and the pin's stylesheet builds on `selected-<column>`,
         * so the two must keep agreeing (see editor-pin.js).
         */
        getCellClass(key, source) {
            if (source === this.targetSource() && this.isEdited(key)) {
                // Still shown, and shown as NOT going anywhere — see editIsHeld.
                return this.editIsHeld(key) ? 'selected-manual' : 'edit-set-aside';
            }
            if (this.pickedSource(key) !== source) return '';

            const held = 'selected-' + this.cellTone(source);
            return this.isUnclaimed(key) ? held + ' selection-unclaimed' : held;
        },

        /**
         * Is the pending rewording of this row the answer, or set aside by a pick elsewhere?
         *
         * 🔴 **A rewording is never destroyed by picking a column.** Somebody who reworded fifty
         * lines and then pressed "take everything from this side" would lose all fifty to one
         * click — and picking a column is not the gesture for throwing typing away; reverting the
         * row is. So the draft stays in `editedValues`, the SELECTION decides what gets written,
         * and the cell says which of the two it is looking at (`edit-set-aside`).
         *
         * ⚠ **One test, no per-screen hook.** Typing answers the row as `manual` on every screen
         * that arbitrates (onEditStaged), and `null` means nothing was picked at all — a live edit,
         * where the typing is the only answer there is. The comparison used to answer with the
         * target's column id instead, which reads the same right up to this question; aligning it
         * removed the second definition rather than adding a hook to reconcile them.
         */
        editIsHeld(key) {
            if (!this.isEdited(key)) return false;

            const picked = this.pickedSource(key);
            return picked === null || picked === 'manual';
        },

        /**
         * Page hook: is this pick worth recording at all?
         *
         * ⚠ The merge view answers no on a row whose own version is picked with nothing to change:
         * writing it back changes no byte and would count a modification nobody made. Default yes —
         * a screen that has no such case says nothing.
         */
        pickIsWorthRecording(key, source, picked) { return true; },

        /**
         * Clicking a column: hold it, claim it, let it go — then take it.
         *
         * 🔴 The same gesture on the same grid, written twice. What legitimately differed is one
         * screen's guard against a pick that writes nothing, and that is now a hook rather than a
         * second copy of the whole gesture.
         */
        select(key, source) {
            // Even on inert rows the click moves the search cursor (IDE caret)
            this.focusRow(key);
            // A deleted key must be un-deleted before picking a side again
            if (this.isDeleted(key)) return;

            // 🔴 Clicking typing a pick had set aside takes it back. The value under the cursor IS
            // that typing — it is what the cell shows — and clicking a value is how every other
            // cell on this grid is taken. Without this the click ran into advancePick, which
            // answers for the column and left the screen visibly unchanged: reported as "nothing
            // happens and the row looks unselected".
            if (source === this.targetSource() && this.isEdited(key) && !this.editIsHeld(key)) {
                this.onEditStaged(key);
                this.persistPendingState();
                return;
            }

            // Clicking the column this row is already on: held → claimed → back to its own default.
            if (this.advancePick(key, source)) return;

            // ⚠ Claimed, never auto: this ran because somebody clicked, and a pick made by hand on
            // an `A` line is exactly the validation the defaults refuse to invent.
            const picked = this.pickFrom(key, source);
            if (!picked) return;
            if (!this.pickIsWorthRecording(key, source, picked)) return;

            // ⚠ A pending rewording is NOT dropped here — it is set aside, and stays visible and
            // recoverable. What decides the file is the line below. See editIsHeld.
            this.selections[key] = picked;
            this.persistPendingState();
        },

        /**
         * Drop every pending answer on this screen, after asking.
         *
         * ⚠ Asked with the page's own words (`clearAllPrompt`), because the three screens name what
         * is being dropped differently — but the act is one, and it was written three times.
         */
        clearAll() {
            if (!window.confirm(this.clearAllPrompt)) return;

            // ⚠ Only where there are columns to pick between: the live editor has none, and
            // creating the map here would be inventing state nothing reads.
            if (this.selections) this.selections = {};
            this.clearPendingState();
        },

        /** Page hook: what the confirmation says. */
        clearAllPrompt: '',

        // ── What this screen lets somebody DO ────────────────────────────
        //
        // 🔴 **Rights are the second thing that legitimately differs between the three editors**
        // (the first being the targets) — the user's own division. Written as hooks rather than as
        // `@if`s scattered through the markup: a control that appears on one screen and not on
        // another is a decision, and a decision needs somewhere to be read.
        //
        // ⚠ **Two, not four.** The plan named `canChangeTag` and `canPick` as well; both turned out
        // to be second names for questions already answered here — `tagCellDisabled`, which the tag
        // cell component already reads, and `arbitratesAnotherSide`, which the defaults already
        // read. A synonym with no caller is the parallel path the project forbids, so neither was
        // written. See analyse/editors-mutualisation.md.

        /**
         * May somebody reword a line here?
         *
         * ⚠ True even where the target does not hold the row yet: writing one's own translation of
         * a line only a contribution carries is a normal act, and the screens already render the
         * result (`entryOnFile(key) !== undefined || isEdited(key)`). What was not normal was the
         * merge view hiding the pencil there while its double-click went on working.
         */
        canEdit(key) { return true; },

        /**
         * May somebody strike a line out?
         *
         * 🔴 Not on a row the target does not hold: there is nothing to remove. Deleting is about
         * what the saved file carries, which is why this one asks `targetEntry` and `canEdit` does
         * not — the two look alike and answer different questions.
         */
        canDelete(key) { return this.targetEntry(key) !== undefined; },

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

            this.forgetTagSetByHand(key);
            return this.byHand(this.pick(id, this.getValue(entry), this.getTag(entry), false));
        },

        /**
         * A later gesture on the row wipes a tag somebody had set by hand.
         *
         * 🔴 **The last gesture wins — and until this existed, none of them did.** A tag set from
         * the menu lived in its own store that nothing ever cleared, so it outlived every click and
         * every rewording that followed: taking a version tagged `A` still wrote `V`, and editing a
         * line after setting `V` no longer produced the `H` an edit produces. The result depended on
         * the order of OUR code (selections applied first, tags second), never on the order the
         * reader worked in.
         *
         * ⚠ Called from the three gestures that state a new answer for the row — picking a column,
         * claiming a held one, rewording — and from nowhere else. The defaults must not clear it:
         * they run on load, after a draft has been restored.
         */
        forgetTagSetByHand(key) {
            if (!(key in this.tagChanges)) return;

            delete this.tagChanges[key];
        },

        /**
         * Mark an answer as one somebody CLICKED.
         *
         * 🔴 **Not the same question as `auto`, and they look alike enough to be mistaken for each
         * other — they were.** `auto` says "do not promote this A to V"; the defaults leave it false
         * on every line that is not an `A`, because there is nothing to promote. So reading `auto`
         * to mean "a human chose this" counted 18 rows nobody had touched, on a page just opened.
         *
         * This one says only what it says, and nothing reads it but the warning shown before hiding
         * a contribution: what is about to be lost is work, and the defaults are not work.
         *
         * ⚠ Stays in the browser: the save builds its own payload field by field.
         */
        byHand(sel) {
            return sel === null ? null : { ...sel, byHand: true };
        },

        /** Did somebody click this row's answer, as opposed to the screen answering for them? */
        isByHand(key) {
            const sel = this.selections?.[key];
            return typeof sel === 'object' && sel !== null && sel.byHand === true;
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

            // Clicking the column states a new answer, so a tag set by hand on this row goes —
            // whichever of the three steps below the click lands on. See forgetTagSetByHand.
            this.forgetTagSetByHand(key);

            if (this.isUnclaimed(key)) {
                // Claiming a held row IS a click — see byHand.
                this.selections[key] = this.byHand(
                    this.pick(source, current.value, current.tag, false));
                this.persistPendingState();
                return true;
            }

            const back = this.defaultSelection(key);

            // ⚠ The typing is not touched by any of the three steps: `manual` is not a column, so a
            // row holding its own rewording never reaches here at all (the guard above returns).
            // What does reach here is a row on a real column, which has no typing to lose.
            if (back && (back.source !== source || back.auto !== (current && current.auto))) {
                this.selections[key] = back;
            } else {
                delete this.selections[key];
            }

            this.persistPendingState();
            return true;
        },

        // ── Pending-state persistence (survives F5, never a reopening) ───

        persistPendingState() {
            try {
                sessionStorage.setItem(pendingKey, JSON.stringify({
                    // When this sitting was last touched — read by _forgetStaleSittings.
                    at: Date.now(),
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

        /** A pending edit changed the row's placeholders. Silent on one set aside: nothing goes. */
        hasPlaceholderWarning(key) {
            if (!this.editIsHeld(key)) return false;
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

        /**
         * ⚠ Per VIEW, not per editor. Showing the capture order is a way of reading a grid, and the
         * four grids do not hold the same columns — one answer for all of them was one answer too
         * few. It outlives the sitting all the same: it decides nothing, it only shows.
         */
        _indexPrefKey() {
            return 'ugt_editor_show_index_' + config.view;
        },

        toggleIndexColumn() {
            this.showIndexColumn = !this.showIndexColumn;
            try {
                localStorage.setItem(this._indexPrefKey(), this.showIndexColumn ? '1' : '0');
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
            if (this.editIsHeld(key) && storedTag !== 'M' && storedTag !== 'S') return 'H';
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
            return this.tagArrives(key) && !this.editIsHeld(key);
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
                    const value = this.editIsHeld(key) ? this.editedValues[key] : this.storedValue(key);
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
            if (this.entryOnFile(key) === undefined && !this.editIsHeld(key)) return false;

            const value = this.editIsHeld(key) ? this.editedValues[key] : this.storedValue(key);
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
         * Where column widths are remembered — see the table on editorCore.
         *
         * ⚠ **In localStorage, unlike everything else here.** A width is not part of a sitting: it
         * is how this document reads on this screen, and losing it every time the page is reopened
         * means dragging the same edge back every time.
         */
        _widthsKey() {
            return widthsKey;
        },

        /**
         * Drop the storage of sittings nobody is coming back to.
         *
         * ⚠ **Comes WITH the per-sitting keys, not as an extra.** Each one writes under a name of
         * its own, so without this they pile up for the life of the tab — and a draft of a 2500-line
         * merge is hundreds of kilobytes. Nothing warns when they stop fitting: a full quota is
         * swallowed on purpose (a draft is a convenience, never worth breaking the screen for), so
         * the failure would be a draft that silently stops being saved.
         *
         * ⚠ **By age, not "everything but mine".** Going back in history returns to the entry that
         * held a sitting, and its id comes back with it — so the reader expects their work to still
         * be there. Two hours leaves that intact while bounding what a long tab accumulates.
         */
        _forgetStaleSittings() {
            const cutoff = Date.now() - 2 * 60 * 60 * 1000;

            try {
                for (const name of Object.keys(sessionStorage)) {
                    if (!name.startsWith('ugt_work_')) continue;
                    if (name === persistKey || name === pendingKey) continue;

                    let at = 0;
                    try { at = JSON.parse(sessionStorage.getItem(name))?.at ?? 0; } catch (e) { /* unreadable */ }
                    if (at > cutoff) continue;

                    sessionStorage.removeItem(name);
                }
            } catch (e) { /* storage blocked: nothing to clean, nothing to report */ }
        },

        persistUiState() {
            try {
                sessionStorage.setItem(persistKey, JSON.stringify({
                    at: Date.now(),
                    searchQuery: this.searchQuery,
                    searchScope: this.searchScope,
                    filters: this.filters,
                    sortColumn: this.sortColumn,
                    sortDirection: this.sortDirection,
                    replaceOpen: this.replaceOpen,
                    replaceValue: this.replaceValue,
                    pinMain: this.pinMain
                }));
                localStorage.setItem(this._widthsKey(), JSON.stringify({
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
                const raw = sessionStorage.getItem(persistKey);
                const state = raw ? JSON.parse(raw) : null;

                // 🔴 **Widths are restored even when there is NO sitting to restore**, and this
                // `if` used to be a `return`. That was harmless while both lived in sessionStorage
                // and were written together — no sitting meant no widths either. Once widths moved
                // to localStorage to outlive the sitting, the early exit killed them on every
                // opening: dragged, refreshed, gone.
                if (state) {
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
                }
            } catch (e) { /* corrupted state: keep defaults */ }

            // 🔴 **When the CONTENT is there, not a frame after init.** Two conditions have to hold
            // for a remembered layout to be judged: the box must have a width, and the columns must
            // be the ones it was measured on. A frame after init only satisfies the first — the
            // fetch has not returned, a merge view has no contribution columns yet, so the stored
            // layout never matched the current one and was dropped on every opening. Dragged,
            // refreshed, gone.
            //
            // ⚠ `loaded` is each page's own "the file is here" flag, and all three set it. Watching
            // it rather than calling from three places is what keeps the rule in one.
            // ⚠ `$nextTick` and THEN a frame: the flag turning true is what makes Alpine render the
            // columns, so a bare `requestAnimationFrame` still runs against the old header — the
            // same "too early" as before, one step further along. nextTick waits for the render,
            // the frame waits for the layout that gives the box its width.
            this.$watch('loaded', (ready) => {
                if (!ready) return;
                this.$nextTick(() => requestAnimationFrame(() => this._restoreColumnWidths()));
                // Establishes the mark to compare against later — see checkFreshness.
                this.checkFreshness();
            });
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
                const raw = localStorage.getItem(this._widthsKey());
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

                // 🔴 **Restoring is two acts, and only the first was done.** The numbers go back
                // into the map, and the map is written as a stylesheet — it is not bound in the
                // markup, so nothing happens until somebody says so. Left out, the widths came back
                // correctly and the columns still showed the browser's own layout: right in the
                // state, wrong on screen, which is the hardest kind to see.
                //
                // ⚠ And the pin measures its offsets FROM these widths, so it has to be told too
                // (onColumnsResized) — otherwise the frozen block stays at the old offset and
                // overlaps its neighbour.
                this.applyColumnWidths();
                this.onColumnsResized();
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
                // ...and a tag somebody had set by hand: rewriting the line states a new answer,
                // whose tag is H. See forgetTagSetByHand.
                this.forgetTagSetByHand(key);
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
         *
         * 🔴 **Setting a tag IS answering the row**, not opening a second track beside the answer.
         * A row held by the screen's own proposal (dashed) becomes claimed, exactly as if its
         * column had been clicked — otherwise the screen writes `V` and says in the same breath that
         * nobody validated it, and the column stays clickable on top of a decision already made.
         *
         * ⚠ Same rule for every tag, `S` included. `S` is not "this line leaves the game": our own
         * ladder ranks it with `H`, above `V` (common/Merge.cs), and the review coverage counts it
         * with the human lines. Making it the one tag that clears the answer would drop the value
         * that was picked, in silence, and give one gesture two behaviours.
         */
        setTag(newTag) {
            const { key, originalTag, value } = this.tagDropdown;
            if (newTag === originalTag) {
                delete this.tagChanges[key];
            } else {
                this.tagChanges[key] = { newTag: newTag, originalTag: originalTag, value: value };
                this.onTagSet(key, newTag);
            }
            this.persistPendingState();
            this.closeTagDropdown();
        },

        /**
         * Page hook: a tag was set by hand, so the row's answer is claimed.
         *
         * ⚠ Not called when the original tag is set back — there is nothing to claim, and the row
         * has no memory of what its answer was before the first tag gesture. It keeps whatever it
         * holds; only the tag returns to the screen's own projection.
         */
        onTagSet(key, newTag) {},

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
