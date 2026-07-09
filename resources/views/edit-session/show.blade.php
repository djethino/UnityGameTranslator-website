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
                <input type="checkbox" x-model="filters.tagH"
                    class="rounded bg-gray-700 border-gray-600 text-green-600">
                <span class="tag-H">H</span>
            </label>
            <label class="flex items-center gap-2 cursor-pointer">
                <input type="checkbox" x-model="filters.tagV"
                    class="rounded bg-gray-700 border-gray-600 text-blue-600">
                <span class="tag-V">V</span>
            </label>
            <label class="flex items-center gap-2 cursor-pointer">
                <input type="checkbox" x-model="filters.tagA"
                    class="rounded bg-gray-700 border-gray-600 text-orange-600">
                <span class="tag-A">A</span>
            </label>
            <label class="flex items-center gap-2 cursor-pointer">
                <input type="checkbox" x-model="filters.tagS"
                    class="rounded bg-gray-700 border-gray-600 text-gray-600">
                <span class="tag-S">S</span>
            </label>
            <label class="flex items-center gap-2 cursor-pointer">
                <input type="checkbox" x-model="filters.tagM"
                    class="rounded bg-gray-700 border-gray-600 text-purple-600">
                <span class="tag-M">M</span>
            </label>

            <span class="text-gray-600">|</span>

            <label class="flex items-center gap-2 cursor-pointer">
                <input type="checkbox" x-model="filters.pendingOnly"
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
                    <template x-for="key in filteredKeys" :key="key">
                        <tr class="border-t border-gray-700 hover:bg-gray-750 transition-colors">
                            {{-- Key --}}
                            <td class="px-4 py-2 font-mono text-xs text-gray-500 break-words" x-text="key"></td>

                            {{-- Tag (clickable for tag change) --}}
                            <td class="px-2 py-2 text-center border-l border-gray-700"
                                :class="hasTagChange(key) ? 'tag-changed-cell' : ''">
                                <button type="button"
                                    @click.stop="openTagDropdown($event, key, getTag(data[key]), getValue(data[key]))"
                                    class="transition rounded cursor-pointer hover:ring-2 hover:ring-purple-400 hover:ring-offset-1 hover:ring-offset-gray-800"
                                    title="{{ __('merge.click_to_change_tag') }}">
                                    <span x-show="isEdited(key) && !hasTagChange(key)" class="tag-H">H</span>
                                    <template x-if="hasTagChange(key)">
                                        <span :class="'tag-' + tagChanges[key].newTag" x-text="tagChanges[key].newTag"></span>
                                    </template>
                                    <span x-show="!isEdited(key) && !hasTagChange(key)"
                                        :class="'tag-' + getTag(data[key])" x-text="getTag(data[key])"></span>
                                </button>
                            </td>

                            {{-- Value (click to edit) --}}
                            <td class="px-4 py-2 border-l border-gray-700 merge-cell"
                                :class="isEdited(key) ? 'selected-manual' : ''"
                                @click="editCell(key, getValue(data[key]))">
                                <span class="break-words" :class="isEdited(key) ? 'text-purple-300' : ''">
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
                    onsubmit="return confirm('{{ __('edit_session.end_confirm') }}')">
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
                <textarea
                    id="editModalTextarea"
                    x-model="editModal.value"
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

    {{-- Tag Dropdown Menu (Skip only, same rule as merge-preview) --}}
    <div x-show="tagDropdown.open" x-cloak
        class="fixed z-50 bg-gray-800 rounded-lg shadow-xl border border-gray-600 py-1 min-w-[160px]"
        :style="'left: ' + tagDropdown.x + 'px; top: ' + tagDropdown.y + 'px;'"
        @click.outside="closeTagDropdown()"
        @keydown.escape="closeTagDropdown()">

        <div class="px-3 py-2 border-b border-gray-700">
            <p class="text-xs text-gray-400">{{ __('merge.change_tag_to') }}</p>
        </div>

        <button type="button"
            @click="setTagSkip()"
            :class="tagDropdown.currentTag === 'S' ? 'bg-gray-700' : 'hover:bg-gray-700'"
            class="w-full px-3 py-2 text-left flex items-center gap-3 transition">
            <span class="tag-S">S</span>
            <span class="text-sm text-gray-300">{{ __('merge.tag_skip') }}</span>
            <span x-show="tagDropdown.currentTag === 'S'" class="ml-auto text-green-400">
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

@push('head')
<style>
    [x-cloak] { display: none !important; }

    .tag-H { background-color: rgb(22 163 74); color: white; padding: 0.125rem 0.375rem; border-radius: 0.25rem; font-size: 0.75rem; font-weight: 700; }
    .tag-A { background-color: rgb(234 88 12); color: white; padding: 0.125rem 0.375rem; border-radius: 0.25rem; font-size: 0.75rem; font-weight: 700; }
    .tag-V { background-color: rgb(37 99 235); color: white; padding: 0.125rem 0.375rem; border-radius: 0.25rem; font-size: 0.75rem; font-weight: 700; }
    .tag-M { background-color: rgb(147 51 234); color: white; padding: 0.125rem 0.375rem; border-radius: 0.25rem; font-size: 0.75rem; font-weight: 700; }
    .tag-S { background-color: rgb(75 85 99); color: white; padding: 0.125rem 0.375rem; border-radius: 0.25rem; font-size: 0.75rem; font-weight: 700; }

    .merge-cell { cursor: pointer; transition: all 150ms; user-select: none; -webkit-user-select: none; }
    .merge-cell:hover { background-color: rgba(55, 65, 81, 0.5); }
    .selected-manual { background-color: rgba(88, 28, 135, 0.5) !important; box-shadow: inset 0 0 0 2px rgb(168 85 247); }
    .tag-changed-cell { background-color: rgba(88, 28, 135, 0.3) !important; }
</style>
@endpush

<script nonce="{{ $cspNonce }}">
/**
 * Normalize line endings to Unix format (\n) — same helper as merge-preview,
 * keys must stay consistent across platforms.
 */
function normalizeLineEndings(text) {
    if (typeof text !== 'string') return text;
    return text.replace(/\r\n/g, '\n').replace(/\r/g, '\n');
}

document.addEventListener('alpine:init', () => {
    Alpine.data('editSession', () => ({
        loaded: false,
        error: null,
        saving: false,
        saveMessage: '',
        savedCount: 0,
        data: {},          // key -> {v, t} or string (session file, minus _metadata)
        allKeys: [],
        editedValues: {},  // key -> new value (pending)
        tagChanges: {},    // key -> { newTag, originalTag, value } (pending)
        filters: {
            tagH: true,
            tagV: true,
            tagA: true,
            tagS: true,
            tagM: true,
            pendingOnly: false
        },
        searchQuery: '',
        searchScope: 'both', // 'both' | 'keys' | 'values'
        sortColumn: 'key',
        sortDirection: 'asc',

        editModal: {
            open: false,
            key: '',
            value: '',
            originalValue: ''
        },
        tagDropdown: {
            open: false,
            key: '',
            currentTag: '',
            originalTag: '',
            value: '',
            x: 0,
            y: 0
        },

        init() {
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
                })
                .catch(e => {
                    this.error = e.message === 'expired'
                        ? '{{ __("edit_session.error_expired") }}'
                        : '{{ __("merge_preview.error_load_failed") }}';
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

        get totalChanges() {
            const keys = new Set([
                ...Object.keys(this.editedValues),
                ...Object.keys(this.tagChanges)
            ]);
            return keys.size;
        },

        get filteredKeys() {
            let keys = this.allKeys.filter(key => {
                // Pending-only filter
                if (this.filters.pendingOnly && !this.isEdited(key) && !this.hasTagChange(key)) {
                    return false;
                }

                // Tag filter (on the DISPLAYED tag: pending edit shows H, pending skip shows S)
                let tag = this.getTag(this.data[key]);
                if (this.hasTagChange(key)) tag = this.tagChanges[key].newTag;
                else if (this.isEdited(key)) tag = 'H';
                const tagFilters = {
                    'H': this.filters.tagH,
                    'V': this.filters.tagV,
                    'A': this.filters.tagA,
                    'S': this.filters.tagS,
                    'M': this.filters.tagM
                };
                if (!tagFilters[tag]) return false;

                // Search filter (scope: 'both' = keys + values, 'keys', 'values')
                if (this.searchQuery.trim()) {
                    const query = this.searchQuery.toLowerCase().trim();
                    const keyMatch = this.searchScope !== 'values' && key.toLowerCase().includes(query);
                    let valueMatch = false;
                    if (this.searchScope !== 'keys') {
                        const value = (this.editedValues[key] ?? this.getValue(this.data[key])).toLowerCase();
                        valueMatch = value.includes(query);
                    }
                    if (!keyMatch && !valueMatch) return false;
                }

                return true;
            });

            const col = this.sortColumn;
            const dir = this.sortDirection === 'asc' ? 1 : -1;
            keys.sort((a, b) => {
                let valA, valB;
                if (col === 'key') {
                    valA = a.toLowerCase();
                    valB = b.toLowerCase();
                } else if (col === 'tag') {
                    valA = this.getTag(this.data[a]);
                    valB = this.getTag(this.data[b]);
                } else if (col === 'value') {
                    valA = (this.editedValues[a] ?? this.getValue(this.data[a])).toLowerCase();
                    valB = (this.editedValues[b] ?? this.getValue(this.data[b])).toLowerCase();
                } else {
                    return 0;
                }
                if (valA < valB) return -1 * dir;
                if (valA > valB) return 1 * dir;
                return 0;
            });

            return keys;
        },

        toggleSort(column) {
            if (this.sortColumn === column) {
                this.sortDirection = this.sortDirection === 'asc' ? 'desc' : 'asc';
            } else {
                this.sortColumn = column;
                this.sortDirection = 'asc';
            }
        },

        getSortIcon(column) {
            if (this.sortColumn !== column) {
                return 'fa-sort text-gray-600';
            }
            return this.sortDirection === 'asc' ? 'fa-sort-up text-purple-400' : 'fa-sort-down text-purple-400';
        },

        editCell(key, currentValue) {
            const existingValue = this.editedValues[key] ?? currentValue;
            this.editModal = {
                open: true,
                key: key,
                value: existingValue,
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
            const { key, value, originalValue } = this.editModal;

            if (value !== originalValue) {
                this.editedValues[key] = value;
            } else {
                delete this.editedValues[key];
            }

            this.closeEditModal();
        },

        closeEditModal() {
            this.editModal = { open: false, key: '', value: '', originalValue: '' };

            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape' && this.editModal.open) {
                    this.closeEditModal();
                }
            }, { once: true });
        },

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

        setTagSkip() {
            const { key, originalTag, value } = this.tagDropdown;
            if (originalTag === 'S') {
                delete this.tagChanges[key];
            } else {
                this.tagChanges[key] = { newTag: 'S', originalTag: originalTag, value: value };
            }
            this.closeTagDropdown();
        },

        cancelAndCloseTagDropdown(key) {
            delete this.tagChanges[key];
            this.closeTagDropdown();
        },

        clearAll() {
            if (confirm('{{ __("merge_preview.confirm_cancel") }}')) {
                this.editedValues = {};
                this.tagChanges = {};
            }
        },

        save() {
            if (this.saving || this.totalChanges === 0) return;
            this.saving = true;
            this.saveMessage = '';

            // One selection per pending key; a value edit combined with a tag
            // change sends the new value AND the new tag (server keeps S/M as-is)
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
                    source: isEdited ? 'manual' : 'local'
                });
            }

            fetch('{{ route("edit-session.save") }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                },
                body: JSON.stringify({ selections })
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
                    }
                    this.editedValues = {};
                    this.tagChanges = {};
                    this.savedCount += result.saved;
                    this.saveMessage = '{{ __("edit_session.saved_ok") }}';
                    setTimeout(() => { this.saveMessage = ''; }, 5000);
                })
                .catch(e => {
                    if (e.message === 'expired') {
                        this.error = '{{ __("edit_session.error_expired") }}';
                    } else {
                        this.saveMessage = '';
                        alert('{{ __("edit_session.save_failed") }}');
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
