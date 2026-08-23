@props([
    // Alpine component name the page registered on window.UGT.createViewer
    'component',
    'sourceLabel' => null,
    'targetLabel' => null,
])

{{--
    Reading a translation's lines — the same grid as the editors, minus every gesture that writes.

    It runs on the shared editor core (js/components/translation-viewer.js), so the search is live
    and highlights, the sort is client-side, rows arrive by "show more" rather than by page, the
    columns resize, the line-break switch works, and the workbench is one click away. Before this
    the reading screens had their own server-rendered filtering, paging and search: looking at a
    file behaved differently from editing it, which is one screen too many to learn.

    Shared by the public view and the admin inspection screen: same three columns, same file, and
    they differ only in who may reach them.
--}}
<div x-data="{{ $component }}" @keydown.window="handleEditorKeydown($event)">
    <div x-show="!loaded" class="text-center py-12">
        <i class="fas fa-spinner fa-spin text-4xl text-purple-400 mb-4"></i>
        <p class="text-gray-400">{{ __('merge_preview.loading') }}</p>
    </div>

    <div x-show="error" x-cloak class="bg-red-900/50 border border-red-600 rounded-lg p-6 text-center">
        <i class="fas fa-exclamation-triangle text-4xl text-red-400 mb-4"></i>
        <p class="text-red-300" x-text="error"></p>
    </div>

    <div x-show="loaded && !error" x-cloak>
        {{-- What this translation carries besides its lines.

             🔴 This screen is where somebody decides whether to TAKE a translation, and it could
             not tell them which fonts it replaces, which lines it leaves alone or where the images
             it needs live. The only way to find out was to download the file and open it — which
             is the decision they came here to make.

             ⚠ Read-only by construction, not by a flag: the block offers a gesture only when
             there is somebody to take from, and a reading screen has no contributions. Both start
             folded, since nothing is ever disputed here. --}}
        <x-editor.metadata-grid name="settings" :title="__('merge.block_file_settings')" />
        <x-editor.metadata-grid name="publication" :title="__('merge.block_description')" />

        @include('partials.editor-quality-bar')

        {{-- Nothing to filter on beyond the tags: reading a file offers no categories to sort
             rows into and no pending edits to single out. --}}
        <x-editor.filter-bar />

        @include('partials.editor-floating-search')

        {{-- No replace: this screen writes nothing --}}
        <x-editor.search-bar />

        <x-editor.workbench-bar />

        <div x-ref="gridBox"
             class="overflow-x-auto bg-gray-800 rounded-lg border border-gray-700"
             :class="wide && 'workbench-grid fixed inset-x-0 bottom-0 z-50 rounded-none border-0 overflow-auto'">
            <table class="editor-grid w-full text-sm border-separate border-spacing-0"
                   :class="[showLineBreaks && 'show-linebreaks', columnsSized && 'cols-sized']">
                <thead class="bg-gray-900 sticky top-0 z-20">
                    <tr>
                        <th x-show="showIndexColumn" x-cloak
                            class="px-2 py-3 text-right text-gray-400 font-medium w-16 min-w-[4rem] max-w-[4rem] cursor-pointer hover:text-white transition sticky left-0 z-30 bg-gray-900"
                            @click="toggleSort('index')" title="{{ __('editor.capture_order_hint') }}">
                            <div class="flex items-center justify-end gap-1">
                                <span class="text-xs">#</span>
                                <i class="fas text-xs" :class="getSortIcon('index')"></i>
                            </div>
                        </th>
                        {{-- No editor-text on a HEADER: it holds template markup, not a line of
                             the game, and pre-wrap would render the Blade indentation as visible
                             whitespace — which it did, three times the height it should be. --}}
                        <th data-col="key"
                            class="relative px-4 py-3 text-left text-gray-400 font-medium cursor-pointer hover:text-white transition sticky z-30 bg-gray-900 border-r border-gray-700 shadow-[4px_0_8px_-4px_rgba(0,0,0,0.6)]"
                            :class="showIndexColumn ? 'left-16' : 'left-0'"
                            @click="toggleSort('key')">
                            <div class="flex items-center gap-2">
                                {{ $sourceLabel ?? __('admin.original') }}
                                <i class="fas" :class="getSortIcon('key')"></i>
                            </div>
                            <x-editor.col-resize col="key" />
                        </th>
                        <th class="px-2 py-3 text-center border-l border-gray-700 w-12 cursor-pointer hover:text-white transition"
                            @click="toggleSort('tag')">
                            <div class="flex items-center justify-center gap-1">
                                <span class="text-gray-400 font-medium text-xs">Tag</span>
                                <i class="fas text-xs" :class="getSortIcon('tag')"></i>
                            </div>
                        </th>
                        {{-- min-w like the editors' value column, and for a reason this screen
                             showed plainly: with automatic layout a column is as wide as its
                             content, and a capture-only file has NO content here — so the source
                             column swallowed the width and the translation column shrank to the
                             word "Captures". The one thing a reader came for was the narrowest
                             thing on screen. --}}
                        <th data-col="value"
                            class="relative px-4 py-3 text-left border-l border-gray-700 min-w-[250px] cursor-pointer hover:text-white transition"
                            @click="toggleSort('value')">
                            <div class="flex items-center gap-2">
                                <span class="text-purple-400 font-medium">{{ $targetLabel ?? __('admin.translated') }}</span>
                                <i class="fas" :class="getSortIcon('value')"></i>
                            </div>
                            <x-editor.col-resize col="value" />
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="(key, idx) in visibleKeys" :key="key">
                        {{-- The whole row moves the cursor, not just the cells that already had a job.
                             Clicking a value both selects it and focuses the row, but
                             clicking the key did nothing — which reads as "you have to
                             click in the right place to pick a line", and forced a double
                             toggle on a value you did not want to change. Buttons inside
                             stop propagation, so their own actions are unaffected. --}}
                        <tr @click="focusRow(key)" class="cursor-default hover:bg-gray-750 transition-colors"
                            :class="isCurrentMatchRow(key) ? 'current-match-row' : ''"
                            :data-row-index="idx">
                            <td x-show="showIndexColumn" x-cloak
                                class="px-2 py-2 text-right font-mono text-xs text-gray-600 tabular-nums align-top sticky left-0 z-10 bg-gray-800 w-16 min-w-[4rem] max-w-[4rem]"
                                x-text="indexCellText(key)"></td>

                            <td data-col="key"
                                class="editor-text px-4 py-2 font-mono text-xs text-gray-500 sticky z-10 bg-gray-800 border-r border-gray-700 shadow-[4px_0_8px_-4px_rgba(0,0,0,0.6)]"
                                :class="showIndexColumn ? 'left-16' : 'left-0'"
                                x-safe-html="highlightKey(key)"></td>

                            <td class="px-2 py-2 text-center border-l border-gray-700">
                                <span :class="'tag-' + getTag(data[key]) + (isCaptureRow(key) ? ' opacity-40' : '')"
                                    x-text="getTag(data[key])"></span>
                            </td>

                            <td data-col="value" class="px-4 py-2 border-l border-gray-700 align-top">
                                <template x-if="isEmptyValue(key)">
                                    <span class="text-gray-600 italic">{{ __('progress.capture') }}</span>
                                </template>
                                <template x-if="!isEmptyValue(key)">
                                    <span class="editor-text" x-safe-html="valueHtml(key)"></span>
                                </template>
                            </td>
                        </tr>
                    </template>

                    <tr x-show="isEmptyResult" x-cloak>
                        <td :colspan="showIndexColumn ? 4 : 3" class="py-12 text-center text-gray-500">
                            {{-- Kept where the eye is, not where the table is: see .grid-visible-center --}}
                            <div class="grid-visible-center">
                            <i class="fas fa-search text-4xl mb-3 opacity-50"></i>
                            <p>{{ __('merge.no_keys_found') }}</p>
                            </div>
                        </td>
                    </tr>

                    {{-- "Show more", not pages: the same gesture as every editor --}}
                    <tr x-show="hiddenCount > 0" x-cloak>
                        <td :colspan="showIndexColumn ? 4 : 3" class="py-3 text-center">
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

        {{-- A viewer has nothing to save, but it still needs a bar at the bottom: it is what the
             mirrored horizontal scrollbar rides on, and where the tag legend belongs. --}}
        <div class="mt-6 sticky bottom-4 z-40">
            <x-editor.h-scrollbar />
            <div class="bg-gray-800 rounded-lg p-3 border border-gray-700 flex flex-wrap items-center gap-4 text-xs text-gray-500">
                <span><span class="tag-H">H</span> {{ __('merge.legend_human') }}</span>
                <span><span class="tag-V">V</span> {{ __('merge.legend_validated') }}</span>
                <span><span class="tag-A">A</span> {{ __('merge.legend_ai') }}</span>
                <span><span class="tag-S">S</span> {{ __('merge.legend_skipped') }}</span>
                <span><span class="tag-M">M</span> {{ __('merge.legend_mod_ui') }}</span>
                <span class="ml-auto text-gray-400 tabular-nums">
                    <span x-text="filteredKeys.length"></span> / <span x-text="allKeys.length"></span>
                </span>
            </div>
        </div>
    </div>
</div>
