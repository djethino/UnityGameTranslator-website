/**
 * What a translation carries besides its lines: its file settings, its description, its link.
 *
 * 🔴 **These are part of what a translation IS, and every screen that shows a translation owes
 * them.** They were built here from the CONTRIBUTIONS at first, which meant a screen with none to
 * show — a translation being worked on by itself, a translation being read — listed nothing at
 * all. Measured on a real file: seven fonts, an image, two exclusions, two variables and a
 * resources link, none of them anywhere on the page while its author was looking for exactly
 * those. So the rows come from the FILE, and contributions are folded in on top.
 *
 * ⚠ **It knows nothing about choosing.** Building the rows, telling an addition from a
 * disagreement, and counting what is disputed are the same questions on a merge screen, on an
 * edit screen and on a reading screen; taking somebody else's value is a question only one of
 * them asks. That half stays with the page, and this module never mentions a pick.
 *
 * ⚠ **The words come from the page**, through `metadataLabels`: they are translated server-side,
 * and a bundled module cannot reach Laravel's translator. Set them before building.
 */
export function editorMetadata() {
    return {
        settingsRows: [],
        hasSettingsRows: false,
        settingsOpen: false,

        publicationRows: [],
        hasPublicationRows: false,
        publicationOpen: false,

        /**
         * Filled by the page from Blade.
         *  - sections: section id -> its name ("fonts" -> "Fonts")
         *  - fields:   'notes' | 'resources_url' -> their names
         *  - absent:   what a cell says when this side holds nothing
         */
        metadataLabels: { sections: {}, fields: {}, absent: '' },

        buildMetadataRows(payload) {
            this.buildSettingsRows(payload);
            this.buildPublicationRows(payload);
        },

        buildSettingsRows(payload) {
            const { sections, absent } = this.metadataLabels;
            const mainSettings = payload.main_settings || {};
            const byKey = {};

            const row = (key, entry) => {
                if (byKey[key]) return byKey[key];
                const mine = mainSettings[key];
                byKey[key] = {
                    id: key,
                    key,
                    label: (sections[entry.section] || entry.section) + ' › ' + entry.label,
                    mineValue: mine ? mine.value : absent,
                    // What this side actually holds, as opposed to what the cell shows when it
                    // holds nothing. Telling the two apart is what says whether a contribution is
                    // ADDING something or disagreeing with something.
                    mineRaw: mine ? mine.value : '',
                    byBranch: {},
                };
                return byKey[key];
            };

            for (const key of Object.keys(mainSettings)) row(key, mainSettings[key]);

            for (const branch of (payload.branches || [])) {
                const branchSettings = branch.settings || {};
                for (const key of Object.keys(branchSettings)) {
                    row(key, branchSettings[key]).byBranch[branch.id] = branchSettings[key].value;
                }
            }

            const rows = Object.values(byKey);
            rows.sort((a, b) => a.label.localeCompare(b.label));

            this.settingsRows = rows;
            this.hasSettingsRows = rows.length > 0;
        },

        /**
         * The description and the link.
         *
         * ⚠ Two fields and no more. Whether a translation is finished descends from a Main to its
         * contributions and never travels back, so it is not listed here at all: a row that
         * cannot be taken is worse than an absent one.
         */
        buildPublicationRows(payload) {
            const { fields, absent } = this.metadataLabels;

            const mine = {
                notes: (payload.main_notes || '').trim(),
                resources_url: (payload.main_resources_url || '').trim(),
            };

            const rows = [];
            for (const field of ['notes', 'resources_url']) {
                const byBranch = {};
                for (const branch of (payload.branches || [])) {
                    const theirs = (branch[field] || '').trim();
                    // The same thing said on both sides is not a decision; it is still worth
                    // seeing, so it lands in the row rather than being dropped.
                    if (theirs) byBranch[branch.id] = theirs;
                }

                // ⚠ A field nobody has filled in, on either side, is the one thing worth leaving
                // out: an empty row that says "not set" twice teaches nothing.
                if (!mine[field] && Object.keys(byBranch).length === 0) continue;

                rows.push({
                    id: field,
                    field,
                    label: fields[field] || field,
                    // What is SHOWN when nothing is staged, and what this side actually holds.
                    // They differ when it holds nothing: the cell then shows a placeholder, and
                    // comparing an edit against that placeholder would call it a change.
                    mineValue: mine[field] || absent,
                    mineRaw: mine[field],
                    byBranch,
                });
            }

            this.publicationRows = rows;
            this.hasPublicationRows = rows.length > 0;
        },

        /**
         * What a contribution's cell says about itself, before anything is chosen.
         *
         * ⚠ Same two thresholds and same two colours as the lines' own (branchCellTint /
         * branchTextTint): an addition is not a disagreement, and which of the two it is decides
         * whether there is anything to arbitrate.
         */
        metaDifference(row, branchId) {
            const theirs = row.byBranch[branchId];
            if (theirs === undefined) return '';
            if (!row.mineRaw) return 'new';
            return theirs === row.mineRaw ? '' : 'differs';
        },

        metaCellTint(row, branchId) {
            const kind = this.metaDifference(row, branchId);
            if (kind === 'new') return 'bg-green-900/20';
            return kind === 'differs' ? 'bg-yellow-900/20' : '';
        },

        metaTextTint(row, branchId) {
            const kind = this.metaDifference(row, branchId);
            if (kind === 'new') return 'text-green-300';
            return kind === 'differs' ? 'text-yellow-300' : '';
        },

        /** A link keeps its own colour when it agrees, and takes the difference's when it does not. */
        metaLinkTint(row, branchId) {
            return this.metaTextTint(row, branchId) || 'text-blue-400';
        },

        /** Whether a value may be rendered as a clickable link. http(s) and nothing else. */
        isWebLink(value) {
            return typeof value === 'string'
                && (value.startsWith('https://') || value.startsWith('http://'));
        },

        // ── Reading is the default; taking is what a screen adds ──────────────────────────
        //
        // 🔴 Three screens were repeating the same empty implementations to be allowed to show
        // these blocks at all, which is the shape of a default living in the wrong place. A
        // screen that CAN take a value implements these and, since a page's members override the
        // core's, its own win. A screen that only reads writes nothing and gets a block that
        // offers nothing.
        //
        // ⚠ Methods and plain properties, never getters: this module is spread into editorCore,
        // and a spread evaluates a getter and copies the value it returned.

        /** Who the shown side belongs to, when it belongs to somebody. */
        mainOwner: '',

        settingsPick: {},
        publicationPick: {},
        // Methods, like the screen that overrides them: a block asks the same way everywhere.
        settingsTakenCount() { return 0; },
        publicationTakenCount() { return 0; },

        visibleSettingsRows() { return this.settingsRows; },
        visiblePublicationRows() { return this.publicationRows; },

        settingsCellClass() { return ''; },
        publicationCellClass() { return ''; },
        settingsTake() {},
        publicationTake() {},
        settingsKeepMine() {},
        publicationKeepMine() {},

        /** What the shown side's cell holds. A reader sees the value; an editor may stage over it. */
        publicationResult(row) { return row.mineValue; },

        /**
         * The other sides this screen compares against, as [{id, col, name}].
         *
         * ⚠ Default: the contributions of a merge. A screen that compares against something else
         * — a published version, a file on disk — overrides this, and a screen that compares
         * against nothing returns an empty list and gets a read-only block for free.
         */
        metaOtherColumns() {
            return (this.branches || []).map((branch) => ({
                id: branch.id,
                col: 'branch-' + branch.id,
                name: branch.name,
            }));
        },

        rowIsDisputed(row) {
            for (const other of this.metaOtherColumns()) {
                if (this.metaDifference(row, other.id)) return true;
            }
            return false;
        },

        // 🔴 **METHODS, NOT GETTERS** — the rule the whole core is written to, and it cost a bug
        // to relearn. This module is SPREAD into editorCore, and a spread EVALUATES a getter and
        // copies the value it returned: the accessor is lost. Written as `get
        // canTakeContributions()` it froze to false at composition time, when `branches` did not
        // exist yet, and stayed false on a merge screen showing four contributions. The
        // difference counts froze to zero the same way, so both blocks stayed shut.
        //
        // editor-hscroll.js carries the same warning at the top. It applies to every module
        // composed this way, and the templates simply call them.

        /** How many rows a contribution actually disputes — what a block's count is about. */
        settingsDifferenceCount() {
            return this.settingsRows.filter((row) => this.rowIsDisputed(row)).length;
        },

        publicationDifferenceCount() {
            return this.publicationRows.filter((row) => this.rowIsDisputed(row)).length;
        },

        /**
         * Whether anything on this screen can be taken from somebody else at all.
         *
         * ⚠ False on a screen that reads or edits one translation, and everything that offers a
         * gesture has to ask: a hand cursor over a cell that answers no question, or a hint
         * describing a click nobody can make, sends somebody looking for a control that is not
         * there.
         */
        canTakeContributions() {
            return this.metaOtherColumns().length > 0;
        },
    };
}
