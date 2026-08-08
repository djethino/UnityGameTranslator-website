@extends('layouts.app')

{{-- Same reason as the merge screen it shares its editor with: local beside online, side by
     side, and the window decides how much room that takes. --}}
@section('container', 'w-full px-4 sm:px-6 lg:px-8')

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

        {{-- Which way this comparison runs, said plainly and permanently.

             The same buttons do opposite things in the two directions — most of all Delete,
             which removes a line from the server when publishing and from the player's own file
             when comparing into the game. Someone who believes they are tidying up the Main
             would be erasing their own work, and nothing else on this page would tell them. --}}
        @if($toLocal)
            <div class="mb-4 bg-blue-900/30 border border-blue-700 rounded-lg px-4 py-3 text-sm text-blue-200">
                <i class="fas fa-download mr-2"></i>{{ __('merge_preview.direction_to_game') }}
            </div>
        @endif

        {{-- Settings are part of the file but not of this comparison: there is no
             row to show and no side to pick for a font or an exclusion list.
             What CAN be said is which sections differ and by how much — the
             previous banner only said "something differs", which is unusable.

             Labels are rendered by Blade and picked with x-show, never through
             x-text: the CSP build of Alpine renders string literals verbatim,
             so a translated string inside an expression prints its escapes. --}}
        <div x-show="settingsDiffer" x-cloak
            class="mb-4 bg-gray-800 border border-gray-700 rounded-lg px-4 py-3 text-sm">
            <div class="flex items-center gap-2 text-gray-300 mb-2">
                <i class="fas fa-sliders text-gray-500"></i>
                <span>{{ __('merge_preview.settings_differ_title') }}</span>
            </div>
            <div class="flex flex-wrap gap-x-6 gap-y-1 text-gray-400">
                @foreach([
                    'fonts' => __('file_settings.label.fonts'),
                    'font_rules' => __('file_settings.label.font_rules'),
                    'images' => __('file_settings.label.images'),
                    'exclusions' => __('file_settings.label.exclusions'),
                    'variables' => __('file_settings.label.variables'),
                    'game_settings' => __('file_settings.game_settings'),
                ] as $section => $label)
                    {{-- Flat two-level paths only: the CSP evaluator accepts
                         property access, not operators or expressions --}}
                    <span x-show="settingsDiffFlags.{{ $section }}" x-cloak>
                        {{ $label }} :
                        <span class="text-gray-300" x-text="settingsLocal.{{ $section }}"></span>
                        <span class="text-gray-600">/</span>
                        <span class="text-gray-300" x-text="settingsOnline.{{ $section }}"></span>
                    </span>
                @endforeach
            </div>
            <p class="text-xs text-gray-500 mt-2">
                {{ __('merge_preview.local_file') }} <span class="text-gray-600">/</span> {{ __('merge_preview.online_version') }}
                &bull; {{ __('merge_preview.settings_differ') }}
            </p>

            {{-- Setting by setting, when both sides are known.

                 Only the mod flow leaves the local file on the server; the web flow keeps it in
                 sessionStorage, so there is nothing to compare against server-side and the
                 summary above stays the whole story there. Extracting settings in JavaScript
                 too would mean a second definition of "what a setting is" to keep in step with
                 the mod — the counts above already cost us one such drift.

                 Values are read-only: a font or an exclusion is edited in the mod, never here.
                 The only decision offered is WHICH SIDE wins, which is exactly what a merge
                 has to ask. --}}
            <div x-show="settingsRowsReady" x-cloak class="mt-3 pt-3 border-t border-gray-700">
                <button type="button" @click="toggleSettingsRows()"
                    class="text-xs text-purple-300 hover:text-purple-200">
                    <span x-show="showSettingsRows" x-cloak>{{ __('merge_preview.settings_hide_detail') }}</span>
                    <span x-show="hideSettingsRows" x-cloak>{{ __('merge_preview.settings_show_detail') }}</span>
                </button>

                <div x-show="showSettingsRows" x-cloak class="mt-2 overflow-x-auto">
                    <table class="w-full text-xs">
                        <thead class="text-gray-500">
                            <tr>
                                <th class="text-left font-normal px-2 py-1">{{ __('merge_preview.settings_column') }}</th>
                                <th class="text-left font-normal px-2 py-1">{{ __('merge_preview.local_file') }}</th>
                                <th class="text-left font-normal px-2 py-1">{{ __('merge_preview.online_version') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="row in settingsRows" :key="row.key">
                                <tr class="border-t border-gray-750">
                                    <td class="px-2 py-1 align-top">
                                        <span class="text-gray-500" x-text="row.sectionLabel"></span>
                                        <span class="text-gray-300 font-mono" x-text="row.label"></span>
                                    </td>
                                    <td class="px-2 py-1 align-top cursor-pointer"
                                        :class="settingCellClass(row.key, 'local')"
                                        @click="selectSetting(row.key, 'local')"
                                        x-text="row.localValue"></td>
                                    <td class="px-2 py-1 align-top cursor-pointer"
                                        :class="settingCellClass(row.key, 'online')"
                                        @click="selectSetting(row.key, 'online')"
                                        x-text="row.onlineValue"></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                    <p class="text-xs text-gray-500 mt-2">{{ __('merge_preview.settings_pick_hint') }}</p>
                </div>
            </div>
        </div>

        @include('partials.editor-quality-bar')

        {{-- Filters. Two zones, like the merge view: what to show on the left, how to show it on
             the right. The right group is pinned, so a long language wraps the FILTERS and never
             strands a view option on a line of its own. --}}
        <div class="mb-4 flex gap-4 items-start text-sm bg-gray-800 p-4 rounded-lg border border-gray-700">
          <div class="flex flex-wrap gap-x-3 gap-y-2 items-center flex-1 min-w-0">
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

          {{-- How a row is drawn, and how much room the grid gets. Same group, same icons, same
               place as on the merge view. --}}
          <div class="flex items-center gap-3 shrink-0 border-l border-gray-700 pl-4">
            <x-editor.view-options />
            <x-editor.workbench-toggle />
          </div>
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

        {{-- The workbench strip, shared with the merge view — see
             components/editor/workbench-bar.blade.php. Only the category filters differ from one
             screen to the next, so only those are passed in. --}}
        <x-editor.workbench-bar save="saveToServer()" save-disabled="saving || totalChanges === 0">
            <label class="flex items-center gap-1 text-xs cursor-pointer shrink-0" title="{{ __('merge_preview.local_only') }}">
                <input type="checkbox" :checked="filters.localOnly" @change="toggleFilter('localOnly')"
                       class="rounded bg-gray-700 border-gray-600 text-green-600">
                <span class="text-green-400"><i class="fas fa-desktop"></i></span>
            </label>
            <label class="flex items-center gap-1 text-xs cursor-pointer shrink-0" title="{{ __('merge_preview.online_only') }}">
                <input type="checkbox" :checked="filters.onlineOnly" @change="toggleFilter('onlineOnly')"
                       class="rounded bg-gray-700 border-gray-600 text-blue-600">
                <span class="text-blue-400"><i class="fas fa-cloud"></i></span>
            </label>
            <label class="flex items-center gap-1 text-xs cursor-pointer shrink-0" title="{{ __('merge_preview.different') }}">
                <input type="checkbox" :checked="filters.different" @change="toggleFilter('different')"
                       class="rounded bg-gray-700 border-gray-600 text-yellow-600">
                <span class="text-yellow-400">≠</span>
            </label>
            <label class="flex items-center gap-1 text-xs cursor-pointer shrink-0" title="{{ __('merge_preview.same') }}">
                <input type="checkbox" :checked="filters.same" @change="toggleFilter('same')"
                       class="rounded bg-gray-700 border-gray-600 text-gray-600">
                <span class="text-gray-500">=</span>
            </label>
            <span class="w-px h-5 bg-gray-700 shrink-0"></span>
        </x-editor.workbench-bar>

        {{-- Table. An ordinary block that the page scrolls, until the workbench tears it out and
             hands it the window — then the scrollbars belong to the box and sit at the edges of
             the screen, where they can be reached without leaving the line being read. --}}
        <div x-ref="gridBox"
             class="overflow-x-auto bg-gray-800 rounded-lg border border-gray-700 mb-6"
             :class="wide && 'fixed inset-x-0 bottom-0 top-12 z-50 mb-0 rounded-none border-0 overflow-auto'">
            {{-- border-separate, and it is not cosmetic: with the default collapsed borders, a
                 browser does not paint the background of a sticky cell — only its text, so the
                 frozen key column would let every scrolled column show through behind its own
                 words. --}}
            <table class="editor-grid w-full text-sm border-separate border-spacing-0"
                   :class="[showLineBreaks && 'show-linebreaks', pinMain && !resizingColumns && 'pin-main', columnsSized && 'cols-sized']">
                <thead class="bg-gray-900 sticky top-0 z-20">
                    <tr>
                        {{-- Capture-order index (toggleable, sortable). Frozen with the key: the
                             line's identity has to travel with it when the values scroll past.
                             The width is PINNED, not suggested — a table lays its columns out to
                             fit their content, so "w-16" alone is a hint it may ignore, and the
                             key column frozen at a hard left-16 would then leave a strip of
                             nothing where the scrolled columns show through. --}}
                        <th x-show="showIndexColumn" x-cloak
                            class="px-2 py-3 text-right text-gray-400 font-medium w-16 min-w-[4rem] max-w-[4rem] cursor-pointer hover:text-white transition sticky left-0 z-30 bg-gray-900"
                            @click="toggleSort('index')" title="{{ __('editor.capture_order_hint') }}">
                            <div class="flex items-center justify-end gap-1">
                                <span class="text-xs">#</span>
                                <i class="fas text-xs" :class="getSortIcon('index')"></i>
                            </div>
                        </th>
                        {{-- A right edge, because a frozen column is not an overlapping one:
                             without it, the columns sliding underneath read as a rendering fault
                             rather than as content passing behind a fixed edge. --}}
                        <th data-col="key"
                            class="relative px-4 py-3 text-left text-gray-400 font-medium cursor-pointer hover:text-white transition sticky z-30 bg-gray-900 border-r border-gray-700 shadow-[4px_0_8px_-4px_rgba(0,0,0,0.6)]"
                            :class="showIndexColumn ? 'left-16' : 'left-0'"
                            @click="toggleSort('key')">
                            <div class="flex items-center gap-2">
                                {{ __('merge_preview.key') }}
                                <i class="fas" :class="getSortIcon('key')"></i>
                            </div>
                            <x-editor.col-resize col="key" />
                        </th>
                        {{-- Local Tag. data-col so the pin can freeze the pair: a value without
                             its tag says only half of what the row holds. --}}
                        <th data-col="localTag"
                            class="px-2 py-3 text-center border-l border-gray-700 w-12 cursor-pointer hover:text-white transition"
                            @click="toggleSort('localTag')">
                            <div class="flex items-center justify-center gap-1">
                                <span class="text-green-400 font-medium text-xs">Tag</span>
                                <i class="fas text-xs" :class="getSortIcon('localTag')"></i>
                            </div>
                        </th>
                        {{-- Local Value --}}
                        <th data-col="local"
                            class="relative px-4 py-3 text-left border-l border-gray-700 cursor-pointer hover:text-white transition"
                            @click="toggleSort('localValue')">
                            <div class="flex items-center gap-2">
                                <span class="text-green-400 font-medium">{{ __('merge_preview.local_file') }}</span>
                                <i class="fas" :class="getSortIcon('localValue')"></i>
                                <x-editor.pin-toggle />
                            </div>
                            <x-editor.col-resize col="local" />
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
                        <th data-col="online"
                            class="relative px-4 py-3 text-left border-l border-gray-700 cursor-pointer hover:text-white transition"
                            @click="toggleSort('onlineValue')">
                            <div class="flex items-center gap-2">
                                <span class="text-blue-400 font-medium">{{ __('merge_preview.online_version') }}</span>
                                <span class="text-xs text-gray-500">({{ $translation->user->name }})</span>
                                <i class="fas" :class="getSortIcon('onlineValue')"></i>
                            </div>
                            <x-editor.col-resize col="online" />
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
                        {{-- The whole row moves the cursor, not just the cells that already had a job.
                             Clicking a value both selects it and focuses the row, but
                             clicking the key did nothing — which reads as "you have to
                             click in the right place to pick a line", and forced a double
                             toggle on a value you did not want to change. Buttons inside
                             stop propagation, so their own actions are unaffected. --}}
                        <tr @click="focusRow(key)" class="cursor-default hover:bg-gray-750 transition-colors"
                            :class="isCurrentMatchRow(idx) ? 'current-match-row' : ''"
                            :data-row-index="idx">
                            {{-- Capture-order index. Frozen with its header: an opaque background
                                 is required, or the scrolled columns show through underneath. --}}
                            <td x-show="showIndexColumn" x-cloak
                                class="px-2 py-2 text-right font-mono text-xs text-gray-600 tabular-nums align-top sticky left-0 z-10 bg-gray-800 w-16 min-w-[4rem] max-w-[4rem]"
                                x-text="indexCellText(key)"></td>

                            {{-- Key column --}}
                            {{-- editor-text on the cell itself: its whole content is written by
                                 highlightKey, so there is no markup here whose indentation
                                 pre-wrap could turn into visible whitespace. --}}
                            <td data-col="key"
                                class="editor-text px-4 py-2 font-mono text-xs text-gray-500 break-words sticky z-10 bg-gray-800 border-r border-gray-700 shadow-[4px_0_8px_-4px_rgba(0,0,0,0.6)]"
                                :class="showIndexColumn ? 'left-16' : 'left-0'"
                                x-safe-html="highlightKey(key)"></td>

                            {{-- Local Tag column (clickable for tag change).
                                 Carries the selection colour like its online
                                 counterpart: both cells of a side belong to that
                                 side, and colouring only one of the two made the
                                 local pick look half-selected. --}}
                            <td data-col="localTag" class="px-2 py-2 text-center border-l border-gray-700"
                                :class="[getCellClass(key, 'local'), hasTagChange(key) ? 'tag-changed-cell' : '']">
                                <template x-if="localData[key] !== undefined">
                                    {{-- Shows the tag the save will PRODUCE (edit → H,
                                         sent local selection → A promoted to V), not just the stored one --}}
                                    <button type="button"
                                        @click.stop="openTagDropdown($event, key, displayLocalTag(key), getValue(localData[key]))"
                                        class="transition rounded cursor-pointer hover:ring-2 hover:ring-purple-400 hover:ring-offset-1 hover:ring-offset-gray-800"
                                        title="{{ __('merge.click_to_change_tag') }}">
                                        <span :class="'tag-' + displayLocalTag(key) + (isCaptureRow(key) ? ' opacity-40' : '') + (tagChangedBetweenSides(key) ? ' ring-2 ring-amber-400/80' : '')" x-text="displayLocalTag(key)"></span>
                                    </button>
                                </template>
                                <template x-if="localData[key] === undefined">
                                    <span class="text-gray-600">—</span>
                                </template>
                            </td>

                            {{-- Local Value column --}}
                            <td data-col="local" class="px-4 py-2 border-l border-gray-700 merge-cell"
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
                                        :class="[isEdited(key) ? 'text-purple-300' : '', isDeleted(key) ? 'line-through opacity-40' : '', valueUnchanged(key) ? 'opacity-50' : '']">
                                        {{-- Non-blocking guard: the pending edit altered [!v*N] placeholders --}}
                                        <span x-show="hasPlaceholderWarning(key)" x-cloak
                                            class="inline-block mb-1 px-1.5 py-0.5 rounded bg-orange-900/60 text-orange-300 text-xs"
                                            title="{{ __('merge.placeholder_warning') }}">
                                            <i class="fas fa-exclamation-triangle mr-1"></i>Placeholders
                                        </span>
                                        <span class="editor-text" x-safe-html="localValueHtml(key)"></span>
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
                                    <span :class="'tag-' + getTag(onlineData[key]) + (tagChangedBetweenSides(key) ? ' ring-2 ring-amber-400/80' : '')" x-text="getTag(onlineData[key])"></span>
                                </template>
                                <template x-if="onlineData[key] === undefined">
                                    <span class="text-gray-600">—</span>
                                </template>
                            </td>

                            {{-- Online Value column --}}
                            <td data-col="online" class="px-4 py-2 border-l border-gray-700 merge-cell"
                                :class="getCellClass(key, 'online')"
                                @click="select(key, 'online')">
                                <template x-if="onlineData[key] !== undefined">
                                    <span class="break-words editor-text" :class="valueUnchanged(key) ? 'opacity-50' : ''"
                                        x-safe-html="onlineValueHtml(key)"></span>
                                </template>
                                <template x-if="onlineData[key] === undefined">
                                    <span class="text-gray-600 italic">—</span>
                                </template>
                            </td>
                        </tr>
                    </template>

                    <tr x-show="filteredKeys.length === 0">
                        <td :colspan="showIndexColumn ? 6 : 5" class="py-12 text-center text-gray-500">
                            {{-- Kept where the eye is, not where the table is: see .grid-visible-center --}}
                            <div class="grid-visible-center">
                            <i class="fas fa-check-circle text-4xl mb-3 text-green-500"></i>
                            <p>{{ __('merge_preview.no_differences') }}</p>
                            </div>
                        </td>
                    </tr>

                    <tr x-show="hiddenCount > 0">
                        <td :colspan="showIndexColumn ? 6 : 5" class="py-3 text-center">
                            {{-- Kept where the eye is, not where the table is: see .grid-visible-center --}}
                            <div class="grid-visible-center">
                            <button type="button" @click="showMore()"
                                class="text-purple-400 hover:text-purple-300 text-sm transition">
                                <i class="fas fa-chevron-down mr-1"></i>
                                {{ __('merge_preview.show_more') }} (<span x-text="hiddenCount"></span>)
                            </button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        {{-- Footer with Save button. min-w-0 on the text + shrink-0 on the
             buttons: the instructions wrap instead of squeezing the save button.
             ↑↓ shortcuts float at both ends of the bar --}}
        {{-- z-40, and it is not decoration: a sticky cell creates its own stacking context and
             paints above ordinary content, so without it the frozen key column slid over the save
             bar and hid the button. --}}
        {{-- Sticky wrapper: the mirrored scrollbar has to ride WITH the bar, so both live in
             the same sticky block rather than the bar being sticky on its own. --}}
        <div class="sticky bottom-4 z-40">
        <x-editor.h-scrollbar />
        <div class="flex flex-wrap gap-4 justify-between items-center bg-gray-800 p-4 rounded-lg border border-gray-700">
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
                {{-- Small type: read once, then only glanced at, in a bar
                     pinned over the rows being edited (parity with the
                     edit-session and merge editors) --}}
                <div x-show="totalChanges === 0" class="text-gray-500 text-xs leading-snug space-y-0.5">
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
        </div>

        {{-- Hidden form for saving to server --}}
        <form method="POST" action="{{ $toLocal ? route('translations.merge-preview.apply-local', $translation) : route('translations.merge-preview.apply', $translation) }}" id="saveForm" class="hidden">
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
                {{-- Always with its line breaks, never subject to the display switch: this is the
                         reference you match while typing, and a translation is expected to keep the
                         original's breaks. The textarea below has always kept them. --}}
                    <p class="text-sm text-gray-400 font-mono mt-1 break-words whitespace-pre-wrap" x-text="editModal.key"></p>
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
        <span class="text-gray-600">|</span>
        {{-- The visual code for "what changed", as opposed to "which side wins" --}}
        <span><span class="tag-V ring-2 ring-amber-400/80 mr-1">V</span> {{ __('merge_preview.legend_tag_differs') }}</span>
        <span><span class="opacity-50 mr-1">Abc</span> {{ __('merge_preview.legend_same_text') }}</span>
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
        // Which columns the pin freezes here: the local file is this screen's reference, the
        // way Main is the merge view's (see js/components/editor-pin.js)
        pinTagCol: 'localTag',
        pinValueCol: 'local',
        localData: {},
        onlineData: {},
        onlineMetadata: {},
        // Fonts, images, exclusions, variables: reported, never merged here.
        // Split into three flat objects so the CSP-safe template can read
        // them with plain property paths (no expressions allowed there).
        settingsDiffer: false,
        settingsDiffFlags: {},
        settingsLocal: {},
        settingsOnline: {},
        // Setting-by-setting comparison. Only filled when the server holds both sides
        // (mod flow) — see the template for why the web flow keeps the summary only.
        // Which way this comparison runs. Reverses what counts as a change to send, and what
        // Delete means — see buildSaveForm.
        toLocal: @json($toLocal ?? false),
        settingsRows: [],
        settingsRowsReady: false,
        settingsSelections: {},
        showSettingsRows: false,
        // The CSP build evaluates property access, not expressions, so a template cannot
        // negate a flag: it needs the opposite as its own property.
        hideSettingsRows: true,
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
            const localMetadata = {};
            for (const [key, value] of Object.entries(content)) {
                if (key.startsWith('_')) {
                    localMetadata[key] = value;
                }
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

            this.settingsDiffer = this.compareSettings(localMetadata, this.onlineMetadata);
            if (this.settingsDiffer) {
                // Asked for only when something differs — the detail of settings that agree
                // would be a request made to display nothing
                this.loadSettingsRows();
            }

            this.calculateStats();
            this.applySmartDefaults();
            this.loaded = true;
        },

        /**
         * Which translation SETTINGS differ between local and online, and by
         * how many entries?
         *
         * Fonts, images, exclusions and variables travel in the file alongside
         * the lines, but they are not lines: there is no row to show, no side
         * to pick. They are edited in the mod and published by a full upload,
         * never from this page — so this reports, it never merges.
         *
         * It reports per SECTION though: "settings differ" alone gave the
         * reader nothing to act on. Sections mirror
         * Translation::SETTINGS_SECTIONS so both comparison screens speak the
         * same language, even though this one reads a file the mod just
         * uploaded while the merge view reads database columns.
         *
         * Sync bookkeeping (_uuid, _source, _local_changes...) is excluded: it
         * differs by construction and would cry wolf on every comparison.
         */
        compareSettings(local, online) {
            const SECTIONS = {
                fonts: '_fonts',
                font_rules: '_font_overrides',
                images: '_image_replacements',
                exclusions: '_exclusions',
                variables: '_variables',
                game_settings: '_settings',
            };

            const size = (value) => {
                if (Array.isArray(value)) return value.length;
                if (value && typeof value === 'object') return Object.keys(value).length;
                return 0;
            };

            const flags = {};
            const localCounts = {};
            const onlineCounts = {};
            let any = false;

            for (const [section, key] of Object.entries(SECTIONS)) {
                localCounts[section] = size(local[key]);
                onlineCounts[section] = size(online[key]);
                // Compare CONTENT, not just counts: swapping one font for
                // another leaves the count identical but is a real change
                const differs = JSON.stringify(local[key] ?? null) !== JSON.stringify(online[key] ?? null);
                flags[section] = differs;
                any = any || differs;
            }

            this.settingsDiffFlags = flags;
            this.settingsLocal = localCounts;
            this.settingsOnline = onlineCounts;

            return any;
        },

        /**
         * Load the setting-by-setting comparison. Served apart from the lines because those are
         * streamed without ever being decoded; settings are few, and extracting them server-side
         * keeps a single definition of what a setting is (the mod holds the other half of it).
         *
         * Failure is silent on purpose: the lines are the subject of this page, and losing the
         * settings detail must never cost the merge itself.
         */
        loadSettingsRows() {
            fetch('{{ route("translations.merge-preview.settings", $translation) }}', {
                headers: { 'Accept': 'application/json' }
            })
                .then(response => response.ok ? response.json() : null)
                .then(data => {
                    if (!data || !data.local) return;
                    this.buildSettingsRows(data.local, data.online || {});
                })
                .catch(() => {});
        },

        buildSettingsRows(local, online) {
            const labels = @js([
                'fonts' => __('file_settings.label.fonts'),
                'font_rules' => __('file_settings.label.font_rules'),
                'images' => __('file_settings.label.images'),
                'exclusions' => __('file_settings.label.exclusions'),
                'variables' => __('file_settings.label.variables'),
                'game_settings' => __('file_settings.game_settings'),
            ]);
            const absent = @js(__('merge_preview.settings_absent'));

            const rows = [];
            const keys = new Set([...Object.keys(local), ...Object.keys(online)]);

            for (const key of keys) {
                const l = local[key];
                const o = online[key];
                // Both sides identical: nothing to decide, and a row per unchanged setting would
                // bury the handful that actually moved
                if (l && o && l.value === o.value) continue;

                const meta = l || o;
                rows.push({
                    key,
                    section: meta.section,
                    sectionLabel: labels[meta.section] || meta.section,
                    label: meta.label,
                    localValue: l ? l.value : absent,
                    onlineValue: o ? o.value : absent,
                });
            }

            // Grouped by section, then by name: the order of a Set is insertion order, which
            // here means "whatever the JSON happened to hold"
            rows.sort((a, b) => a.section.localeCompare(b.section) || a.label.localeCompare(b.label));

            for (const row of rows) {
                // Default to the online side, like every other default on this page: the server
                // version is the one everybody else already has.
                if (!(row.key in this.settingsSelections)) {
                    this.settingsSelections[row.key] = 'online';
                }
            }

            this.settingsRows = rows;
            this.settingsRowsReady = rows.length > 0;
        },

        toggleSettingsRows() {
            this.showSettingsRows = !this.showSettingsRows;
            this.hideSettingsRows = !this.showSettingsRows;
        },

        selectSetting(key, side) {
            this.settingsSelections[key] = side;
        },

        settingCellClass(key, side) {
            return this.settingsSelections[key] === side
                ? 'bg-purple-900/40 text-purple-200'
                : 'text-gray-400 hover:bg-gray-750';
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
                    if (this.entriesDiffer(key)) {
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

        /**
         * Do the local and online entries for this key differ?
         *
         * Value OR tag: validating an AI line (A → V) leaves the wording
         * untouched yet is a change worth publishing, and the mod counts it as
         * one. Callers must have checked that the key exists on both sides.
         *
         * SINGLE SOURCE OF TRUTH — this comparison was previously rewritten in
         * five places (stats, filter, smart defaults, modified check, save).
         * Fixing some of them left the page counting a difference it then
         * filtered out as identical, and offering rows it would have refused to
         * send: exactly the "no differences found" on a file the mod was
         * offering to upload.
         */
        entriesDiffer(key) {
            return this.valueDiffers(key) || this.tagDiffers(key);
        },

        /** Halves of the rule above. Both assume the key exists on both sides. */
        valueDiffers(key) {
            return this.getValue(this.localData[key]) !== this.getValue(this.onlineData[key]);
        },

        tagDiffers(key) {
            return this.getTag(this.localData[key]) !== this.getTag(this.onlineData[key]);
        },

        /**
         * Display helpers — a row carries TWO pieces of information, and the
         * selection highlight only ever said which SIDE was kept, never which of
         * the two actually changed. A tag-only change left the text highlighted
         * as though the wording were at stake.
         *
         * So: dim what is identical, ring what differs. Nothing is added to the
         * screen; the eye simply lands on what matters. Safe on one-sided rows
         * (added or removed lines), where everything is relevant by definition.
         */
        valueUnchanged(key) {
            return key in this.localData && key in this.onlineData && !this.valueDiffers(key);
        },

        tagChangedBetweenSides(key) {
            return key in this.localData && key in this.onlineData && this.tagDiffers(key);
        },

        // ── Cell rendering with the difference underlined ─────────────────
        // One method per side rather than an expression in the template: the
        // CSP build of Alpine evaluates a restricted subset, and "which text
        // am I compared against" is a question worth naming anyway.
        //
        // A pending edit is what the user sees, so it is what gets compared —
        // otherwise the underline would describe a value no longer on screen.
        // A key present on one side only passes null: nothing is underlined,
        // because underlining a whole new line tells the reader nothing.

        localValueHtml(key) {
            const mine = this.isEdited(key) ? this.editedValues[key] : this.getValue(this.localData[key]);
            const other = key in this.onlineData ? this.getValue(this.onlineData[key]) : null;
            return this.highlightDifference(mine, other);
        },

        onlineValueHtml(key) {
            const mine = this.getValue(this.onlineData[key]);
            const other = key in this.localData
                ? (this.isEdited(key) ? this.editedValues[key] : this.getValue(this.localData[key]))
                : null;
            return this.highlightDifference(mine, other);
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
                    if (this.entriesDiffer(key)) {
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
                passesCategory = this.entriesDiffer(key) ? this.filters.different : this.filters.same;
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
            if (column === 'index') {
                return this.indexSortValue(this.orderIndexFor(key));
            }
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
                return this.entriesDiffer(key);
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
            this.focusRow(key);
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
                // Same condition as "will actually be sent" (see entriesDiffer),
                // so the previewed tag never promises a promotion the save drops
                if (!hasOnline || this.entriesDiffer(key)) {
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

                // Carry over the ordering index "i" — presentation metadata
                // that must survive the rebuild (local file is the authority)
                if (merged[key]) {
                    const idx = this.orderIndexFor(key);
                    if (idx !== undefined) merged[key].i = idx;
                }
            }

            return merged;
        },

        orderIndexFor(key) {
            for (const src of [this.localData[key], this.onlineData[key]]) {
                if (src && typeof src === 'object' && Number.isInteger(src.i) && src.i > 0) {
                    return src.i;
                }
            }
            return undefined;
        },

        /** Index cell text (local file is the authority, online fallback). */
        indexCellText(key) {
            const idx = this.orderIndexFor(key);
            return idx === undefined ? '' : String(idx);
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
                } else if (this.toLocal) {
                    // Comparing INTO the game: what has to travel is what comes from the online
                    // side, since the result starts from the player's own file. The publishing
                    // branch below sends the mirror image of this, and sending its version here
                    // would import nothing at all.
                    if (source === 'online' && hasOnline && (!hasLocal || this.entriesDiffer(key))) {
                        value = this.getValue(this.onlineData[key]);
                        tag = this.getTag(this.onlineData[key]);
                        sourceType = 'online';
                        isRealChange = true;
                    }
                } else if (hasLocal && !hasOnline && source === 'local') {
                    // Local-only key = addition
                    value = this.getValue(this.localData[key]);
                    tag = this.getTag(this.localData[key]);
                    sourceType = 'local';
                    isRealChange = true;
                } else if (hasLocal && hasOnline && source === 'local') {
                    // Both exist, local selected — send if the value OR the tag
                    // differs. Tag-only differences are real changes to publish
                    // (a validated line), and dropping them here would silently
                    // discard exactly what the row was showing.
                    if (this.entriesDiffer(key)) {
                        value = this.getValue(this.localData[key]);
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

            // Settings: only the winning SIDE is sent. The entry itself is copied server-side
            // from the file it belongs to — what this page displays is a readable summary that
            // drops fields it does not render, so rebuilding from it would strip them.
            //
            // Which side needs sending depends on where the result is built: publishing starts
            // from the server file, so only 'local' picks change anything; comparing into the
            // game starts from the player's file, so only 'online' picks do.
            const settingSideToSend = this.toLocal ? 'online' : 'local';
            let s = 0;
            for (const row of this.settingsRows) {
                const side = this.settingsSelections[row.key];
                if (side !== settingSideToSend) continue;

                const el = document.createElement('input');
                el.type = 'hidden';
                el.name = `settings[${row.key}]`;
                el.value = settingSideToSend;
                container.appendChild(el);
                s++;
            }

            if (i === 0 && d === 0 && s === 0) {
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
