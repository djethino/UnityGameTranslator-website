@extends('layouts.app')

@section('title', __('merge_preview.title') . ' - ' . $translation->game->name)

@section('content')
<div class="container mx-auto px-4 py-8" x-data="mergePreview" @keydown.window="handleEditorKeydown($event)">
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
                <p class="text-2xl font-bold text-purple-400" x-text="editedCount"></p>
                <p class="text-sm text-gray-400">{{ __('merge_preview.edited') }}</p>
            </div>
        </div>

        @include('partials.editor-quality-bar')

        {{-- Filters --}}
        <div class="mb-4 flex flex-wrap gap-4 items-center text-sm bg-gray-800 p-4 rounded-lg border border-gray-700">
            <span class="text-gray-500">{{ __('merge_preview.show') }}:</span>

            <label class="flex items-center gap-2 cursor-pointer">
                <input type="checkbox" :checked="filters.localOnly" @change="toggleFilter('localOnly')"
                    class="rounded bg-gray-700 border-gray-600 text-green-600">
                <span class="text-green-400">{{ __('merge_preview.local_only') }}</span>
            </label>

            <label class="flex items-center gap-2 cursor-pointer">
                <input type="checkbox" :checked="filters.onlineOnly" @change="toggleFilter('onlineOnly')"
                    class="rounded bg-gray-700 border-gray-600 text-blue-600">
                <span class="text-blue-400">{{ __('merge_preview.online_only') }}</span>
            </label>

            <label class="flex items-center gap-2 cursor-pointer">
                <input type="checkbox" :checked="filters.different" @change="toggleFilter('different')"
                    class="rounded bg-gray-700 border-gray-600 text-yellow-600">
                <span class="text-yellow-400">{{ __('merge_preview.different') }}</span>
            </label>

            <label class="flex items-center gap-2 cursor-pointer">
                <input type="checkbox" :checked="filters.same" @change="toggleFilter('same')"
                    class="rounded bg-gray-700 border-gray-600 text-gray-600">
                <span class="text-gray-400">{{ __('merge_preview.same') }}</span>
            </label>

            <span class="text-gray-600">|</span>

            {{-- Tag filters in HVASM order --}}
            <label class="flex items-center gap-2 cursor-pointer" title="{{ __('merge.legend_human') }}">
                <input type="checkbox" :checked="filters.tagH" @change="toggleFilter('tagH')"
                    class="rounded bg-gray-700 border-gray-600 text-green-600">
                <span class="tag-H">H</span>
            </label>

            <label class="flex items-center gap-2 cursor-pointer" title="{{ __('merge.legend_validated') }}">
                <input type="checkbox" :checked="filters.tagV" @change="toggleFilter('tagV')"
                    class="rounded bg-gray-700 border-gray-600 text-blue-600">
                <span class="tag-V">V</span>
            </label>

            <label class="flex items-center gap-2 cursor-pointer" title="{{ __('merge.legend_ai') }}">
                <input type="checkbox" :checked="filters.tagA" @change="toggleFilter('tagA')"
                    class="rounded bg-gray-700 border-gray-600 text-orange-600">
                <span class="tag-A">A</span>
            </label>

            <label class="flex items-center gap-2 cursor-pointer" title="{{ __('merge.legend_skipped') }}">
                <input type="checkbox" :checked="filters.tagS" @change="toggleFilter('tagS')"
                    class="rounded bg-gray-700 border-gray-600 text-gray-600">
                <span class="tag-S">S</span>
            </label>

            <label class="flex items-center gap-2 cursor-pointer" title="{{ __('merge.legend_mod_ui') }}">
                <input type="checkbox" :checked="filters.tagM" @change="toggleFilter('tagM')"
                    class="rounded bg-gray-700 border-gray-600 text-purple-600">
                <span class="tag-M">M</span>
            </label>

            <span class="text-gray-600">|</span>

            <label class="flex items-center gap-2 cursor-pointer">
                <input type="checkbox" :checked="filters.modifiedOnly" @change="toggleFilter('modifiedOnly')"
                    class="rounded bg-gray-700 border-gray-600 text-purple-600">
                <span class="text-purple-400">{{ __('merge_preview.modifications') }}</span>
            </label>

            <span class="text-gray-600">|</span>

            <button type="button" @click="selectAllLocal()" class="text-green-400 hover:text-green-300">
                <i class="fas fa-check-double mr-1"></i> {{ __('merge_preview.select_all_local') }}
            </button>

            <button type="button" @click="selectAllOnline()" class="text-blue-400 hover:text-blue-300">
                <i class="fas fa-check-double mr-1"></i> {{ __('merge_preview.select_all_online') }}
            </button>
        </div>

        @include('partials.editor-floating-search')

        {{-- Search (Enter/Shift+Enter navigate matches) + replace --}}
        <div class="mb-4 space-y-2" x-ref="searchBar">
            <div class="flex gap-2">
                <div class="relative flex-1">
                    <input type="text" x-model="searchQuery" @keydown.enter.prevent="onSearchEnter($event)"
                        placeholder="{{ __('merge_preview.search_placeholder') }}"
                        class="w-full px-4 py-2 pl-10 pr-32 bg-gray-800 border border-gray-700 rounded-lg text-white placeholder-gray-500 focus:border-purple-500 focus:ring-1 focus:ring-purple-500">
                    <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-500"></i>
                    <div class="absolute right-3 top-1/2 -translate-y-1/2 flex items-center gap-2">
                        <span x-show="hasQuery" x-cloak class="text-xs text-gray-500 tabular-nums" x-text="matchCounterText"></span>
                        <button x-show="hasQuery" x-cloak @click="prevMatch()" type="button"
                            class="text-gray-500 hover:text-white transition" title="{{ __('merge.search_prev') }}">
                            <i class="fas fa-chevron-up"></i>
                        </button>
                        <button x-show="hasQuery" x-cloak @click="nextMatch()" type="button"
                            class="text-gray-500 hover:text-white transition" title="{{ __('merge.search_next') }}">
                            <i class="fas fa-chevron-down"></i>
                        </button>
                        <button x-show="searchQuery" x-cloak @click="searchQuery = ''" type="button"
                            class="text-gray-500 hover:text-white transition">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
                <select x-model="searchScope"
                    class="bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-gray-300 focus:border-purple-500 focus:ring-1 focus:ring-purple-500"
                    title="{{ __('merge_preview.search_scope_title') }}">
                    <option value="both">{{ __('merge_preview.search_scope_both') }}</option>
                    <option value="keys">{{ __('merge_preview.search_scope_keys') }}</option>
                    <option value="values">{{ __('merge_preview.search_scope_values') }}</option>
                </select>
                <button type="button" @click="toggleReplace()"
                    :class="replaceOpen ? 'bg-purple-700 text-white border-purple-500' : 'bg-gray-800 text-gray-300 border-gray-700 hover:text-white'"
                    class="border rounded-lg px-3 py-2 text-sm transition" title="{{ __('merge.replace') }}">
                    <i class="fas fa-right-left"></i>
                </button>
            </div>
            {{-- Replace: single-row only, staged as a human edit (→ H), no replace-all --}}
            <div x-show="replaceOpen" x-cloak class="flex gap-2">
                <div class="relative flex-1">
                    <input type="text" x-model="replaceValue" @keydown.enter.prevent="replaceCurrent()"
                        placeholder="{{ __('merge.replace_with') }}"
                        class="w-full px-4 py-2 pl-10 bg-gray-800 border border-gray-700 rounded-lg text-white placeholder-gray-500 focus:border-purple-500 focus:ring-1 focus:ring-purple-500">
                    <i class="fas fa-right-left absolute left-3 top-1/2 -translate-y-1/2 text-gray-500"></i>
                </div>
                <button type="button" @click="replaceCurrent()" :disabled="replaceDisabled"
                    class="bg-purple-600 hover:bg-purple-700 disabled:bg-gray-700 disabled:text-gray-500 disabled:cursor-not-allowed px-4 py-2 rounded-lg text-white text-sm transition">
                    {{ __('merge.replace') }}
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
                    {{-- Windowed rendering: huge files stay snappy --}}
                    {{-- Keyed by translation key, NOT by position: index-keyed row
                         recycling was measured barely faster and desynced recycled
                         scopes (wrong values shown on wrong keys) — unacceptable in
                         an editor. The window size is the safe lever instead. --}}
                    <template x-for="(key, idx) in visibleKeys" :key="key">
                        <tr class="border-t border-gray-700 hover:bg-gray-750 transition-colors"
                            :class="isCurrentMatchRow(idx) ? 'current-match-row' : ''"
                            :data-row-index="idx">
                            {{-- Key column --}}
                            <td class="px-4 py-2 font-mono text-xs text-gray-500 break-words" x-safe-html="highlightKey(key)"></td>

                            {{-- Local Tag column (clickable for tag change) --}}
                            <td class="px-2 py-2 text-center border-l border-gray-700"
                                :class="hasTagChange(key) ? 'tag-changed-cell' : ''">
                                <template x-if="localData[key] !== undefined">
                                    {{-- Shows the tag the save will PRODUCE (edit → H,
                                         sent local selection → A promoted to V), not just the stored one --}}
                                    <button type="button"
                                        @click.stop="openTagDropdown($event, key, displayLocalTag(key), getValue(localData[key]))"
                                        class="transition rounded cursor-pointer hover:ring-2 hover:ring-purple-400 hover:ring-offset-1 hover:ring-offset-gray-800"
                                        title="{{ __('merge.click_to_change_tag') }}">
                                        <span :class="'tag-' + displayLocalTag(key) + (isCaptureRow(key) ? ' opacity-40' : '')" x-text="displayLocalTag(key)"></span>
                                    </button>
                                </template>
                                <template x-if="localData[key] === undefined">
                                    <span class="text-gray-600">—</span>
                                </template>
                            </td>

                            {{-- Local Value column --}}
                            <td class="px-4 py-2 border-l border-gray-700 merge-cell"
                                :class="[getCellClass(key, 'local'), isDeleted(key) ? 'deleted-cell' : '']"
                                @click="select(key, 'local')"
                                @dblclick="editCell(key, getValue(localData[key]))">
                                <span class="edit-affordance">
                                    <button type="button" x-show="rowHasPending(key)" @click.stop="revertRow(key)"
                                        title="{{ __('merge.revert_row') }}"><i class="fas fa-undo"></i></button>
                                    <button type="button" @click.stop="editCell(key, getValue(localData[key]))"
                                        title="{{ __('translation.edit') }}"><i class="fas fa-pen"></i></button>
                                    <button type="button" class="delete-btn" @click.stop="toggleDelete(key)"
                                        title="{{ __('translation.delete') }}"><i class="fas fa-trash"></i></button>
                                </span>
                                <template x-if="localData[key] !== undefined">
                                    <span class="break-words"
                                        :class="[isEdited(key) ? 'text-purple-300' : '', isDeleted(key) ? 'line-through opacity-40' : '']">
                                        {{-- Non-blocking guard: the pending edit altered [!v*N] placeholders --}}
                                        <span x-show="hasPlaceholderWarning(key)" x-cloak
                                            class="inline-block mb-1 px-1.5 py-0.5 rounded bg-orange-900/60 text-orange-300 text-xs"
                                            title="{{ __('merge.placeholder_warning') }}">
                                            <i class="fas fa-exclamation-triangle mr-1"></i>Placeholders
                                        </span>
                                        <span x-show="isEdited(key)" x-safe-html="highlightValue(editedValues[key])"></span>
                                        <span x-show="!isEdited(key)" x-safe-html="highlightValue(getValue(localData[key]))"></span>
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
                                    <span class="break-words" x-safe-html="highlightValue(getValue(onlineData[key]))"></span>
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

                    <tr x-show="hiddenCount > 0">
                        <td colspan="5" class="px-4 py-3 text-center">
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

        {{-- Footer with Save button. min-w-0 on the text + shrink-0 on the
             buttons: the instructions wrap instead of squeezing the save button.
             ↑↓ shortcuts float at both ends of the bar --}}
        <div class="flex flex-wrap gap-4 justify-between items-center bg-gray-800 p-4 rounded-lg border border-gray-700 sticky bottom-4">
            <div class="flex flex-col gap-1 shrink-0">
                <button type="button" @click="scrollToTop()"
                    class="text-gray-500 hover:text-white transition" title="{{ __('merge.scroll_top') }}">
                    <i class="fas fa-angles-up"></i>
                </button>
                <button type="button" @click="scrollToBottom()"
                    class="text-gray-500 hover:text-white transition" title="{{ __('merge.scroll_bottom') }}">
                    <i class="fas fa-angles-down"></i>
                </button>
            </div>
            <div class="text-sm text-gray-400 min-w-0 grow">
                <span x-show="totalChanges > 0">
                    <span class="text-white font-bold" x-text="totalChanges"></span> {{ __('merge_preview.modifications') }}
                    <span x-show="editedCount > 0" class="ml-2 text-purple-400">
                        (<span x-text="editedCount"></span> {{ __('merge_preview.edited_manually') }})
                    </span>
                </span>
                {{-- One line per gesture, with the same icons as the table --}}
                <div x-show="totalChanges === 0" class="text-gray-500 space-y-1">
                    <p>
                        <i class="fas fa-arrow-pointer w-4 text-center mr-1"></i>{{ __('merge.instructions_select') }}
                        <span class="tag-A">A</span> <i class="fas fa-arrow-right text-xs"></i> <span class="tag-V">V</span>
                    </p>
                    <p><i class="fas fa-pen w-4 text-center mr-1"></i>{{ __('merge.instructions_edit') }}</p>
                    <p><i class="fas fa-trash w-4 text-center mr-1"></i>{{ __('merge.instructions_delete') }}</p>
                    <p><i class="fas fa-keyboard w-4 text-center mr-1"></i>{{ __('merge.instructions_keyboard') }}</p>
                </div>
            </div>

            <div class="flex gap-4 items-center shrink-0">
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
                <div class="flex flex-col gap-1 shrink-0">
                    <button type="button" @click="scrollToTop()"
                        class="text-gray-500 hover:text-white transition" title="{{ __('merge.scroll_top') }}">
                        <i class="fas fa-angles-up"></i>
                    </button>
                    <button type="button" @click="scrollToBottom()"
                        class="text-gray-500 hover:text-white transition" title="{{ __('merge.scroll_bottom') }}">
                        <i class="fas fa-angles-down"></i>
                    </button>
                </div>
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
                {{-- x-model must target a TOP-LEVEL property: the Alpine CSP
                     build prohibits property assignments (editModal.value = x) --}}
                <textarea
                    id="editModalTextarea"
                    x-model="editModalValue"
                    class="w-full h-48 px-4 py-3 bg-gray-900 border border-gray-600 rounded-lg text-white placeholder-gray-500 focus:border-purple-500 focus:ring-1 focus:ring-purple-500 resize-y"
                    placeholder="{{ __('merge_preview.enter_translation') }}"
                ></textarea>
                <p x-show="editModalPlaceholderMismatch" x-cloak class="mt-2 text-xs text-orange-400">
                    <i class="fas fa-exclamation-triangle mr-1"></i>{{ __('merge.placeholder_warning') }}
                </p>
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

        {{-- Skip option --}}
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

        {{-- Cancel change (if tag was changed) --}}
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
        <span><span class="tag-H">H</span> {{ __('merge.legend_human') }}</span>
        <span><span class="tag-V">V</span> {{ __('merge.legend_validated') }}</span>
        <span><span class="tag-A">A</span> {{ __('merge.legend_ai') }}</span>
        <span><span class="tag-S">S</span> {{ __('merge.legend_skipped') }}</span>
        <span><span class="tag-M">M</span> {{ __('merge.legend_mod_ui') }}</span>
        <span class="text-gray-600">|</span>
        <span><span class="inline-block w-3 h-3 bg-green-900/50 rounded mr-1"></span> {{ __('merge_preview.selection_local') }}</span>
        <span><span class="inline-block w-3 h-3 bg-blue-900/50 rounded mr-1"></span> {{ __('merge_preview.selection_online') }}</span>
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
// Only the merge-preview specifics live here.
document.addEventListener('alpine:init', () => {
    // window.UGT is set by app.js (deferred module): it exists by the time
    // Alpine fires alpine:init, but NOT during the initial HTML parse
    const normalizeLineEndings = window.UGT.normalizeLineEndings;
    Alpine.data('mergePreview', () => window.UGT.composeEditor({
        // UI state (filters/search) is shared across merge previews;
        // PENDING work is scoped to THIS translation — restored edits
        // from another file would be ghost modifications
        persistKey: 'merge_preview_ui',
        pendingKey: 'merge_preview_{{ $translation->id }}_pending',
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
            tagM: true,
            modifiedOnly: false
        }
    }, {
        loaded: false,
        error: null,
        saving: false,
        localData: {},
        onlineData: {},
        onlineMetadata: {},
        allKeys: [],
        selections: {},
        stats: {
            total: 0,
            localOnly: 0,
            onlineOnly: 0,
            different: 0,
            same: 0
        },

        init() {
            this.initEditorCore();

            // Server-side token session error (expired / content file gone)
            const tokenError = @json($tokenError);
            if (tokenError) {
                this.error = tokenError;
                this.loaded = true;
                return;
            }

            // Mod flow: the local content waits server-side, keyed by the
            // session's merge token. Web flow: it was stored in
            // sessionStorage by the upload page.
            const hasTokenContent = @json($hasTokenContent);

            let webLocalContent = null;
            if (!hasTokenContent) {
                const raw = sessionStorage.getItem('merge_local_content');
                const translationId = sessionStorage.getItem('merge_translation_id');

                if (!raw || translationId !== '{{ $translation->id }}') {
                    this.error = @js(__('merge_preview.error_no_local_file'));
                    this.loaded = true;
                    return;
                }

                try {
                    webLocalContent = JSON.parse(raw);
                } catch (e) {
                    this.error = @js(__('merge_preview.error_invalid_json'));
                    this.loaded = true;
                    return;
                }
            }

            // Local and online contents are streamed from the server, never
            // inlined in the page: translation files can be tens of MB.
            fetch('{{ route("translations.merge-preview.data", $translation) }}', {
                headers: { 'Accept': 'application/json' }
            })
                .then(response => {
                    if (!response.ok) {
                        throw new Error(response.status === 410 ? 'expired' : 'load_failed');
                    }
                    return response.json();
                })
                .then(data => {
                    const localContent = hasTokenContent ? data.local : webLocalContent;
                    if (!localContent) {
                        this.error = @js(__('merge_preview.error_no_local_file'));
                        this.loaded = true;
                        return;
                    }

                    this.loadContent(localContent, data.online);

                    if (!hasTokenContent) {
                        // Clear sessionStorage after successful load
                        sessionStorage.removeItem('merge_local_content');
                        sessionStorage.removeItem('merge_translation_id');
                        sessionStorage.removeItem('merge_main_translation_id');
                        sessionStorage.removeItem('merge_is_main_owner');
                    }
                })
                .catch(e => {
                    this.error = e.message === 'expired'
                        ? @js(__('merge_preview.error_session_expired'))
                        : @js(__('merge_preview.error_load_failed'));
                    this.loaded = true;
                });
        },

        loadContent(content, onlineContent) {
            // Keep online metadata keys (_uuid, _game, ...) for buildMergedContent
            this.onlineMetadata = {};
            const rawOnline = {};
            for (const [key, value] of Object.entries(onlineContent)) {
                if (key.startsWith('_')) {
                    this.onlineMetadata[key] = value;
                } else {
                    rawOnline[key] = value;
                }
            }
            this.onlineData = rawOnline;

            // Filter out metadata keys from local and normalize line endings
            this.localData = {};
            for (const [key, value] of Object.entries(content)) {
                if (!key.startsWith('_')) {
                    const normalizedKey = normalizeLineEndings(key);
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
                    const normalizedKey = normalizeLineEndings(key);
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

            this.calculateStats();
            this.applySmartDefaults();
            this.loaded = true;
        },

        /**
         * Auto-select based on smart defaulting:
         * - Local-only: select LOCAL (additions to server)
         * - Online-only: select ONLINE (already on server)
         * - Different: smart default based on tag quality (H > V > A, online wins ties)
         * - Same: select ONLINE (no change needed)
         * Keys already selected are skipped: restored pending choices
         * (F5 mid-review) must not be overwritten by the defaults.
         */
        applySmartDefaults() {
            const tagPriority = { 'H': 3, 'V': 2, 'A': 1, 'M': 0, 'S': 0 };

            for (const key of this.allKeys) {
                if (key in this.selections) continue;
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

        // ── Shared-core callbacks ────────────────────────────────────────

        rowPassesFilters(key) {
            if (this.filters.modifiedOnly && !this.isRowModified(key)) {
                return false;
            }

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

            // Tag filter: local matches on its STORED and its PREVIEWED tag
            // (a pending change must not make its row vanish mid-work)
            const localTagPass = hasLocal
                && (this.tagVisible(this.getTag(this.localData[key])) || this.tagVisible(this.displayLocalTag(key)));
            const onlineTagPass = hasOnline && this.tagVisible(this.getTag(this.onlineData[key]));
            return !!(localTagPass || onlineTagPass);
        },

        rowMatchesSearch(key, query) {
            if (this.searchScope !== 'values' && key.toLowerCase().includes(query)) {
                return true;
            }
            if (this.searchScope !== 'keys') {
                const hasLocal = key in this.localData;
                const hasOnline = key in this.onlineData;
                if (hasLocal && this.getValue(this.localData[key]).toLowerCase().includes(query)) return true;
                if (hasOnline && this.getValue(this.onlineData[key]).toLowerCase().includes(query)) return true;
                // A pending edit matches on its NEW value too, and editing a
                // row must not make it vanish from the current search
                if (this.editedValues[key] !== undefined
                    && this.editedValues[key].toLowerCase().includes(query)) return true;
            }
            return false;
        },

        rowSortValue(key, column) {
            // Sort on STORED values: a pending edit must not make the row
            // jump around while the user is still working
            if (column === 'localTag') {
                return key in this.localData ? this.getTag(this.localData[key]) : '';
            }
            if (column === 'localValue') {
                return key in this.localData ? this.getValue(this.localData[key]).toLowerCase() : '';
            }
            if (column === 'onlineTag') {
                return key in this.onlineData ? this.getTag(this.onlineData[key]) : '';
            }
            if (column === 'onlineValue') {
                return key in this.onlineData ? this.getValue(this.onlineData[key]).toLowerCase() : '';
            }
            return '';
        },

        /** Core hook: the stored editable value (replace, placeholder guard). */
        storedValue(key) {
            return this.getValue(this.localData[key]);
        },

        /** Core hook: projected LOCAL tag for the quality bar. */
        rowQualityTag(key) {
            if (key in this.localData || this.isEdited(key)) {
                return this.displayLocalTag(key);
            }
            return null;
        },

        /** Core hook: a staged manual edit selects the local side. */
        onEditStaged(key) {
            this.selections[key] = 'local';
        },

        /** Core hook: a deletion cancels any side selection for the key. */
        onDeleteToggled(key) {
            delete this.selections[key];
        },

        /** Core hook: a per-row revert puts the selection back to its
         *  smart default (applySmartDefaults skips already-selected keys,
         *  so only this row is recomputed). */
        onRowReverted(key) {
            delete this.selections[key];
            this.applySmartDefaults();
        },

        /** Merge selections survive refreshes with the rest of the pending state. */
        pendingExtraState() {
            return { selections: this.selections };
        },

        restorePendingExtra(extra) {
            if (extra && extra.selections && typeof extra.selections === 'object') {
                this.selections = extra.selections;
            }
        },

        // ── Merge selection logic ────────────────────────────────────────

        /**
         * A row counts as modified when the user did something meaningful:
         * manual edit, explicit tag change, a local-only addition kept, or
         * a differing key where local was selected.
         */
        isRowModified(key) {
            const source = this.selections[key];
            const hasLocal = key in this.localData;
            const hasOnline = key in this.onlineData;

            if (this.isDeleted(key)) return true;
            if (this.editedValues[key] !== undefined) return true;
            if (key in this.tagChanges) return true;
            if (hasLocal && !hasOnline && source === 'local') return true;
            if (hasLocal && hasOnline && source === 'local') {
                return this.getValue(this.localData[key]) !== this.getValue(this.onlineData[key]);
            }
            return false;
        },

        get totalChanges() {
            // Count only user-meaningful modifications to the server file.
            // Note: automatic A→V promotion is NOT counted (implicit).
            let count = 0;
            for (const key of this.allKeys) {
                if (this.isRowModified(key)) count++;
            }
            return count;
        },

        get editedCount() {
            return Object.keys(this.editedValues).length;
        },

        get tagChangeCount() {
            return Object.keys(this.tagChanges).length;
        },

        select(key, source) {
            // Even on inert rows the click moves the search cursor (IDE caret)
            this.setMatchCursor(key);
            // A deleted key must be un-deleted before picking a side again
            if (this.isDeleted(key)) return;
            this.selections[key] = source;
            // If selecting online, clear any manual edit
            if (source === 'online') {
                delete this.editedValues[key];
            }
            this.persistPendingState();
        },

        /**
         * Tag the save will PRODUCE for the local side — previewed live,
         * before anything is saved. Core displayTag covers tag change → that
         * tag and manual edit → H (M/S preserved); on top of it, a local
         * selection that will actually be SENT gets the server's A → V
         * promotion (picks identical to online are not sent → no preview).
         */
        displayLocalTag(key) {
            if (this.hasTagChange(key) || this.isEdited(key)) {
                return this.displayTag(key, this.getTag(this.localData[key]));
            }
            if (this.selections[key] === 'local' && key in this.localData) {
                const hasOnline = key in this.onlineData;
                if (!hasOnline || this.getValue(this.localData[key]) !== this.getValue(this.onlineData[key])) {
                    const tag = this.getTag(this.localData[key]);
                    return tag === 'A' ? 'V' : tag;
                }
            }
            return this.getTag(this.localData[key]);
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
                if (key in this.localData && !this.isDeleted(key)) {
                    this.selections[key] = 'local';
                }
            }
            this.persistPendingState();
        },

        selectAllOnline() {
            for (const key of this.allKeys) {
                if (key in this.onlineData && !this.isDeleted(key)) {
                    this.selections[key] = 'online';
                    // Clear any manual edits when selecting online
                    delete this.editedValues[key];
                }
            }
            this.persistPendingState();
        },

        clearAll() {
            if (confirm(@js(__('merge_preview.confirm_cancel')))) {
                this.selections = {};
                this.clearPendingState();
                this.applySmartDefaults();
            }
        },

        // ── Export / save ────────────────────────────────────────────────

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

            // Copy metadata from online (server version), kept aside by loadContent
            for (const [key, value] of Object.entries(this.onlineMetadata)) {
                merged[key] = value;
            }

            // Build merged translations
            for (const key of this.allKeys) {
                const source = this.selections[key];
                const isEdited = this.editedValues[key] !== undefined;
                const hasTagChange = key in this.tagChanges;

                if (hasTagChange) {
                    // Explicit tag change wins as-is — same rule as saveToServer
                    merged[key] = {
                        v: isEdited ? this.editedValues[key] : this.tagChanges[key].value,
                        t: this.tagChanges[key].newTag
                    };
                } else if (isEdited) {
                    // Manual edit -> becomes H tag (M and S are preserved)
                    let tag = this.getTag(this.localData[key]);
                    if (tag !== 'M' && tag !== 'S') {
                        tag = 'H';
                    }
                    merged[key] = { v: this.editedValues[key], t: tag };
                } else if (source === 'local' && key in this.localData) {
                    // Apply same tag rules as server: A → V when selected by human
                    let tag = this.getTag(this.localData[key]);
                    if (tag === 'A') {
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

            // Build selections array for the form - only include REAL changes
            const container = document.getElementById('selectionsContainer');
            while (container.firstChild) {
                container.removeChild(container.firstChild);
            }

            let i = 0;
            for (const key of this.allKeys) {
                const source = this.selections[key];
                const isEdited = this.editedValues[key] !== undefined;
                const hasTagChange = key in this.tagChanges;
                const hasLocal = key in this.localData;
                const hasOnline = key in this.onlineData;

                let value, tag, sourceType;
                let isRealChange = false;

                if (hasTagChange) {
                    // Explicit tag change: the server writes it AS-IS (no
                    // H forcing, no A→V promotion) — the user's chosen tag
                    // must win, combined with an edit or not
                    value = isEdited ? this.editedValues[key] : this.tagChanges[key].value;
                    tag = this.tagChanges[key].newTag;
                    sourceType = 'tagchange';
                    isRealChange = true;
                } else if (isEdited) {
                    // Manual edit = always a change (server: → H unless M/S)
                    value = this.editedValues[key];
                    tag = this.getTag(this.localData[key]);
                    sourceType = 'manual';
                    isRealChange = true;
                } else if (hasLocal && !hasOnline && source === 'local') {
                    // Local-only key = addition
                    value = this.getValue(this.localData[key]);
                    tag = this.getTag(this.localData[key]);
                    sourceType = 'local';
                    isRealChange = true;
                } else if (hasLocal && hasOnline && source === 'local') {
                    // Both exist, local selected - only send if value differs
                    const localValue = this.getValue(this.localData[key]);
                    const onlineValue = this.getValue(this.onlineData[key]);
                    if (localValue !== onlineValue) {
                        value = localValue;
                        tag = this.getTag(this.localData[key]);
                        sourceType = 'local';
                        isRealChange = true;
                    }
                }
                // Online-only or same value with online selected = no change to send

                if (!isRealChange) continue;

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

            // Deletions: keys to remove from the server file
            let d = 0;
            for (const key of Object.keys(this.deletions)) {
                const el = document.createElement('input');
                el.type = 'hidden';
                el.name = `deletions[${d}]`;
                el.value = key;
                container.appendChild(el);
                d++;
            }

            if (i === 0 && d === 0) {
                this.saving = false;
                return;
            }

            // Pending work is about to be applied server-side
            this.clearPendingState();

            // Submit the form
            document.getElementById('saveForm').submit();
        }
    }));
});
</script>
@endsection
