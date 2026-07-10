@extends('layouts.app')

@section('title', __('edit_session.title') . ($editSession->game_name ? ' - ' . $editSession->game_name : ''))

@section('content')
<div class="container mx-auto px-4 py-8" x-data="editSession">
    {{-- Header --}}
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-white">
            <i class="fas fa-pen-to-square text-purple-400 mr-2"></i>{{ __('edit_session.title') }}
        </h1>
        <p class="text-gray-400">
            @if($editSession->game_name)
                {{ $editSession->game_name }}
                @if($editSession->source_language && $editSession->target_language)
                    &bull; {{ $editSession->source_language }} <i class="fas fa-arrow-right text-xs"></i> {{ $editSession->target_language }}
                @endif
            @endif
        </p>
        <p class="text-sm text-purple-300 mt-1">
            <i class="fas fa-gamepad mr-1"></i> {{ __('edit_session.subtitle') }}
        </p>
    </div>

    {{-- Live update toast (mod pushed changes from the game) --}}
    <div x-show="refreshNotice" x-cloak
        class="fixed top-4 right-4 z-50 bg-purple-900/90 border border-purple-600 rounded-lg px-4 py-3 text-purple-200 shadow-xl">
        <i class="fas fa-gamepad mr-2"></i><span x-text="refreshNotice"></span>
    </div>

    {{-- Loading state --}}
    <div x-show="!loaded" class="text-center py-12">
        <i class="fas fa-spinner fa-spin text-4xl text-purple-400 mb-4"></i>
        <p class="text-gray-400">{{ __('merge_preview.loading') }}</p>
    </div>

    {{-- Error state --}}
    <div x-show="error" x-cloak class="bg-red-900/50 border border-red-600 rounded-lg p-6 text-center">
        <i class="fas fa-exclamation-triangle text-4xl text-red-400 mb-4"></i>
        <p class="text-red-300" x-text="error"></p>
    </div>

    {{-- Main content --}}
    <div x-show="loaded && !error" x-cloak>
        {{-- Stats --}}
        <div class="mb-6 grid grid-cols-3 gap-4">
            <div class="bg-gray-800 rounded-lg p-4 border border-gray-700 text-center">
                <p class="text-2xl font-bold text-white" x-text="allKeys.length"></p>
                <p class="text-sm text-gray-400">{{ __('merge_preview.total_keys') }}</p>
            </div>
            <div class="bg-gray-800 rounded-lg p-4 border border-purple-700 text-center">
                <p class="text-2xl font-bold text-purple-400" x-text="totalChanges"></p>
                <p class="text-sm text-gray-400">{{ __('edit_session.pending_changes') }}</p>
            </div>
            <div class="bg-gray-800 rounded-lg p-4 border border-green-700 text-center">
                <p class="text-2xl font-bold text-green-400" x-text="savedCount"></p>
                <p class="text-sm text-gray-400">{{ __('edit_session.saved_count') }}</p>
            </div>
        </div>

        {{-- Filters --}}
        <div class="mb-4 flex flex-wrap gap-4 items-center text-sm bg-gray-800 p-4 rounded-lg border border-gray-700">
            <span class="text-gray-500">{{ __('merge_preview.show') }}:</span>

            {{-- Tag filters in HVASM order --}}
            <label class="flex items-center gap-2 cursor-pointer">
                <input type="checkbox" :checked="filters.tagH" @change="toggleFilter('tagH')"
                    class="rounded bg-gray-700 border-gray-600 text-green-600">
                <span class="tag-H">H</span>
            </label>
            <label class="flex items-center gap-2 cursor-pointer">
                <input type="checkbox" :checked="filters.tagV" @change="toggleFilter('tagV')"
                    class="rounded bg-gray-700 border-gray-600 text-blue-600">
                <span class="tag-V">V</span>
            </label>
            <label class="flex items-center gap-2 cursor-pointer">
                <input type="checkbox" :checked="filters.tagA" @change="toggleFilter('tagA')"
                    class="rounded bg-gray-700 border-gray-600 text-orange-600">
                <span class="tag-A">A</span>
            </label>
            <label class="flex items-center gap-2 cursor-pointer">
                <input type="checkbox" :checked="filters.tagS" @change="toggleFilter('tagS')"
                    class="rounded bg-gray-700 border-gray-600 text-gray-600">
                <span class="tag-S">S</span>
            </label>
            <label class="flex items-center gap-2 cursor-pointer">
                <input type="checkbox" :checked="filters.tagM" @change="toggleFilter('tagM')"
                    class="rounded bg-gray-700 border-gray-600 text-purple-600">
                <span class="tag-M">M</span>
            </label>

            <span class="text-gray-600">|</span>

            <label class="flex items-center gap-2 cursor-pointer">
                <input type="checkbox" :checked="filters.pendingOnly" @change="toggleFilter('pendingOnly')"
                    class="rounded bg-gray-700 border-gray-600 text-purple-600">
                <span class="text-purple-400">{{ __('edit_session.pending_changes') }}</span>
            </label>
        </div>

        {{-- Search --}}
        <div class="mb-4 flex gap-2">
            <div class="relative flex-1">
                <input type="text" x-model="searchQuery" placeholder="{{ __('merge_preview.search_placeholder') }}"
                    class="w-full px-4 py-2 pl-10 bg-gray-800 border border-gray-700 rounded-lg text-white placeholder-gray-500 focus:border-purple-500 focus:ring-1 focus:ring-purple-500">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-500"></i>
                <button x-show="searchQuery" @click="searchQuery = ''" type="button"
                    class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-500 hover:text-white">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <select x-model="searchScope"
                class="bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-gray-300 focus:border-purple-500 focus:ring-1 focus:ring-purple-500"
                title="{{ __('merge_preview.search_scope_title') }}">
                <option value="both">{{ __('merge_preview.search_scope_both') }}</option>
                <option value="keys">{{ __('merge_preview.search_scope_keys') }}</option>
                <option value="values">{{ __('merge_preview.search_scope_values') }}</option>
            </select>
        </div>

        {{-- Table --}}
        <div class="overflow-x-auto bg-gray-800 rounded-lg border border-gray-700 mb-6">
            <table class="w-full text-sm">
                <thead class="bg-gray-900 sticky top-0 z-10">
                    <tr>
                        <th class="px-4 py-3 text-left text-gray-400 font-medium cursor-pointer hover:text-white transition"
                            @click="toggleSort('key')">
                            <div class="flex items-center gap-2">
                                {{ __('merge_preview.key') }}
                                <i class="fas" :class="getSortIcon('key')"></i>
                            </div>
                        </th>
                        <th class="px-2 py-3 text-center border-l border-gray-700 w-12 cursor-pointer hover:text-white transition"
                            @click="toggleSort('tag')">
                            <div class="flex items-center justify-center gap-1">
                                <span class="text-gray-400 font-medium text-xs">Tag</span>
                                <i class="fas text-xs" :class="getSortIcon('tag')"></i>
                            </div>
                        </th>
                        <th class="px-4 py-3 text-left border-l border-gray-700 cursor-pointer hover:text-white transition"
                            @click="toggleSort('value')">
                            <div class="flex items-center gap-2">
                                <span class="text-purple-400 font-medium">{{ __('edit_session.translation_column') }}</span>
                                <i class="fas" :class="getSortIcon('value')"></i>
                            </div>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    {{-- Windowed rendering: huge files stay snappy --}}
                    <template x-for="key in visibleKeys" :key="key">
                        <tr class="border-t border-gray-700 hover:bg-gray-750 transition-colors">
                            {{-- Key --}}
                            <td class="px-4 py-2 font-mono text-xs text-gray-500 break-words" x-text="key"></td>

                            {{-- Tag (clickable for tag change) --}}
                            <td class="px-2 py-2 text-center border-l border-gray-700"
                                :class="hasTagChange(key) ? 'tag-changed-cell' : ''">
                                {{-- Shows the tag the save will PRODUCE (edit → H,
                                     M/S preserved), not just the stored one --}}
                                <button type="button"
                                    @click.stop="openTagDropdown($event, key, displayTag(key, getTag(data[key])), getValue(data[key]))"
                                    class="transition rounded cursor-pointer hover:ring-2 hover:ring-purple-400 hover:ring-offset-1 hover:ring-offset-gray-800"
                                    title="{{ __('merge.click_to_change_tag') }}">
                                    <span :class="'tag-' + displayTag(key, getTag(data[key]))" x-text="displayTag(key, getTag(data[key]))"></span>
                                </button>
                            </td>

                            {{-- Value (double-click or pencil to edit — same gesture
                                 as the merge views, where single click selects a version) --}}
                            <td class="px-4 py-2 border-l border-gray-700 merge-cell"
                                :class="[isEdited(key) ? 'selected-manual' : '', isDeleted(key) ? 'deleted-cell' : '']"
                                @dblclick="editCell(key, getValue(data[key]))">
                                <span class="edit-affordance">
                                    <button type="button" @click.stop="editCell(key, getValue(data[key]))"
                                        title="{{ __('translation.edit') }}"><i class="fas fa-pen"></i></button>
                                    <button type="button" class="delete-btn" @click.stop="toggleDelete(key)"
                                        title="{{ __('translation.delete') }}"><i class="fas fa-trash"></i></button>
                                </span>
                                <span x-show="underlyingChanged[key]"
                                    class="inline-block mb-1 px-1.5 py-0.5 rounded bg-orange-900/60 text-orange-300 text-xs"
                                    title="{{ __('edit_session.changed_in_game') }}">
                                    <i class="fas fa-exclamation-triangle mr-1"></i>{{ __('edit_session.changed_in_game') }}
                                </span>
                                <span class="break-words"
                                    :class="[isEdited(key) ? 'text-purple-300' : '', isDeleted(key) ? 'line-through opacity-40' : '']">
                                    <span x-show="isEdited(key)" x-text="editedValues[key]"></span>
                                    <span x-show="!isEdited(key)" x-text="getValue(data[key])"></span>
                                </span>
                            </td>
                        </tr>
                    </template>

                    <tr x-show="filteredKeys.length === 0">
                        <td colspan="3" class="px-4 py-12 text-center text-gray-500">
                            <i class="fas fa-filter text-4xl mb-3 text-gray-600"></i>
                            <p>{{ __('edit_session.no_entries') }}</p>
                        </td>
                    </tr>

                    <tr x-show="hiddenCount > 0">
                        <td colspan="3" class="px-4 py-3 text-center">
                            <button type="button" @click="showMore()"
                                class="text-purple-400 hover:text-purple-300 text-sm transition">
                                <i class="fas fa-chevron-down mr-1"></i>
                                {{ __('merge_preview.show_more') }} (<span x-text="hiddenCount"></span>)
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        {{-- Footer with Save button --}}
        <div class="flex flex-wrap gap-4 justify-between items-center bg-gray-800 p-4 rounded-lg border border-gray-700 sticky bottom-4">
            <div class="text-sm text-gray-400">
                <span x-show="totalChanges > 0">
                    <span class="text-white font-bold" x-text="totalChanges"></span> {{ __('merge_preview.modifications') }}
                </span>
                <span x-show="totalChanges === 0 && !saveMessage" class="text-gray-500">
                    {{ __('edit_session.instructions') }}
                </span>
                <span x-show="saveMessage" class="text-green-400">
                    <i class="fas fa-check-circle mr-1"></i><span x-text="saveMessage"></span>
                </span>
            </div>

            <div class="flex gap-4 items-center">
                <button type="button" @click="clearAll()" x-show="totalChanges > 0"
                    class="text-gray-400 hover:text-white text-sm transition">
                    <i class="fas fa-times mr-1"></i> {{ __('merge_preview.cancel_changes') }}
                </button>

                <form method="POST" action="{{ route('edit-session.end') }}"
                    onsubmit="return confirm(@js(__('edit_session.end_confirm')))">
                    @csrf
                    <button type="submit"
                        class="text-red-400 hover:text-red-300 text-sm transition">
                        <i class="fas fa-power-off mr-1"></i> {{ __('edit_session.end_session') }}
                    </button>
                </form>

                <button type="button" @click="save()" :disabled="saving || totalChanges === 0"
                    class="bg-green-600 hover:bg-green-700 disabled:bg-gray-600 disabled:cursor-not-allowed px-6 py-3 rounded-lg text-white font-bold transition">
                    <i class="fas fa-save mr-2" x-show="!saving"></i>
                    <i class="fas fa-spinner fa-spin mr-2" x-show="saving"></i>
                    {{ __('edit_session.save') }} (<span x-text="totalChanges">0</span>)
                </button>
            </div>
        </div>
    </div>

    {{-- Edit Modal --}}
    <div x-show="editModal.open" x-cloak
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/70"
        @click.self="closeEditModal()">
        <div class="bg-gray-800 rounded-lg shadow-xl border border-gray-700 w-full max-w-2xl mx-4"
            @keydown.ctrl.enter="saveEditModal()">
            <div class="px-6 py-4 border-b border-gray-700">
                <h3 class="text-lg font-semibold text-white">{{ __('merge_preview.edit_translation') }}</h3>
                <p class="text-sm text-gray-400 font-mono mt-1 break-words" x-text="editModal.key"></p>
            </div>
            <div class="px-6 py-4">
                {{-- x-model must target a TOP-LEVEL property: the Alpine CSP
                     build prohibits property assignments (editModal.value = x) --}}
                <textarea
                    id="editModalTextarea"
                    x-model="editModalValue"
                    class="w-full h-48 px-4 py-3 bg-gray-900 border border-gray-600 rounded-lg text-white placeholder-gray-500 focus:border-purple-500 focus:ring-1 focus:ring-purple-500 resize-y"
                    placeholder="{{ __('merge_preview.enter_translation') }}"
                ></textarea>
                <p class="mt-2 text-xs text-gray-500">
                    <kbd class="px-1.5 py-0.5 bg-gray-700 rounded text-gray-300">Ctrl+Enter</kbd> {{ __('merge_preview.to_save') }} &bull;
                    <kbd class="px-1.5 py-0.5 bg-gray-700 rounded text-gray-300">Esc</kbd> {{ __('merge_preview.to_cancel') }}
                </p>
            </div>
            <div class="px-6 py-4 border-t border-gray-700 flex justify-end gap-3">
                <button type="button" @click="closeEditModal()"
                    class="px-4 py-2 text-gray-400 hover:text-white transition">
                    {{ __('merge_preview.cancel') }}
                </button>
                <button type="button" @click="saveEditModal()"
                    class="px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-lg transition">
                    <i class="fas fa-check mr-1"></i> {{ __('merge_preview.save') }}
                </button>
            </div>
        </div>
    </div>

    {{-- Tag Dropdown Menu (V = validate, A = invalidate, S = skip — same in every editor) --}}
    <div x-show="tagDropdown.open" x-cloak
        class="fixed z-50 bg-gray-800 rounded-lg shadow-xl border border-gray-600 py-1 min-w-[160px]"
        :style="'left: ' + tagDropdown.x + 'px; top: ' + tagDropdown.y + 'px;'"
        @click.outside="closeTagDropdown()"
        @keydown.escape="closeTagDropdown()">

        <div class="px-3 py-2 border-b border-gray-700">
            <p class="text-xs text-gray-400">{{ __('merge.change_tag_to') }}</p>
        </div>

        <button type="button"
            @click="setTag('V')"
            :class="tagDropdown.currentTag === 'V' ? 'bg-gray-700' : 'hover:bg-gray-700'"
            class="w-full px-3 py-2 text-left flex items-center gap-3 transition">
            <span class="tag-V">V</span>
            <span class="text-sm text-gray-300">{{ __('merge.tag_validate') }}</span>
            <span x-show="tagDropdown.currentTag === 'V'" class="ml-auto text-green-400">
                <i class="fas fa-check"></i>
            </span>
        </button>

        <button type="button"
            @click="setTag('S')"
            :class="tagDropdown.currentTag === 'S' ? 'bg-gray-700' : 'hover:bg-gray-700'"
            class="w-full px-3 py-2 text-left flex items-center gap-3 transition">
            <span class="tag-S">S</span>
            <span class="text-sm text-gray-300">{{ __('merge.tag_skip') }}</span>
            <span x-show="tagDropdown.currentTag === 'S'" class="ml-auto text-green-400">
                <i class="fas fa-check"></i>
            </span>
        </button>

        <button type="button"
            @click="setTag('A')"
            :class="tagDropdown.currentTag === 'A' ? 'bg-gray-700' : 'hover:bg-gray-700'"
            class="w-full px-3 py-2 text-left flex items-center gap-3 transition">
            <span class="tag-A">A</span>
            <span class="text-sm text-gray-300">{{ __('merge.tag_invalidate') }}</span>
            <span x-show="tagDropdown.currentTag === 'A'" class="ml-auto text-green-400">
                <i class="fas fa-check"></i>
            </span>
        </button>

        <template x-if="hasTagChange(tagDropdown.key)">
            <div class="border-t border-gray-700 mt-1 pt-1">
                <button type="button"
                    @click="cancelAndCloseTagDropdown(tagDropdown.key)"
                    class="w-full px-3 py-2 text-left flex items-center gap-3 text-gray-400 hover:bg-gray-700 hover:text-white transition">
                    <i class="fas fa-undo text-xs"></i>
                    <span class="text-sm">{{ __('merge.cancel_tag_change') }}</span>
                </button>
            </div>
        </template>
    </div>

    {{-- Legend (HVASM order) --}}
    <div class="mt-6 text-xs text-gray-500 flex flex-wrap gap-4">
        <span><span class="tag-H">H</span> Human</span>
        <span><span class="tag-V">V</span> Validated</span>
        <span><span class="tag-A">A</span> AI</span>
        <span><span class="tag-S">S</span> Skipped</span>
        <span><span class="tag-M">M</span> Mod UI</span>
        <span class="text-gray-600">|</span>
        <span><span class="inline-block w-3 h-3 bg-purple-900/50 rounded mr-1"></span> {{ __('merge_preview.manual_edit') }}</span>
    </div>
</div>

{{-- Editor styles (tags, cells, affordances) are shared in resources/css/app.css --}}
@push('head')
<style>
    [x-cloak] { display: none !important; }
</style>
@endpush

<script nonce="{{ $cspNonce }}">
// Shared editor core (modal, filters, search, sort, tag rules, windowing):
// resources/js/components/translation-editor.js, exposed by app.js.
// Only the edit-session specifics live here.
document.addEventListener('alpine:init', () => {
    // window.UGT is set by app.js (deferred module): it exists by the time
    // Alpine fires alpine:init, but NOT during the initial HTML parse
    const normalizeLineEndings = window.UGT.normalizeLineEndings;
    Alpine.data('editSession', () => window.UGT.composeEditor({
        persistKey: 'edit_session_ui',
        filters: {
            tagH: true,
            tagV: true,
            tagA: true,
            tagS: true,
            tagM: true,
            pendingOnly: false
        }
    }, {
        loaded: false,
        error: null,
        saving: false,
        saveMessage: '',
        savedCount: 0,
        data: {},          // key -> {v, t} or string (session file, minus _metadata)
        allKeys: [],
        // Live sync with the game (mod pushes new AI translations / in-game edits)
        currentHash: null,
        pollTimer: null,
        refreshNotice: '',
        underlyingChanged: {},  // pending keys whose in-game value changed under the edit

        init() {
            // Search/filters/sort survive page refreshes (F5 keeps the
            // session alive by design — the UI state survives with it)
            this.initEditorCore();

            // Content is streamed from the server, never inlined in the page:
            // translation files can be tens of MB
            fetch('{{ route("edit-session.data") }}', {
                headers: { 'Accept': 'application/json' }
            })
                .then(response => {
                    if (!response.ok) {
                        throw new Error(response.status === 410 ? 'expired' : 'load_failed');
                    }
                    return response.json();
                })
                .then(payload => {
                    this.loadContent(payload.content);
                    this.startLiveSync();
                })
                .catch(e => {
                    this.error = e.message === 'expired'
                        ? @js(__('edit_session.error_expired'))
                        : @js(__('merge_preview.error_load_failed'));
                    this.loaded = true;
                });
        },

        loadContent(content) {
            // Metadata keys (_uuid, _game, ...) stay server-side untouched:
            // the page neither displays nor sends them
            this.data = {};
            for (const [key, value] of Object.entries(content)) {
                if (key.startsWith('_')) continue;
                const normalizedKey = normalizeLineEndings(key);
                let normalizedValue = value;
                if (typeof value === 'object' && value !== null && 'v' in value) {
                    normalizedValue = { ...value, v: normalizeLineEndings(value.v) };
                } else if (typeof value === 'string') {
                    normalizedValue = normalizeLineEndings(value);
                }
                this.data[normalizedKey] = normalizedValue;
            }

            this.allKeys = Object.keys(this.data).sort();
            this.loaded = true;
        },

        // ── Shared-core callbacks ────────────────────────────────────────

        rowPassesFilters(key) {
            if (this.filters.pendingOnly && !this.isEdited(key) && !this.hasTagChange(key) && !this.isDeleted(key)) {
                return false;
            }

            // Tag filter: the row passes on its STORED or its PREVIEWED tag —
            // a pending change must not make its row vanish mid-work
            return this.tagVisible(this.getTag(this.data[key]))
                || this.tagVisible(this.displayTag(key, this.getTag(this.data[key])));
        },

        rowMatchesSearch(key, query) {
            if (this.searchScope !== 'values' && key.toLowerCase().includes(query)) {
                return true;
            }
            if (this.searchScope !== 'keys') {
                // A pending edit matches on its OLD value too: correcting the
                // very text you searched for must not make the row vanish
                // before "Save & apply in game"
                if (this.getValue(this.data[key]).toLowerCase().includes(query)) return true;
                if (this.editedValues[key] !== undefined
                    && this.editedValues[key].toLowerCase().includes(query)) return true;
            }
            return false;
        },

        rowSortValue(key, column) {
            if (column === 'tag') {
                return this.getTag(this.data[key]);
            }
            // 'value' — sort on the stored value: a pending edit must not
            // make the row jump around while the user is still working
            return this.getValue(this.data[key]).toLowerCase();
        },

        get totalChanges() {
            const keys = new Set([
                ...Object.keys(this.editedValues),
                ...Object.keys(this.tagChanges),
                ...Object.keys(this.deletions)
            ]);
            return keys.size;
        },

        // ── Live sync with the game ─────────────────────────────────────
        // The mod pushes its local file to the session when it changes
        // (new AI translations while playing, in-game edits). The state
        // endpoint doubles as the browser presence heartbeat.

        startLiveSync() {
            this.pollTimer = setInterval(() => this.checkState(), 10000);
            this.checkState(); // seed currentHash immediately

            // Tell the mod when the page goes away (close, navigation —
            // also fires on refresh, which the mod absorbs with its grace
            // period; the next state poll signals the rejoin)
            window.addEventListener('pagehide', () => {
                navigator.sendBeacon('{{ route("edit-session.leave") }}');
            });
        },

        stopLiveSync() {
            if (this.pollTimer) {
                clearInterval(this.pollTimer);
                this.pollTimer = null;
            }
        },

        checkState() {
            fetch('{{ route("edit-session.state") }}', { headers: { 'Accept': 'application/json' } })
                .then(response => {
                    if (!response.ok) {
                        throw new Error(response.status === 410 ? 'expired' : 'state_failed');
                    }
                    return response.json();
                })
                .then(state => {
                    if (this.currentHash === null) {
                        this.currentHash = state.content_hash;
                        return;
                    }
                    if (state.content_hash !== this.currentHash) {
                        this.currentHash = state.content_hash;
                        this.refreshData();
                    }
                })
                .catch(e => {
                    if (e.message === 'expired') {
                        this.stopLiveSync();
                        this.error = @js(__('edit_session.error_expired'));
                    }
                    // transient network errors: next poll retries
                });
        },

        refreshData() {
            fetch('{{ route("edit-session.data") }}', { headers: { 'Accept': 'application/json' } })
                .then(response => response.ok ? response.json() : Promise.reject())
                .then(payload => this.mergeRefreshedContent(payload.content))
                .catch(() => { /* next poll retries */ });
        },

        mergeRefreshedContent(content) {
            const fresh = {};
            for (const [key, value] of Object.entries(content)) {
                if (key.startsWith('_')) continue;
                const normalizedKey = normalizeLineEndings(key);
                let normalizedValue = value;
                if (typeof value === 'object' && value !== null && 'v' in value) {
                    normalizedValue = { ...value, v: normalizeLineEndings(value.v) };
                } else if (typeof value === 'string') {
                    normalizedValue = normalizeLineEndings(value);
                }
                fresh[normalizedKey] = normalizedValue;
            }

            // Flag pending keys whose in-game value changed under the edit —
            // the pending edit stays displayed and wins at save (human > AI),
            // the badge lets the user double-check before saving
            const pendingKeys = new Set([
                ...Object.keys(this.editedValues),
                ...Object.keys(this.tagChanges)
            ]);
            for (const key of pendingKeys) {
                if (key in fresh && key in this.data
                    && this.getValue(fresh[key]) !== this.getValue(this.data[key])) {
                    this.underlyingChanged[key] = true;
                }
            }

            // Apply as a DIFF: replacing this.data wholesale would
            // invalidate every row's reactive dependencies and re-render
            // the full window — a visible hitch every 10s while the game
            // translates. Touching only actual changes keeps the mod's
            // periodic pushes almost free.
            let changedCount = 0;
            let keysChanged = false;
            for (const key of Object.keys(fresh)) {
                if (!(key in this.data)) {
                    this.data[key] = fresh[key];
                    changedCount++;
                    keysChanged = true;
                } else if (this.getValue(fresh[key]) !== this.getValue(this.data[key])
                    || this.getTag(fresh[key]) !== this.getTag(this.data[key])) {
                    this.data[key] = fresh[key];
                    changedCount++;
                }
            }
            for (const key of Object.keys(this.data)) {
                if (!(key in fresh)) {
                    delete this.data[key];
                    changedCount++;
                    keysChanged = true;
                }
            }
            if (keysChanged) {
                this.allKeys = Object.keys(this.data).sort();
            }

            if (changedCount > 0) {
                this.refreshNotice = @js(__('edit_session.updated_from_game')) + ' (' + changedCount + ')';
                setTimeout(() => { this.refreshNotice = ''; }, 5000);
            }
        },

        // ── Actions ──────────────────────────────────────────────────────

        clearAll() {
            if (confirm(@js(__('merge_preview.confirm_cancel')))) {
                this.clearPendingState();
            }
        },

        save() {
            if (this.saving || this.totalChanges === 0) return;
            this.saving = true;
            this.saveMessage = '';

            // One selection per pending key; a value edit combined with a tag
            // change sends the new value AND the new tag. An explicit tag
            // change goes as 'local' even when combined with an edit: the
            // server writes 'local' tags as-is, while 'manual' would force H
            // and override the user's chosen tag
            const selections = [];
            const pendingKeys = new Set([
                ...Object.keys(this.editedValues),
                ...Object.keys(this.tagChanges)
            ]);
            for (const key of pendingKeys) {
                const isEdited = this.editedValues[key] !== undefined;
                const value = isEdited ? this.editedValues[key] : this.getValue(this.data[key]);
                const tag = this.tagChanges[key]
                    ? this.tagChanges[key].newTag
                    : this.getTag(this.data[key]);
                selections.push({
                    key: key,
                    value: value,
                    tag: tag,
                    source: isEdited && !this.tagChanges[key] ? 'manual' : 'local'
                });
            }
            const deletions = Object.keys(this.deletions);

            fetch('{{ route("edit-session.save") }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                },
                body: JSON.stringify({ selections, deletions })
            })
                .then(response => {
                    if (!response.ok) {
                        throw new Error(response.status === 410 ? 'expired' : 'save_failed');
                    }
                    return response.json();
                })
                .then(result => {
                    // Saved entries become the new baseline (same tag rules as the server)
                    for (const sel of selections) {
                        let tag = sel.tag;
                        if (tag !== 'M' && tag !== 'S' && sel.source === 'manual') {
                            tag = 'H';
                        }
                        this.data[sel.key] = { v: sel.value, t: tag };
                        // Conflict resolved by this save: the user's version won
                        delete this.underlyingChanged[sel.key];
                    }
                    for (const key of deletions) {
                        delete this.data[key];
                        delete this.underlyingChanged[key];
                    }
                    if (deletions.length > 0) {
                        this.allKeys = Object.keys(this.data).sort();
                    }
                    this.clearPendingState();
                    this.savedCount += (result.saved || 0) + (result.deleted || 0);
                    // Our own save changed the session hash — don't refetch on next poll
                    this.currentHash = result.content_hash;
                    this.saveMessage = @js(__('edit_session.saved_ok'));
                    setTimeout(() => { this.saveMessage = ''; }, 5000);
                })
                .catch(e => {
                    if (e.message === 'expired') {
                        this.error = @js(__('edit_session.error_expired'));
                    } else {
                        this.saveMessage = '';
                        alert(@js(__('edit_session.save_failed')));
                    }
                })
                .finally(() => {
                    this.saving = false;
                });
        }
    }));
});
</script>
@endsection
