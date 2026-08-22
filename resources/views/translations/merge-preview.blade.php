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
        {{-- ⚠ Which way this comparison runs decides far more than a form action — what counts as
             a change is reversed, and Delete stops meaning "remove it from the server" to mean
             "remove it from my file". That was known only by reading the buttons. It is said
             here first, in the control the mod and the manager show for the same question. --}}
        <div class="flex items-center gap-3 flex-wrap">
            <x-editor.scope-badge :side="$toLocal ? 'local' : 'server'" :why="$toLocal
                ? ['server' => __('edit_scope.why_page_is_local'), 'both' => __('edit_scope.why_page_is_local')]
                : ['local' => __('edit_scope.why_page_is_server'), 'both' => __('edit_scope.why_page_is_server')]" />
            <h1 class="text-2xl font-bold text-white">{{ __('merge_preview.title') }}</h1>
        </div>
        <p class="text-gray-400">
            {{ $translation->game->name }} &bull;
            <x-language-mark :language="$translation->source_language" named /> {{ $translation->source_language }}
            <i class="fas fa-arrow-right text-xs"></i>
            <x-language-mark :language="$translation->target_language" named /> {{ $translation->target_language }}
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

    @php
        // 🔴 **The left-hand side is always the result being built** — the file this screen is about
        // to write. It is the local one when comparing into the game, the server's one when
        // publishing, and putting it first is what makes this screen read like the merge view and
        // the live editor rather than like its own dialect.
        //
        // ⚠ Written once, here, and read by the head tiles, the filter boxes, the workbench strip,
        // the select-all buttons, the header row and the body row. Each of those used to spell the
        // two sides out for itself, which is how they were free to disagree — and they did: the
        // tiles and the boxes listed the local file first while the grid below put it second.
        //
        // What follows the SIDE (colour, label, icon, its own counter) and what follows the ROLE
        // (its place, and which situation its box hides) are separated on purpose — see
        // x-editor.side-head.
        $local = [
            'id' => 'local',
            'label' => __('merge_preview.local_file'),
            'byline' => null,
            'target' => $toLocal,
            'tone' => 'text-green-400',
            'box' => 'text-green-600',
            'border' => 'border-green-700',
            'icon' => 'fa-desktop',
            // The box hides "only this side holds it" — which IS the `new` situation when this side
            // is the one offering, and `onlyOnTarget` when it is the one being written.
            'filter' => $toLocal ? 'catOnlyOnTarget' : 'catNew',
            'stat' => 'localOnly',
            'onlyLabel' => __('merge_preview.local_only'),
            'selectAllLabel' => __('merge_preview.select_all_local'),
        ];
        $online = [
            'id' => 'online',
            'label' => __('merge_preview.online_version'),
            'byline' => $translation->user->name,
            'target' => !$toLocal,
            'tone' => 'text-blue-400',
            'box' => 'text-blue-600',
            'border' => 'border-blue-700',
            'icon' => 'fa-cloud',
            'filter' => $toLocal ? 'catNew' : 'catOnlyOnTarget',
            'stat' => 'onlineOnly',
            'onlyLabel' => __('merge_preview.online_only'),
            'selectAllLabel' => __('merge_preview.select_all_online'),
        ];
        $sides = $toLocal ? [$local, $online] : [$online, $local];
    @endphp

    {{-- Main content --}}
    <div x-show="loaded && !error" x-cloak>
        {{-- Stats --}}
        <div class="mb-6 grid grid-cols-2 md:grid-cols-5 gap-4">
            <div class="bg-gray-800 rounded-lg p-4 border border-gray-700 text-center">
                <p class="text-2xl font-bold text-white" x-text="stats.total"></p>
                <p class="text-sm text-gray-400">{{ __('merge_preview.total_keys') }}</p>
            </div>
            {{-- Target first, like the boxes below and the columns below those: three blocks
                 listing the same two sides, and a reader learns their order once. --}}
            @foreach ($sides as $side)
                <div class="bg-gray-800 rounded-lg p-4 border {{ $side['border'] }} text-center">
                    <p class="text-2xl font-bold {{ $side['tone'] }}" x-text="stats.{{ $side['stat'] }}"></p>
                    <p class="text-sm text-gray-400">{{ $side['onlyLabel'] }}</p>
                </div>
            @endforeach
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
            {{-- Setting by setting, on this screen's own columns.

                 🔴 The same block as the merge view, the reading screens and the live editor,
                 rather than a fourth hand-written table. It reads local/localTag/online here
                 because widths are written per column name — a block that assumed another
                 screen's names would float free of the lines below it.

                 ⚠ Values stay read-only: a font or an exclusion is edited in the mod, never on a
                 web page. What IS offered is which side wins, which is exactly what a comparison
                 has to ask — so this screen opts into taking, and the ones that only read do not.

                 ⚠ Only the mod flow leaves the local file on the server; the web flow keeps it in
                 the browser, so there is nothing to compare against and the summary above stays
                 the whole story there. --}}
            <div class="mt-3 pt-3 border-t border-gray-700">
                {{-- ⚠ The TARGET's columns, not the local file's: these two tables align by column
                     NAME, so naming a column the lines grid no longer carries would let this block
                     float free of the lines it sits above. --}}
                <x-editor.metadata-grid name="settings"
                    :title="__('merge_preview.settings_show_detail')"
                    :hint="__('merge_preview.settings_pick_hint')"
                    :value-col="$toLocal ? 'local' : 'online'"
                    :tag-col="$toLocal ? 'localTag' : 'onlineTag'"
                    :mine-label="$toLocal ? __('merge_preview.local_file') : __('merge_preview.online_version')"
                    :mine-tone="$toLocal ? 'text-green-400' : 'text-blue-400'"
                    :other-span="1" />
            </div>
        </div>

        @include('partials.editor-quality-bar')

        {{-- Ahead of the tags, where a row COMES FROM — the question this screen exists to
             answer. After them, what to do with the answer.

             ⚠ **In the columns' order, target first**, because these boxes ARE the columns: each
             one hides the rows only that side holds, and reading them left to right in one order
             while the grid below reads them in the other is a layout somebody has to re-learn at
             every glance. Same list, one order — see $sides. --}}
        <x-editor.filter-bar>
            <x-slot:before>
                @foreach ($sides as $side)
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" :checked="filters.{{ $side['filter'] }}"
                            @change="toggleFilter('{{ $side['filter'] }}')"
                            class="rounded bg-gray-700 border-gray-600 {{ $side['box'] }}">
                        <span class="{{ $side['tone'] }}">{{ $side['onlyLabel'] }}</span>
                    </label>
                @endforeach

                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" :checked="filters.catDiffering" @change="toggleFilter('catDiffering')"
                        class="rounded bg-gray-700 border-gray-600 text-yellow-600">
                    <span class="text-yellow-400">{{ __('merge_preview.different') }}</span>
                </label>

                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" :checked="filters.catSame" @change="toggleFilter('catSame')"
                        class="rounded bg-gray-700 border-gray-600 text-gray-600">
                    <span class="text-gray-400">{{ __('merge_preview.same') }}</span>
                </label>

                <x-editor.filter-sep />
            </x-slot:before>

            <x-editor.filter-sep />

            <label class="flex items-center gap-2 cursor-pointer">
                <input type="checkbox" :checked="filters.modifiedOnly" @change="toggleFilter('modifiedOnly')"
                    class="rounded bg-gray-700 border-gray-600 text-purple-600">
                <span class="text-purple-400">{{ __('merge_preview.modifications') }}</span>
            </label>

            <x-editor.filter-sep />

            {{-- Same order as the columns and as the boxes above: take everything from the result
                 being built, or everything from what is being offered. --}}
            @foreach ($sides as $side)
                <button type="button" @click="selectAllFrom('{{ $side['id'] }}')"
                    class="{{ $side['tone'] }} hover:text-white">
                    <i class="fas fa-check-double mr-1"></i> {{ $side['selectAllLabel'] }}
                </button>
            @endforeach
        </x-editor.filter-bar>

        @include('partials.editor-floating-search')

        {{-- Search (Enter/Shift+Enter navigate matches) + replace --}}
        <x-editor.search-bar replace />

        {{-- The workbench strip, shared with the merge view — see
             components/editor/workbench-bar.blade.php. Only the category filters differ from one
             screen to the next, so only those are passed in. --}}
        <x-editor.workbench-bar save="submitResult()" save-disabled="saving || totalChanges === 0">
            @foreach ($sides as $side)
                <label class="flex items-center gap-1 text-xs cursor-pointer shrink-0" title="{{ $side['onlyLabel'] }}">
                    <input type="checkbox" :checked="filters.{{ $side['filter'] }}"
                           @change="toggleFilter('{{ $side['filter'] }}')"
                           class="rounded bg-gray-700 border-gray-600 {{ $side['box'] }}">
                    <span class="{{ $side['tone'] }}"><i class="fas {{ $side['icon'] }}"></i></span>
                </label>
            @endforeach
            <label class="flex items-center gap-1 text-xs cursor-pointer shrink-0" title="{{ __('merge_preview.different') }}">
                <input type="checkbox" :checked="filters.catDiffering" @change="toggleFilter('catDiffering')"
                       class="rounded bg-gray-700 border-gray-600 text-yellow-600">
                <span class="text-yellow-400">≠</span>
            </label>
            <label class="flex items-center gap-1 text-xs cursor-pointer shrink-0" title="{{ __('merge_preview.same') }}">
                <input type="checkbox" :checked="filters.catSame" @change="toggleFilter('catSame')"
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
                        <x-editor.head-index />
                        <x-editor.head-key />
                        @foreach ($sides as $side)
                            <x-editor.side-head :side="$side['id']" :target="$side['target']"
                                                :label="$side['label']" :byline="$side['byline']" />
                        @endforeach
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
                            <x-editor.cell-index />
                            <x-editor.cell-key />

                            @foreach ($sides as $side)
                                <x-editor.side-cells :side="$side['id']" :target="$side['target']" />
                            @endforeach
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
                {{-- Proposing and cancelling are two acts, and the same two buttons as the merge
                     view — in the same order, so the gesture is learnt once.

                     Shown only while something is left to answer: a button that does nothing is a
                     button that teaches nothing. --}}
                <button type="button" @click="suggestTheRest()"
                    x-show="undecidedCount > 0" x-cloak
                    class="text-gray-400 hover:text-white text-sm transition">
                    <i class="fas fa-wand-magic-sparkles mr-1"></i> {{ __('merge.suggest_rest') }}
                </button>
                <button type="button" @click="clearAll()" x-show="totalChanges > 0"
                    class="text-gray-400 hover:text-white text-sm transition">
                    <i class="fas fa-times mr-1"></i> {{ __('merge_preview.cancel_changes') }}
                </button>

                <button type="button" @click="downloadMerged()"
                    class="bg-blue-600 hover:bg-blue-700 px-4 py-2 rounded-lg text-white transition">
                    <i class="fas fa-download mr-2"></i> {{ __('merge_preview.download_merged') }}
                </button>

                {{-- 🔴 **The button names where the result GOES, and the two places are not the
                     same.** It said "Save to server" whichever way the comparison ran, three inches
                     under a banner reading "Nothing is published" — and nothing does go to the
                     server that way round: the result waits in the token's own file for the mod to
                     collect it. One verb, one destination, per direction. --}}
                <button type="button" @click="submitResult()" :disabled="saving || totalChanges === 0"
                    class="bg-green-600 hover:bg-green-700 disabled:bg-gray-600 disabled:cursor-not-allowed px-6 py-3 rounded-lg text-white font-bold transition">
                    <i class="fas mr-2" :class="saving ? 'fa-spinner fa-spin' : '{{ $toLocal ? 'fa-download' : 'fa-save' }}'"></i>
                    {{ $toLocal ? __('merge_preview.send_to_game') : __('merge_preview.save_to_server') }}
                    (<span x-text="totalChanges">0</span>)
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
    {{-- x-ref so the core can measure it and keep it inside the window. --}}
    <div x-show="tagDropdown.open" x-cloak x-ref="tagMenu"
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
        // ⚠ One view whichever way it runs: the two directions show the same two columns and answer
        // the same question, only the target swaps. What must not be shared is the SITTING, and the
        // core sees to that (see workSessionId) — reopening a comparison starts a fresh one.
        view: 'preview',
        scope: '{{ $translation->id }}',
        // 🔴 Open on what needs DECIDING: what the other side is offering, and where the two
        // disagree. What only the result already holds, and what both say identically, ask nothing.
        //
        // ⚠ Written per situation, not per column, and that fixed a real refusal: it used to read
        // `localOnly: true, onlineOnly: false` ("already on server, nothing to merge") — true when
        // publishing and exactly backwards the other way, where the screen opened with the server's
        // new lines HIDDEN. Those are the lines somebody opened it to fetch.
        filters: {
            catNew: true,
            catOnlyOnTarget: false,
            catDiffering: true,
            catSame: false,
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
        // Which columns the pin freezes here: the TARGET pair, the way Main is the merge view's
        // (see js/components/editor-pin.js). ⚠ Written from PHP rather than derived from
        // `targetSource()`: the pin reads these before the component is alive, and freezing the
        // column somebody is not building would keep the wrong half of the row in sight.
        pinTagCol: @json($toLocal ? 'localTag' : 'onlineTag'),
        pinValueCol: @json($toLocal ? 'local' : 'online'),
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

        /**
         * The side being OFFERED, as the shared block names its other columns — the local file when
         * publishing, the server's version when comparing into the game.
         *
         * ⚠ One column, and it carries no tag of its own — hence other-span="1" above. The merge
         * view's contributions carry a tag beside their value and take two.
         */
        metaOtherColumns() {
            const id = this.sourceIds()[0];
            return [{ id, col: id, name: this.sideLabel(id), tone: this.sideTone(id) }];
        },

        /** The two columns' names, in one place: the header, the settings grid and the hints. */
        sideLabel(id) {
            return id === 'local'
                ? @js(__('merge_preview.local_file'))
                : @js(__('merge_preview.online_version'));
        },

        /** ⚠ The colour names the FILE, so it travels with the side and never with the role. */
        sideTone(id) {
            return id === 'local' ? 'text-green-400' : 'text-blue-400';
        },

        /**
         * This screen arbitrates two sides, so it takes. The shared default is no, because
         * showing a second column and being allowed to take from it are different questions —
         * the reading screens show one and grant nothing.
         */
        canTakeContributions() {
            return this.hasSettingsRows;
        },

        settingsTake(row, otherId) {
            this.settingsPick = { ...this.settingsPick, [row.id]: otherId };
        },

        settingsKeepMine(row) {
            this.settingsPick = { ...this.settingsPick, [row.id]: 'main' };
        },

        settingsCellClass(row, otherId) {
            const picked = this.settingsPick[row.id];
            if (otherId === null) return picked === 'main' ? 'selected-local' : '';
            return picked === otherId ? 'selected-online' : '';
        },

        settingsTakenCount() {
            return Object.keys(this.settingsPick).length;
        },
        // The CSP build evaluates property access, not expressions, so a template cannot
        // negate a flag: it needs the opposite as its own property.
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
                    this.settingsFromBothSides(data.local, data.online || {});
                })
                .catch(() => {});
        },

        /**
         * ⚠ NOT `buildSettingsRows` — that name belongs to the shared module, and the module's
         * buildMetadataRows calls it. Overriding it here made the two call each other until the
         * stack ran out, on the first fetch. A page that wraps a shared method must not answer to
         * its name.
         */
        settingsFromBothSides(local, online) {
            // The shared builder, from the same shape the endpoint already returns for both
            // sides. This screen used to derive its own rows next to three other screens
            // deriving theirs — four definitions of what a setting row is.
            this.metadataLabels = {
                sections: @js([
                    'fonts' => __('file_settings.label.fonts'),
                    'font_rules' => __('file_settings.label.font_rules'),
                    'images' => __('file_settings.label.images'),
                    'exclusions' => __('file_settings.label.exclusions'),
                    'variables' => __('file_settings.label.variables'),
                    'game_settings' => __('file_settings.game_settings'),
                ]),
                fields: {},
                absent: @js(__('merge_preview.settings_absent')),
            };

            // ⚠ The shared builder's "main" is the column a screen shows as its own — the TARGET
            // here, so this pair swaps with the direction exactly as the lines below do. Feeding it
            // the local file either way put the settings grid in the reverse order of the lines it
            // is meant to line up with.
            this.buildMetadataRows({
                main_settings: this.toLocal ? local : online,
                branches: [{ id: this.sourceIds()[0], settings: this.toLocal ? online : local }],
            });

            this.settingsRowsReady = this.hasSettingsRows;
            this.settingsOpen = this.settingsDifferenceCount() > 0;
        },

        /**
         * Auto-select based on smart defaulting:
         * - Local-only: select LOCAL (additions to server)
         * - Online-only: select ONLINE (already on server)
         * - Different: smart default on the socle's ladder — capture < A < V < H = S — with the
         *   PUBLISHED side winning ties, which is the rule for somebody meeting their own earlier
         *   version (a contribution against a Main is the other way round: see merge/show)
         * - Same: select ONLINE (no change needed)
         * Keys already selected are skipped: restored pending choices
         * (F5 mid-review) must not be overwritten by the defaults.
         */
        applySmartDefaults() {
            for (const key of this.allKeys) {
                if (key in this.selections) continue;
                const hasLocal = key in this.localData;
                const hasOnline = key in this.onlineData;

                // ⚠ Every default goes through `staged`, which carries the one flag deciding
                // whether the pick VALIDATES — see the core's `pick`. Written as bare strings, an
                // arriving machine line was promoted to V by a page load, in the player's own file.
                const staged = (source, entry) =>
                    this.pick(source, this.getValue(entry), this.getTag(entry));

                if (hasLocal && !hasOnline) {
                    this.selections[key] = staged('local', this.localData[key]);
                } else if (!hasLocal && hasOnline) {
                    this.selections[key] = staged('online', this.onlineData[key]);
                } else if (hasLocal && hasOnline) {
                    if (this.entriesDiffer(key)) {
                        // The shared scale, not a copy of it: see translation-editor.js.
                        // ⚠ The whole entry, not its tag: a captured line (H with nothing in it)
                        // ranks at the floor, and the letter alone cannot tell.
                        const localPriority = this.priorityOf(this.localData[key]);
                        const onlinePriority = this.priorityOf(this.onlineData[key]);

                        // 🔴 **A tie is answered, and the answer does not claim a reading** — the
                        // same three states as the merge screen, from the same core. Blank would
                        // leave the row out of every filtered view; a plain pick would record a
                        // validation nobody performed, two machine translations that differ being
                        // exactly that case, and the common one.
                        //
                        // ⚠ **A tie goes to the side the result is BUILT FROM**, which is not the
                        // same column in both directions — comparing into the game keeps the
                        // player's line, publishing keeps the server's. Written as 'local' either
                        // way it was right one time in two, and the wrong way round it swaps a
                        // line for another nobody preferred.
                        if (localPriority === onlinePriority) {
                            const target = this.targetSource();
                            this.selections[key] = staged(target, this.entryOf(key, target));
                        } else {
                            this.selections[key] = localPriority > onlinePriority
                                ? staged('local', this.localData[key])
                                : staged('online', this.onlineData[key]);
                        }
                    } else {
                        this.selections[key] = staged('online', this.onlineData[key]);
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
        // A pending edit is what the user sees, so it is what gets compared — otherwise the
        // underline would describe a value no longer on screen. A key present on one side only
        // passes null: nothing is underlined, because underlining a whole new line tells the
        // reader nothing.

        /**
         * What a side reads as, pending edit included.
         *
         * ⚠ The edit belongs to the TARGET, not to the local file. Publishing, typing a line used
         * to leave the text under the column nothing was written to, while the receiving column
         * went on showing the version being replaced.
         */
        sideValue(key, id) {
            if (id === this.targetSource() && this.isEdited(key)) return this.editedValues[key];
            return this.getValue(this.entryOf(key, id));
        },

        {{-- One method per side rather than an expression in the template: the CSP build of Alpine
             evaluates a restricted subset. Both go through the same pair, so the two columns cannot
             disagree about which text is being compared. --}}
        valueHtmlOf(key, id) {
            const facing = id === 'local' ? 'online' : 'local';
            const other = this.entryOf(key, facing) === undefined ? null : this.sideValue(key, facing);
            return this.highlightDifference(this.sideValue(key, id), other);
        },

        localValueHtml(key) { return this.valueHtmlOf(key, 'local'); },
        onlineValueHtml(key) { return this.valueHtmlOf(key, 'online'); },

        /**
         * The head tiles, in this screen's own words.
         *
         * 🔴 **The count is the core's, the WORDING is this screen's, and the two are not the same
         * question.** "Only on the local file" is one situation when publishing (`new` — the local
         * file is offering it) and the other when comparing into the game (`onlyOnTarget` — the
         * local file already has it). The tile says which FILE, because that is what somebody
         * reads; the category says which SITUATION, because that is what the screen acts on.
         */
        calculateStats() {
            const counts = this.categoryCounts;

            this.stats = {
                total: counts.total,
                different: counts.differing,
                same: counts.same,

                localOnly: this.toLocal ? counts.onlyOnTarget : counts.new,
                onlineOnly: this.toLocal ? counts.new : counts.onlyOnTarget,
            };
        },

        // ── Shared-core callbacks ────────────────────────────────────────

        rowPassesFilters(key) {
            if (this.filters.modifiedOnly && !this.isRowModified(key)) {
                return false;
            }

            const hasLocal = key in this.localData;
            const hasOnline = key in this.onlineData;

            // Category filter, from the core's one vocabulary
            if (!this.filters[this.rowCategoryFilter(key)]) return false;

            // Tag filter: either side's STORED tag keeps its row, and the TARGET's previewed one
            // too — a pending change must not make the row vanish mid-work. ⚠ Previewed on the
            // target, not on the local file: it is the target's tag that a pick moves.
            const stored = (hasLocal && this.tagVisible(this.getTag(this.localData[key])))
                || (hasOnline && this.tagVisible(this.getTag(this.onlineData[key])));
            const previewed = this.tagVisible(this.displayLocalTag(key));
            return !!(stored || previewed);
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

        /** Core hook: the stored editable value — the target's, since that is what a save writes. */
        storedValue(key) {
            return this.getValue(this.targetEntry(key));
        },

        /** Core hook: the target's projected tag for the quality bar. */
        rowQualityTag(key) {
            if (this.targetEntry(key) !== undefined || this.isEdited(key)) {
                return this.displayLocalTag(key);
            }
            return null;
        },

        /** Core hook: a staged manual edit selects the target side — claimed, since somebody typed. */
        onEditStaged(key) {
            const entry = this.targetEntry(key);
            this.selections[key] = this.pick(
                this.targetSource(), entry === undefined ? '' : this.getValue(entry),
                entry === undefined ? 'A' : this.getTag(entry), false);
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
            if (!extra || !extra.selections || typeof extra.selections !== 'object') return;

            // ⚠ A draft saved before selections became objects holds bare strings. Read as they
            // are, `isUnclaimed` answers false on every one of them — which is right: they were all
            // picked under the rules of the day, and treating them as unclaimed would silently drop
            // a promotion somebody had already decided on. Normalised on the way in so nothing
            // downstream has to know two shapes.
            const restored = {};

            for (const [key, sel] of Object.entries(extra.selections)) {
                if (typeof sel !== 'string') { restored[key] = sel; continue; }

                const entry = sel === 'local' ? this.localData[key] : this.onlineData[key];
                if (entry === undefined) continue;

                restored[key] = this.pick(sel, this.getValue(entry), this.getTag(entry), false);
            }

            this.selections = restored;
        },

        // ── Merge selection logic ────────────────────────────────────────

        /**
         * A row counts as modified when the user did something meaningful: manual edit, explicit
         * tag change, or a line taken from the side that is not the target.
         *
         * ⚠ It read "local was selected" either way, which is a change only when publishing.
         * Comparing into the game, keeping the player's own line changes nothing — and the screen
         * offered to save nine of them.
         */
        isRowModified(key) {
            if (this.isDeleted(key)) return true;
            if (this.editedValues[key] !== undefined) return true;
            if (key in this.tagChanges) return true;
            return this.willWriteFromSource(key);
        },

        /**
         * The save will write this row, because its value comes from the side being OFFERED.
         *
         * 🔴 One statement of "what actually travels", read by the counter, by the button and by the
         * tag cell's A → V. They each had their own, and the tag cell promised promotions the save
         * dropped. The two build functions still spell the same test out per direction — they carry
         * the value and the tag with it — but they must agree with this, and the tests below hold
         * them to it.
         */
        willWriteFromSource(key) {
            const picked = this.pickedSource(key);
            if (picked === null || picked === this.targetSource()) return false;
            if (this.entryOf(key, picked) === undefined) return false;

            const bothSides = key in this.localData && key in this.onlineData;
            return !bothSides || this.entriesDiffer(key);
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

            // Clicking the column this row is already on: held → claimed → back to its own
            // default. The three states and their reasons are the core's, shared with the merge
            // view, which runs the identical gesture on the identical grid.
            if (this.advancePick(key, source)) return;

            // ⚠ Claimed, never auto: this ran because somebody clicked, and a pick made by hand on
            // an `A` line is exactly the validation the defaults refuse to invent.
            const picked = this.pickFrom(key, source);
            if (!picked) return;

            // Taking the offered side drops what somebody typed: an edit belongs to the target, and
            // keeping it would leave the row showing a value the pick just replaced.
            if (source !== this.targetSource()) {
                delete this.editedValues[key];
            }

            this.selections[key] = picked;
            this.persistPendingState();
        },

        /**
         * Tag the save will PRODUCE for the local side — previewed live,
         * before anything is saved. Core displayTag covers tag change → that
         * tag and manual edit → H (M/S preserved); on top of it, a local
         * selection that will actually be SENT gets the server's A → V
         * promotion (picks identical to online are not sent → no preview).
         */
        /**
         * Core hook: the row the tag cell describes — the one being WRITTEN.
         *
         * 🔴 It used to be the local file whichever way the screen ran, so publishing previewed a
         * tag against a file it was never going to touch: the column that actually receives the
         * lines showed a frozen chip, and the transition sat on the column beside it. The tag cell
         * belongs to the target, exactly as it does on the merge view, where it has always sat
         * against the Main.
         */
        entryOnFile(key) {
            return this.targetEntry(key);
        },

        // ── The roles this screen plays, and they SWAP ───────────────────
        //
        // 🔴 This screen runs both ways, and which column receives changes with it. Comparing into
        // the game builds its result from the player's own file; publishing builds it from the
        // server's. So neither "local" nor "online" is the answer to "which one is being written" —
        // the role has to be asked separately from the column, and everything the editor does to a
        // target (its tag transition, its edit affordances, its place on the row) reads the role.
        // See analyse/editors-mutualisation.md.

        /** Core hook: the column the result is built on — the one that receives. */
        targetSource() {
            return this.toLocal ? 'local' : 'online';
        },

        {{-- ⚠ Reads entryOf directly, never entryOnFile: that one now answers "the target's row",
             so routing it back through here would be a loop. --}}
        targetEntry(key) {
            return this.entryOf(key, this.targetSource());
        },

        /** Core hook: exactly one column proposes here, and it is the other one. */
        sourceIds() {
            return [this.toLocal ? 'online' : 'local'];
        },

        /** Core hook: what a given column holds for this key. */
        entryOf(key, id) {
            return id === 'local' ? this.localData[key] : this.onlineData[key];
        },

        /**
         * Core hook: the tag the save will store on the target.
         *
         * ⚠ No "does the target hold this row" guard, deliberately — the merge view has none
         * either. A line the target does not have yet still gets a tag the moment one is picked,
         * and that is precisely what `tagArrives` reads to draw the arrow with nothing on its left.
         * Guarding here answered null on exactly those rows, so the arrival could never show.
         */
        tagAfterSave(key) {
            return this.displayLocalTag(key);
        },

        /**
         * Core hook: this screen has a second thing to say about a tag — the two sides disagree
         * about it, which its own ring marks. It rides on the resulting chip rather than replacing
         * the transition, because they answer different questions: one is what the save does, the
         * other is where the disagreement is.
         */
        tagChipExtraClass(key) {
            return this.tagChangedBetweenSides(key) ? ' ring-2 ring-amber-400/80' : '';
        },

        /**
         * The tag the save will store — on the column being WRITTEN, whichever way this runs.
         *
         * ⚠ It read the local file either way, which is the receiving side only when comparing into
         * the game. Publishing, it previewed a tag against a file nothing was going to touch.
         */
        displayLocalTag(key) {
            const written = this.tagOnFile(key);

            if (this.hasTagChange(key) || this.isEdited(key)) {
                return this.displayTag(key, written);
            }

            // 🔴 A picked row shows the tag it brings, ALWAYS — the same shape as the merge view's
            // `displayMainTag`. Read only when the row was also claimed, this cell said nothing at
            // all on a line the target does not hold yet: no chip, no arrow, a grey dash where the
            // screen was about to add a line.
            const picked = this.pickedSource(key);
            const chosen = picked === null ? undefined : this.entryOf(key, picked);

            if (chosen === undefined) return written;

            const tag = this.getTag(chosen);

            // A → V says "somebody read this", so it needs BOTH: a row somebody claimed, and a save
            // that actually writes it. An unclaimed hold keeps its A (the save sends `auto` and the
            // server leaves it alone), and so does a row the save has nothing to send for — a value
            // already on the target, or one identical on both sides.
            const sent = this.willWriteFromSource(key);

            return (tag === 'A' && sent && !this.isUnclaimed(key)) ? 'V' : tag;
        },

        getCellClass(key, source) {
            // Check if manually edited (only applies to local column)
            if (source === 'local' && this.editedValues[key] !== undefined) {
                return 'selected-manual';
            }

            const selected = this.pickedSource(key) === source;
            if (selected) {
                const held = source === 'local' ? 'selected-local' : 'selected-online';

                // ⚠ The colour still says WHICH column is held; the modifier says how firmly. Two
                // separate facts, so two classes rather than four names — same as the merge view.
                return this.isUnclaimed(key) ? held + ' selection-unclaimed' : held;
            }
            return '';
        },

        /**
         * Take every line this side holds.
         *
         * ⚠ Claimed: pressing "take everything from this side" is a decision about every one of
         * those lines, said once instead of row by row. It is the DEFAULTS — what the screen
         * answers on its own — that must not claim anything.
         *
         * ⚠ One method for both sides, keyed on the column. It was two, and only one of them
         * dropped pending edits — so sweeping one way replaced the values and sweeping the other
         * left somebody's typing on top of the lines it had just replaced.
         */
        selectAllFrom(source) {
            for (const key of this.allKeys) {
                const entry = this.entryOf(key, source);
                if (entry === undefined || this.isDeleted(key)) continue;

                // Pressing "take everything from this side" is a click, once, about every one of
                // them — see byHand in the core.
                this.selections[key] = this.byHand(
                    this.pick(source, this.getValue(entry), this.getTag(entry), false));

                // An edit belongs to the target: taking the offered side replaces what it was on.
                if (source !== this.targetSource()) delete this.editedValues[key];
            }
            this.persistPendingState();
        },

        clearAll() {
            if (confirm(@js(__('merge_preview.confirm_cancel')))) {
                this.selections = {};
                this.clearPendingState();
            }
        },

        /**
         * Put the proposal back on whatever is still unanswered.
         *
         * 🔴 **Cancel used to do this itself, which made it look inert.** It emptied every answer
         * and re-applied the defaults in the same breath, putting back exactly what had just been
         * cleared — and on a screen where every contested row arrives answered, the two cancel out
         * and nothing moves. Cancelling and proposing are two acts. The merge view has always had
         * them as two buttons; this screen now agrees with it.
         *
         * ⚠ It touches only what has no answer yet, so it is safe to press at any point, and
         * pressing it twice does nothing the second time.
         */
        suggestTheRest() {
            this.applySmartDefaults();
            this.persistPendingState();
        },

        /**
         * Rows on screen that nobody and nothing has answered.
         *
         * ⚠ Counted the way the button ACTS: a key the defaults would not touch is not one of "the
         * rest", and counting it would promise work the button is not about to do.
         */
        get undecidedCount() {
            let count = 0;

            for (const key of this.allKeys) {
                if (key in this.selections) continue;
                if (this.isDeleted(key)) continue;
                if (key in this.localData || key in this.onlineData) count++;
            }

            return count;
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
                const source = this.pickedSource(key);
                const isEdited = this.editedValues[key] !== undefined;
                const hasTagChange = key in this.tagChanges;

                if (hasTagChange) {
                    // Explicit tag change wins as-is — same rule as submitResult
                    merged[key] = {
                        v: isEdited ? this.editedValues[key] : this.tagChanges[key].value,
                        t: this.tagChanges[key].newTag
                    };
                } else if (isEdited) {
                    // Manual edit -> becomes H tag (M and S are preserved).
                    // ⚠ Read off the TARGET, the row being rewritten — the same place the tag cell
                    // previews from, so what the chip announced is what gets written.
                    let tag = this.getTag(this.targetEntry(key));
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

        {{-- Capture-order index: the core reads the target first, then the sources. This screen
             hard-coded "local, then online", which is right one way round and wrong the other —
             publishing, the receiving file is the server's. --}}

        /**
         * Hand the arbitrated result to whichever side this comparison is for.
         *
         * ⚠ Named for what it does, not for one of its two destinations: it was `saveToServer`, and
         * comparing into the game it posts to `merge-preview.apply-local`, which publishes nothing
         * — the mod collects the result from the token's own file. The form's action already knew;
         * only this name and the button's label were still saying the other thing.
         */
        submitResult() {
            this.saving = true;

            // Build selections array for the form - only include REAL changes
            const container = document.getElementById('selectionsContainer');
            while (container.firstChild) {
                container.removeChild(container.firstChild);
            }

            let i = 0;
            for (const key of this.allKeys) {
                const source = this.pickedSource(key);
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
                    // Manual edit = always a change (server: → H unless M/S).
                    // ⚠ Tag read off the TARGET, like the cell that previewed it.
                    value = this.editedValues[key];
                    tag = this.getTag(this.targetEntry(key));
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
                    { name: `selections[${i}][source]`, value: sourceType },

                    // Whether anybody claimed this row. The server promotes A to V on a pick, and
                    // this is what tells that apart from a row the screen answered on its own.
                    { name: `selections[${i}][auto]`, value: this.isUnclaimed(key) ? '1' : '0' }
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
            // Which side needs sending depends on where the result is built: only a pick on the
            // SOURCE changes anything, since the target's own settings are already in place.
            const settingSideToSend = this.sourceIds()[0];
            let s = 0;
            for (const row of this.settingsRows) {
                // 'main' is what the shared block calls the column a screen shows as its own —
                // here the target, which swaps with the direction.
                const picked = this.settingsPick[row.id];
                const side = (picked === undefined || picked === 'main') ? this.targetSource() : picked;
                if (side !== settingSideToSend) continue;

                const el = document.createElement('input');
                el.type = 'hidden';
                el.name = `settings[${row.id}]`;
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
