import { composeEditor } from './translation-editor.js';

/**
 * The read-only screens, on the same core as the editors.
 *
 * They were built first as their own thing — server-rendered, paginated, a search that needed
 * Enter and highlighted nothing — because the admin inspection screen they were modelled on
 * predates the shared core. The result was that LOOKING at a file behaved differently from
 * EDITING it: different search, different paging, no highlight, no resizable columns. For someone
 * comparing three translations before taking one, that is three screens to learn instead of one.
 *
 * So there is no second implementation of filtering, searching, sorting or windowing here. This
 * file only answers the five questions the core asks of every page — which keys exist, does a row
 * pass the filters, does it match the search, what does it sort on, what is its stored value —
 * and then closes every door the core opens for editing.
 *
 * Shared by the public view and the admin inspection screen: they show the same three columns of
 * the same file and differ only in who may reach them.
 */
export function createViewer(config) {
    return composeEditor(
        {
            // UI state (which tags, what search, which sort) is shared by every viewer: it is a
            // way of reading, not something about one file
            persistKey: 'translation_viewer_ui',
            // Column widths are NOT: they are about this file's content. Shared, a width measured
            // on a file of long source lines followed the reader to the next one and pushed its
            // tag and translation columns off the screen — grab edges included, so there was no
            // dragging them back.
            widthsKey: 'translation_viewer_cols_' + config.translationId,
            // The core insists on a pending-work key. Nothing here ever writes one — a viewer has
            // no pending work — but scoping it per file keeps a stray restore from ever landing
            // on the wrong document
            pendingKey: 'translation_viewer_' + config.translationId + '_unused',
            filters: {
                tagH: true,
                tagV: true,
                tagA: true,
                tagS: true,
                tagM: true,
            },
        },
        {
            loaded: false,
            error: null,
            data: {},
            allKeys: [],

            init() {
                this.initEditorCore();
                this.loadContent();
            },

            loadContent() {
                fetch(config.dataUrl, { headers: { Accept: 'application/json' } })
                    .then((response) => {
                        if (!response.ok) throw new Error(String(response.status));
                        return response.json();
                    })
                    .then((payload) => {
                        if (!payload.ok) {
                            this.error = config.unreadableMessage;
                            this.loaded = true;
                            return;
                        }
                        this.data = payload.content || {};
                        this.allKeys = Object.keys(this.data);

                        // 🔴 What the file carries besides its lines. This screen is where
                        // somebody decides whether to TAKE a translation, and it could not tell
                        // them which fonts it replaces, which lines it leaves alone or where the
                        // images it needs live — the only way to find out was to download it and
                        // open it.
                        //
                        // ⚠ Read-only by construction: the shared block offers a gesture only
                        // when there is somebody to take from, and a viewer has no contributions.
                        this.metadataLabels = config.metadataLabels || this.metadataLabels;
                        this.buildMetadataRows(payload);
                        this.settingsOpen = false;
                        this.publicationOpen = false;

                        this.loaded = true;
                    })
                    .catch(() => {
                        // Said out loud, never swallowed: an empty grid and a failed request must
                        // not look the same
                        this.error = config.unreadableMessage;
                        this.loaded = true;
                    });
            },

            // ── Read-only: every gesture the core offers for editing is closed here ──────
            //
            // The core carries an edit modal, a tag dropdown, deletions and a row cursor that
            // answers V/E/Delete. None of that markup is rendered on these screens, but the
            // keyboard handler is bound to the window — without these, pressing E on a viewer
            // would silently open a modal nobody can see and leave the page in a state no
            // control can get it out of.
            // Everything a read-only block needs is the shared module's default. The only thing
            // this screen adds is whose translation it is showing.
            mainOwner: config.owner || '',

            editCell() {},
            toggleDelete() {},
            openTagDropdown() {},
            cursorPrimaryAction() {},
            replaceCurrent() {},

            // ── The five questions the core asks ────────────────────────────────────────

            rowPassesFilters(key) {
                return this.tagVisible(this.getTag(this.data[key]));
            },

            rowMatchesSearch(key, query) {
                if (this.searchScope !== 'values' && key.toLowerCase().includes(query)) {
                    return true;
                }
                if (this.searchScope !== 'keys') {
                    return this.getValue(this.data[key]).toLowerCase().includes(query);
                }
                return false;
            },

            rowSortValue(key, column) {
                if (column === 'index') {
                    return this.indexSortValue(this.getOrderIndex(this.data[key]));
                }
                if (column === 'tag') {
                    return this.getTag(this.data[key]);
                }
                if (column === 'value') {
                    return this.getValue(this.data[key]).toLowerCase();
                }
                return '';
            },

            storedValue(key) {
                return this.getValue(this.data[key]);
            },

            /** Core hook: the tag that counts towards the quality bar. */
            rowQualityTag(key) {
                return this.getTag(this.data[key]);
            },

            // ── Template helpers ────────────────────────────────────────────────────────

            valueHtml(key) {
                return this.highlightValue(this.getValue(this.data[key]));
            },

            indexCellText(key) {
                return this.displayIndex(this.data[key]);
            },

            /** A captured line has no translation yet: saying so beats an empty cell. */
            isEmptyValue(key) {
                return this.getValue(this.data[key]) === '';
            },

            get isEmptyResult() {
                return this.loaded && !this.error && this.filteredKeys.length === 0;
            },
        }
    );
}
