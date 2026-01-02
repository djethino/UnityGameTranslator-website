@extends('layouts.app')

@section('title', __('merge_preview.title') . ' - ' . $translation->game->name)

@section('content')
<div class="container mx-auto px-4 py-8" x-data="mergePreview()">
    {{-- Header --}}
    <div class="mb-6">
        <div class="flex items-center gap-4 mb-2">
            <a href="{{ route('translations.mine') }}" class="text-purple-400 hover:text-purple-300">
                <i class="fas fa-arrow-left"></i> {{ __('merge_preview.back_to_translations') }}
            </a>
        </div>
        <h1 class="text-2xl font-bold text-white">{{ __('merge_preview.title') }}</h1>
        <p class="text-gray-400">
            {{ $translation->game->name }} &bull;
            {{ $translation->source_language }} <i class="fas fa-arrow-right text-xs"></i> {{ $translation->target_language }}
        </p>
    </div>

    {{-- Success message --}}
    @if(session('success'))
    <div class="mb-6 bg-green-900/50 border border-green-600 rounded-lg p-4 text-green-300">
        <i class="fas fa-check-circle mr-2"></i> {{ session('success') }}
    </div>
    @endif

    {{-- Error messages --}}
    @if($errors->any())
    <div class="mb-6 bg-red-900/50 border border-red-600 rounded-lg p-4 text-red-300">
        <ul class="list-disc list-inside">
            @foreach($errors->all() as $error)
            <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
    @endif

    {{-- Loading state --}}
    <div x-show="!loaded" class="text-center py-12">
        <i class="fas fa-spinner fa-spin text-4xl text-purple-400 mb-4"></i>
        <p class="text-gray-400">{{ __('merge_preview.loading') }}</p>
    </div>

    {{-- Error state --}}
    <div x-show="error" x-cloak class="bg-red-900/50 border border-red-600 rounded-lg p-6 text-center">
        <i class="fas fa-exclamation-triangle text-4xl text-red-400 mb-4"></i>
        <p class="text-red-300" x-text="error"></p>
        <a href="{{ route('translations.mine') }}" class="mt-4 inline-block bg-purple-600 hover:bg-purple-700 px-6 py-2 rounded-lg text-white transition">
            {{ __('merge_preview.back_to_translations') }}
        </a>
    </div>

    {{-- Main content --}}
    <div x-show="loaded && !error" x-cloak>
        {{-- Stats --}}
        <div class="mb-6 grid grid-cols-2 md:grid-cols-5 gap-4">
            <div class="bg-gray-800 rounded-lg p-4 border border-gray-700 text-center">
                <p class="text-2xl font-bold text-white" x-text="stats.total"></p>
                <p class="text-sm text-gray-400">{{ __('merge_preview.total_keys') }}</p>
            </div>
            <div class="bg-gray-800 rounded-lg p-4 border border-green-700 text-center">
                <p class="text-2xl font-bold text-green-400" x-text="stats.localOnly"></p>
                <p class="text-sm text-gray-400">{{ __('merge_preview.local_only') }}</p>
            </div>
            <div class="bg-gray-800 rounded-lg p-4 border border-blue-700 text-center">
                <p class="text-2xl font-bold text-blue-400" x-text="stats.onlineOnly"></p>
                <p class="text-sm text-gray-400">{{ __('merge_preview.online_only') }}</p>
            </div>
            <div class="bg-gray-800 rounded-lg p-4 border border-yellow-700 text-center">
                <p class="text-2xl font-bold text-yellow-400" x-text="stats.different"></p>
                <p class="text-sm text-gray-400">{{ __('merge_preview.different') }}</p>
            </div>
            <div class="bg-gray-800 rounded-lg p-4 border border-purple-700 text-center">
                <p class="text-2xl font-bold text-purple-400" x-text="Object.keys(editedValues).length"></p>
                <p class="text-sm text-gray-400">{{ __('merge_preview.edited') }}</p>
            </div>
        </div>

        {{-- Filters --}}
        <div class="mb-4 flex flex-wrap gap-4 items-center text-sm bg-gray-800 p-4 rounded-lg border border-gray-700">
            <span class="text-gray-500">{{ __('merge_preview.show') }}:</span>

            <label class="flex items-center gap-2 cursor-pointer">
                <input type="checkbox" x-model="filters.localOnly"
                    class="rounded bg-gray-700 border-gray-600 text-green-600">
                <span class="text-green-400">{{ __('merge_preview.local_only') }}</span>
            </label>

            <label class="flex items-center gap-2 cursor-pointer">
                <input type="checkbox" x-model="filters.onlineOnly"
                    class="rounded bg-gray-700 border-gray-600 text-blue-600">
                <span class="text-blue-400">{{ __('merge_preview.online_only') }}</span>
            </label>

            <label class="flex items-center gap-2 cursor-pointer">
                <input type="checkbox" x-model="filters.different"
                    class="rounded bg-gray-700 border-gray-600 text-yellow-600">
                <span class="text-yellow-400">{{ __('merge_preview.different') }}</span>
            </label>

            <label class="flex items-center gap-2 cursor-pointer">
                <input type="checkbox" x-model="filters.same"
                    class="rounded bg-gray-700 border-gray-600 text-gray-600">
                <span class="text-gray-400">{{ __('merge_preview.same') }}</span>
            </label>

            <span class="text-gray-600">|</span>

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

            <button type="button" @click="selectAllLocal()" class="text-green-400 hover:text-green-300">
                <i class="fas fa-check-double mr-1"></i> {{ __('merge_preview.select_all_local') }}
            </button>

            <button type="button" @click="selectAllOnline()" class="text-blue-400 hover:text-blue-300">
                <i class="fas fa-check-double mr-1"></i> {{ __('merge_preview.select_all_online') }}
            </button>
        </div>

        {{-- Search --}}
        <div class="mb-4">
            <div class="relative">
                <input type="text" x-model="searchQuery" placeholder="{{ __('merge_preview.search_placeholder') }}"
                    class="w-full px-4 py-2 pl-10 bg-gray-800 border border-gray-700 rounded-lg text-white placeholder-gray-500 focus:border-purple-500 focus:ring-1 focus:ring-purple-500">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-500"></i>
                <button x-show="searchQuery" @click="searchQuery = ''" type="button"
                    class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-500 hover:text-white">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </div>

        {{-- Table --}}
        <div class="overflow-x-auto bg-gray-800 rounded-lg border border-gray-700 mb-6">
            <table class="w-full text-sm">
                <thead class="bg-gray-900 sticky top-0 z-10">
                    <tr>
                        {{-- Key column with sort --}}
                        <th class="px-4 py-3 text-left text-gray-400 font-medium cursor-pointer hover:text-white transition"
                            @click="toggleSort('key')">
                            <div class="flex items-center gap-2">
                                {{ __('merge_preview.key') }}
                                <i class="fas" :class="getSortIcon('key')"></i>
                            </div>
                        </th>
                        {{-- Local Tag --}}
                        <th class="px-2 py-3 text-center border-l border-gray-700 w-12 cursor-pointer hover:text-white transition"
                            @click="toggleSort('localTag')">
                            <div class="flex items-center justify-center gap-1">
                                <span class="text-green-400 font-medium text-xs">Tag</span>
                                <i class="fas text-xs" :class="getSortIcon('localTag')"></i>
                            </div>
                        </th>
                        {{-- Local Value --}}
                        <th class="px-4 py-3 text-left border-l border-gray-700 cursor-pointer hover:text-white transition"
                            @click="toggleSort('localValue')">
                            <div class="flex items-center gap-2">
                                <span class="text-green-400 font-medium">{{ __('merge_preview.local_file') }}</span>
                                <i class="fas" :class="getSortIcon('localValue')"></i>
                            </div>
                        </th>
                        {{-- Online Tag --}}
                        <th class="px-2 py-3 text-center border-l border-gray-700 w-12 cursor-pointer hover:text-white transition"
                            @click="toggleSort('onlineTag')">
                            <div class="flex items-center justify-center gap-1">
                                <span class="text-blue-400 font-medium text-xs">Tag</span>
                                <i class="fas text-xs" :class="getSortIcon('onlineTag')"></i>
                            </div>
                        </th>
                        {{-- Online Value --}}
                        <th class="px-4 py-3 text-left border-l border-gray-700 cursor-pointer hover:text-white transition"
                            @click="toggleSort('onlineValue')">
                            <div class="flex items-center gap-2">
                                <span class="text-blue-400 font-medium">{{ __('merge_preview.online_version') }}</span>
                                <span class="text-xs text-gray-500">({{ $translation->user->name }})</span>
                                <i class="fas" :class="getSortIcon('onlineValue')"></i>
                            </div>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="key in filteredKeys" :key="key">
                        <tr class="border-t border-gray-700 hover:bg-gray-750 transition-colors">
                            {{-- Key column --}}
                            <td class="px-4 py-2 font-mono text-xs text-gray-500 break-words" x-text="key"></td>

                            {{-- Local Tag column (clickable for tag change) --}}
                            <td class="px-2 py-2 text-center border-l border-gray-700"
                                :class="hasTagChange(key) ? 'tag-changed-cell' : ''">
                                <template x-if="localData[key] !== undefined">
                                    <button type="button"
                                        @click.stop="openTagDropdown($event, key, getTag(localData[key]), getValue(localData[key]))"
                                        class="transition rounded cursor-pointer hover:ring-2 hover:ring-purple-400 hover:ring-offset-1 hover:ring-offset-gray-800"
                                        title="{{ __('merge.click_to_change_tag') }}">
                                        {{-- Show H if edited --}}
                                        <span x-show="isEdited(key)" class="tag-H">H</span>
                                        {{-- Show changed tag if tag was changed --}}
                                        <template x-if="!isEdited(key) && hasTagChange(key)">
                                            <span :class="'tag-' + getDisplayTag(key, getTag(localData[key]))" x-text="getDisplayTag(key, getTag(localData[key]))"></span>
                                        </template>
                                        {{-- Show original tag --}}
                                        <span x-show="!isEdited(key) && !hasTagChange(key)" :class="'tag-' + getTag(localData[key])" x-text="getTag(localData[key])"></span>
                                    </button>
                                </template>
                                <template x-if="localData[key] === undefined">
                                    <span class="text-gray-600">—</span>
                                </template>
                            </td>

                            {{-- Local Value column --}}
                            <td class="px-4 py-2 border-l border-gray-700 merge-cell"
                                :class="getCellClass(key, 'local')"
                                @click="select(key, 'local')"
                                @dblclick="editCell(key, getValue(localData[key]))">
                                <template x-if="localData[key] !== undefined">
                                    <span class="break-words" :class="isEdited(key) ? 'text-purple-300' : ''">
                                        <span x-show="isEdited(key)" x-text="editedValues[key]"></span>
                                        <span x-show="!isEdited(key)" x-text="getValue(localData[key])"></span>
                                    </span>
                                </template>
                                <template x-if="localData[key] === undefined">
                                    <span class="text-gray-600 italic">—</span>
                                </template>
                            </td>

                            {{-- Online Tag column --}}
                            <td class="px-2 py-2 text-center border-l border-gray-700 merge-cell"
                                :class="getCellClass(key, 'online')"
                                @click="select(key, 'online')">
                                <template x-if="onlineData[key] !== undefined">
                                    <span :class="'tag-' + getTag(onlineData[key])" x-text="getTag(onlineData[key])"></span>
                                </template>
                                <template x-if="onlineData[key] === undefined">
                                    <span class="text-gray-600">—</span>
                                </template>
                            </td>

                            {{-- Online Value column --}}
                            <td class="px-4 py-2 border-l border-gray-700 merge-cell"
                                :class="getCellClass(key, 'online')"
                                @click="select(key, 'online')">
                                <template x-if="onlineData[key] !== undefined">
                                    <span class="break-words" x-text="getValue(onlineData[key])"></span>
                                </template>
                                <template x-if="onlineData[key] === undefined">
                                    <span class="text-gray-600 italic">—</span>
                                </template>
                            </td>
                        </tr>
                    </template>

                    <tr x-show="filteredKeys.length === 0">
                        <td colspan="5" class="px-4 py-12 text-center text-gray-500">
                            <i class="fas fa-check-circle text-4xl mb-3 text-green-500"></i>
                            <p>{{ __('merge_preview.no_differences') }}</p>
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
                    <span x-show="Object.keys(editedValues).length > 0" class="ml-2 text-purple-400">
                        (<span x-text="Object.keys(editedValues).length"></span> {{ __('merge_preview.edited_manually') }})
                    </span>
                </span>
                <span x-show="totalChanges === 0" class="text-gray-500">
                    {{ __('merge_preview.instructions') }}
                </span>
            </div>

            <div class="flex gap-4 items-center">
                <button type="button" @click="clearAll()" x-show="totalChanges > 0"
                    class="text-gray-400 hover:text-white text-sm transition">
                    <i class="fas fa-times mr-1"></i> {{ __('merge_preview.cancel_changes') }}
                </button>

                <button type="button" @click="downloadMerged()"
                    class="bg-blue-600 hover:bg-blue-700 px-4 py-2 rounded-lg text-white transition">
                    <i class="fas fa-download mr-2"></i> {{ __('merge_preview.download_merged') }}
                </button>

                <button type="button" @click="saveToServer()" :disabled="saving || totalChanges === 0"
                    class="bg-green-600 hover:bg-green-700 disabled:bg-gray-600 disabled:cursor-not-allowed px-6 py-3 rounded-lg text-white font-bold transition">
                    <i class="fas fa-save mr-2" x-show="!saving"></i>
                    <i class="fas fa-spinner fa-spin mr-2" x-show="saving"></i>
                    {{ __('merge_preview.save_to_server') }} (<span x-text="totalChanges">0</span>)
                </button>
            </div>
        </div>

        {{-- Hidden form for saving to server --}}
        <form method="POST" action="{{ route('translations.merge-preview.apply', $translation) }}" id="saveForm" class="hidden">
            @csrf
            <div id="selectionsContainer"></div>
        </form>
    </div>

    {{-- Edit Modal --}}
    <div x-show="editModal.open" x-cloak
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/70"
        @click.self="closeEditModal()">
        <div class="bg-gray-800 rounded-lg shadow-xl border border-gray-700 w-full max-w-2xl mx-4"
            @keydown.ctrl.enter="saveEditModal()">
            {{-- Modal Header --}}
            <div class="px-6 py-4 border-b border-gray-700">
                <h3 class="text-lg font-semibold text-white">{{ __('merge_preview.edit_translation') }}</h3>
                <p class="text-sm text-gray-400 font-mono mt-1 break-words" x-text="editModal.key"></p>
            </div>

            {{-- Modal Body --}}
            <div class="px-6 py-4">
                <textarea
                    id="editModalTextarea"
                    x-model="editModal.value"
                    class="w-full h-48 px-4 py-3 bg-gray-900 border border-gray-600 rounded-lg text-white placeholder-gray-500 focus:border-purple-500 focus:ring-1 focus:ring-purple-500 resize-y"
                    :placeholder="__('merge_preview.enter_translation')"
                ></textarea>
                <p class="mt-2 text-xs text-gray-500">
                    <kbd class="px-1.5 py-0.5 bg-gray-700 rounded text-gray-300">Ctrl+Enter</kbd> {{ __('merge_preview.to_save') }} &bull;
                    <kbd class="px-1.5 py-0.5 bg-gray-700 rounded text-gray-300">Esc</kbd> {{ __('merge_preview.to_cancel') }}
                </p>
            </div>

            {{-- Modal Footer --}}
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

    {{-- Tag Dropdown Menu (branches can only Skip) --}}
    <div x-show="tagDropdown.open" x-cloak
        class="fixed z-50 bg-gray-800 rounded-lg shadow-xl border border-gray-600 py-1 min-w-[160px]"
        :style="`left: ${tagDropdown.x}px; top: ${tagDropdown.y}px;`"
        @click.outside="closeTagDropdown()"
        @keydown.escape="closeTagDropdown()">

        <div class="px-3 py-2 border-b border-gray-700">
            <p class="text-xs text-gray-400">{{ __('merge.change_tag_to') }}</p>
        </div>

        {{-- Skip option --}}
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

        {{-- Cancel change (if tag was changed) --}}
        <template x-if="hasTagChange(tagDropdown.key)">
            <div class="border-t border-gray-700 mt-1 pt-1">
                <button type="button"
                    @click="cancelTagChange(tagDropdown.key); closeTagDropdown()"
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
        <span><span class="inline-block w-3 h-3 bg-green-900/50 rounded mr-1"></span> {{ __('merge_preview.selection_local') }}</span>
        <span><span class="inline-block w-3 h-3 bg-blue-900/50 rounded mr-1"></span> {{ __('merge_preview.selection_online') }}</span>
        <span><span class="inline-block w-3 h-3 bg-purple-900/50 rounded mr-1"></span> {{ __('merge_preview.manual_edit') }}</span>
    </div>
</div>

@push('head')
<style>
    [x-cloak] { display: none !important; }

    .tag-H {
        background-color: rgb(22 163 74);
        color: white;
        padding: 0.125rem 0.375rem;
        border-radius: 0.25rem;
        font-size: 0.75rem;
        font-weight: 700;
    }
    .tag-A {
        background-color: rgb(234 88 12);
        color: white;
        padding: 0.125rem 0.375rem;
        border-radius: 0.25rem;
        font-size: 0.75rem;
        font-weight: 700;
    }
    .tag-V {
        background-color: rgb(37 99 235);
        color: white;
        padding: 0.125rem 0.375rem;
        border-radius: 0.25rem;
        font-size: 0.75rem;
        font-weight: 700;
    }
    .tag-M {
        background-color: rgb(147 51 234);
        color: white;
        padding: 0.125rem 0.375rem;
        border-radius: 0.25rem;
        font-size: 0.75rem;
        font-weight: 700;
    }
    .tag-S {
        background-color: rgb(75 85 99);
        color: white;
        padding: 0.125rem 0.375rem;
        border-radius: 0.25rem;
        font-size: 0.75rem;
        font-weight: 700;
    }

    .merge-cell {
        cursor: pointer;
        transition: all 150ms;
        user-select: none;
        -webkit-user-select: none;
    }
    .merge-cell:hover {
        background-color: rgba(55, 65, 81, 0.5);
    }

    .selected-local {
        background-color: rgba(20, 83, 45, 0.5) !important;
        box-shadow: inset 0 0 0 2px rgb(34 197 94);
    }
    .selected-online {
        background-color: rgba(30, 58, 138, 0.5) !important;
        box-shadow: inset 0 0 0 2px rgb(59 130 246);
    }
    .selected-manual {
        background-color: rgba(88, 28, 135, 0.5) !important;
        box-shadow: inset 0 0 0 2px rgb(168 85 247);
    }
    .tag-changed-cell {
        background-color: rgba(88, 28, 135, 0.3) !important;
    }
</style>
@endpush

<script nonce="{{ $cspNonce }}">
/**
 * Normalize line endings to Unix format (\n).
 * Converts \r\n (Windows) and \r (old Mac) to \n.
 * This ensures consistent keys across platforms.
 */
function normalizeLineEndings(text) {
    if (typeof text !== 'string') return text;
    // Order is important: first \r\n, then \r
    // Otherwise \r\n would become \n\n
    return text.replace(/\r\n/g, '\n').replace(/\r/g, '\n');
}

function mergePreview() {
    return {
        loaded: false,
        error: null,
        saving: false,
        localData: {},
        onlineData: @json($onlineContent),
        allKeys: [],
        selections: {},
        editedValues: {},
        filters: {
            localOnly: true,
            onlineOnly: false,  // Already on server, nothing to merge
            different: true,
            same: false,
            // Tag filters (HVASM) - all enabled by default
            tagH: true,
            tagV: true,
            tagA: true,
            tagS: true,
            tagM: true
        },
        searchQuery: '',
        sortColumn: 'key',
        sortDirection: 'asc',
        stats: {
            total: 0,
            localOnly: 0,
            onlineOnly: 0,
            different: 0,
            same: 0
        },

        // Modal state
        editModal: {
            open: false,
            key: '',
            value: '',
            originalValue: ''
        },

        // Tag change state (branches can only skip, not invalidate)
        tagChanges: {},  // { key: { newTag: 'S', originalTag: 'A', value: '...' } }
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
            // Check for token-provided content first (mod flow)
            const tokenContent = @json($tokenContent);

            if (tokenContent) {
                // Mod flow: use token content
                this.loadContent(tokenContent);
                return;
            }

            // Web flow: check sessionStorage
            const localContent = sessionStorage.getItem('merge_local_content');
            const translationId = sessionStorage.getItem('merge_translation_id');

            if (!localContent || translationId !== '{{ $translation->id }}') {
                this.error = '{{ __("merge_preview.error_no_local_file") }}';
                this.loaded = true;
                return;
            }

            try {
                const parsed = JSON.parse(localContent);
                this.loadContent(parsed);

                // Clear sessionStorage after successful load
                sessionStorage.removeItem('merge_local_content');
                sessionStorage.removeItem('merge_translation_id');
                sessionStorage.removeItem('merge_main_translation_id');
                sessionStorage.removeItem('merge_is_main_owner');
            } catch (e) {
                this.error = '{{ __("merge_preview.error_invalid_json") }}';
                this.loaded = true;
            }
        },

        loadContent(content) {
            // Filter out metadata keys from local and normalize line endings
            this.localData = {};
            for (const [key, value] of Object.entries(content)) {
                if (!key.startsWith('_')) {
                    // Normalize key line endings for cross-platform consistency
                    const normalizedKey = normalizeLineEndings(key);
                    // Normalize value if it's a string or {v, t} object
                    let normalizedValue = value;
                    if (typeof value === 'object' && value !== null && 'v' in value) {
                        normalizedValue = { ...value, v: normalizeLineEndings(value.v) };
                    } else if (typeof value === 'string') {
                        normalizedValue = normalizeLineEndings(value);
                    }
                    this.localData[normalizedKey] = normalizedValue;
                }
            }

            // Filter online data too and normalize
            const filteredOnline = {};
            for (const [key, value] of Object.entries(this.onlineData)) {
                if (!key.startsWith('_')) {
                    // Normalize key line endings for cross-platform consistency
                    const normalizedKey = normalizeLineEndings(key);
                    // Normalize value if it's a string or {v, t} object
                    let normalizedValue = value;
                    if (typeof value === 'object' && value !== null && 'v' in value) {
                        normalizedValue = { ...value, v: normalizeLineEndings(value.v) };
                    } else if (typeof value === 'string') {
                        normalizedValue = normalizeLineEndings(value);
                    }
                    filteredOnline[normalizedKey] = normalizedValue;
                }
            }
            this.onlineData = filteredOnline;

            // Build list of all keys
            this.allKeys = [...new Set([
                ...Object.keys(this.localData),
                ...Object.keys(this.onlineData)
            ])].sort();

            // Calculate stats
            this.calculateStats();

            // Auto-select based on smart defaulting:
            // - Local-only: select LOCAL (additions to server)
            // - Online-only: select ONLINE (already on server)
            // - Different: smart default based on tag quality (H > V > A, online wins ties)
            // - Same: select ONLINE (no change needed)
            const tagPriority = { 'H': 3, 'V': 2, 'A': 1, 'M': 0, 'S': 0 };

            for (const key of this.allKeys) {
                const hasLocal = key in this.localData;
                const hasOnline = key in this.onlineData;

                if (hasLocal && !hasOnline) {
                    // Local-only: addition, select local
                    this.selections[key] = 'local';
                } else if (!hasLocal && hasOnline) {
                    // Online-only: already on server, keep online
                    this.selections[key] = 'online';
                } else if (hasLocal && hasOnline) {
                    // Both exist: check if values differ (conflict)
                    const localVal = this.getValue(this.localData[key]);
                    const onlineVal = this.getValue(this.onlineData[key]);

                    if (localVal !== onlineVal) {
                        // Conflict: smart default based on tag quality
                        const localTag = this.getTag(this.localData[key]);
                        const onlineTag = this.getTag(this.onlineData[key]);
                        const localPriority = tagPriority[localTag] || 0;
                        const onlinePriority = tagPriority[onlineTag] || 0;

                        if (localPriority > onlinePriority) {
                            // Local is better quality
                            this.selections[key] = 'local';
                        } else {
                            // Online is better or equal (server wins ties)
                            this.selections[key] = 'online';
                        }
                    } else {
                        // Same value: keep online (no change)
                        this.selections[key] = 'online';
                    }
                }
            }

            this.loaded = true;
        },

        calculateStats() {
            this.stats = { total: 0, localOnly: 0, onlineOnly: 0, different: 0, same: 0 };

            for (const key of this.allKeys) {
                this.stats.total++;

                const hasLocal = key in this.localData;
                const hasOnline = key in this.onlineData;

                if (hasLocal && !hasOnline) {
                    this.stats.localOnly++;
                } else if (!hasLocal && hasOnline) {
                    this.stats.onlineOnly++;
                } else if (hasLocal && hasOnline) {
                    const localVal = this.getValue(this.localData[key]);
                    const onlineVal = this.getValue(this.onlineData[key]);
                    if (localVal !== onlineVal) {
                        this.stats.different++;
                    } else {
                        this.stats.same++;
                    }
                }
            }
        },

        get filteredKeys() {
            let keys = this.allKeys.filter(key => {
                const hasLocal = key in this.localData;
                const hasOnline = key in this.onlineData;

                // Category filter
                let passesCategory = false;
                if (hasLocal && !hasOnline) {
                    passesCategory = this.filters.localOnly;
                } else if (!hasLocal && hasOnline) {
                    passesCategory = this.filters.onlineOnly;
                } else if (hasLocal && hasOnline) {
                    const localVal = this.getValue(this.localData[key]);
                    const onlineVal = this.getValue(this.onlineData[key]);
                    passesCategory = (localVal !== onlineVal) ? this.filters.different : this.filters.same;
                }

                if (!passesCategory) return false;

                // Tag filter (check both local and online tags)
                const localTag = hasLocal ? this.getTag(this.localData[key]) : null;
                const onlineTag = hasOnline ? this.getTag(this.onlineData[key]) : null;
                const tagFilters = {
                    'H': this.filters.tagH,
                    'V': this.filters.tagV,
                    'A': this.filters.tagA,
                    'S': this.filters.tagS,
                    'M': this.filters.tagM
                };
                // Pass if either tag matches an enabled filter
                const localTagPass = localTag && tagFilters[localTag];
                const onlineTagPass = onlineTag && tagFilters[onlineTag];
                if (!localTagPass && !onlineTagPass) return false;

                // Search filter
                if (this.searchQuery.trim()) {
                    const query = this.searchQuery.toLowerCase().trim();
                    const localVal = hasLocal ? this.getValue(this.localData[key]).toLowerCase() : '';
                    const onlineVal = hasOnline ? this.getValue(this.onlineData[key]).toLowerCase() : '';
                    if (!key.toLowerCase().includes(query) &&
                        !localVal.includes(query) &&
                        !onlineVal.includes(query)) {
                        return false;
                    }
                }

                return true;
            });

            // Sorting
            const col = this.sortColumn;
            const dir = this.sortDirection === 'asc' ? 1 : -1;

            keys.sort((a, b) => {
                let valA, valB;

                if (col === 'key') {
                    valA = a.toLowerCase();
                    valB = b.toLowerCase();
                } else if (col === 'localTag') {
                    valA = a in this.localData ? this.getTag(this.localData[a]) : '';
                    valB = b in this.localData ? this.getTag(this.localData[b]) : '';
                } else if (col === 'localValue') {
                    valA = a in this.localData ? this.getValue(this.localData[a]).toLowerCase() : '';
                    valB = b in this.localData ? this.getValue(this.localData[b]).toLowerCase() : '';
                } else if (col === 'onlineTag') {
                    valA = a in this.onlineData ? this.getTag(this.onlineData[a]) : '';
                    valB = b in this.onlineData ? this.getTag(this.onlineData[b]) : '';
                } else if (col === 'onlineValue') {
                    valA = a in this.onlineData ? this.getValue(this.onlineData[a]).toLowerCase() : '';
                    valB = b in this.onlineData ? this.getValue(this.onlineData[b]).toLowerCase() : '';
                } else {
                    return 0;
                }

                if (valA < valB) return -1 * dir;
                if (valA > valB) return 1 * dir;
                return 0;
            });

            return keys;
        },

        get totalChanges() {
            // Count only REAL modifications to the server file
            // A tag becomes V when selected = counts as change
            // H, V, M, S tags don't change status = only count if value differs
            let count = 0;
            for (const key of this.allKeys) {
                const source = this.selections[key];
                const hasLocal = key in this.localData;
                const hasOnline = key in this.onlineData;
                const isEdited = this.editedValues[key] !== undefined;

                // Case 1: Manual edit - always a change (becomes H tag)
                if (isEdited) {
                    count++;
                    continue;
                }

                // Case 2: Local-only key selected as local = addition
                if (hasLocal && !hasOnline && source === 'local') {
                    count++;
                    continue;
                }

                // Case 3: Selection from common key - check value AND tag
                if (hasLocal && hasOnline) {
                    const selectedData = source === 'local' ? this.localData[key] : this.onlineData[key];
                    const onlineData = this.onlineData[key];

                    const selectedValue = this.getValue(selectedData);
                    const onlineValue = this.getValue(onlineData);
                    const selectedTag = this.getTag(selectedData);

                    // Value differs = change
                    if (selectedValue !== onlineValue) {
                        count++;
                        continue;
                    }

                    // Tag A will become V = change (even if same value)
                    if (selectedTag === 'A') {
                        count++;
                        continue;
                    }

                    // H, V, M, S with same value = no change
                }

                // Case 4: Online-only key selected as online
                if (!hasLocal && hasOnline && source === 'online') {
                    const onlineTag = this.getTag(this.onlineData[key]);
                    // Tag A will become V = change
                    if (onlineTag === 'A') {
                        count++;
                    }
                }
            }
            return count;
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

        isEdited(key) {
            return this.editedValues[key] !== undefined;
        },

        select(key, source) {
            this.selections[key] = source;
            // If selecting online, clear any manual edit
            if (source === 'online') {
                delete this.editedValues[key];
            }
        },

        getCellClass(key, source) {
            // Check if manually edited (only applies to local column)
            if (source === 'local' && this.editedValues[key] !== undefined) {
                return 'selected-manual';
            }

            const selected = this.selections[key] === source;
            if (selected) {
                return source === 'local' ? 'selected-local' : 'selected-online';
            }
            return '';
        },

        selectAllLocal() {
            for (const key of this.allKeys) {
                if (key in this.localData) {
                    this.selections[key] = 'local';
                }
            }
        },

        selectAllOnline() {
            for (const key of this.allKeys) {
                if (key in this.onlineData) {
                    this.selections[key] = 'online';
                    // Clear any manual edits when selecting online
                    delete this.editedValues[key];
                }
            }
        },

        editCell(key, currentValue) {
            // Use existing edit if any, otherwise use current value
            const existingValue = this.editedValues[key] ?? currentValue;
            this.editModal = {
                open: true,
                key: key,
                value: existingValue,
                originalValue: currentValue
            };

            // Focus textarea after modal opens
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
                // Save edited value
                this.editedValues[key] = value;
                // Ensure local is selected when editing
                this.selections[key] = 'local';
            } else {
                // Value unchanged, clear any existing edit
                delete this.editedValues[key];
            }

            this.closeEditModal();
        },

        closeEditModal() {
            this.editModal = {
                open: false,
                key: '',
                value: '',
                originalValue: ''
            };

            // Close modal on Escape key
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape' && this.editModal.open) {
                    this.closeEditModal();
                }
            }, { once: true });
        },

        // Tag change methods (branches can only set Skip)
        hasTagChange(key) {
            return key in this.tagChanges;
        },

        getDisplayTag(key, originalTag) {
            if (this.tagChanges[key]) {
                return this.tagChanges[key].newTag;
            }
            return originalTag;
        },

        openTagDropdown(event, key, currentTag, value) {
            event.stopPropagation();
            const rect = event.target.getBoundingClientRect();
            this.tagDropdown = {
                open: true,
                key: key,
                currentTag: this.getDisplayTag(key, currentTag),
                originalTag: currentTag,
                value: value,
                x: rect.left,
                y: rect.bottom + window.scrollY
            };
        },

        closeTagDropdown() {
            this.tagDropdown = {
                open: false,
                key: '',
                currentTag: '',
                originalTag: '',
                value: '',
                x: 0,
                y: 0
            };
        },

        setTagSkip() {
            const { key, originalTag, value } = this.tagDropdown;
            const newTag = 'S';

            if (newTag === originalTag) {
                delete this.tagChanges[key];
            } else {
                this.tagChanges[key] = {
                    newTag: newTag,
                    originalTag: originalTag,
                    value: value
                };
            }
            this.closeTagDropdown();
        },

        cancelTagChange(key) {
            delete this.tagChanges[key];
        },

        get tagChangeCount() {
            return Object.keys(this.tagChanges).length;
        },

        clearAll() {
            if (confirm('{{ __("merge_preview.confirm_cancel") }}')) {
                this.selections = {};
                this.editedValues = {};
                this.tagChanges = {};

                // Reset to smart defaults (same logic as loadContent)
                const tagPriority = { 'H': 3, 'V': 2, 'A': 1, 'M': 0, 'S': 0 };

                for (const key of this.allKeys) {
                    const hasLocal = key in this.localData;
                    const hasOnline = key in this.onlineData;

                    if (hasLocal && !hasOnline) {
                        this.selections[key] = 'local';
                    } else if (!hasLocal && hasOnline) {
                        this.selections[key] = 'online';
                    } else if (hasLocal && hasOnline) {
                        const localVal = this.getValue(this.localData[key]);
                        const onlineVal = this.getValue(this.onlineData[key]);

                        if (localVal !== onlineVal) {
                            const localTag = this.getTag(this.localData[key]);
                            const onlineTag = this.getTag(this.onlineData[key]);
                            const localPriority = tagPriority[localTag] || 0;
                            const onlinePriority = tagPriority[onlineTag] || 0;

                            this.selections[key] = localPriority > onlinePriority ? 'local' : 'online';
                        } else {
                            this.selections[key] = 'online';
                        }
                    }
                }
            }
        },

        downloadMerged() {
            const merged = this.buildMergedContent();

            const blob = new Blob([JSON.stringify(merged, null, 2)], { type: 'application/json' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'translations-merged.json';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        },

        buildMergedContent() {
            const merged = {};

            // Copy metadata from online (server version)
            for (const [key, value] of Object.entries(@json($onlineContent))) {
                if (key.startsWith('_')) {
                    merged[key] = value;
                }
            }

            // Build merged translations
            for (const key of this.allKeys) {
                const source = this.selections[key];
                const isEdited = this.editedValues[key] !== undefined;
                const hasTagChange = key in this.tagChanges;

                if (isEdited) {
                    // Manual edit -> becomes H tag (unless tag was explicitly changed)
                    let tag = hasTagChange ? this.tagChanges[key].newTag : this.getTag(this.localData[key]);
                    // M and S are preserved, otherwise manual = H (if no explicit tag change)
                    if (!hasTagChange && tag !== 'M' && tag !== 'S') {
                        tag = 'H';
                    }
                    merged[key] = { v: this.editedValues[key], t: tag };
                } else if (source === 'local' && key in this.localData) {
                    // Check if tag was explicitly changed
                    let tag = hasTagChange ? this.tagChanges[key].newTag : this.getTag(this.localData[key]);
                    // Apply same tag rules as server: A → V when selected by human (unless tag explicitly changed)
                    if (!hasTagChange && tag !== 'M' && tag !== 'S' && tag === 'A') {
                        tag = 'V';
                    }
                    merged[key] = { v: this.getValue(this.localData[key]), t: tag };
                } else if (source === 'online' && key in this.onlineData) {
                    // Apply same tag rules as server: A → V when selected by human
                    let tag = this.getTag(this.onlineData[key]);
                    if (tag !== 'M' && tag !== 'S' && tag === 'A') {
                        tag = 'V';
                    }
                    merged[key] = { v: this.getValue(this.onlineData[key]), t: tag };
                }
            }

            return merged;
        },

        saveToServer() {
            this.saving = true;

            // Build selections array for the form
            const container = document.getElementById('selectionsContainer');
            while (container.firstChild) {
                container.removeChild(container.firstChild);
            }

            let i = 0;
            for (const key of this.allKeys) {
                const source = this.selections[key];
                const isEdited = this.editedValues[key] !== undefined;
                const hasTagChange = key in this.tagChanges;

                let value, tag, sourceType;

                if (isEdited) {
                    value = this.editedValues[key];
                    // Apply explicit tag change, or use original tag
                    tag = hasTagChange ? this.tagChanges[key].newTag : this.getTag(this.localData[key]);
                    sourceType = 'manual';
                } else if (source === 'local' && key in this.localData) {
                    value = this.getValue(this.localData[key]);
                    // Apply explicit tag change, or use original tag
                    tag = hasTagChange ? this.tagChanges[key].newTag : this.getTag(this.localData[key]);
                    sourceType = 'local';
                } else if (source === 'online' && key in this.onlineData) {
                    value = this.getValue(this.onlineData[key]);
                    tag = this.getTag(this.onlineData[key]);
                    sourceType = 'online';
                } else {
                    continue;
                }

                // Create hidden inputs
                const inputs = [
                    { name: `selections[${i}][key]`, value: key },
                    { name: `selections[${i}][value]`, value: value },
                    { name: `selections[${i}][tag]`, value: tag },
                    { name: `selections[${i}][source]`, value: sourceType }
                ];

                for (const input of inputs) {
                    const el = document.createElement('input');
                    el.type = 'hidden';
                    el.name = input.name;
                    el.value = input.value;
                    container.appendChild(el);
                }

                i++;
            }

            // Submit the form
            document.getElementById('saveForm').submit();
        }
    };
}
</script>
@endsection
