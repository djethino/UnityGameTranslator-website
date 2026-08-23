@extends('layouts.app')

@section('title', ($mode === 'edit' ? __('merge.edit_heading') : __('merge.title')) . ' - ' . $main->game->name)

{{-- No container override: outside the workbench this is an ordinary page, the same width as
     every other screen on the site. Stretching it to the window served neither use — it did not
     make four columns fit, and it made the page unlike everything around it. The room is asked
     for explicitly, through the workbench, and only for as long as the arbitration lasts. --}}

@section('content')
@php
    // Navigation state is now limited to what the SERVER needs to rebuild the
    // page: mode and selected branches. Filters/search/sort/windowing are
    // client-side (shared translation-editor core) and persist on their own.
    // branches_chosen travels with the selection: without it, "none" is indistinguishable from
    // "never chose", and switching mode or reloading would hand the default selection back.
    $stateParams = array_merge(
        ['mode' => $mode],
        request()->boolean('branches_chosen') ? ['branches_chosen' => 1] : [],
        $selectedBranches->isNotEmpty() ? ['branches' => $selectedBranches->pluck('id')->all()] : []
    );
    $dataUrl = route('translations.merge.data', ['uuid' => $uuid]) . '?' . http_build_query($stateParams);
@endphp
{{-- The two confirmation sentences ride on the element rather than through an Alpine expression:
     the CSP build parses a restricted subset, and an object literal in x-data is not in it. --}}
<div class="container mx-auto px-4 py-8" x-data="mergeTable"
     data-hide-one="{{ __('merge.hide_branch_one') }}"
     data-hide-many="{{ __('merge.hide_branch_many') }}">
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
        {{-- ⚠ Both modes of this screen write to the PUBLISHED translation: editing changes the
             Main, and merging takes a contribution into it. Nothing here ever reaches a machine —
             which is exactly the thing somebody arriving from an in-game editor would assume, and
             the reason the same control is shown here as there. --}}
        <div class="flex items-center gap-3 flex-wrap">
            <x-editor.scope-badge side="server" :why="[
                'local' => __('edit_scope.why_page_is_server'),
                'both' => __('edit_scope.why_page_is_server'),
            ]" />
            <h1 class="text-2xl font-bold text-white">
                @if($mode === 'edit')
                    <i class="fas fa-pen mr-2 text-purple-400"></i>{{ __('merge.edit_heading') }}
                @else
                    <i class="fas fa-code-merge mr-2 text-green-400"></i>{{ __('merge.heading') }}
                @endif
            </h1>
        </div>
        <p class="text-gray-400">
            <x-language-mark :language="$main->source_language" named /> {{ $main->source_language }}
            <i class="fas fa-arrow-right text-xs"></i>
            <x-language-mark :language="$main->target_language" named /> {{ $main->target_language }}
        </p>
    </div>

    {{-- Success and warning banners come from the layout: rendering success
         here too showed it twice, which reads as two separate saves. --}}

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
                {{-- Submitting this form IS the choice, even when it selects nothing --}}
                <input type="hidden" name="branches_chosen" value="1">
                {{-- 🔴 **Which sitting this is, carried across the reload this form causes.**
                     Choosing which contributions to show is a GET, so it navigates — and a new
                     history entry means a new sitting, which is the rule everywhere else and
                     exactly wrong here: hiding one contribution would throw away everything already
                     decided about the others. The Edit/Merge link deliberately does NOT carry it:
                     those are two screens, and crossing between them starts afresh. --}}
                <input type="hidden" name="w" :value="workSession">
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
                        {{-- data-branch-name: the confirmation before hiding a contribution names
                             it, and the page chrome is a separate component with no access to the
                             translated markup below. --}}
                        <input type="checkbox" name="branches[]" value="{{ $branch->id }}"
                            data-branch-name="{{ $branch->user->name }}"
                            class="branch-checkbox rounded bg-gray-700 border-gray-600 text-purple-600 focus:ring-purple-500"
                            {{ $selectedBranches->contains('id', $branch->id) ? 'checked' : '' }}>
                        <x-user-mention :user="$branch->user" class="text-gray-300" />
                        <span class="text-xs text-gray-500">({{ $branch->line_count }})</span>
                    </label>
                    {{-- Rating and report sit tight against the name: the row holds one chip per
                         branch and has to stay on ONE line — three of them wrapped the moment the
                         report flag was added, and a name longer than these would have done it
                         anyway. Nothing is removed, the spacing simply stops being generous. --}}
                    <div class="flex items-center branch-rating" data-branch-id="{{ $branch->id }}">
                        @for($i = 1; $i <= 5; $i++)
                        <button type="button"
                            class="rating-star text-xs px-px {{ $branch->main_rating >= $i ? 'text-yellow-400' : 'text-gray-600' }} hover:text-yellow-300 transition"
                            data-rating="{{ $i }}"
                            title="{{ trans_choice('rating.rate_branch', $i, ['stars' => $i]) }}">
                            <i class="fas fa-star"></i>
                        </button>
                        @endfor
                        @if($branch->wasModifiedSinceReview())
                        <span class="ml-1 text-xs text-orange-400" title="{{ __('rating.modified_since_review') }}">
                            <i class="fas fa-exclamation-circle"></i>
                        </span>
                        @endif
                    </div>

                    {{-- Reporting a branch.

                         A Main receives contributions it did not ask for and could not preview:
                         until now the only answers available were to merge it, ignore it, or
                         rate it one star. Rating says "this is poor work"; reporting says "this
                         should not have been sent", which is a different message and the only
                         one that reaches a moderator.

                         Placed with the rating rather than in the grid header: both are
                         judgements about a contributor's work as a whole, not about a line. --}}
                    <button type="button" data-report-id="{{ $branch->id }}"
                        class="report-btn text-xs text-gray-500 hover:text-red-400 transition"
                        title="{{ __('report.report_branch') }}">
                        <i class="fas fa-flag"></i>
                    </button>
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

        {{-- Settings gap with the branches being merged. Applying a merge
             rewrites the MAIN's file, so a branch's fonts, images or
             exclusions never travel upstream — worth stating before the owner
             assumes accepting every line accepted everything. --}}
        @foreach($selectedBranches as $branch)
            <div class="mb-4">
                @include('partials.translation-settings-diff', [
                    'left' => $main,
                    'right' => $branch,
                    'leftLabel' => __('merge.settings_yours'),
                    'rightLabel' => $branch->user->name,
                ])
            </div>
        @endforeach
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
            <x-editor.stale-banner />

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
                    <p class="text-sm text-gray-400">{{ __('merge_preview.different') }}</p>
                </div>
                @endif
                <div class="bg-gray-800 rounded-lg p-4 border border-purple-700 text-center">
                    <p class="text-2xl font-bold text-purple-400" x-text="totalChanges"></p>
                    <p class="text-sm text-gray-400">{{ __('merge.modifications') }}</p>
                </div>
            </div>

            {{-- What the contributions differ on that is not a translated line.

                 🔴 **Two folded blocks, and the same grid as the lines.** Both were invented here
                 as little hand-written tables — one of them prepared long ago and never once seen,
                 because nothing ever opened it. A screen that lays out the same gesture two ways
                 makes the reader learn it twice, so they now run on the columns the lines run on:
                 same names, therefore same widths, same drag, same pin, and everything reads down
                 the page in one alignment.

                 Folded because this screen is a line-by-line merge, and a block sitting open
                 above the table pushes the actual work off the screen for something usually
                 empty. The header's count is what decides whether to open it.

                 ⚠ Separate blocks, because what can be DONE differs: a font is edited in the mod
                 and can only be taken or left, while a description is written here and is meant to
                 be reworded before it goes on the Main's public page.

                 ⚠ No status anywhere in either. Whether a translation is finished descends from a
                 Main to its contributions and never travels back. --}}
            {{-- What the contributions differ on that is not a translated line.

                 🔴 **Two folded blocks, on the grid the lines run on.** Both were invented here
                 as little hand-written tables — one of them prepared long ago and never once
                 seen, because nothing ever opened it. A screen that lays out the same gesture two
                 ways makes the reader learn it twice, so they run on the columns the lines run
                 on: same names, therefore same widths, same drag, same pin.

                 ⚠ **And they are ONE component now, used twice.** They were the same table
                 written twice in this file, each free to drift from the other — and one did: the
                 pin froze one table's header and not the next one's body, for weeks.

                 ⚠ Separate blocks, because what can be DONE differs: a font is edited in the mod
                 and can only be taken or left, while a description is written here and is meant
                 to be reworded before it goes on the Main's public page.

                 ⚠ No status anywhere in either. Whether a translation is finished descends from a
                 Main to its contributions and never travels back. --}}
            <x-editor.metadata-grid name="settings"
                :title="__('merge.block_file_settings')"
                :hint="__('merge.settings_pick_hint')">
                <x-slot:mainCell>
                    <span class="editor-text break-words" x-text="row.mineValue"></span>
                </x-slot:mainCell>
            </x-editor.metadata-grid>

            <x-editor.metadata-grid name="publication"
                :title="__('merge.block_description')"
                :hint="__('merge.publication_pick_hint')">
                <x-slot:mainCell>
                    {{-- The lines' own affordance, not a box of its own: revert what is staged, or
                         open the shared edit modal. A textarea living in the cell was invented
                         here — this screen already had one way to edit a value. --}}
                    <span class="edit-affordance" x-show="canTakeContributions()">
                        <button type="button" x-show="publicationPick[row.id] !== undefined"
                            @click.stop="publicationKeepMine(row)"
                            title="{{ __('merge.revert_row') }}"><i class="fas fa-undo"></i></button>
                        <button type="button"
                            @click.stop="editCell(row.id, publicationResult(row), 'publication')"
                            title="{{ __('translation.edit') }}"><i class="fas fa-pen"></i></button>
                    </span>
                    {{-- Purple on a rewording and on nothing else, exactly as on a line: it says
                         "this text is not what is stored". Taking a contribution leaves this cell
                         alone, so there is nothing here to mark. --}}
                    <span class="editor-text break-words"
                        :class="publicationPick[row.id] === 'manual' ? 'text-purple-300' : ''"
                        x-text="publicationResult(row)"></span>
                </x-slot:mainCell>
            </x-editor.metadata-grid>

            @include('partials.editor-quality-bar')

            {{-- Ahead of the tags, what kind of row this is — only in merge mode, since a
                 comparison of one file against itself has no categories to offer. --}}
            <x-editor.filter-bar>
                @if($mode === 'merge')
                    <x-slot:before>
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" :checked="filters.catNew" @change="toggleFilter('catNew')"
                                class="rounded bg-gray-700 border-gray-600 text-green-600">
                            <span class="text-green-400">{{ __('merge.filter_new_keys') }}</span>
                        </label>
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
                @endif

                <x-editor.filter-sep />

                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" :checked="filters.modifiedOnly" @change="toggleFilter('modifiedOnly')"
                        class="rounded bg-gray-700 border-gray-600 text-purple-600">
                    <span class="text-purple-400">{{ __('merge.modifications') }}</span>
                </label>
            </x-editor.filter-bar>

            @include('partials.editor-floating-search')

            {{-- Search (Enter/Shift+Enter navigate matches) + replace --}}
            <x-editor.search-bar replace />

            {{-- The workbench strip, shared by every editing screen — see
                 components/editor/workbench-bar.blade.php. Only the category filters differ from
                 one screen to the next, so only those are passed in. --}}
            <x-editor.workbench-bar save="submitMerge()">
                @if($mode === 'merge')
                    <label class="flex items-center gap-1 text-xs cursor-pointer shrink-0" title="{{ __('merge.filter_new_keys') }}">
                        <input type="checkbox" :checked="filters.catNew" @change="toggleFilter('catNew')"
                               class="rounded bg-gray-700 border-gray-600 text-green-600">
                        <span class="text-green-400">+</span>
                    </label>
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
                @endif
            </x-editor.workbench-bar>

            {{-- An ordinary block that the page scrolls, until the workbench tears it out and hands
                 it the window — then the scrollbars belong to the box and sit at the edges of the
                 screen, where they can be reached without leaving the line being read.

                 A guessed height was the mistake here before: the box was capped at "100vh minus
                 14rem" while the chrome above it runs to some four hundred pixels, so it hung below
                 the fold and took its horizontal scrollbar with it. --}}
            <div x-ref="gridBox"
                 @scroll="refreshOffScreenSides()"
                 class="overflow-x-auto bg-gray-800 rounded-lg border border-gray-700"
                 :class="wide && 'fixed inset-x-0 bottom-0 top-12 z-50 rounded-none border-0 overflow-auto'">
                {{-- border-separate, and it is not cosmetic: with the default collapsed borders,
                     a browser does not paint the background of a sticky cell — only its text. The
                     frozen key column therefore let every scrolled column show through behind its
                     own words, which reads as a rendering fault and made the column useless for
                     the one thing it is for, reading and copying the source line. --}}
                <table class="editor-grid w-full text-sm border-separate border-spacing-0"
                   :class="[showLineBreaks && 'show-linebreaks', pinMain && !resizingColumns && 'pin-main', columnsSized && 'cols-sized']">
                    <thead class="bg-gray-900 sticky top-0 z-20">
                        <tr>
                            {{-- The line's identity travels with it. Scrolling sideways used to
                                 carry the key off screen, and past the third column nobody could
                                 tell which line they were looking at. Both columns, their frozen
                                 offsets and the reasons for them are in the shared components. --}}
                            <x-editor.head-index />
                            <x-editor.head-key />
                            {{-- data-col on the tag column too: the pin freezes the pair, since a
                                 value without its tag says only half of what the row holds. --}}
                            {{-- w-20, not w-12: this cell shows `A → V` when a save will change the
                                 tag, and under `table-layout: fixed` (the moment any column is
                                 dragged) a declared width is honoured to the pixel — the pair
                                 simply spilled over its neighbour. ⚠ The same width is written on
                                 the metadata grids above, which align on this column by name. --}}
                            <th data-col="mainTag"
                                class="px-2 py-3 text-center border-l border-gray-700 w-20 cursor-pointer hover:text-white transition"
                                @click="toggleSort('mainTag')">
                                <div class="flex items-center justify-center gap-1">
                                    <span class="text-green-400 font-medium text-xs">Tag</span>
                                    <i class="fas text-xs" :class="getSortIcon('mainTag')"></i>
                                </div>
                            </th>
                            <th data-col="main"
                                class="relative px-4 py-3 text-left border-l border-gray-700 min-w-[250px] cursor-pointer hover:text-white transition"
                                @click="toggleSort('mainValue')">
                                <div class="flex items-center gap-2">
                                    <span class="text-green-400 font-medium">Main</span>
                                    <span class="text-xs text-gray-500" x-text="'(' + mainOwner + ')'"></span>
                                    <i class="fas" :class="getSortIcon('mainValue')"></i>
                                    <x-editor.pin-toggle />
                                </div>
                                <x-editor.col-resize col="main" />
                            </th>
                            <template x-for="branch in branches" :key="branch.id">
                                <th colspan="2" :data-col="'branch-' + branch.id"
                                    class="relative px-4 py-3 text-left border-l border-gray-700 min-w-[280px]">
                                    <div class="flex items-center gap-2">
                                        <span class="text-blue-400 font-medium" x-text="branch.name"></span>
                                        {{-- ⚠ The word stays in the HTML, only the NUMBER comes from Alpine. Passing a translated
                                             string through @js encodes it as ا… and eleven of the twenty
                                             languages have a non-ASCII word here — which is how "Humain" reaches a
                                             page as an escape sequence. --}}
                                        <span class="text-xs text-gray-500"><span x-text="branchHumanShare(branch)"></span>% {{ __('progress.human') }}</span>
                                    </div>
                                    {{-- What this contribution is MADE OF, on the site's one bar.
                                         Three raw counters stood here (N H / N V / N A), which
                                         left out everything kept as is and everything merely
                                         captured: a branch of ten translated lines and nine
                                         hundred captures read as ten lines of pure human work. --}}
                                    <x-quality-bar percent-fn="branchPercent" percent-arg="branch"
                                        height="h-1" class="mt-1" />
                                    <x-editor.col-resize :bind="true" col="'branch-' + branch.id" />
                                </th>
                            </template>
                            <th class="answer-rail"></th>
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
                                {{-- Capture-order index --}}
                                {{-- Frozen with its header: an opaque background is required, or
                                     the scrolled columns show through underneath. --}}
                                <x-editor.cell-index />

                                {{-- Key --}}
                                <x-editor.cell-key>
                                    {{-- The answer is that way.

                                         🔴 A row whose answer is off screen reads as a row
                                         nobody answered. With four contributions the grid is
                                         twice the width of the window, so this is the ordinary
                                         case, not an edge one.

                                         It rides the last frozen cell — this one, unless the pin
                                         moved the edge past Main — so it needs no measuring: it
                                         follows the pin, the index column and every drag on its
                                         own. It floats OVER the scrolling content rather than
                                         taking width, and it goes there when clicked. --}}
                                    <template x-if="!pinMain">
                                        <button type="button"
                                        x-show="lineAnswerLeft(key)" x-cloak
                                        @click.stop="goToLineAnswer(key)"
                                        class="absolute left-full top-1/2 -translate-y-1/2 ml-1 z-20"
                                        :title="offScreenHint"
                                        ><i class="fas answer-mark" :class="lineAnswerIconClass(key)"></i></button>
                                    </template>
                                </x-editor.cell-key>

                                {{-- Main Tag (clickable for tag change) --}}
                                <td data-col="mainTag" class="px-2 py-2 text-center border-l border-gray-700"
                                    :class="[tagCellClass(key), isDeleted(key) ? 'deleted-cell' : '']">
                                    {{-- 🔴 **A row the Main does not hold gets a tag cell too, from the
                                         moment a tag is on its way in.** The dash belongs to a line
                                         that would be written nowhere — nothing taken, nothing typed
                                         — and it used to cover EVERY such row. So the arriving tag
                                         had nowhere to be drawn however right the core's answer was:
                                         the cell turned purple, meaning "this is not what will be
                                         stored", and showed a dash.

                                         ⚠ tagArrives, never a list of gestures rebuilt here: which
                                         ones produce a tag is the core's business, and a second
                                         list is a list that drifts. --}}
                                    <template x-if="mainData[key] !== undefined || tagArrives(key)">
                                        <x-editor-tag-cell />
                                    </template>
                                    <template x-if="mainData[key] === undefined && !tagArrives(key)">
                                        <span class="text-gray-600">—</span>
                                    </template>
                                </td>

                                {{-- Main Value (click = keep/validate main, dblclick/pencil = edit) --}}
                                <td data-col="main" class="relative px-4 py-2 border-l border-gray-700 merge-cell"
                                    :class="[getCellClass(key, 'main'), isDeleted(key) ? 'deleted-cell' : '']"
                                    @click="select(key, 'main')"
                                    @dblclick="editCell(key, getValue(mainData[key]))">
                                    {{-- Pinned, the frozen block ends here, so the mark moves with
                                         it. Same one, one cell further right. --}}
                                    <template x-if="pinMain">
                                        <button type="button"
                                        x-show="lineAnswerLeft(key)" x-cloak
                                        @click.stop="goToLineAnswer(key)"
                                        class="absolute left-full top-1/2 -translate-y-1/2 ml-1 z-20"
                                        :title="offScreenHint"
                                        ><i class="fas answer-mark" :class="lineAnswerIconClass(key)"></i></button>
                                    </template>
                                    {{-- ⚠ Each button asks its own question — the block used to be
                                         hidden whole on a row the Main does not hold, which shut
                                         off the pencil on exactly the rows where writing one's own
                                         translation is the point. The double-click below never was,
                                         so the same row answered twice. `canDelete` is the one that
                                         is genuinely false there: nothing to strike out. --}}
                                    <span class="edit-affordance">
                                        <button type="button" x-show="isRowModified(key)" @click.stop="revertRow(key)"
                                            title="{{ __('merge.revert_row') }}"><i class="fas fa-undo"></i></button>
                                        <button type="button" x-show="canEdit(key)" @click.stop="editCell(key, storedValue(key))"
                                            title="{{ __('translation.edit') }}"><i class="fas fa-pen"></i></button>
                                        <button type="button" class="delete-btn" x-show="canDelete(key)"
                                            @click.stop="toggleDelete(key)"
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
                                            <span class="editor-text" x-show="isEdited(key)" x-safe-html="highlightValue(editedValues[key])"></span>
                                            <span class="editor-text" x-show="!isEdited(key)" x-safe-html="mainValueHtml(key)"></span>
                                        </span>
                                    </template>
                                    <template x-if="mainData[key] === undefined && !isEdited(key)">
                                        <span class="text-gray-600 italic">—</span>
                                    </template>
                                </td>

                                {{-- Branch columns (click = take this branch's version) --}}
                                <template x-for="branch in branches" :key="branch.id">
                                    <td class="px-2 py-2 border-l border-gray-700 merge-cell" colspan="2"
                                        :data-col="'branch-' + branch.id"
                                        :class="[getCellClass(key, 'branch_' + branch.id), branchCellTint(branch, key)]"
                                        @click="select(key, 'branch_' + branch.id)">
                                        <template x-if="branch.content[key] !== undefined">
                                            {{-- items-center, to match the Main. Its tag sits in a
                                                 cell of its own, so the table centres it on the
                                                 row; a branch tag shares a cell with its value and
                                                 was pinned to the top of the text instead. On a
                                                 long entry the two columns disagreed by half a
                                                 paragraph, and a badge that changes height from
                                                 one column to the next reads as a fault. --}}
                                            <div class="flex items-center gap-2">
                                                <span class="shrink-0" :class="'tag-' + getTag(branch.content[key])" x-text="getTag(branch.content[key])"></span>
                                                <span class="editor-text"
                                                    :class="branchTextTint(branch, key)"
                                                    x-safe-html="branchValueHtml(branch, key)"></span>
                                            </div>
                                        </template>
                                        <template x-if="branch.content[key] === undefined">
                                            <span class="text-gray-600 italic">—</span>
                                        </template>
                                    </td>
                                </template>
                                <td class="answer-rail"><button type="button"
                                        x-show="lineAnswerRight(key)" x-cloak
                                        @click.stop="goToLineAnswer(key)"
                                        class="absolute right-1 top-1/2 -translate-y-1/2 z-20"
                                        :title="offScreenHint"
                                        ><i class="fas answer-mark" :class="lineAnswerIconClass(key)"></i></button></td>
                            </tr>
                        </template>

                        <tr x-show="filteredKeys.length === 0">
                            <td :colspan="(showIndexColumn ? 5 : 4) + branches.length * 2" class="py-12 text-center text-gray-500">
                            {{-- Kept where the eye is, not where the table is: see .grid-visible-center --}}
                            <div class="grid-visible-center">
                                <i class="fas fa-search text-4xl mb-3 opacity-50"></i>
                                <p>{{ __('merge.no_keys_found') }}</p>
                            </div>
                        </td>
                        </tr>

                        <tr x-show="hiddenCount > 0">
                            <td :colspan="(showIndexColumn ? 5 : 4) + branches.length * 2" class="py-3 text-center">
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

            {{-- Apply form + footer. The sticky class must live on the FORM:
                 sticky only works within the parent's bounds, and the form
                 wraps nothing but this bar — sticky on the inner div would
                 have no room to stick and the bar would sit at the very
                 bottom of the page --}}
            {{-- z-40, and the frozen key column below it. A sticky element paints above ordinary
                 content whatever its order in the document, so the key cells — sticky since they
                 have to stay legible while scrolling sideways — were coming out on top of this
                 bar and hiding the save button behind them. --}}
            <form method="POST" action="{{ route('translations.merge.apply', $uuid) }}" id="mergeForm"
                class="mt-6 sticky bottom-4 z-40">
                @csrf
                {{-- Server needs mode + branches back for the redirect --}}
                <input type="hidden" name="mode" value="{{ $mode }}">
                @foreach($selectedBranches as $branch)
                <input type="hidden" name="branches[]" value="{{ $branch->id }}">
                @endforeach
                {{-- JSON-encoded data (avoids Laravel TrimStrings corrupting keys with whitespace) --}}
                <input type="hidden" id="selectionsJson" name="selections_json" value="">
                <input type="hidden" id="deletionsJson" name="deletions_json" value="">
                {{-- ⚠ No tag_changes_json any more: a tag set by hand rides in its row's own entry,
                     with `source: tagchange`. The endpoint still READS the old field, for the tab
                     that was open with the previous script when this shipped. --}}
                <input type="hidden" id="settingsJson" name="settings_json" value="">
                <input type="hidden" id="publicationJson" name="publication_json" value="">

                {{-- The grid's sideways scroll, brought within reach: the real bar is at the
                     bottom of six thousand rows, this one rides with the save bar. --}}
                <x-editor.h-scrollbar />

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
                            {{-- The total, not the line count.

                                 ⚠ Both this and the Save button beside it are labelled with the
                                 same word, and they held two different numbers the moment a
                                 decision was taken on anything but a line: "50 modifications"
                                 under a button reading "Save (51)". The deletions and the tag
                                 changes after it stay what they are — notable parts of that
                                 total, not a partition of it. --}}
                            <span x-show="totalChanges > 0">
                                <span class="text-white font-bold" x-text="totalChanges"></span> {{ __('merge.modifications') }}
                            </span>
                            <span x-show="totalChanges > 0 && (deleteCount > 0 || tagChangeCount > 0)"> &bull; </span>
                            <span x-show="deleteCount > 0">
                                <span class="text-red-400 font-bold" x-text="deleteCount"></span> {{ __('merge.deletions') }}
                            </span>
                            <span x-show="deleteCount > 0 && tagChangeCount > 0"> &bull; </span>
                            <span x-show="tagChangeCount > 0">
                                <span class="text-purple-400 font-bold" x-text="tagChangeCount"></span> {{ __('merge.tag_changes') }}
                            </span>
                        </span>
                        {{-- One line per gesture, with the same icons as the table --}}
                        {{-- Small type: read once, then only glanced at, in a
                             bar pinned over the rows being edited (parity with
                             the edit-session and merge-preview editors) --}}
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
                        {{-- The way back to the proposal.

                             🔴 The screen arrives already answered, and until now nothing could
                             put that back: the defaults run once, at load, so Cancel — or
                             unticking a few rows by hand — left the review at zero with a page
                             reload as the only way out.

                             ⚠ It touches only what has no answer yet, which is what its name
                             says: a row you decided is not one of "the rest". So it is safe to
                             press at any point, and pressing it twice does nothing the second
                             time.

                             Shown when there is something left to answer, rather than always: a
                             button that does nothing is a button that teaches nothing. --}}
                        <button type="button" @click="suggestTheRest()"
                            x-show="undecidedCount > 0" x-cloak
                            class="text-gray-400 hover:text-white text-sm transition">
                            <i class="fas fa-wand-magic-sparkles mr-1"></i> {{ __('merge.suggest_rest') }}
                        </button>
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
                {{-- The dashed swatch, beside the two solid ones it modifies: the reader meets the
                     colour first and the frame second, which is the order the cells present them. --}}
                <span><span class="inline-block w-3 h-3 rounded mr-1 border-2 border-dashed border-green-500"></span> {{ __('merge.legend_selection_unclaimed') }}</span>
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
        {{-- x-ref so the core can measure it and keep it inside the window (see
             _keepTagDropdownOnScreen): its height depends on what this screen offers. --}}
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

{{-- The report dialog, opened by the flag beside each branch. Same component as the game pages,
     so what a report looks like does not depend on where it is raised. --}}
<x-report-modal />

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
        // 🔴 Two screens, never one. Arbitrating contributions and correcting one's own file show
        // different columns and answer different questions; sharing a filter or a pick between them
        // put picks aimed at contributions on a screen that shows none — and sent them.
        view: isEditMode ? 'edit' : 'merge',
        // ⚠ Encoded, never echoed between quotes: inside a <script>, HTML escaping protects
        // nothing, and this value comes from the URL. The rule the rest of this file follows.
        scope: @json($uuid),
        // ⚠ Named after the SITUATIONS the core counts (`catNew`, `catDiffering`, `catSame`), not
        // after boxes this screen invented. `catSame` also carries `onlyOnTarget` here — see
        // categoryFilter below, where that fold is stated once.
        filters: {
            catNew: true,
            catDiffering: true,
            // 🔴 Off: on a real lineage this is 2497 rows out of 2536 — the lines both sides
            // already agree on. Leaving them in means the few that need a decision are found by
            // scrolling past a hundred that do not. The box is in the bar, one click away.
            catSame: false,
            tagH: true,
            tagV: true,
            tagA: true,
            tagS: true,
            tagM: true,
            // 🔴 **Off, and it has to be.** It hides every row with no pending decision — which
            // is exactly what a DIFFERING line is until somebody arbitrates it. Measured on a
            // real lineage: 21 new lines pre-taken, 35 differences pre-taken by nobody, and with
            // this on the screen showed the 21 and hid the 35. The review lost the rows it exists
            // for.
            //
            // ⚠ And the defaults cannot cover the gap: on a differing line, pre-selecting the
            // Main is not a neutral act — the apply endpoint reads it as "validate this", which
            // promotes a machine translation to human-checked. Marking 35 lines as reviewed
            // because nobody looked at them is the one outcome worse than scrolling.
            //
            // What the screen opens on instead is catSame off: everything that needs a decision,
            // nothing that does not.
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
        // Settings a branch holds differently, and which of them the Main takes.
        // Keyed "<branchId>|<settingKey>": the same setting can come from several branches.
        // One row per setting, one column per branch — the lines grid's own shape. It was one row
        // per (branch, setting) pair, which put the same font on three rows and turned "whose do
        // I take" into a comparison across rows rather than across columns.
        // row id -> the branch whose value is taken. Absent means the Main keeps its own.
        settingsPick: {},
        // Folded: this screen is a line-by-line merge, and a block sitting open above the table
        // pushes the actual work off the screen for something usually empty.

        // Same shape for what the contributions SAY about their work. One difference: a taken
        // value can be reworded first, so the text is held apart from the choice.
        publicationPick: {},
        publicationValues: {},
        stats: { newKeys: 0, different: 0 },

        init() {
            this.initEditorCore();

            // The page chrome asks before hiding a contribution — see merge-table.js. A plain
            // window event rather than shared state: it is one question with one answer, and the
            // two components have no other reason to know about each other.
            window.addEventListener('ugt-count-picks-from', (e) => {
                e.detail.count = this.countClaimedPicksFrom(e.detail.branchId);
            });

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
            this.settingsPick = {};
            this.publicationPick = {};
            this.publicationValues = {};

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

            this.buildMetadataRows(payload);

            // 🔴 **Open when there is something in them.** Folded was right while they listed
            // everything; they list only differences, so a folded block is a difference nobody
            // sees — and the whole point of putting them on this screen was that a contribution's
            // fonts and wording stopped travelling upstream unnoticed. They fold back on a click,
            // and the day they also list what AGREES, this is where the rule changes again.
            // ⚠ Open on a DISAGREEMENT, not on having rows. Now that both tables list what the
            // file carries, having rows only means the translation has settings — true of nearly
            // every file, and not a reason to push the lines down the page.
            this.settingsOpen = this.settingsDifferenceCount() > 0;
            this.publicationOpen = this.publicationDifferenceCount() > 0;

            // ⚠ Only once one of them is on screen: they share the lines' columns, and sharing
            // them means sharing their WIDTHS, which do not exist until a photograph is taken.
            if (this.hasSettingsRows || this.hasPublicationRows) {
                this.$nextTick(() => this.alignGridsToEachOther());
            }

            // Once the columns are laid out: a grid wider than its box has answers off screen
            // from the first paint, not only after somebody scrolls.
            this.$nextTick(() => this.refreshOffScreenSides());

            // 🔴 Before the defaults, and only now: the branches did not exist when the sitting's
            // pending work was restored (that happens in initEditorCore, before the fetch), so
            // "does this column exist" could not be asked yet. applySmartDefaults then fills the
            // rows this just emptied — it skips keys that still hold an answer.
            this.dropAnswersWithNoColumn();

            this.calculateStats();
            this.applySmartDefaults();
            this.loaded = true;
        },

        /**
         * Forget every answer pointing at a contribution this screen is not showing.
         *
         * 🔴 **Hiding a contribution is closing it, and closing is a cancel** — the user's own rule,
         * the same one that makes reopening a screen start afresh. Keeping such answers inert and
         * bringing them back later is precisely the "you think one thing, it does another" this
         * screen must not do. `merge` → `?mode=edit` is the same story: no contribution is shown
         * there, so none of their answers survives the crossing.
         *
         * ⚠ Rewordings are untouched. Rewriting a line makes its answer `manual` (onEditStaged), so
         * it names no contribution and belongs to whoever typed it.
         */
        dropAnswersWithNoColumn() {
            for (const key of Object.keys(this.selections)) {
                const source = this.selections[key].source;
                if (source === 'main' || source === 'manual') continue;
                if (this.entryOf(key, source) !== undefined) continue;

                delete this.selections[key];
            }
        },

        /**
         * Answer the page chrome's question before it hides a contribution — see merge-table.js.
         *
         * ⚠ **Clicked answers only** (`byHand`), never `auto`: the two look interchangeable and are
         * not. `auto` says "do not promote this A to V", so the defaults leave it false on every
         * line that is not an `A` — reading it here counted 18 rows nobody had touched, on a page
         * just opened, and would have asked for a confirmation about nothing.
         */
        countClaimedPicksFrom(branchId) {
            const source = 'branch_' + branchId;
            let count = 0;

            for (const key of Object.keys(this.selections)) {
                if (this.selections[key].source === source && this.isByHand(key)) count++;
            }

            return count;
        },

        /**
         * Pre-select what a Main owner would almost always pick anyway.
         *
         * 🔴 The other comparison screen has done this since it existed; this one never has, so
         * the same file opened from two places asked for two different amounts of work. Same
         * rule, ported: a line only a contribution has is taken, and where both hold one the
         * better tag wins — the socle's ladder, capture < A < V < H = S.
         *
         * ⚠ **A refusal stands level with a hand-written line, and nothing is pre-selected on a
         * tie.** So H against H, H against S and S against H are shown and left alone — as is the
         * commonest tie of all, two machine translations that differ. That is what the strict `>`
         * below means, and it is the one asymmetry separating this screen from a three-way merge:
         * there, the two sides are equal and an ancestor says who moved; here, one side published
         * and the other is proposing.
         *
         * 🔴 **A tie is NOT pre-answered, and this is the rule the screen turns on.** Every
         * selection is written back with A promoted to V (TranslationService::resolveMergedTag) —
         * picking a version means "I read this". Pre-selecting the Main on a tie therefore wrote a
         * validation nobody performed: on the lineage this was measured against, opening the page
         * and pressing Merge marked 18 machine lines human-checked without anyone reading one.
         *
         * ⚠ Validating stays one click away, and the click is what makes it true: pick a column,
         * switch the tag, or edit the value. What the defaults may do is take what OUTRANKS what
         * the Main holds — there, a contributor has already done the reading.
         *
         * ⚠ Keys already chosen are skipped, so a review interrupted and resumed (the pending
         * state is restored on load) does not have its decisions overwritten by the defaults.
         *
         * ⚠ Nothing is written by this: it fills the same selection map a click fills, and the
         * owner unpicks whatever they disagree with before applying. A default is a starting
         * point, not a decision taken on somebody's behalf.
         */
        /**
         * The column a chosen source lives in.
         *
         * ⚠ A rewording shows in the Main's own column, because that is where it will be
         * written — so it points there, not at the contribution it was written over.
         */
        // What the mark says when somebody rests on it. Translated server-side, so it is
        // handed to the shared module rather than built inside it.
        offScreenHint: @js(__('merge.answer_off_screen')),

        // The same three answers for a translated LINE, asked by key. The shared module speaks in
        // sources ('main', 'branch_7'); a line keeps its own in the selection map, and these are
        // the only place that knows it.
        lineAnswerLeft(key) {
            const sel = this.selections[key];
            return !!sel && this.answerLeft(sel.source);
        },

        lineAnswerRight(key) {
            const sel = this.selections[key];
            return !!sel && this.answerRight(sel.source);
        },

        lineAnswerIconClass(key) {
            const sel = this.selections[key];
            return sel ? this.answerIconClass(sel.source) : '';
        },

        goToLineAnswer(key) {
            const sel = this.selections[key];
            if (sel) this.goToAnswer(sel.source);
        },

        /**
         * The best a contribution offers for one line, or null when none offers anything.
         *
         * 🔴 **A contribution can be a TAG and not a word.** Reading the Main's machine
         * translation and marking it correct changes no text and is exactly the work this site
         * asks for. Comparing values alone dropped every one of them — seventeen on the lineage
         * this was measured against, all of them a contributor's V sitting on the Main's A, none
         * ever settled by anybody.
         *
         * ⭐ Two contributions of equal quality on one line are separated by what the owner
         * already said about their AUTHORS — the stars on the branch selector. Somebody who has
         * judged a contributor once should not be made to judge them again, line by line.
         *
         * ⚠ Strictly greater on both counts, so unrated or equally rated leaves the first one in
         * front — and "first" is the order the branch list is built in: unreviewed before
         * reviewed, then best rated, then most recent.
         *
         * ⚠ Its own method because the BUTTON counts what it is about to answer with it. Asking
         * the question one way and answering it another is how a control ends up offering to
         * settle two thousand lines it will not touch.
         */
        bestContributionFor(key) {
            const mainEntry = this.mainData[key];
            let best = null;

            for (const branch of this.branches) {
                const entry = branch.content[key];
                if (entry === undefined) continue;

                if (mainEntry !== undefined
                    && this.getValue(entry) === this.getValue(mainEntry)
                    && this.getTag(entry) === this.getTag(mainEntry)) continue;

                // The mod's own interface is not a line of the game: never offered, never taken.
                if (!this.isGameLine(entry)) continue;

                const rank = this.priorityOf(entry);
                const rating = branch.main_rating ?? 0;

                if (!best
                    || rank > best.rank
                    || (rank === best.rank && rating > best.rating)) {
                    best = { branch, entry, rank, rating };
                }
            }

            return best;
        },

        applySmartDefaults() {
            for (const key of this.allKeys) {
                if (key in this.selections) continue;
                if (this.isDeleted(key)) continue;

                const mainEntry = this.mainData[key];

                // ⚠ -1, not 0: a key the Main does not hold at all must lose to anything, and the
                // floor of the ladder is 0 (a captured line). The whole ENTRY is weighed, so a
                // blank capture on the Main no longer outranks a contributor's real translation.
                // ⚠ The Main's own interface lines are not arbitrated either.
                const mainTag = mainEntry === undefined
                    ? -1
                    : (this.isGameLine(mainEntry) ? this.priorityOf(mainEntry) : Infinity);

                const best = this.bestContributionFor(key);

                // No contribution holds anything different: nothing to settle on this line.
                if (!best) continue;

                // 🔴 **Every contested row arrives answered — and an answer that keeps a machine
                // line does NOT claim somebody read it.**
                //
                // Taking a version is written back with A promoted to V (resolveMergedTag): picking
                // means "I checked this". So a default that lands on an `A` used to state a
                // validation nobody performed — on the lineage this was measured against, opening
                // the page and pressing Merge marked 18 machine lines human-checked with nobody
                // having read one, and the file's quality bar rose on its own.
                //
                // Leaving those rows blank instead was worse in the other direction: the
                // contribution stayed unanswered, and the row vanished from any filtered view.
                //
                // So they are answered, and the answer carries `auto`: taken as they are, tag
                // untouched, drawn in a paler colour with a dashed ring. One click on the same
                // column turns it into an ordinary pick — which is what validating IS, said once,
                // deliberately, by somebody who has the two versions in front of them.
                if (best.rank > mainTag) {
                    this.selections[key] = this.pick('branch_' + best.branch.id,
                                                     this.getValue(best.entry),
                                                     this.getTag(best.entry));
                    continue;
                }

                // ⚠ The same definition `select` returns to when a pick is undone, so the row the
                // page opens on and the row an undo lands on are the same row. It answers null
                // where holding the Main would write nothing at all — see defaultSelection.
                const held = this.defaultSelection(key);
                if (held) this.selections[key] = held;
            }

            this.applyMetadataDefaults();
        },

        /**
         * The same principle on the settings and on what the contributions say about their work.
         *
         * ⚠ **Only what the Main does not have at all.** A line is arbitrated on its tag — human
         * beats machine — and neither a font nor a description carries one, so there is nothing
         * to arbitrate on: every such row is a tie, and a tie goes to the Main, exactly as it
         * does above. What a contribution ADDS where the Main holds nothing is the case with no
         * question in it, and it is pre-taken.
         *
         * ⚠ Nothing is written by this either. The owner unpicks what they disagree with, and
         * every row stays on screen whether it was pre-taken or not.
         */
        applyMetadataDefaults() {
            for (const row of this.settingsRows) {
                if (this.settingsPick[row.id] !== undefined) continue;
                if (row.mineRaw) continue;

                const branch = this.branches.find((b) => row.byBranch[b.id] !== undefined);
                if (branch) this.settingsPick[row.id] = branch.id;
            }

            // 🔴 **A description is never adopted on its own, even when the Main has none.**
            // It is not a line: it speaks in the Main owner's name, on their public page. A
            // contribution proposing one is proposing how somebody else's translation presents
            // itself, and that is theirs to accept in as many words. Measured on a real lineage,
            // four contributions all proposed "Cas dérivé à l'import — donnée de test" over a
            // Main that had said nothing; the defaults adopted the first.
            //
            // ⚠ So the Main is picked, not left blank. The row is answered, it shows as
            // answered, and taking a contribution's wording stays one click away.
            // ⚠ Nothing to arbitrate, nothing to answer. Lighting the Main's cell on a screen
            // with no contributions would say "chosen" about a row where there was never a
            // choice.
            if (!this.canTakeContributions()) return;

            for (const row of this.publicationRows) {
                if (this.publicationPick[row.id] !== undefined) continue;
                this.publicationPick[row.id] = 'main';
            }
        },

        /**
         * Settings a branch holds differently from the Main.
         *
         * Applying a merge rewrites the Main's file, so a branch's fonts or exclusions never
         * travelled upstream: the page could say a section differed, never which entry, and
         * accepting every line accepted none of them. One row per differing setting, per branch,
         * because two branches may hold the same setting with different values — the Main has to
         * say whose it takes.
         */
        // The words for those rows. They are translated server-side, so they are handed to the
        // shared module rather than built inside it.
        metadataLabels: {
            sections: @js([
                'fonts' => __('file_settings.label.fonts'),
                'font_rules' => __('file_settings.label.font_rules'),
                'images' => __('file_settings.label.images'),
                'exclusions' => __('file_settings.label.exclusions'),
                'variables' => __('file_settings.label.variables'),
                'game_settings' => __('file_settings.game_settings'),
            ]),
            fields: @js([
                'notes' => __('upload.notes'),
                'resources_url' => __('upload.resources_url'),
            ]),
            absent: @js(__('merge_preview.settings_absent')),
        },

        // ── Taking a value, on the gestures the lines already use ───────────────────
        //
        // Click a contribution's cell to take it, click the Main's to keep your own. No checkbox:
        // the lines below are chosen exactly this way, and one screen holding two ways of saying
        // "this one" is one way too many.

        settingsTake(row, branchId) {
            if (row.byBranch[branchId] === undefined) return;
            this.settingsPick = { ...this.settingsPick, [row.id]: branchId };
        },

        settingsKeepMine(row) {
            const pick = { ...this.settingsPick };
            delete pick[row.id];
            this.settingsPick = pick;
        },

        /**
         * ⚠ Nothing picked means NO highlight anywhere, as on a line nobody has touched. The Main
         * column was lit by default here, which said "chosen" about a row where nothing had been
         * decided — and left no way to tell it apart from one where the owner had deliberately
         * kept their own.
         */
        settingsCellClass(row, branchId) {
            const picked = this.settingsPick[row.id];
            if (branchId === null) return '';
            return picked === branchId ? 'selected-branch' : '';
        },

        /**
         * Take a contribution's value.
         *
         * 🔴 Records the CHOICE and touches no text. Every grid on this site works that way, and
         * for a reason that only shows once it is broken: copying the taken value into the Main's
         * cell destroys the very thing being compared — the wording you are choosing against.
         * A cell's text changes on one occasion only, a manual edit, and that is what the purple
         * says. Mixing the two makes the colour mean two things and the column mean nothing.
         */
        publicationTake(row, branchId) {
            if (row.byBranch[branchId] === undefined) return;

            // Re-clicking the chosen one drops the choice, the way a line behaves.
            const pick = { ...this.publicationPick };
            if (pick[row.id] === branchId) delete pick[row.id];
            else pick[row.id] = branchId;
            this.publicationPick = pick;

            // A pending rewording is dropped: it was written against another wording, and
            // keeping it would show the Main's cell disagreeing with the cell just chosen.
            const values = { ...this.publicationValues };
            delete values[row.id];
            this.publicationValues = values;
        },

        publicationKeepMine(row) {
            const pick = { ...this.publicationPick };
            if (pick[row.id] === 'main') delete pick[row.id];
            else pick[row.id] = 'main';
            this.publicationPick = pick;

            // Keeping one's own words drops a rewording staged over them: the two are the same
            // cell, and leaving the text behind would light "kept mine" over somebody's edit.
            const values = { ...this.publicationValues };
            delete values[row.id];
            this.publicationValues = values;
        },

        /** What the Main's cell shows: whatever is staged, or the Main's own. */
        /**
         * The rows each grid shows.
         *
         * 🔴 **"Modified only" removes nothing here, and that is not an oversight.** On the lines
         * it hides rows with no pending decision — most of a file. These two tables are BUILT
         * from differences: every row in them is something to decide, so the same predicate is
         * true of all of them.
         *
         * ⚠ Filtering them on "already picked" instead emptied both tables the moment the box
         * defaulted to on, which is exactly how a screen ends up showing nothing and looking
         * broken. The day the blocks list settings that AGREE as well — they should, for
         * information — this getter is where the filter starts discriminating.
         */
        /**
         * This screen arbitrates contributions, so it takes — as soon as there is one to take
         * from. The shared default is no, precisely because the other screens show a second
         * column without granting that right.
         */
        canTakeContributions() {
            return this.metaOtherColumns().length > 0;
        },

        visibleSettingsRows() {
            return this.settingsRows;
        },

        visiblePublicationRows() {
            return this.publicationRows;
        },

        /**
         * What the Main's cell shows: its own value, or a rewording staged over it.
         *
         * ⚠ Never the value taken from a contribution. That one is shown where it is, in the
         * contribution's own column, and only resolved into a value when the merge is applied.
         */
        publicationResult(row) {
            return this.publicationValues[row.id] ?? row.mineValue;
        },

        /**
         * Core hook: the shared edit box was used on a description or a link.
         *
         * ⚠ Deliberately NOT the lines' edit map. That one becomes a line selection on save,
         * so a description staged there would be published as a translated line named "notes".
         * Typing the Main's own value back drops the staging, exactly as it does on a line.
         */
        stageScopedEdit(scope, field, value) {
            if (scope !== 'publication') return;

            const row = this.publicationRows.find((r) => r.field === field);
            if (!row) return;

            if (value === (row.mineRaw ?? '')) {
                this.publicationKeepMine(row);
                return;
            }

            this.publicationPick = { ...this.publicationPick, [field]: 'manual' };
            this.publicationValues = { ...this.publicationValues, [field]: value };
        },

        publicationCellClass(row, branchId) {
            const picked = this.publicationPick[row.id];

            // The Main's cell lights for its own two answers and for nothing else: kept as it
            // stands, or reworded over. Taking a contribution lights the contribution's cell,
            // where the chosen words actually are — never this one.
            if (branchId === null) {
                if (picked === 'manual') return 'selected-manual';
                return picked === 'main' ? 'selected-main' : '';
            }

            return picked === branchId ? 'selected-branch' : '';
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

        /** The tiles, from the core's one vocabulary. */
        calculateStats() {
            const counts = this.categoryCounts;
            this.stats = { newKeys: counts.new, different: counts.differing };
        },

        /**
         * Three boxes over the core's four situations: this screen folds `onlyOnTarget` onto
         * `catSame`.
         *
         * ⚠ **A choice, not an omission.** A line no contribution carries reads here as "nothing to
         * settle", exactly like one they all agree on — and on a Main of two thousand lines with
         * two branches, that is nearly the whole file. The comparison screen keeps them apart
         * because there the two situations are two columns somebody is arbitrating between.
         */
        categoryFilter(category) {
            if (category === 'onlyOnTarget') return 'catSame';
            return 'cat' + category.charAt(0).toUpperCase() + category.slice(1);
        },

        // ── Shared-core callbacks ────────────────────────────────────────

        rowPassesFilters(key) {
            if (this.filters.modifiedOnly && !this.isRowModified(key)) {
                return false;
            }

            if (!isEditMode && this.branches.length > 0) {
                if (!this.filters[this.rowCategoryFilter(key)]) return false;
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

        {{-- Capture-order index: the core reads the target first, then the sources — which is
             exactly what this screen used to spell out for itself. --}}

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

        /**
         * One branch's share of a given band, for the header bar.
         *
         * The shares are computed server-side by Translation::qualityShares and travel with the
         * branch: recomputing them here from three counters is exactly how this header came to
         * disagree with the same branch's card, which counts captured and kept-as-is lines too.
         */
        branchPercent(tag, branch) {
            return (branch && branch.shares && branch.shares[tag]) || 0;
        },

        /** Human + validated over everything that branch has met — the headline figure. */
        branchHumanShare(branch) {
            return Math.round(this.branchPercent('H', branch) + this.branchPercent('V', branch));
        },

        /**
         * A branch cell, with what it changes relative to MAIN underlined.
         *
         * Main is the reference and stays unmarked: with several branches side
         * by side, marking every column against every other would leave nothing
         * readable. Each branch answers one question — what does it propose
         * that Main does not say? A key Main does not have passes null: the
         * whole line is new, and underlining all of it would say nothing.
         */
        branchValueHtml(branch, key) {
            const mine = this.getValue(branch.content[key]);
            const other = key in this.mainData ? this.getValue(this.mainData[key]) : null;
            return this.highlightDifference(mine, other);
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

        /**
         * Core hook: setting a tag by hand claims this row's answer.
         *
         * A row the screen proposed for itself (dashed) becomes an ordinary pick, exactly as a
         * click on its column would make it. A row nobody had answered gets one, on the Main and
         * with the Main's current value — a tag is about the line that will be written, and here
         * that line is the Main's.
         */
        onTagSet(key) {
            const held = this.selections[key];

            if (held) {
                this.selections[key] = this.byHand({ ...held, auto: false });
                return;
            }

            const entry = this.mainData[key];
            if (entry === undefined) return;

            this.selections[key] = this.byHand(
                this.pick('main', this.getValue(entry), this.getTag(entry), false));
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
        // ── The roles this screen plays ──────────────────────────────────
        //
        // The Main receives; the contributions propose. In edit mode there are no contributions at
        // all — somebody is correcting their own file — which is what makes the core answer that
        // there is nobody to answer, and therefore nothing to hold unclaimed.

        /** Core hook: the result is built on the Main. */
        targetSource() { return 'main'; },

        /** Core hook: where to ask whether the files still say what this page shows. */
        freshnessUrl() {
            return @js(route('translations.merge.state', ['uuid' => $uuid]));
        },

        /**
         * Core hook: what counts as "the files moved".
         *
         * ⚠ **The contributions on screen, not every contribution in the lineage.** A merge reads
         * several files and any of them moving changes what it proposes — but one being updated
         * while nobody is showing it changes nothing here, and announcing it would be an alarm
         * about a column that is not on the page.
         */
        freshnessMark(state) {
            const shown = this.branches.map(b => b.id + ':' + (state.branches?.[b.id] ?? ''));
            return [state.file_hash, ...shown].join('|');
        },

        /** Core hook: one column per contribution being reviewed. */
        sourceIds() {
            return this.branches.map(branch => 'branch_' + branch.id);
        },

        /** Core hook: what a given column holds for this key. */
        entryOf(key, id) {
            if (id === 'main') return this.mainData[key];

            const branch = this.branches.find(b => 'branch_' + b.id === id);
            return branch ? branch.content[key] : undefined;
        },

        select(key, source) {
            // Even on inert rows the click moves the search cursor (IDE caret)
            this.focusRow(key);
            if (this.isDeleted(key)) return;

            // Clicking the column this row is already on: held → claimed → back to its own
            // default. The three states and their reasons live in the core, because the preview
            // screen runs the identical gesture on the identical grid.
            if (this.advancePick(key, source)) return;

            // ⚠ Claimed, never auto: this ran because somebody clicked. A pick made by hand on an
            // `A` line is exactly the validation the defaults refuse to invent. Null when the
            // column holds nothing for this key.
            const picked = this.pickFrom(key, source);
            if (!picked) return;

            // A click only ever produces a REAL change (see analyse/editors-gestures-parity.md):
            // picking Main acts when it validates an A line, or when it replaces an existing
            // selection (branch pick / manual edit) — on a V/H/M/S line with nothing selected it
            // would rewrite the line identically and count a phantom modification.
            if (source === 'main' && picked.tag !== 'A' && !this.selections[key]) return;

            // Choosing a version discards a pending manual edit
            delete this.editedValues[key];

            this.selections[key] = picked;
            this.persistPendingState();
        },

        getCellClass(key, source) {
            const sel = this.selections[key];
            if (!sel) return '';
            if (sel.source === source) {
                const held = source === 'main' ? 'selected-main' : 'selected-branch';

                // ⚠ The colour still says WHICH column is held; the modifier says how firmly. Two
                // separate facts, so they are two separate classes rather than four names.
                return sel.auto ? held + ' selection-unclaimed' : held;
            }
            // A manual edit displays in the Main column
            if (source === 'main' && sel.source === 'manual') {
                return 'selected-manual';
            }
            return '';
        },

        /** Core hook: this screen's rows are the Main's. */
        entryOnFile(key) {
            return this.mainData[key];
        },

        /**
         * Core hook: the tag the save will store here.
         *
         * ⚠ **Answered even where the Main holds nothing.** The core's default returns null there,
         * and rightly so for the tag a row is LEAVING — an absent line has none. But a row about to
         * be added does get one, and returning null left the cell blank: a new line held as `A`
         * showed no letter at all, so the one thing the paler colour promises — that this stays
         * machine-written until somebody says otherwise — could not be read anywhere.
         *
         * The arrow stays absent all the same: `tagWillChange` asks for a tag on file, and there is
         * none. Nothing to leave, something to arrive.
         */
        tagAfterSave(key) {
            return this.displayMainTag(key);
        },

        /** Core hook: a row on its way out is not open to a tag change. */
        tagCellDisabled(key) {
            return this.isDeleted(key);
        },

        /**
         * Tag the save will PRODUCE for the Main entry — previewed live,
         * before anything is saved. Core displayTag covers tag change → that
         * tag and manual edit → H (M/S preserved); on top of it, a selected
         * version keeps its tag with the server's A → V promotion.
         *
         * ⚠ Still its own method although `tagAfterSave` delegates to it: the row filter and the
         * quality bar ask this question of rows the Main does not hold (a pending edit on a line
         * only a branch has), where the core's hook answers null by contract.
         */
        displayMainTag(key) {
            if (this.hasTagChange(key) || this.isEdited(key)) {
                // ⚠ tagOnFile, not getTag(mainData): on a row the Main does not hold, getTag reads
                // undefined and answers 'A', so writing one's own translation of a line only a
                // branch has showed A instead of the H the save produces. tagOnFile answers null
                // there, and the core reads null as "nothing stored" — which is what it is.
                return this.displayTag(key, this.tagOnFile(key));
            }
            const sel = this.selections[key];
            if (sel) {
                // ⚠ An unclaimed pick keeps its tag: the save promotes A to V only where somebody
                // said so, and the cell has to preview what will actually be written — otherwise
                // the row shows a V nobody is going to get.
                return sel.tag === 'A' && !sel.auto ? 'V' : sel.tag;
            }
            return this.tagOnFile(key);
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

        get deleteCount() {
            return Object.keys(this.deletions).length;
        },

        get tagChangeCount() {
            return Object.keys(this.tagChanges).length;
        },

        settingsTakenCount() {
            return Object.keys(this.settingsPick).length;
        },

        /**
         * Answered is not the same as CHANGED.
         *
         * 🔴 Every description row arrives answered — the Main keeps its own words unless told
         * otherwise — and counting that as a modification put a "1" on a block where nothing was
         * going to be written. On a translated LINE the same gesture does change something: the
         * apply endpoint reads a Main selection as "validate this" and promotes a machine
         * translation to human-checked. There is no such promotion here, so keeping one's own
         * wording writes the wording that is already stored, and that is not a modification.
         */
        publicationTakenCount() {
            let count = 0;
            for (const row of this.publicationRows) {
                if (this.publicationPick[row.id] === undefined) continue;
                if (this.publicationResolved(row) !== (row.mineRaw ?? '')) count++;
            }
            return count;
        },

        /** What this row would write, whichever of the three answers it carries. */
        publicationResolved(row) {
            const pick = this.publicationPick[row.id];
            if (pick === undefined) return row.mineRaw ?? '';
            if (pick === 'manual') return this.publicationValues[row.id] ?? '';
            if (pick === 'main') return row.mineRaw ?? '';
            return row.byBranch[pick];
        },

        get totalChanges() {
            // Settings count as changes: taking a branch's font without touching a single line
            // is a merge, and the Apply button must not stay disabled on it.
            //
            // 🔴 **One pair of parentheses, not two.** `settingsTakenCount()()` called the NUMBER
            // the first call returned, so this getter threw on every evaluation — and a getter that
            // throws inside Alpine takes the whole binding down silently: the counter stayed at 0,
            // the Save button stayed disabled, and no Main could merge a single contribution.
            // Four days in production, invisible to the tests, which read the rendered HTML and
            // never run the script.
            // 🔴 **Lines, not gestures.** This added its counters together, so a row somebody
            // picked AND tagged counted twice — measured: 59 rows touched, the button said 61. The
            // comparison screen has always counted rows; only this one summed. `isRowModified` is
            // the one test for "this row has an answer", and every gesture on a row goes through it.
            let rows = 0;
            for (const key of this.allKeys) {
                if (this.isRowModified(key)) rows++;
            }

            // The two metadata blocks are not lines and cannot collide with them: taking a
            // contribution's font without touching a single line is a merge, and the Save button
            // must not stay disabled on it.
            return rows + this.settingsTakenCount() + this.publicationTakenCount();
        },

        clearAll() {
            if (confirm(@js(__('merge.cancel_all')))) {
                this.selections = {};
                this.clearPendingState();
            }
        },

        /**
         * Put the proposal back on whatever is still unanswered.
         *
         * 🔴 The defaults run once, at load. Cancel — or unticking a handful of rows — therefore
         * left the review with no way back to them short of reloading the page, which is the one
         * gesture that also throws away everything else.
         *
         * ⚠ Only what has no answer yet, which is what the button says: applySmartDefaults skips
         * any key already in `selections`, so a row somebody decided is left exactly as they
         * decided it. Pressing this twice does nothing the second time.
         */
        suggestTheRest() {
            this.applySmartDefaults();
            this.persistPendingState();
        },

        /**
         * Rows on screen that nobody and nothing has answered.
         *
         * ⚠ Counted over the FILTERED keys, not over the file: the button offers to answer what
         * is being looked at, and a count including the two thousand identical lines nobody is
         * reviewing would promise work it is not about to do.
         */
        get undecidedCount() {
            let count = 0;

            for (const key of this.allKeys) {
                if (key in this.selections) continue;
                if (this.isDeleted(key)) continue;
                // The same question the button answers, asked the same way. Counting every
                // unanswered key instead offered to settle 2480 lines both sides already agree
                // on — rows the button would not have touched.
                if (this.bestContributionFor(key)) count++;
            }

            for (const row of this.settingsRows) {
                if (this.settingsPick[row.id] === undefined && !row.mineRaw) count++;
            }
            for (const row of this.publicationRows) {
                if (this.publicationPick[row.id] === undefined && !row.mineRaw) count++;
            }

            return count;
        },

        // ── Submit (exact wire format of MergeController::apply) ─────────

        submitMerge() {
            if (this.totalChanges === 0) return;

            // 🔴 **One entry per row, tag included.** The tag used to travel in a channel of its
            // own, applied after the picks, so the file was right only because of the order our
            // code happened to run in — and the same row was counted twice on the way out. The
            // comparison screen has always sent one entry per row; this is the same shape.
            //
            // ⚠ Everything here is on screen: answers pointing at a contribution this view does not
            // show were dropped when it loaded — see dropAnswersWithNoColumn.
            const answered = new Set([
                ...Object.keys(this.selections),
                // A tag set on a row with no answer at all — not reachable from the UI today (the
                // tag cell needs one), kept so that a stored draft cannot lose one in silence.
                ...Object.keys(this.tagChanges),
            ]);

            const selectionsArr = [...answered].map((key) => {
                const data = this.selections[key] || {};
                const tagged = this.tagChanges[key];

                return {
                key,
                value: data.source === 'manual'
                    ? (this.editedValues[key] ?? data.value)
                    : (data.value ?? this.getValue(this.mainData[key])),
                // A tag somebody set by hand is written AS IS by the server (no H forcing, no
                // A → V promotion), which is what `tagchange` means to resolveMergedTag.
                tag: tagged ? tagged.newTag : data.tag,
                source: tagged ? 'tagchange' : data.source,

                // Whether anybody actually claimed this row. The server promotes A to V on a pick,
                // and this is what tells it apart from a row that merely arrived answered.
                auto: data.auto === true,
                // The value this page loaded — the common ancestor. The server
                // refuses to overwrite a line whose stored value no longer
                // matches it, which is how a save from here stops clobbering
                // captures the game uploaded while the page was open.
                base: this.getValue(this.mainData[key]) ?? ''
                };
            });
            const deletionsArr = Object.keys(this.deletions);

            // Settings: only WHICH branch entry is taken. The entry itself is copied server-side
            // from that branch's file — the page shows a readable summary that drops fields it
            // never renders, so rebuilding from it would strip them.
            const settingsByBranch = {};
            for (const row of this.settingsRows) {
                const branchId = this.settingsPick[row.id];
                if (branchId === undefined) continue;
                if (!settingsByBranch[branchId]) settingsByBranch[branchId] = {};
                settingsByBranch[branchId][row.key] = true;
            }
            document.getElementById('settingsJson').value = Object.keys(settingsByBranch).length > 0
                ? JSON.stringify(settingsByBranch) : '';

            // The final wording, not "take branch N's": the Main may have reworded it, and only
            // one text per field can end up on the page.
            const publication = {};
            for (const row of this.publicationRows) {
                if (this.publicationPick[row.id] === undefined) continue;

                // ⚠ Nothing is sent for a row that would write back what is already stored. The
                // endpoint refuses a merge with no changes at all, so a screen whose only answer
                // was "keep my own words" offered a Save that the server would turn down.
                const value = this.publicationResolved(row);
                // ⚠ Keyed by FIELD here, not by row id: this is the server's vocabulary
                // ("notes", "resources_url"), and the row id only happens to match it.
                if (value !== (row.mineRaw ?? '')) publication[row.field] = value;
            }
            document.getElementById('publicationJson').value = Object.keys(publication).length > 0
                ? JSON.stringify(publication) : '';

            document.getElementById('selectionsJson').value = selectionsArr.length > 0 ? JSON.stringify(selectionsArr) : '';
            document.getElementById('deletionsJson').value = deletionsArr.length > 0 ? JSON.stringify(deletionsArr) : '';

            // Pending work is about to be applied server-side
            this.clearPendingState();

            document.getElementById('mergeForm').submit();
        }
    }));
});
</script>
@endsection
