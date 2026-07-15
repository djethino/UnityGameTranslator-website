@extends('layouts.app')

@section('title', ($mode === 'edit' ? __('merge.edit_heading') : __('merge.title')) . ' - ' . $main->game->name)

@section('content')
@php
    // Navigation state is now limited to what the SERVER needs to rebuild the
    // page: mode and selected branches. Filters/search/sort/windowing are
    // client-side (shared translation-editor core) and persist on their own.
    $stateParams = array_merge(
        ['mode' => $mode],
        $selectedBranches->isNotEmpty() ? ['branches' => $selectedBranches->pluck('id')->all()] : []
    );
    $dataUrl = route('translations.merge.data', ['uuid' => $uuid]) . '?' . http_build_query($stateParams);
@endphp
<div class="container mx-auto px-4 py-8" x-data="mergeTable">
    {{-- Header --}}
    <div class="mb-6">
        <div class="flex items-center gap-4 mb-2">
            <a href="{{ route('translations.mine') }}" class="text-purple-400 hover:text-purple-300">
                <i class="fas fa-arrow-left"></i> {{ $main->game->name }}
            </a>
            @if($hasBranches)
            {{-- Mode switcher --}}
            <div class="ml-auto flex gap-2 text-sm">
                <a href="{{ route('translations.merge', array_merge(['uuid' => $uuid], $stateParams, ['mode' => 'edit'])) }}"
                   class="px-3 py-1 rounded {{ $mode === 'edit' ? 'bg-purple-600 text-white' : 'bg-gray-700 text-gray-400 hover:text-white' }}">
                    <i class="fas fa-pen mr-1"></i> {{ __('merge.mode_edit') }}
                </a>
                <a href="{{ route('translations.merge', array_merge(['uuid' => $uuid], $stateParams, ['mode' => 'merge'])) }}"
                   class="px-3 py-1 rounded {{ $mode === 'merge' ? 'bg-green-600 text-white' : 'bg-gray-700 text-gray-400 hover:text-white' }}">
                    <i class="fas fa-code-merge mr-1"></i> {{ __('merge.mode_merge') }}
                </a>
            </div>
            @endif
        </div>
        <h1 class="text-2xl font-bold text-white">
            @if($mode === 'edit')
                <i class="fas fa-pen mr-2 text-purple-400"></i>{{ __('merge.edit_heading') }}
            @else
                <i class="fas fa-code-merge mr-2 text-green-400"></i>{{ __('merge.heading') }}
            @endif
        </h1>
        <p class="text-gray-400">
            {{ $main->source_language }} <i class="fas fa-arrow-right text-xs"></i> {{ $main->target_language }}
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

    {{-- Branch Selection (merge mode only) --}}
    @if($mode === 'merge')
        @if($branches->isNotEmpty())
        <div class="mb-6 bg-gray-800 rounded-lg p-4 border border-gray-700">
            <form method="GET" id="branchForm" class="flex flex-wrap gap-3 items-center">
                <input type="hidden" name="mode" value="{{ $mode }}">
                <span class="text-sm text-gray-400 font-medium">{{ __('merge.branches') }}</span>

                {{-- Quick filters --}}
                @php
                    $unreviewedIds = $branches->filter(fn($b) => !$b->reviewed_hash || $b->file_hash !== $b->reviewed_hash)->pluck('id');
                @endphp
                <div class="flex gap-1 text-xs">
                    <button type="button" class="branch-quick-filter px-2 py-1 rounded bg-gray-700 hover:bg-gray-600 text-gray-300 transition"
                        data-ids="{{ $branches->pluck('id')->join(',') }}" title="{{ __('merge.select_all') }}">
                        {{ __('merge.all') }}
                    </button>
                    @if($unreviewedIds->count() < $branches->count())
                    <button type="button" class="branch-quick-filter px-2 py-1 rounded bg-gray-700 hover:bg-gray-600 text-orange-300 transition"
                        data-ids="{{ $unreviewedIds->join(',') }}" title="{{ __('merge.select_unreviewed') }}">
                        <i class="fas fa-exclamation-circle mr-1"></i>{{ __('merge.unreviewed') }}
                    </button>
                    @endif
                    <button type="button" class="branch-quick-filter px-2 py-1 rounded bg-gray-700 hover:bg-gray-600 text-gray-400 transition"
                        data-ids="" title="{{ __('merge.select_none') }}">
                        {{ __('merge.none') }}
                    </button>
                </div>

                <span class="text-gray-600">|</span>

                {{-- Individual branch checkboxes --}}
                @foreach($branches as $branch)
                <div class="flex items-center gap-2 px-2 py-1 rounded bg-gray-700/50 border border-gray-600">
                    <label class="flex items-center gap-2 cursor-pointer hover:text-white transition">
                        <input type="checkbox" name="branches[]" value="{{ $branch->id }}"
                            class="branch-checkbox rounded bg-gray-700 border-gray-600 text-purple-600 focus:ring-purple-500"
                            {{ $selectedBranches->contains('id', $branch->id) ? 'checked' : '' }}>
                        <span class="text-gray-300">{{ $branch->user->name }}</span>
                        <span class="text-xs text-gray-500">({{ $branch->line_count }})</span>
                    </label>
                    {{-- Rating Stars --}}
                    <div class="flex items-center gap-1 ml-2 branch-rating" data-branch-id="{{ $branch->id }}">
                        @for($i = 1; $i <= 5; $i++)
                        <button type="button"
                            class="rating-star text-sm {{ $branch->main_rating >= $i ? 'text-yellow-400' : 'text-gray-600' }} hover:text-yellow-300 transition"
                            data-rating="{{ $i }}"
                            title="{{ __('rating.rate_branch', ['stars' => $i]) }}">
                            <i class="fas fa-star"></i>
                        </button>
                        @endfor
                        @if($branch->wasModifiedSinceReview())
                        <span class="ml-1 text-xs text-orange-400" title="{{ __('rating.modified_since_review') }}">
                            <i class="fas fa-exclamation-circle"></i>
                        </span>
                        @endif
                    </div>
                </div>
                @endforeach
            </form>
        </div>
        @else
        <div class="mb-6 bg-gray-800 rounded-lg p-4 border border-gray-700">
            <p class="text-gray-400 text-sm">
                <i class="fas fa-info-circle mr-2 text-blue-400"></i>
                {{ __('merge.no_branches') }}
            </p>
        </div>
        @endif
    @endif

    {{-- Client-side editor (shared translation-editor core) --}}
    <div x-data="mergeView" @keydown.window="handleEditorKeydown($event)">
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

        <div x-show="loaded && !error" x-cloak>
            {{-- Stats --}}
            <div class="mb-6 grid {{ $mode === 'edit' ? 'grid-cols-2' : 'grid-cols-2 md:grid-cols-4' }} gap-4">
                <div class="bg-gray-800 rounded-lg p-4 border border-gray-700 text-center">
                    <p class="text-2xl font-bold text-white" x-text="allKeys.length"></p>
                    <p class="text-sm text-gray-400">{{ __('merge_preview.total_keys') }}</p>
                </div>
                @if($mode === 'merge')
                <div class="bg-gray-800 rounded-lg p-4 border border-green-700 text-center">
                    <p class="text-2xl font-bold text-green-400" x-text="stats.newKeys"></p>
                    <p class="text-sm text-gray-400">{{ __('merge.filter_new_keys') }}</p>
                </div>
                <div class="bg-gray-800 rounded-lg p-4 border border-yellow-700 text-center">
                    <p class="text-2xl font-bold text-yellow-400" x-text="stats.different"></p>
                    <p class="text-sm text-gray-400">{{ __('merge.filter_differences') }}</p>
                </div>
                @endif
                <div class="bg-gray-800 rounded-lg p-4 border border-purple-700 text-center">
                    <p class="text-2xl font-bold text-purple-400" x-text="totalChanges"></p>
                    <p class="text-sm text-gray-400">{{ __('merge.modifications') }}</p>
                </div>
            </div>

            @include('partials.editor-quality-bar')

            {{-- Filters (checked = visible, same model as the other editors) --}}
            <div class="mb-4 flex flex-wrap gap-4 items-center text-sm bg-gray-800 p-4 rounded-lg border border-gray-700">
                <span class="text-gray-500">{{ __('merge_preview.show') }}:</span>

                @if($mode === 'merge')
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" :checked="filters.catNew" @change="toggleFilter('catNew')"
                        class="rounded bg-gray-700 border-gray-600 text-green-600">
                    <span class="text-green-400">{{ __('merge.filter_new_keys') }}</span>
                </label>
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" :checked="filters.catDiff" @change="toggleFilter('catDiff')"
                        class="rounded bg-gray-700 border-gray-600 text-yellow-600">
                    <span class="text-yellow-400">{{ __('merge.filter_differences') }}</span>
                </label>
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" :checked="filters.catOther" @change="toggleFilter('catOther')"
                        class="rounded bg-gray-700 border-gray-600 text-gray-600">
                    <span class="text-gray-400">{{ __('merge_preview.same') }}</span>
                </label>

                <span class="text-gray-600">|</span>
                @endif

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
                    <span class="text-purple-400">{{ __('merge.modifications') }}</span>
                </label>

                {{-- Capture-order index column (mod-assigned "i") --}}
                <label class="flex items-center gap-2 cursor-pointer" title="{{ __('editor.capture_order_hint') }}">
                    <input type="checkbox" :checked="showIndexColumn" @change="toggleIndexColumn()"
                        class="rounded bg-gray-700 border-gray-600 text-gray-500">
                    <span class="text-gray-400"><i class="fas fa-arrow-down-1-9 mr-1"></i>{{ __('editor.capture_order') }}</span>
                </label>
            </div>

            @include('partials.editor-floating-search')

            {{-- Search (Enter/Shift+Enter navigate matches) + replace --}}
            <div class="mb-4 space-y-2" x-ref="searchBar">
                <div class="flex gap-2">
                    <div class="relative flex-1">
                        <input type="text" x-model="searchQuery" @keydown.enter.prevent="onSearchEnter($event)"
                            placeholder="{{ __('merge.search_placeholder') }}"
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
                        title="{{ __('merge.search_scope_title') }}">
                        <option value="both">{{ __('merge.search_scope_both') }}</option>
                        <option value="keys">{{ __('merge.search_scope_keys') }}</option>
                        <option value="values">{{ __('merge.search_scope_values') }}</option>
                    </select>
                    <button type="button" @click="toggleReplace()"
                        :class="replaceOpen ? 'bg-purple-700 text-white border-purple-500' : 'bg-gray-800 text-gray-300 border-gray-700 hover:text-white'"
                        class="border rounded-lg px-3 py-2 text-sm transition" title="{{ __('merge.replace') }}">
                        <i class="fas fa-right-left"></i>
                    </button>
                </div>
                {{-- Replace: single-row only, staged as a human edit (→ H), no replace-all.
                     Applies to the Main column, the only editable one --}}
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
            <div class="overflow-x-auto bg-gray-800 rounded-lg border border-gray-700">
                <table class="w-full text-sm">
                    <thead class="bg-gray-900 sticky top-0 z-10">
                        <tr>
                            {{-- Capture-order index (toggleable, sortable) --}}
                            <th x-show="showIndexColumn" x-cloak
                                class="px-2 py-3 text-right text-gray-400 font-medium w-16 cursor-pointer hover:text-white transition"
                                @click="toggleSort('index')" title="{{ __('editor.capture_order_hint') }}">
                                <div class="flex items-center justify-end gap-1">
                                    <span class="text-xs">#</span>
                                    <i class="fas text-xs" :class="getSortIcon('index')"></i>
                                </div>
                            </th>
                            <th class="px-4 py-3 text-left text-gray-400 font-medium cursor-pointer hover:text-white transition"
                                @click="toggleSort('key')">
                                <div class="flex items-center gap-2">
                                    {{ __('merge.key') }}
                                    <i class="fas" :class="getSortIcon('key')"></i>
                                </div>
                            </th>
                            <th class="px-2 py-3 text-center border-l border-gray-700 w-12 cursor-pointer hover:text-white transition"
                                @click="toggleSort('mainTag')">
                                <div class="flex items-center justify-center gap-1">
                                    <span class="text-green-400 font-medium text-xs">Tag</span>
                                    <i class="fas text-xs" :class="getSortIcon('mainTag')"></i>
                                </div>
                            </th>
                            <th class="px-4 py-3 text-left border-l border-gray-700 min-w-[250px] cursor-pointer hover:text-white transition"
                                @click="toggleSort('mainValue')">
                                <div class="flex items-center gap-2">
                                    <span class="text-green-400 font-medium">Main</span>
                                    <span class="text-xs text-gray-500" x-text="'(' + mainOwner + ')'"></span>
                                    <i class="fas" :class="getSortIcon('mainValue')"></i>
                                </div>
                            </th>
                            <template x-for="branch in branches" :key="branch.id">
                                <th colspan="2" class="px-4 py-3 text-left border-l border-gray-700 min-w-[280px]">
                                    <div class="flex items-center gap-2">
                                        <span class="text-blue-400 font-medium" x-text="branch.name"></span>
                                        <span class="text-xs text-gray-500"
                                            x-text="'(' + branch.human_count + 'H / ' + branch.validated_count + 'V / ' + branch.ai_count + 'A)'"></span>
                                    </div>
                                </th>
                            </template>
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
                                {{-- Capture-order index --}}
                                <td x-show="showIndexColumn" x-cloak
                                    class="px-2 py-2 text-right font-mono text-xs text-gray-600 tabular-nums align-top"
                                    x-text="indexCellText(key)"></td>

                                {{-- Key --}}
                                <td class="px-4 py-2 font-mono text-xs text-gray-500 break-words">
                                    <span :class="isDeleted(key) ? 'line-through text-red-400' : ''" x-safe-html="highlightKey(key)"></span>
                                </td>

                                {{-- Main Tag (clickable for tag change) --}}
                                <td class="px-2 py-2 text-center border-l border-gray-700"
                                    :class="[hasTagChange(key) ? 'tag-changed-cell' : '', isDeleted(key) ? 'deleted-cell' : '']">
                                    <template x-if="mainData[key] !== undefined">
                                        {{-- Shows the tag the save will PRODUCE (edit → H,
                                             selection → A promoted to V), not just the stored one --}}
                                        <button type="button"
                                            @click.stop="openTagDropdown($event, key, displayMainTag(key), getValue(mainData[key]))"
                                            :class="isDeleted(key) ? 'opacity-40 cursor-not-allowed' : 'cursor-pointer hover:ring-2 hover:ring-purple-400 hover:ring-offset-1 hover:ring-offset-gray-800'"
                                            :disabled="isDeleted(key)"
                                            class="transition rounded"
                                            title="{{ __('merge.click_to_change_tag') }}">
                                            <span :class="'tag-' + displayMainTag(key) + (isCaptureRow(key) ? ' opacity-40' : '')" x-text="displayMainTag(key)"></span>
                                        </button>
                                    </template>
                                    <template x-if="mainData[key] === undefined">
                                        <span class="text-gray-600">—</span>
                                    </template>
                                </td>

                                {{-- Main Value (click = keep/validate main, dblclick/pencil = edit) --}}
                                <td class="px-4 py-2 border-l border-gray-700 merge-cell"
                                    :class="[getCellClass(key, 'main'), isDeleted(key) ? 'deleted-cell' : '']"
                                    @click="select(key, 'main')"
                                    @dblclick="editCell(key, getValue(mainData[key]))">
                                    <span class="edit-affordance" x-show="mainData[key] !== undefined">
                                        <button type="button" x-show="isRowModified(key)" @click.stop="revertRow(key)"
                                            title="{{ __('merge.revert_row') }}"><i class="fas fa-undo"></i></button>
                                        <button type="button" x-show="!isDeleted(key)" @click.stop="editCell(key, getValue(mainData[key]))"
                                            title="{{ __('translation.edit') }}"><i class="fas fa-pen"></i></button>
                                        <button type="button" class="delete-btn" @click.stop="toggleDelete(key)"
                                            title="{{ __('translation.delete') }}"><i class="fas fa-trash"></i></button>
                                    </span>
                                    <template x-if="mainData[key] !== undefined || isEdited(key)">
                                        <span class="break-words"
                                            :class="[isEdited(key) ? 'text-purple-300' : '', isDeleted(key) ? 'line-through opacity-40' : '']">
                                            {{-- Non-blocking guard: the pending edit altered [!v*N] placeholders --}}
                                            <span x-show="hasPlaceholderWarning(key)" x-cloak
                                                class="inline-block mb-1 px-1.5 py-0.5 rounded bg-orange-900/60 text-orange-300 text-xs"
                                                title="{{ __('merge.placeholder_warning') }}">
                                                <i class="fas fa-exclamation-triangle mr-1"></i>Placeholders
                                            </span>
                                            <span x-show="isEdited(key)" x-safe-html="highlightValue(editedValues[key])"></span>
                                            <span x-show="!isEdited(key)" x-safe-html="mainValueHtml(key)"></span>
                                        </span>
                                    </template>
                                    <template x-if="mainData[key] === undefined && !isEdited(key)">
                                        <span class="text-gray-600 italic">—</span>
                                    </template>
                                </td>

                                {{-- Branch columns (click = take this branch's version) --}}
                                <template x-for="branch in branches" :key="branch.id">
                                    <td class="px-2 py-2 border-l border-gray-700 merge-cell" colspan="2"
                                        :class="[getCellClass(key, 'branch_' + branch.id), branchCellTint(branch, key)]"
                                        @click="select(key, 'branch_' + branch.id)">
                                        <template x-if="branch.content[key] !== undefined">
                                            <div class="flex items-start gap-2">
                                                <span :class="'tag-' + getTag(branch.content[key])" x-text="getTag(branch.content[key])"></span>
                                                <span class="break-words"
                                                    :class="branchTextTint(branch, key)"
                                                    x-safe-html="highlightValue(getValue(branch.content[key]))"></span>
                                            </div>
                                        </template>
                                        <template x-if="branch.content[key] === undefined">
                                            <span class="text-gray-600 italic">—</span>
                                        </template>
                                    </td>
                                </template>
                            </tr>
                        </template>

                        <tr x-show="filteredKeys.length === 0">
                            <td :colspan="(showIndexColumn ? 4 : 3) + branches.length" class="px-4 py-12 text-center text-gray-500">
                                <i class="fas fa-search text-4xl mb-3 opacity-50"></i>
                                <p>{{ __('merge.no_keys_found') }}</p>
                            </td>
                        </tr>

                        <tr x-show="hiddenCount > 0">
                            <td :colspan="(showIndexColumn ? 4 : 3) + branches.length" class="px-4 py-3 text-center">
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

            {{-- Apply form + footer. The sticky class must live on the FORM:
                 sticky only works within the parent's bounds, and the form
                 wraps nothing but this bar — sticky on the inner div would
                 have no room to stick and the bar would sit at the very
                 bottom of the page --}}
            <form method="POST" action="{{ route('translations.merge.apply', $uuid) }}" id="mergeForm"
                class="mt-6 sticky bottom-4">
                @csrf
                {{-- Server needs mode + branches back for the redirect --}}
                <input type="hidden" name="mode" value="{{ $mode }}">
                @foreach($selectedBranches as $branch)
                <input type="hidden" name="branches[]" value="{{ $branch->id }}">
                @endforeach
                {{-- JSON-encoded data (avoids Laravel TrimStrings corrupting keys with whitespace) --}}
                <input type="hidden" id="selectionsJson" name="selections_json" value="">
                <input type="hidden" id="deletionsJson" name="deletions_json" value="">
                <input type="hidden" id="tagChangesJson" name="tag_changes_json" value="">

                {{-- min-w-0 on the text + shrink-0 on the buttons: the
                     instructions wrap instead of squeezing the save button --}}
                <div class="flex justify-between items-center gap-4 bg-gray-800 rounded-lg p-4 border border-gray-700">
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
                            <span x-show="selectionCount > 0">
                                <span class="text-white font-bold" x-text="selectionCount"></span> {{ __('merge.modifications') }}
                            </span>
                            <span x-show="selectionCount > 0 && (deleteCount > 0 || tagChangeCount > 0)"> &bull; </span>
                            <span x-show="deleteCount > 0">
                                <span class="text-red-400 font-bold" x-text="deleteCount"></span> {{ __('merge.deletions') }}
                            </span>
                            <span x-show="deleteCount > 0 && tagChangeCount > 0"> &bull; </span>
                            <span x-show="tagChangeCount > 0">
                                <span class="text-purple-400 font-bold" x-text="tagChangeCount"></span> {{ __('merge.tag_changes') }}
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
                            <i class="fas fa-times mr-1"></i> {{ __('merge.cancel_all') }}
                        </button>
                        <button type="button" @click="submitMerge()" :disabled="totalChanges === 0"
                            class="bg-green-600 hover:bg-green-700 disabled:bg-gray-600 disabled:cursor-not-allowed px-6 py-3 rounded-lg text-white font-bold transition">
                            <i class="fas fa-save mr-2"></i>
                            {{ __('common.save') }} (<span x-text="totalChanges">0</span>)
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
            </form>

            {{-- Legend (HVASM order) --}}
            <div class="mt-6 text-xs text-gray-500 flex flex-wrap gap-4">
                <span><span class="tag-H">H</span> {{ __('merge.legend_human') }}</span>
                <span><span class="tag-V">V</span> {{ __('merge.legend_validated') }}</span>
                <span><span class="tag-A">A</span> {{ __('merge.legend_ai') }}</span>
                <span><span class="tag-S">S</span> {{ __('merge.legend_skipped') }}</span>
                <span><span class="tag-M">M</span> {{ __('merge.legend_mod_ui') }}</span>
                <span class="text-gray-600">|</span>
                <span><span class="inline-block w-3 h-3 bg-green-900/50 rounded mr-1"></span> {{ __('merge.legend_selection_main') }}</span>
                <span><span class="inline-block w-3 h-3 bg-blue-900/50 rounded mr-1"></span> {{ __('merge.legend_selection_branch') }}</span>
                <span><span class="inline-block w-3 h-3 bg-purple-900/50 rounded mr-1"></span> {{ __('merge.legend_manual_edit') }}</span>
                <span><span class="inline-block w-3 h-3 bg-yellow-900/30 rounded mr-1"></span> {{ __('merge.legend_difference') }}</span>
                <span><span class="inline-block w-3 h-3 bg-green-900/30 rounded mr-1"></span> {{ __('merge.legend_new_key') }}</span>
                <span><span class="inline-block w-3 h-3 bg-red-900/50 rounded mr-1"></span> {{ __('merge.legend_deletion') }}</span>
            </div>
        </div>

        {{-- Edit Modal --}}
        <div x-show="editModal.open" x-cloak
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/70"
            @click.self="closeEditModal()">
            <div class="bg-gray-800 rounded-lg shadow-xl border border-gray-700 w-full max-w-2xl mx-4"
                @keydown.ctrl.enter="saveEditModal()">
                <div class="px-6 py-4 border-b border-gray-700">
                    <h3 class="text-lg font-semibold text-white">{{ __('merge.edit_translation') }}</h3>
                    <p class="text-sm text-gray-400 font-mono mt-1 break-words" x-text="editModal.key"></p>
                </div>
                <div class="px-6 py-4">
                    {{-- x-model must target a TOP-LEVEL property: the Alpine CSP
                         build prohibits property assignments (editModal.value = x) --}}
                    <textarea
                        id="editModalTextarea"
                        x-model="editModalValue"
                        class="w-full h-48 px-4 py-3 bg-gray-900 border border-gray-600 rounded-lg text-white placeholder-gray-500 focus:border-purple-500 focus:ring-1 focus:ring-purple-500 resize-y"
                        placeholder="{{ __('merge.enter_translation') }}"
                    ></textarea>
                    <p x-show="editModalPlaceholderMismatch" x-cloak class="mt-2 text-xs text-orange-400">
                        <i class="fas fa-exclamation-triangle mr-1"></i>{{ __('merge.placeholder_warning') }}
                    </p>
                    <p class="mt-2 text-xs text-gray-500">
                        <kbd class="px-1.5 py-0.5 bg-gray-700 rounded text-gray-300">Ctrl+Enter</kbd> {{ __('merge.save_shortcut') }} &bull;
                        <kbd class="px-1.5 py-0.5 bg-gray-700 rounded text-gray-300">Esc</kbd> {{ __('merge.cancel_shortcut') }}
                    </p>
                </div>
                <div class="px-6 py-4 border-t border-gray-700 flex justify-end gap-3">
                    <button type="button" @click="closeEditModal()"
                        class="px-4 py-2 text-gray-400 hover:text-white transition">
                        {{ __('common.cancel') }}
                    </button>
                    <button type="button" @click="saveEditModal()"
                        class="px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-lg transition">
                        <i class="fas fa-check mr-1"></i> {{ __('common.save') }}
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
// Only the merge-view specifics live here (multi-branch columns, the
// selections model of the apply endpoint, the hidden-form submit).
document.addEventListener('alpine:init', () => {
    // window.UGT is set by app.js (deferred module): it exists by the time
    // Alpine fires alpine:init, but NOT during the initial HTML parse
    const normalizeLineEndings = window.UGT.normalizeLineEndings;
    const isEditMode = @json($mode === 'edit');

    Alpine.data('mergeView', () => window.UGT.composeEditor({
        persistKey: 'merge_view_{{ $uuid }}',
        filters: {
            catNew: true,
            catDiff: true,
            catOther: true,
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
        mainData: {},
        mainOwner: '',
        branches: [],       // [{id, name, human_count, validated_count, ai_count, content{}}]
        allKeys: [],
        // Merge selections: key -> {source: 'main'|'branch_{id}'|'manual', value, tag}
        // (selecting main = validate it: the apply endpoint promotes A -> V)
        selections: {},
        stats: { newKeys: 0, different: 0 },

        init() {
            this.initEditorCore();

            fetch(@js($dataUrl), { headers: { 'Accept': 'application/json' } })
                .then(response => response.ok ? response.json() : Promise.reject(new Error('load_failed')))
                .then(payload => this.loadContent(payload))
                .catch(() => {
                    this.error = @js(__('merge_preview.error_load_failed'));
                    this.loaded = true;
                });
        },

        loadContent(payload) {
            this.mainOwner = payload.main_owner || '';

            this.mainData = {};
            for (const [key, value] of Object.entries(payload.main || {})) {
                this.mainData[normalizeLineEndings(key)] = this.normalizeEntry(value);
            }

            this.branches = (payload.branches || []).map(branch => {
                const content = {};
                for (const [key, value] of Object.entries(branch.content || {})) {
                    content[normalizeLineEndings(key)] = this.normalizeEntry(value);
                }
                return { ...branch, content };
            });

            const keys = new Set(Object.keys(this.mainData));
            for (const branch of this.branches) {
                for (const key of Object.keys(branch.content)) keys.add(key);
            }
            this.allKeys = [...keys].sort();

            this.calculateStats();
            this.loaded = true;
        },

        normalizeEntry(value) {
            if (typeof value === 'object' && value !== null && 'v' in value) {
                return { ...value, v: normalizeLineEndings(value.v) };
            }
            if (typeof value === 'string') {
                return normalizeLineEndings(value);
            }
            return value;
        },

        calculateStats() {
            this.stats = { newKeys: 0, different: 0 };
            for (const key of this.allKeys) {
                const category = this.rowCategory(key);
                if (category === 'new') this.stats.newKeys++;
                else if (category === 'diff') this.stats.different++;
            }
        },

        /**
         * Category of a row relative to the branches:
         * 'new'  = missing in Main, present in at least one branch
         * 'diff' = present in Main, at least one branch differs
         * 'other' = everything else (identical everywhere or Main-only)
         */
        rowCategory(key) {
            const hasMain = key in this.mainData;
            if (!hasMain) {
                for (const branch of this.branches) {
                    if (key in branch.content) return 'new';
                }
                return 'other';
            }
            const mainValue = this.getValue(this.mainData[key]);
            for (const branch of this.branches) {
                if (key in branch.content && this.getValue(branch.content[key]) !== mainValue) {
                    return 'diff';
                }
            }
            return 'other';
        },

        // ── Shared-core callbacks ────────────────────────────────────────

        rowPassesFilters(key) {
            if (this.filters.modifiedOnly && !this.isRowModified(key)) {
                return false;
            }

            if (!isEditMode && this.branches.length > 0) {
                const category = this.rowCategory(key);
                if (category === 'new' && !this.filters.catNew) return false;
                if (category === 'diff' && !this.filters.catDiff) return false;
                if (category === 'other' && !this.filters.catOther) return false;
            }

            // Tag filter: the row passes if ANY of its tags (Main or branch)
            // is visible. Main matches on its STORED and its PREVIEWED tag:
            // a pending change must not make its row vanish mid-work
            if (key in this.mainData && this.tagVisible(this.getTag(this.mainData[key]))) return true;
            if ((key in this.mainData || this.isEdited(key)) && this.tagVisible(this.displayMainTag(key))) return true;
            for (const branch of this.branches) {
                if (key in branch.content && this.tagVisible(this.getTag(branch.content[key]))) return true;
            }
            return false;
        },

        rowMatchesSearch(key, query) {
            if (this.searchScope !== 'values' && key.toLowerCase().includes(query)) {
                return true;
            }
            if (this.searchScope !== 'keys') {
                if (key in this.mainData
                    && this.getValue(this.mainData[key]).toLowerCase().includes(query)) return true;
                for (const branch of this.branches) {
                    if (key in branch.content
                        && this.getValue(branch.content[key]).toLowerCase().includes(query)) return true;
                }
                if (this.editedValues[key] !== undefined
                    && this.editedValues[key].toLowerCase().includes(query)) return true;
            }
            return false;
        },

        rowSortValue(key, column) {
            if (column === 'index') {
                return this.indexSortValue(this.orderIndexFor(key));
            }
            if (column === 'mainTag') {
                return key in this.mainData ? this.getTag(this.mainData[key]) : '';
            }
            // 'mainValue' — stored value so pending edits don't reorder rows
            return key in this.mainData ? this.getValue(this.mainData[key]).toLowerCase() : '';
        },

        /** Core hook: the stored editable value (replace, placeholder guard). */
        storedValue(key) {
            return this.getValue(this.mainData[key]);
        },

        /** Capture-order index: Main first, branches as fallback (branch-only keys). */
        orderIndexFor(key) {
            const mainIdx = this.getOrderIndex(this.mainData[key]);
            if (mainIdx !== Infinity) return mainIdx;
            for (const branch of this.branches) {
                const idx = this.getOrderIndex(branch.content[key]);
                if (idx !== Infinity) return idx;
            }
            return Infinity;
        },

        indexCellText(key) {
            const idx = this.orderIndexFor(key);
            return idx === Infinity ? '' : String(idx);
        },

        /** Core hook: projected Main tag for the quality bar (rows the
         *  save will put in the Main file: existing, edited or selected). */
        rowQualityTag(key) {
            if (key in this.mainData || this.isEdited(key) || this.selections[key]) {
                return this.displayMainTag(key);
            }
            return null;
        },

        /** Core hook: V on the cursor row = the click-Main validate gesture
         *  (same real-change rules: A lines, or replacing a selection). */
        cursorPrimaryAction(key) {
            this.select(key, 'main');
        },

        /** Main value cell HTML: highlighted, or the empty-value marker. */
        mainValueHtml(key) {
            const value = this.getValue(this.mainData[key]);
            return value !== '' ? this.highlightValue(value) : this.escapeHtml(@js(__('merge.empty_value')));
        },

        /** Core hook: a staged manual edit becomes a 'manual' selection.
         *  Sends the STORED tag: the server applies manual → H itself and
         *  preserves M/S — hardcoding H here would override that rule. */
        onEditStaged(key) {
            this.selections[key] = { source: 'manual', value: this.editedValues[key], tag: this.getTag(this.mainData[key]) };
        },

        /** Core hook: an edit reverted to the original drops its selection. */
        onEditUnstaged(key) {
            if (this.selections[key]?.source === 'manual') {
                delete this.selections[key];
            }
        },

        /** Core hook: a deletion cancels any selection for the key. */
        onDeleteToggled(key) {
            delete this.selections[key];
        },

        /** Core hook: a per-row revert also drops the merge selection. */
        onRowReverted(key) {
            delete this.selections[key];
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
         * Pick a version for a key. Re-clicking the same source deselects
         * (toggle, same behavior as before). Selecting main = validate it
         * (A -> V server-side).
         */
        select(key, source) {
            // Even on inert rows the click moves the search cursor (IDE caret)
            this.setMatchCursor(key);
            if (this.isDeleted(key)) return;

            if (this.selections[key]?.source === source && source !== 'manual') {
                delete this.selections[key];
                delete this.editedValues[key];
                this.persistPendingState();
                return;
            }

            let value = '';
            let tag = 'A';
            if (source === 'main') {
                if (this.mainData[key] === undefined) return;
                value = this.getValue(this.mainData[key]);
                tag = this.getTag(this.mainData[key]);
                // A click only ever produces a REAL change (see
                // analyse/editors-gestures-parity.md): picking Main acts
                // when it validates an A line, or when it replaces an
                // existing selection (branch pick / manual edit) — on a
                // V/H/M/S line with nothing selected it would rewrite the
                // line identically and count a phantom modification
                if (tag !== 'A' && !this.selections[key]) return;
            } else {
                const branchId = parseInt(source.replace('branch_', ''), 10);
                const branch = this.branches.find(b => b.id === branchId);
                if (!branch || branch.content[key] === undefined) return;
                value = this.getValue(branch.content[key]);
                tag = this.getTag(branch.content[key]);
            }

            // Choosing a version discards a pending manual edit
            delete this.editedValues[key];
            this.selections[key] = { source, value, tag };
            this.persistPendingState();
        },

        getCellClass(key, source) {
            const sel = this.selections[key];
            if (!sel) return '';
            if (sel.source === source) {
                return source === 'main' ? 'selected-main' : 'selected-branch';
            }
            // A manual edit displays in the Main column
            if (source === 'main' && sel.source === 'manual') {
                return 'selected-manual';
            }
            return '';
        },

        /**
         * Tag the save will PRODUCE for the Main entry — previewed live,
         * before anything is saved. Core displayTag covers tag change → that
         * tag and manual edit → H (M/S preserved); on top of it, a selected
         * version keeps its tag with the server's A → V promotion.
         */
        displayMainTag(key) {
            if (this.hasTagChange(key) || this.isEdited(key)) {
                return this.displayTag(key, this.getTag(this.mainData[key]));
            }
            const sel = this.selections[key];
            if (sel) {
                return sel.tag === 'A' ? 'V' : sel.tag;
            }
            return this.getTag(this.mainData[key]);
        },

        branchCellTint(branch, key) {
            if (branch.content[key] === undefined) return '';
            if (this.mainData[key] === undefined) return 'bg-green-900/20';
            if (this.getValue(branch.content[key]) !== this.getValue(this.mainData[key])) return 'bg-yellow-900/20';
            return '';
        },

        branchTextTint(branch, key) {
            if (branch.content[key] === undefined) return '';
            if (this.mainData[key] === undefined) return 'text-green-300';
            if (this.getValue(branch.content[key]) !== this.getValue(this.mainData[key])) return 'text-yellow-300';
            return '';
        },

        isRowModified(key) {
            return key in this.selections
                || this.editedValues[key] !== undefined
                || key in this.tagChanges
                || this.isDeleted(key);
        },

        get selectionCount() {
            return Object.keys(this.selections).length;
        },

        get deleteCount() {
            return Object.keys(this.deletions).length;
        },

        get tagChangeCount() {
            return Object.keys(this.tagChanges).length;
        },

        get totalChanges() {
            return this.selectionCount + this.deleteCount + this.tagChangeCount;
        },

        clearAll() {
            if (confirm(@js(__('merge.cancel_all')))) {
                this.selections = {};
                this.clearPendingState();
            }
        },

        // ── Submit (exact wire format of MergeController::apply) ─────────

        submitMerge() {
            if (this.totalChanges === 0) return;

            const selectionsArr = Object.entries(this.selections).map(([key, data]) => ({
                key,
                value: data.source === 'manual' ? (this.editedValues[key] ?? data.value) : data.value,
                tag: data.tag,
                source: data.source
            }));
            const deletionsArr = Object.keys(this.deletions);
            const tagChangesArr = Object.entries(this.tagChanges).map(([key, data]) => ({
                key,
                tag: data.newTag,
                value: data.value
            }));

            document.getElementById('selectionsJson').value = selectionsArr.length > 0 ? JSON.stringify(selectionsArr) : '';
            document.getElementById('deletionsJson').value = deletionsArr.length > 0 ? JSON.stringify(deletionsArr) : '';
            document.getElementById('tagChangesJson').value = tagChangesArr.length > 0 ? JSON.stringify(tagChangesArr) : '';

            // Pending work is about to be applied server-side
            this.clearPendingState();

            document.getElementById('mergeForm').submit();
        }
    }));
});
</script>
@endsection
