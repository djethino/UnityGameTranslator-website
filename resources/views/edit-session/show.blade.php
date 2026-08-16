@extends('layouts.app')

@section('title', __('edit_session.title') . ($editSession->game_name ? ' - ' . $editSession->game_name : ''))

@section('content')
<div class="container mx-auto px-4 py-8" x-data="editSession" @keydown.window="handleEditorKeydown($event)">
    {{-- Header --}}
    <div class="mb-6">
        {{-- ⚠ Left of the title, before anything else is read: what this page writes to. A live
             edit session always writes to the machine on the other end — that is what a session
             IS — and nothing here is ever published. Saying it once, in the same control the mod
             and the manager show, is cheaper than a paragraph nobody reads. --}}
        <div class="flex items-center gap-3 flex-wrap">
            <x-editor.scope-badge side="local" :why="[
                'server' => __('edit_scope.why_page_is_local'),
                'both' => __('edit_scope.why_page_is_local'),
            ]" />
            <h1 class="text-2xl font-bold text-white">
                <i class="fas fa-pen-to-square text-purple-400 mr-2"></i>{{ __('edit_session.title') }}
            </h1>
        </div>
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

        {{-- Link state, permanently on screen: whether the game is listening
             decides whether saving means anything, so it must never be
             something the user has to go and find out. Hidden entirely while
             unknown — an absence of information is not a diagnosis. --}}
    </div>

    {{-- Live update toast (mod pushed changes from the game) — clicking it
         filters the table down to the rows that just arrived --}}
    <div x-show="refreshNotice" x-cloak @click="showSessionNew()"
        class="fixed top-4 right-4 z-50 bg-purple-900/90 border border-purple-600 rounded-lg px-4 py-3 text-purple-200 shadow-xl cursor-pointer hover:bg-purple-800/90 transition">
        <i class="fas fa-gamepad mr-2"></i><span x-text="refreshNotice"></span>
        <span class="block text-xs text-purple-300/80 mt-0.5">{{ __('edit_session.click_to_view') }}</span>
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

        @include('partials.editor-quality-bar')

        {{-- What the file carries besides its lines. Collapsed by default: this page is about
             the lines, and the panel answers a question you only ask sometimes — "why is this
             font different in game?", "which images does this translation replace?".
             Read-only: these are edited in the mod, the only side that sees the game. --}}
        <div x-show="hasSettings" x-cloak class="mb-4">
            <button type="button" @click="toggleSettingsPanel()"
                class="flex items-center gap-2 text-sm text-gray-400 hover:text-white transition">
                <i class="fas fa-sliders text-gray-500"></i>
                <span>{{ __('file_settings.section_title') }}</span>
                <i class="fas fa-chevron-down text-xs" x-show="showSettings" x-cloak></i>
                <i class="fas fa-chevron-right text-xs" x-show="hideSettings" x-cloak></i>
            </button>

            <div x-show="showSettings" x-cloak
                class="mt-2 bg-gray-800 border border-gray-700 rounded-lg px-4 py-3 overflow-x-auto">
                <table class="w-full text-xs">
                    <tbody>
                        <template x-for="row in settingsRows" :key="row.key">
                            <tr class="border-t border-gray-750 first:border-t-0">
                                <td class="px-2 py-1 align-top text-gray-500 whitespace-nowrap" x-text="row.sectionLabel"></td>
                                <td class="px-2 py-1 align-top text-gray-300 font-mono" x-text="row.label"></td>
                                <td class="px-2 py-1 align-top text-gray-400" x-text="row.value"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
                <p class="mt-2 text-xs text-gray-500 italic">{{ __('file_settings.section_hint') }}</p>
            </div>
        </div>

        {{-- Nothing ahead of the tags: a capture session holds rows of one kind. What follows
             them is what this screen alone can single out — edits waiting to be saved, and what
             the game has just sent. --}}
        <x-editor.filter-bar>
            <x-editor.filter-sep />

            <label class="flex items-center gap-2 cursor-pointer">
                <input type="checkbox" :checked="filters.pendingOnly" @change="toggleFilter('pendingOnly')"
                    class="rounded bg-gray-700 border-gray-600 text-purple-600">
                <span class="text-purple-400">{{ __('edit_session.pending_changes') }}</span>
            </label>

            {{-- Rows received from the game during this page session --}}
            <label class="flex items-center gap-2 cursor-pointer">
                <input type="checkbox" :checked="sessionNewOnly" @change="toggleSessionNewOnly()"
                    class="rounded bg-gray-700 border-gray-600 text-blue-600">
                <span class="text-blue-400">
                    <i class="fas fa-gamepad mr-1"></i>{{ __('edit_session.new_from_game') }}
                    (<span x-text="sessionNewCount"></span>)
                </span>
            </label>
        </x-editor.filter-bar>

        @include('partials.editor-floating-search')

        {{-- Search (Enter/Shift+Enter navigate matches) + replace --}}
        <x-editor.search-bar replace />

        {{-- The workbench strip, shared with the merge screens — see
             components/editor/workbench-bar.blade.php. Four columns never scroll sideways here,
             so what the mode buys is height: the whole window for the list of captures. --}}
        <x-editor.workbench-bar save="save()" save-disabled="saving || totalChanges === 0"
                                modified-filter="pendingOnly" save-label="{{ __('edit_session.save') }}">
            <label class="flex items-center gap-1 text-xs cursor-pointer shrink-0"
                   title="{{ __('edit_session.new_from_game') }}">
                <input type="checkbox" :checked="sessionNewOnly" @change="toggleSessionNewOnly()"
                       class="rounded bg-gray-700 border-gray-600 text-blue-600">
                <span class="text-blue-400"><i class="fas fa-gamepad"></i></span>
                <span class="text-blue-400 tabular-nums" x-text="sessionNewCount"></span>
            </label>
            <span class="w-px h-5 bg-gray-700 shrink-0"></span>
        </x-editor.workbench-bar>

        {{-- Table. An ordinary block that the page scrolls, until the workbench tears it out and
             hands it the window. --}}
        {{-- x-ref="gridBox" is not decoration: the shared core measures THIS element to know how
             much room the grid has. Without it a resize read a box of zero width, so narrowing a
             column had no slack to give away and left a gap at the right edge — the very defect
             fixed on the merge view, invisible here because the reference was missing rather
             than the code. The horizontal scrollbar mirror and the pinned column measure it too. --}}
        <div x-ref="gridBox"
             class="overflow-x-auto bg-gray-800 rounded-lg border border-gray-700 mb-6"
             :class="wide && 'fixed inset-x-0 bottom-0 top-12 z-50 mb-0 rounded-none border-0 overflow-auto'">
            {{-- border-separate, like the other editor grids: a browser does not paint the
                 background of a sticky cell under collapsed borders, and the frozen key column
                 would let the value column show through behind its own words. The line between
                 two entries then comes from .editor-grid rather than from the row. --}}
            <table class="editor-grid w-full text-sm border-separate border-spacing-0"
                   :class="[showLineBreaks && 'show-linebreaks', columnsSized && 'cols-sized']">
                <thead class="bg-gray-900 sticky top-0 z-20">
                    <tr>
                        {{-- Capture-order index (toggleable, sortable). Width PINNED, not
                             suggested: the key column freezes at a hard left-16 beside it. --}}
                        <th x-show="showIndexColumn" x-cloak
                            class="px-2 py-3 text-right text-gray-400 font-medium w-16 min-w-[4rem] max-w-[4rem] cursor-pointer hover:text-white transition sticky left-0 z-30 bg-gray-900"
                            @click="toggleSort('index')" title="{{ __('editor.capture_order_hint') }}">
                            <div class="flex items-center justify-end gap-1">
                                <span class="text-xs">#</span>
                                <i class="fas text-xs" :class="getSortIcon('index')"></i>
                            </div>
                        </th>
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
                        <th class="px-2 py-3 text-center border-l border-gray-700 w-12 cursor-pointer hover:text-white transition"
                            @click="toggleSort('tag')">
                            <div class="flex items-center justify-center gap-1">
                                <span class="text-gray-400 font-medium text-xs">Tag</span>
                                <i class="fas text-xs" :class="getSortIcon('tag')"></i>
                            </div>
                        </th>
                        {{-- min-w like every other grid: with automatic layout a column is as wide
                             as its content, and during a capture session this one is empty by
                             definition — the very thing being filled in would have been the
                             narrowest thing on screen. --}}
                        <th data-col="value"
                            class="relative px-4 py-3 text-left border-l border-gray-700 min-w-[250px] cursor-pointer hover:text-white transition"
                            @click="toggleSort('value')">
                            <div class="flex items-center gap-2">
                                <span class="text-purple-400 font-medium">{{ __('edit_session.translation_column') }}</span>
                                <i class="fas" :class="getSortIcon('value')"></i>
                            </div>
                            <x-editor.col-resize col="value" />
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
                                x-text="displayIndex(data[key])"></td>

                            {{-- Key. editor-text on the cell itself: its whole content is written
                                 by highlightKey, so there is no markup here whose indentation
                                 pre-wrap could turn into visible whitespace. --}}
                            <td data-col="key"
                                class="editor-text px-4 py-2 font-mono text-xs text-gray-500 sticky z-10 bg-gray-800 border-r border-gray-700 shadow-[4px_0_8px_-4px_rgba(0,0,0,0.6)]"
                                :class="showIndexColumn ? 'left-16' : 'left-0'"
                                x-safe-html="highlightKey(key)"></td>

                            {{-- Tag (clickable for tag change) --}}
                            <td class="px-2 py-2 text-center border-l border-gray-700"
                                :class="entryTagCellClass(key)">
                                {{-- Shows the tag the save will PRODUCE (edit → H,
                                     M/S preserved), not just the stored one --}}
                                <button type="button"
                                    @click.stop="openTagDropdown($event, key, displayTag(key, getTag(data[key])), getValue(data[key]))"
                                    class="transition rounded cursor-pointer hover:ring-2 hover:ring-purple-400 hover:ring-offset-1 hover:ring-offset-gray-800"
                                    title="{{ __('merge.click_to_change_tag') }}">
                                    <span :class="'tag-' + displayTag(key, getTag(data[key])) + (isCaptureRow(key) ? ' opacity-40' : '')" x-text="displayTag(key, getTag(data[key]))"></span>
                                </button>
                            </td>

                            {{-- Value: single click validates an AI line (A → V, same
                                 gesture as clicking Main in the merge view — a double
                                 click toggles twice, so editing never alters the tag),
                                 double-click or pencil to edit --}}
                            <td data-col="value" class="px-4 py-2 border-l border-gray-700 merge-cell"
                                :class="[isValidatedPending(key) ? 'selected-main' : '', isEdited(key) ? 'selected-manual' : '', isDeleted(key) ? 'deleted-cell' : '']"
                                @click="toggleValidate(key)"
                                @dblclick="editCell(key, getValue(data[key]))">
                                <span class="edit-affordance">
                                    {{-- Re-translate with the PLAYER's AI backend (request travels
                                         to the mod over SSE — the site holds no AI credential) --}}
                                    <button type="button" x-show="canRetranslate(key)" @click.stop="requestRetranslate(key)"
                                        title="{{ __('edit_session.retranslate') }}{{ $editSession->ai_model ? ' — ' . $editSession->ai_model : '' }}"><i class="fas fa-wand-magic-sparkles"></i></button>
                                    <button type="button" x-show="rowHasPending(key)" @click.stop="revertRow(key)"
                                        title="{{ __('merge.revert_row') }}"><i class="fas fa-undo"></i></button>
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
                                {{-- Non-blocking guard: the pending edit altered [!v*N] placeholders --}}
                                <span x-show="hasPlaceholderWarning(key)" x-cloak
                                    class="inline-block mb-1 px-1.5 py-0.5 rounded bg-orange-900/60 text-orange-300 text-xs"
                                    title="{{ __('merge.placeholder_warning') }}">
                                    <i class="fas fa-exclamation-triangle mr-1"></i>Placeholders
                                </span>
                                {{-- The player's AI is working on this line --}}
                                <span x-show="retranslating[key]" x-cloak
                                    class="inline-block mb-1 px-1.5 py-0.5 rounded bg-purple-900/60 text-purple-300 text-xs">
                                    <i class="fas fa-spinner fa-spin mr-1"></i>{{ __('edit_session.retranslating') }}
                                </span>
                                {{-- Arrived from the game during this page session --}}
                                <span x-show="sessionNew[key]" x-cloak
                                    class="inline-block mb-1 text-blue-400 text-xs"
                                    title="{{ __('edit_session.new_from_game') }}">
                                    <i class="fas fa-gamepad"></i>
                                </span>
                                <span class="break-words"
                                    :class="[isEdited(key) ? 'text-purple-300' : '', isDeleted(key) ? 'line-through opacity-40' : '']">
                                    <span class="editor-text" x-show="isEdited(key)" x-safe-html="highlightValue(editedValues[key])"></span>
                                    <span class="editor-text" x-show="!isEdited(key)" x-safe-html="highlightValue(getValue(data[key]))"></span>
                                </span>
                            </td>
                        </tr>
                    </template>

                    <tr x-show="filteredKeys.length === 0">
                        <td :colspan="showIndexColumn ? 4 : 3" class="py-12 text-center text-gray-500">
                            {{-- Kept where the eye is, not where the table is: see .grid-visible-center --}}
                            <div class="grid-visible-center">
                            <i class="fas fa-filter text-4xl mb-3 text-gray-600"></i>
                            <p>{{ __('edit_session.no_entries') }}</p>
                            </div>
                        </td>
                    </tr>

                    <tr x-show="hiddenCount > 0">
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

        {{-- Footer with Save button. min-w-0 on the text + shrink-0 on the
             buttons: the instructions wrap instead of squeezing the save button.
             ↑↓ shortcuts float at both ends of the bar --}}
        {{-- Sticky wrapper: the mirrored scrollbar has to ride WITH the bar, so both live in
             the same sticky block rather than the bar being sticky on its own. --}}
        <div class="sticky bottom-4 z-40">
        <x-editor.h-scrollbar />
        <div class="bg-gray-800 p-4 rounded-lg border border-gray-700">
        <div class="flex flex-wrap gap-4 justify-between items-center">
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
                </span>
                {{-- One line per gesture, with the same icons as the table --}}
                {{-- Smaller than the rest of the bar on purpose: these are
                     read once and then only glanced at, while the bar they sit
                     in is pinned over the rows being edited. Same treatment in
                     the merge and merge-preview editors. --}}
                <div x-show="totalChanges === 0 && !saveMessage" class="text-gray-500 text-xs leading-snug space-y-0.5">
                    <p>
                        <i class="fas fa-arrow-pointer w-4 text-center mr-1"></i>{{ __('edit_session.instructions_validate') }}
                        <span class="tag-A">A</span> <i class="fas fa-arrow-right text-xs"></i> <span class="tag-V">V</span>
                    </p>
                    <p><i class="fas fa-pen w-4 text-center mr-1"></i>{{ __('edit_session.instructions') }}</p>
                    <p><i class="fas fa-trash w-4 text-center mr-1"></i>{{ __('merge.instructions_delete') }}</p>
                    <p><i class="fas fa-keyboard w-4 text-center mr-1"></i>{{ __('merge.instructions_keyboard') }}</p>
                </div>
                {{-- Amber while the game is away: the save landed on the site,
                     but "applied in-game" would be a lie --}}
                <span x-show="saveMessage" :class="gameConnected === false ? 'text-amber-300' : 'text-green-400'">
                    <i class="fas mr-1" :class="gameConnected === false ? 'fa-clock' : 'fa-check-circle'"></i><span x-text="saveMessage"></span>
                </span>

                {{-- A retranslation that changed nothing. The success case needs no
                     line here: the row turns purple and the Save counter moves. --}}
                <span x-show="retranslateNotice" x-cloak class="text-amber-300">
                    <i class="fas fa-wand-magic-sparkles mr-1"></i><span x-text="retranslateNotice"></span>
                </span>
            </div>

            <div class="flex gap-4 items-center shrink-0">
                <button type="button" @click="clearAll()" x-show="totalChanges > 0"
                    class="text-gray-400 hover:text-white text-sm transition">
                    <i class="fas fa-times mr-1"></i> {{ __('merge_preview.cancel_changes') }}
                </button>

                <form method="POST" action="{{ route('edit-session.end', ['s' => $editSession->id]) }}"
                    data-confirm="{{ __('edit_session.end_confirm') }}">
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

        {{-- Link state, inside the sticky bar rather than up in the header:
             whether the game is listening decides whether saving means
             anything, so it has to stay in view — the header version scrolled
             away exactly when the user was deep in a long file.

             The two states are deliberately NOT symmetric. Connected is the
             normal case and stays a quiet line; disconnected has to be seen,
             so it keeps the amber panel the top banner used to have. Dressing
             both the same way makes the one that matters invisible. --}}
        <div x-show="gameConnected === true" x-cloak
            class="mt-3 pt-3 border-t border-gray-700 flex flex-wrap items-center gap-x-3 text-sm">
            <span class="inline-block w-2.5 h-2.5 rounded-full bg-green-500 shrink-0"></span>
            <span class="text-green-400">{{ __('edit_session.game_connected') }}</span>
            {{-- Shown even when connected: the game fetches within seconds, so
                 this normally flashes by — and if it lingers, that is worth
                 seeing. The number is bound separately from the wording:
                 translated text must never go through an Alpine expression. --}}
            <span x-show="pendingChanges > 0" x-cloak class="text-gray-400">
                <i class="fas fa-clock mr-1"></i><span x-text="pendingChanges"></span>
                {{ __('edit_session.pending_for_game') }}
            </span>
        </div>

        {{-- One line, not two stacked: this sits in a sticky bar, where every
             pixel of height is taken from the rows being edited.

             The amber is nearly opaque: at 40% it read fine against the page
             background but vanished inside this grey card. And the dot stays —
             it is the same indicator as the green one above, just red, so the
             eye reads the link state in one place whatever it says. --}}
        <div x-show="gameConnected === false" x-cloak
            class="mt-3 flex flex-wrap items-center gap-x-2 bg-amber-900/80 border border-amber-500 rounded-lg px-3 py-1.5">
            <span class="inline-block w-2.5 h-2.5 rounded-full bg-red-500 animate-pulse shrink-0"></span>
            <span class="text-amber-200 font-semibold text-sm shrink-0">{{ __('edit_session.game_disconnected') }}</span>
            <span class="text-amber-100/90 text-xs leading-snug">{{ __('edit_session.game_disconnected_hint') }}</span>
            {{-- What is actually at stake, in figures --}}
            <span x-show="pendingChanges > 0" x-cloak class="text-amber-100 text-xs font-semibold">
                <i class="fas fa-clock mr-1"></i><span x-text="pendingChanges"></span>
                {{ __('edit_session.pending_for_game') }}
            </span>
            {{-- Only true once everything has reached the game: while edits are
                 still waiting, the session is kept for the full day instead. --}}
            <span x-show="pendingChanges === 0" x-cloak class="text-amber-200/70 text-xs leading-snug italic">{{ __('edit_session.abandoned_warning') }}</span>
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
                {{-- Always with its line breaks, never subject to the display switch: this is the
                         reference you match while typing, and a translation is expected to keep the
                         original's breaks. The textarea below has always kept them. --}}
                    <p class="text-sm text-gray-400 font-mono mt-1 break-words whitespace-pre-wrap" x-text="editModal.key"></p>
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
                <p x-show="editModalPlaceholderMismatch" x-cloak class="mt-2 text-xs text-orange-400">
                    <i class="fas fa-exclamation-triangle mr-1"></i>{{ __('merge.placeholder_warning') }}
                </p>
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
        <span><span class="tag-H">H</span> {{ __('merge.legend_human') }}</span>
        <span><span class="tag-V">V</span> {{ __('merge.legend_validated') }}</span>
        <span><span class="tag-A">A</span> {{ __('merge.legend_ai') }}</span>
        <span><span class="tag-S">S</span> {{ __('merge.legend_skipped') }}</span>
        <span><span class="tag-M">M</span> {{ __('merge.legend_mod_ui') }}</span>
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
    Alpine.data('editSession', () => window.UGT.composeEditor({
        // UI state (filters/search) is shared across sessions of the same
        // browser tab; PENDING work is scoped to THIS session — restored
        // edits from a previous session would be ghost modifications on
        // keys this file may not even contain
        persistKey: 'edit_session_ui',
        // Widths are about this session's file, not about how one likes to read — see
        // translation-editor.js (_widthsKey)
        widthsKey: 'edit_session_{{ $editSession->id }}_cols',
        pendingKey: 'edit_session_{{ $editSession->id }}_pending',
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
        // What the file carries besides its lines — read-only, one source, nothing to arbitrate.
        // hideSettings mirrors showSettings: the CSP evaluator reads properties, it cannot negate.
        settingsRows: [],
        hasSettings: false,
        showSettings: false,
        hideSettings: true,
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
        // Retranslation outcomes that produce no visible row change ("same
        // answer", "nothing came back") — without this the spinner was the
        // only feedback, and it just stopped
        retranslateNotice: '',
        // Request ids already turned into pending edits by THIS page
        retranslateApplied: [],
        // Game presence, from the state poll. null until the first answer, and
        // whenever the server cannot tell — never rendered as a disconnection.
        gameConnected: null,
        _gameDownStreak: 0,
        // Edits saved here that the game has not fetched yet
        pendingChanges: 0,
        underlyingChanged: {},  // pending keys whose in-game value changed under the edit
        // Per-line AI retranslation, executed by the PLAYER's own backend:
        // the request travels to the mod over SSE, the result comes back
        // through the normal mod push. No AI credential ever touches the site.
        aiAvailable: @js((bool) $editSession->ai_available),
        retranslating: {},      // key -> request timestamp (visual state)
        // Keys received from the game during THIS page session (new AI
        // translations, in-game edits, retranslations) — reviewable through
        // a dedicated filter. Volatile on purpose: a refresh starts afresh.
        sessionNew: {},
        sessionNewOnly: false,  // not persisted: sessionNew dies with the page

        _sync: null,

        init() {
            // Search/filters/sort survive page refreshes (F5 keeps the
            // session alive by design — the UI state survives with it)
            this.initEditorCore();

            // Fetch + parse + normalize + diff all run in a Web Worker:
            // doing them here froze the main thread ~200ms on every mod
            // push (translation files can be tens of MB), stalling cursor
            // and clicks while the game translates
            this._sync = window.UGT.createLiveSync('{{ route("edit-session.data", ["s" => $editSession->id]) }}');
            this._sync.fetch()
                .then(result => {
                    // First fetch: the worker sends the full content,
                    // already normalized and metadata-stripped
                    this.data = result.full;
                    this.allKeys = Object.keys(result.full).sort();
                    this.loaded = true;
                    this.startLiveSync();
                    this.loadSettings();
                })
                .catch(e => {
                    this.error = e.message === 'expired'
                        ? @js(__('edit_session.error_expired'))
                        : @js(__('merge_preview.error_load_failed'));
                    this.loaded = true;
                });
        },

        /**
         * What the file carries besides its lines.
         *
         * Read-only and no side to pick: an edit session has ONE source, so there is nothing to
         * arbitrate — only something to know. Someone editing a file that swaps twenty images
         * should not have to open the mod to discover it.
         *
         * Silent on failure: the lines are the subject of this page, and losing this panel must
         * never cost the edit session.
         */
        loadSettings() {
            fetch('{{ route("edit-session.settings") }}', { headers: { 'Accept': 'application/json' } })
                .then(response => response.ok ? response.json() : null)
                .then(payload => {
                    if (!payload || !payload.settings) return;

                    const labels = @js([
                        'fonts' => __('file_settings.label.fonts'),
                        'font_rules' => __('file_settings.label.font_rules'),
                        'images' => __('file_settings.label.images'),
                        'exclusions' => __('file_settings.label.exclusions'),
                        'variables' => __('file_settings.label.variables'),
                        'game_settings' => __('file_settings.game_settings'),
                    ]);

                    const rows = Object.entries(payload.settings).map(([key, entry]) => ({
                        key,
                        sectionLabel: labels[entry.section] || entry.section,
                        label: entry.label,
                        value: entry.value,
                    }));
                    rows.sort((a, b) => a.sectionLabel.localeCompare(b.sectionLabel)
                        || a.label.localeCompare(b.label));

                    this.settingsRows = rows;
                    this.hasSettings = rows.length > 0;
                })
                .catch(() => {});
        },

        toggleSettingsPanel() {
            this.showSettings = !this.showSettings;
            this.hideSettings = !this.showSettings;
        },

        // ── Shared-core callbacks ────────────────────────────────────────

        rowPassesFilters(key) {
            if (this.sessionNewOnly && !this.sessionNew[key]) {
                return false;
            }
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
            if (column === 'index') {
                return this.indexSortValue(this.getOrderIndex(this.data[key]));
            }
            if (column === 'tag') {
                return this.getTag(this.data[key]);
            }
            // 'value' — sort on the stored value: a pending edit must not
            // make the row jump around while the user is still working
            return this.getValue(this.data[key]).toLowerCase();
        },

        /** Core hook: the stored editable value (replace, placeholder guard). */
        storedValue(key) {
            return this.getValue(this.data[key]);
        },

        /** Core hook: projected tag for the quality bar. */
        rowQualityTag(key) {
            return this.displayTag(key, this.getTag(this.data[key]));
        },

        /** Core hook: V on the cursor row = the click-to-validate gesture. */
        cursorPrimaryAction(key) {
            this.toggleValidate(key);
        },

        // ── "New from the game" review filter ─────────────────────────────

        get sessionNewCount() {
            return Object.keys(this.sessionNew).length;
        },

        toggleSessionNewOnly() {
            this.sessionNewOnly = !this.sessionNewOnly;
        },

        /** Toast click: focus the table on what just arrived. */
        showSessionNew() {
            this.sessionNewOnly = true;
            this.refreshNotice = '';
            this.scrollToTop();
        },

        // ── Per-line AI retranslation (player's own backend, via the mod) ──

        canRetranslate(key) {
            return this.aiAvailable && !this.isEdited(key) && !this.isDeleted(key)
                && !this.retranslating[key];
        },

        /**
         * The site relays the key to the mod over SSE, the mod re-translates
         * with the player's configured backend and pushes its file back —
         * the result lands through the normal applyDiff. SSE delivery is not
         * guaranteed (the mod's stream reconnects through gaps and events
         * emitted during a gap are lost), so the request is RE-EMITTED every
         * 30s while still pending, always with the same id: the mod
         * deduplicates on it, and a lost emission is simply caught by the
         * next one. The visual pending state frees itself after 3 minutes
         * if the mod never answers.
         */
        requestRetranslate(key) {
            if (!this.canRetranslate(key)) return;
            this.focusRow(key);

            // A line somebody typed themselves is one click away from being replaced by a
            // machine, with nothing keeping the previous text. Worth asking.
            if (this.getTag(this.data[key]) === 'H'
                && !confirm(@js(__('edit_session.retranslate_human_confirm')))) {
                return;
            }

            this.retranslating[key] = Date.now();
            this._scheduleNextPoll(); // switch to the fast poll right away

            const requestId = Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10);
            const emit = () => fetch('{{ route("edit-session.retranslate", ["s" => $editSession->id]) }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}'
                },
                body: JSON.stringify({ key: key, id: requestId })
            });

            emit()
                .then(response => {
                    if (!response.ok) throw new Error('request_failed');
                })
                .catch(() => {
                    delete this.retranslating[key];
                    this._scheduleNextPoll();
                });

            const retryTimer = setInterval(() => {
                if (!this.retranslating[key]) {
                    clearInterval(retryTimer);
                    return;
                }
                emit().catch(() => { /* the next retry or the timeout handles it */ });
            }, 30000);

            setTimeout(() => {
                clearInterval(retryTimer);
                if (this.retranslating[key]) {
                    delete this.retranslating[key];
                    this._scheduleNextPoll();
                }
            }, 180000);
        },

        /**
         * A retranslation came back. It is a PROPOSAL, not a change: it is
         * staged exactly like something typed here — purple row, counted in
         * the Save button, dropped by Cancel changes — so the same Apply
         * governs it. Nothing was written in the game, and nothing will be
         * until this page saves.
         *
         * Entries are served repeatedly (the server does not consume them, so
         * two tabs both get them); `retranslateApplied` is what makes each
         * page act once. It rides in the pending state, so a refresh does not
         * resurrect a proposal that was cancelled before it.
         */
        applyRetranslations(items) {
            let staged = 0;

            for (const item of items) {
                if (!item || !item.id || this.retranslateApplied.includes(item.id)) continue;
                this.retranslateApplied.push(item.id);
                delete this.retranslating[item.key];

                if (item.outcome === 'replaced' && typeof item.value === 'string' && item.key in this.data) {
                    // stageEdit drops the edit by itself if the value equals what
                    // is stored — a proposal identical to the line is not a change
                    this.stageEdit(item.key, item.value, this.getValue(this.data[item.key]));
                    this.focusRow(item.key);
                    staged++;
                } else if (item.outcome === 'unchanged') {
                    this.retranslateNotice = @js(__('edit_session.retranslate_unchanged'));
                } else if (item.outcome === 'failed') {
                    this.retranslateNotice = @js(__('edit_session.retranslate_failed'));
                }
            }

            // Bounded: one entry per request for the life of the page, and the
            // server forgets them after a few minutes anyway
            if (this.retranslateApplied.length > 50) {
                this.retranslateApplied = this.retranslateApplied.slice(-50);
            }

            if (staged > 0) this.retranslateNotice = '';
            if (this.retranslateNotice) {
                setTimeout(() => { this.retranslateNotice = ''; }, 6000);
            }

            this.persistPendingState();
            this._scheduleNextPoll();
        },

        /** Applied proposals ride with the pending work they became. */
        pendingExtraState() {
            return { retranslateApplied: this.retranslateApplied };
        },

        restorePendingExtra(extra) {
            if (extra && Array.isArray(extra.retranslateApplied)) {
                this.retranslateApplied = extra.retranslateApplied;
            }
        },

        // ── Click-to-validate (parity with the merge view's Main click) ──

        /**
         * The tag cell's marker: set when the save will store a tag other than the one on file.
         *
         * 🔴 Was `hasTagChange(key)` — an EXPLICIT change through the dropdown, and nothing else.
         * A tag also changes by being edited (a rewritten line becomes human) and by being
         * validated. Those rows changed tag with nothing said. The question is not how the tag
         * came to change, it is whether it did.
         *
         * ⚠ Same rule and same class name as the two comparison screens: one gesture, one mark,
         * whichever editor you are in.
         */
        entryTagCellClass(key) {
            const stored = this.data[key];
            if (stored === undefined) return '';
            const tag = this.getTag(stored);
            return tag === this.displayTag(key, tag) ? '' : 'tag-changed-cell';
        },

        /** The row carries a pending validation (previewed V, green cell). */
        isValidatedPending(key) {
            return this.hasTagChange(key) && this.tagChanges[key].newTag === 'V';
        },

        /**
         * Single click on an AI-tagged line stages its validation (A → V)
         * as a regular tag change; clicking again cancels it.
         * A click only ever produces a REAL change (see
         * analyse/editors-gestures-parity.md): already-V lines, H/M/S
         * tags, pending edits and deleted rows are left alone —
         * devalidating is an explicit tag-dropdown gesture, never a click.
         */
        toggleValidate(key) {
            // Even on inert rows the click moves the search cursor (IDE caret)
            this.focusRow(key);
            if (this.isDeleted(key) || this.isEdited(key)) return;
            if (this.isValidatedPending(key)) {
                this.cancelTagChange(key);
                return;
            }
            const tag = this.getTag(this.data[key]);
            if (tag !== 'A') return;
            this.tagChanges[key] = { newTag: 'V', originalTag: tag, value: this.getValue(this.data[key]) };
            this.persistPendingState();
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

        /**
         * Read the game's presence out of a state poll.
         *
         * The mod's open stream is authoritative — a game that dies takes its
         * connection with it, whatever it did or failed to do on the way out.
         * When the server cannot tell (Redis down), fall back on the last call
         * the mod made, so an infrastructure hiccup never paints a running game
         * as gone.
         */
        applyGamePresence(state) {
            const connected = typeof state.game_connected === 'boolean'
                ? state.game_connected
                : (typeof state.game_responding === 'boolean' ? state.game_responding : null);

            if (connected === null) {
                this.gameConnected = null;
                return;
            }

            if (connected) {
                this._gameDownStreak = 0;
                this.gameConnected = true;
                return;
            }

            // The mod reconnects on its own within seconds, and is briefly
            // absent while it does. Two readings in a row before calling it
            // disconnected, so a reconnection does not flash the light red.
            this._gameDownStreak++;
            if (this._gameDownStreak >= 2) {
                this.gameConnected = false;
            }
        },

        startLiveSync() {
            this._scheduleNextPoll();
            this.checkState(); // seed currentHash immediately

            // Tell the mod when the page goes away (close, navigation —
            // also fires on refresh, which the mod absorbs with its grace
            // period; the next state poll signals the rejoin)
            window.addEventListener('pagehide', () => {
                navigator.sendBeacon('{{ route("edit-session.leave", ["s" => $editSession->id]) }}');
            });
        },

        /**
         * Self-rescheduling poll: 2s while a retranslation is pending (the
         * user is actively waiting on the mod's push), 10s otherwise.
         */
        _scheduleNextPoll() {
            clearTimeout(this.pollTimer);
            const delay = Object.keys(this.retranslating).length > 0 ? 2000 : 10000;
            this.pollTimer = setTimeout(() => {
                this.checkState();
                this._scheduleNextPoll();
            }, delay);
        },

        stopLiveSync() {
            if (this.pollTimer) {
                clearTimeout(this.pollTimer);
                this.pollTimer = null;
            }
        },

        checkState() {
            fetch('{{ route("edit-session.state", ["s" => $editSession->id]) }}', { headers: { 'Accept': 'application/json' } })
                .then(response => {
                    if (!response.ok) {
                        throw new Error(response.status === 410 ? 'expired' : 'state_failed');
                    }
                    return response.json();
                })
                .then(state => {
                    // The player can toggle the mod's AI backend mid-session
                    if (typeof state.ai_available === 'boolean') {
                        this.aiAvailable = state.ai_available;
                    }
                    this.applyGamePresence(state);
                    if (typeof state.pending_changes === 'number') {
                        this.pendingChanges = state.pending_changes;
                    }
                    // Before the hash check, which returns early on the very first
                    // poll: a proposal that landed while the page was loading has
                    // nothing to do with the file having changed — it changed nothing.
                    if (Array.isArray(state.retranslations) && state.retranslations.length) {
                        this.applyRetranslations(state.retranslations);
                    }
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
            this._sync.fetch()
                .then(result => this.applyDiff(result.changed || {}, result.removed || []))
                .catch(() => { /* next poll retries */ });
        },

        /**
         * Apply the worker's diff. Entries identical to what we already
         * display are skipped: after OUR OWN save the worker's cache lags
         * behind and re-reports the saved entries — they must not count
         * as "updated from game".
         */
        applyDiff(changed, removed) {
            const pendingKeys = new Set([
                ...Object.keys(this.editedValues),
                ...Object.keys(this.tagChanges)
            ]);

            let changedCount = 0;
            let keysChanged = false;
            for (const [key, value] of Object.entries(changed)) {
                if (key in this.data
                    && this.getValue(value) === this.getValue(this.data[key])
                    && this.getTag(value) === this.getTag(this.data[key])) {
                    continue;
                }
                // Flag pending keys whose in-game value changed under the
                // edit — the pending edit stays displayed and wins at save
                // (human > AI), the badge lets the user double-check
                if (pendingKeys.has(key) && key in this.data
                    && this.getValue(value) !== this.getValue(this.data[key])) {
                    this.underlyingChanged[key] = true;
                }
                // A requested retranslation came back through the mod's push
                if (this.retranslating[key]) {
                    delete this.retranslating[key];
                    this._scheduleNextPoll();
                }
                // Reviewable through the "new from the game" filter
                this.sessionNew[key] = true;
                if (!(key in this.data)) keysChanged = true;
                this.data[key] = value;
                changedCount++;
            }
            for (const key of removed) {
                if (key in this.data) {
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

            fetch('{{ route("edit-session.save", ["s" => $editSession->id]) }}', {
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
                        // Keep the ordering index "i" of the previous entry
                        // (the server-side save preserves it the same way)
                        const prevEntry = this.data[sel.key];
                        const newEntry = { v: sel.value, t: tag };
                        if (prevEntry && typeof prevEntry === 'object' && Number.isInteger(prevEntry.i)) {
                            newEntry.i = prevEntry.i;
                        }
                        this.data[sel.key] = newEntry;
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
                    this.saveMessage = this.gameConnected === false
                        ? @js(__('edit_session.saved_pending_game'))
                        : @js(__('edit_session.saved_ok'));
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
