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
            {{ $main->source_language }} <i class="fas fa-arrow-right text-xs"></i> {{ $main->target_language }}
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
            <div x-show="hasSettingsRows" x-cloak class="mb-4">
                <button type="button" @click="settingsOpen = !settingsOpen"
                    class="w-full flex items-center gap-2 px-4 py-3 text-sm text-gray-300 bg-gray-800 border border-gray-700 rounded-lg hover:bg-gray-750 transition">
                    <i class="fas text-gray-500 w-3"
                       :class="settingsOpen ? 'fa-chevron-down' : 'fa-chevron-right'"></i>
                    <span>{{ __('merge.block_file_settings') }}</span>

                    {{-- Numeric badges, like the branch counter on the translations list: how many
                         differences wait inside, and how many are already taken. Both stay
                         readable folded, or ticking something and folding would hide it. --}}
                    <span class="bg-yellow-600 text-white text-xs rounded-full min-w-[1.25rem] h-5 flex items-center justify-center font-bold px-1"
                          title="{{ __('merge.filter_differences') }}" x-text="settingsRows.length"></span>
                    <span x-show="settingsTakenCount > 0" x-cloak
                          class="bg-purple-600 text-white text-xs rounded-full min-w-[1.25rem] h-5 flex items-center justify-center font-bold px-1"
                          title="{{ __('merge.modifications') }}" x-text="settingsTakenCount"></span>
                </button>

                {{-- Same table as the lines below, down to the column names. That is not a
                     resemblance: the widths are written as one stylesheet rule per
                     [data-col], so carrying the same names makes these columns line up with
                     the lines, follow the same drag and freeze with the same pin. --}}
                <div x-show="settingsOpen" x-cloak
                     class="mt-2 overflow-x-auto bg-gray-800 rounded-lg border border-gray-700">
                    <table class="editor-grid w-full text-sm border-separate border-spacing-0"
                       :class="[showLineBreaks && 'show-linebreaks', pinMain && !resizingColumns && 'pin-main', columnsSized && 'cols-sized']">
                        <thead class="bg-gray-900">
                            <tr>
                                {{-- The index column holds no number here, and is kept all the
                                     same: dropping it would shift every column after it and the
                                     two tables would stop lining up the moment it is shown. --}}
                                <th x-show="showIndexColumn" x-cloak
                                    class="px-2 py-3 w-16 min-w-[4rem] max-w-[4rem] sticky left-0 z-30 bg-gray-900"></th>
                                <th data-col="key"
                                    class="relative px-4 py-3 text-left text-gray-400 font-medium sticky z-30 bg-gray-900 border-r border-gray-700 shadow-[4px_0_8px_-4px_rgba(0,0,0,0.6)]"
                                    :class="showIndexColumn ? 'left-16' : 'left-0'">
                                    {{ __('merge_preview.settings_column') }}
                                    <x-editor.col-resize col="key" />
                                </th>
                                <th data-col="mainTag" class="px-2 py-3 border-l border-gray-700 w-12"></th>
                                <th data-col="main" class="relative px-4 py-3 text-left border-l border-gray-700 min-w-[250px]">
                                    <div class="flex items-center gap-2">
                                        <span class="text-green-400 font-medium">Main</span>
                                        <span class="text-xs text-gray-500" x-text="'(' + mainOwner + ')'"></span>
                                    </div>
                                    <x-editor.col-resize col="main" />
                                </th>
                                <template x-for="branch in branches" :key="branch.id">
                                    <th colspan="2" :data-col="'branch-' + branch.id"
                                        class="relative px-4 py-3 text-left border-l border-gray-700 min-w-[280px]">
                                        <span class="text-blue-400 font-medium" x-text="branch.name"></span>
                                        <x-editor.col-resize :bind="true" col="'branch-' + branch.id" />
                                    </th>
                                </template>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="row in visibleSettingsRows" :key="row.id">
                                <tr class="hover:bg-gray-750 transition-colors">
                                    <td x-show="showIndexColumn" x-cloak
                                        class="px-2 py-2 w-16 min-w-[4rem] max-w-[4rem] sticky left-0 z-10 bg-gray-800"></td>

                                    <td data-col="key"
                                        class="px-4 py-2 font-mono text-xs text-gray-500 break-words sticky z-10 bg-gray-800 border-r border-gray-700 shadow-[4px_0_8px_-4px_rgba(0,0,0,0.6)]"
                                        :class="showIndexColumn ? 'left-16' : 'left-0'"
                                        x-text="row.label"></td>

                                    {{-- No tag on a setting or a description, and the column stays
                                         so the value columns keep their place. --}}
                                    <td data-col="mainTag" class="px-2 py-2 text-center border-l border-gray-700 text-gray-600">—</td>

                                    {{-- Click = keep the Main's own, which is how a line's Main
                                         cell behaves. --}}
                                    <td data-col="main" class="px-4 py-2 border-l border-gray-700 merge-cell"
                                        :class="settingsCellClass(row, null)"
                                        @click="settingsKeepMine(row)">
                                    <span class="editor-text break-words" x-text="row.mineValue"></span>
                                    </td>

                                    <template x-for="branch in branches" :key="branch.id">
                                        <td colspan="2" :data-col="'branch-' + branch.id"
                                            class="px-4 py-2 border-l border-gray-700 merge-cell"
                                            :class="settingsCellClass(row, branch.id)"
                                            @click="settingsTake(row, branch.id)">
                                            <template x-if="row.byBranch[branch.id] === undefined">
                                                <span class="text-gray-600 italic">—</span>
                                            </template>
                                            <template x-if="row.byBranch[branch.id] !== undefined && isWebLink(row.byBranch[branch.id])">
                                                <a :href="row.byBranch[branch.id]" target="_blank"
                                                   rel="noopener noreferrer nofollow" @click.stop
                                                   class="text-blue-400 hover:underline break-all"
                                                   x-text="row.byBranch[branch.id]"></a>
                                            </template>
                                            <template x-if="row.byBranch[branch.id] !== undefined && !isWebLink(row.byBranch[branch.id])">
                                                <span class="editor-text break-words" x-text="row.byBranch[branch.id]"></span>
                                            </template>
                                        </td>
                                    </template>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>

                <p x-show="settingsOpen" x-cloak class="text-xs text-gray-500 mt-2">{{ __('merge.settings_pick_hint') }}</p>
            </div>

            <div x-show="hasPublicationRows" x-cloak class="mb-4">
                <button type="button" @click="publicationOpen = !publicationOpen"
                    class="w-full flex items-center gap-2 px-4 py-3 text-sm text-gray-300 bg-gray-800 border border-gray-700 rounded-lg hover:bg-gray-750 transition">
                    <i class="fas text-gray-500 w-3"
                       :class="publicationOpen ? 'fa-chevron-down' : 'fa-chevron-right'"></i>
                    <span>{{ __('merge.block_description') }}</span>

                    {{-- Numeric badges, like the branch counter on the translations list: how many
                         differences wait inside, and how many are already taken. Both stay
                         readable folded, or ticking something and folding would hide it. --}}
                    <span class="bg-yellow-600 text-white text-xs rounded-full min-w-[1.25rem] h-5 flex items-center justify-center font-bold px-1"
                          title="{{ __('merge.filter_differences') }}" x-text="publicationRows.length"></span>
                    <span x-show="publicationTakenCount > 0" x-cloak
                          class="bg-purple-600 text-white text-xs rounded-full min-w-[1.25rem] h-5 flex items-center justify-center font-bold px-1"
                          title="{{ __('merge.modifications') }}" x-text="publicationTakenCount"></span>
                </button>

                {{-- Same table as the lines below, down to the column names. That is not a
                     resemblance: the widths are written as one stylesheet rule per
                     [data-col], so carrying the same names makes these columns line up with
                     the lines, follow the same drag and freeze with the same pin. --}}
                <div x-show="publicationOpen" x-cloak
                     class="mt-2 overflow-x-auto bg-gray-800 rounded-lg border border-gray-700">
                    <table class="editor-grid w-full text-sm border-separate border-spacing-0"
                       :class="[showLineBreaks && 'show-linebreaks', pinMain && !resizingColumns && 'pin-main', columnsSized && 'cols-sized']">
                        <thead class="bg-gray-900">
                            <tr>
                                {{-- The index column holds no number here, and is kept all the
                                     same: dropping it would shift every column after it and the
                                     two tables would stop lining up the moment it is shown. --}}
                                <th x-show="showIndexColumn" x-cloak
                                    class="px-2 py-3 w-16 min-w-[4rem] max-w-[4rem] sticky left-0 z-30 bg-gray-900"></th>
                                <th data-col="key"
                                    class="relative px-4 py-3 text-left text-gray-400 font-medium sticky z-30 bg-gray-900 border-r border-gray-700 shadow-[4px_0_8px_-4px_rgba(0,0,0,0.6)]"
                                    :class="showIndexColumn ? 'left-16' : 'left-0'">
                                    {{ __('merge_preview.settings_column') }}
                                    <x-editor.col-resize col="key" />
                                </th>
                                <th data-col="mainTag" class="px-2 py-3 border-l border-gray-700 w-12"></th>
                                <th data-col="main" class="relative px-4 py-3 text-left border-l border-gray-700 min-w-[250px]">
                                    <div class="flex items-center gap-2">
                                        <span class="text-green-400 font-medium">Main</span>
                                        <span class="text-xs text-gray-500" x-text="'(' + mainOwner + ')'"></span>
                                    </div>
                                    <x-editor.col-resize col="main" />
                                </th>
                                <template x-for="branch in branches" :key="branch.id">
                                    <th colspan="2" :data-col="'branch-' + branch.id"
                                        class="relative px-4 py-3 text-left border-l border-gray-700 min-w-[280px]">
                                        <span class="text-blue-400 font-medium" x-text="branch.name"></span>
                                        <x-editor.col-resize :bind="true" col="'branch-' + branch.id" />
                                    </th>
                                </template>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="row in visiblePublicationRows" :key="row.id">
                                <tr class="hover:bg-gray-750 transition-colors">
                                    <td x-show="showIndexColumn" x-cloak
                                        class="px-2 py-2 w-16 min-w-[4rem] max-w-[4rem] sticky left-0 z-10 bg-gray-800"></td>

                                    <td data-col="key"
                                        class="px-4 py-2 font-mono text-xs text-gray-500 break-words sticky z-10 bg-gray-800 border-r border-gray-700 shadow-[4px_0_8px_-4px_rgba(0,0,0,0.6)]"
                                        :class="showIndexColumn ? 'left-16' : 'left-0'"
                                        x-text="row.label"></td>

                                    {{-- No tag on a setting or a description, and the column stays
                                         so the value columns keep their place. --}}
                                    <td data-col="mainTag" class="px-2 py-2 text-center border-l border-gray-700 text-gray-600">—</td>

                                    {{-- Click = keep the Main's own, which is how a line's Main
                                         cell behaves. --}}
                                    <td data-col="main" class="px-4 py-2 border-l border-gray-700 merge-cell"
                                        :class="publicationCellClass(row, null)"
                                        @click="publicationKeepMine(row)"
                                        @dblclick="editCell(row.field, publicationResult(row), 'publication')">
                                    {{-- The lines' own affordance, not a box of its own: revert
                                         what is staged, or open the shared edit modal. A textarea
                                         living in the cell was invented here — this screen already
                                         had one way to edit a value and did not need a second. --}}
                                    <span class="edit-affordance">
                                        <button type="button" x-show="publicationPick[row.field] !== undefined"
                                            @click.stop="publicationKeepMine(row)"
                                            title="{{ __('merge.revert_row') }}"><i class="fas fa-undo"></i></button>
                                        <button type="button"
                                            @click.stop="editCell(row.field, publicationResult(row), 'publication')"
                                            title="{{ __('translation.edit') }}"><i class="fas fa-pen"></i></button>
                                    </span>
                                    {{-- Purple on a rewording and on nothing else, exactly as on a
                                         line: it says "this text is not what is stored". Taking a
                                         contribution leaves this cell alone, so there is nothing
                                         here to mark. --}}
                                    <span class="editor-text break-words"
                                        :class="publicationPick[row.field] === 'manual' ? 'text-purple-300' : ''"
                                        x-text="publicationResult(row)"></span>
                                    </td>

                                    <template x-for="branch in branches" :key="branch.id">
                                        <td colspan="2" :data-col="'branch-' + branch.id"
                                            class="px-4 py-2 border-l border-gray-700 merge-cell"
                                            :class="publicationCellClass(row, branch.id)"
                                            @click="publicationTake(row, branch.id)">
                                            <template x-if="row.byBranch[branch.id] === undefined">
                                                <span class="text-gray-600 italic">—</span>
                                            </template>
                                            <template x-if="row.byBranch[branch.id] !== undefined && isWebLink(row.byBranch[branch.id])">
                                                <a :href="row.byBranch[branch.id]" target="_blank"
                                                   rel="noopener noreferrer nofollow" @click.stop
                                                   class="text-blue-400 hover:underline break-all"
                                                   x-text="row.byBranch[branch.id]"></a>
                                            </template>
                                            <template x-if="row.byBranch[branch.id] !== undefined && !isWebLink(row.byBranch[branch.id])">
                                                <span class="editor-text break-words" x-text="row.byBranch[branch.id]"></span>
                                            </template>
                                        </td>
                                    </template>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>

                <p x-show="publicationOpen" x-cloak class="text-xs text-gray-500 mt-2">{{ __('merge.publication_pick_hint') }}</p>
            </div>

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
                            <input type="checkbox" :checked="filters.catDiff" @change="toggleFilter('catDiff')"
                                class="rounded bg-gray-700 border-gray-600 text-yellow-600">
                            <span class="text-yellow-400">{{ __('merge.filter_differences') }}</span>
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" :checked="filters.catOther" @change="toggleFilter('catOther')"
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
                    <label class="flex items-center gap-1 text-xs cursor-pointer shrink-0" title="{{ __('merge.filter_differences') }}">
                        <input type="checkbox" :checked="filters.catDiff" @change="toggleFilter('catDiff')"
                               class="rounded bg-gray-700 border-gray-600 text-yellow-600">
                        <span class="text-yellow-400">≠</span>
                    </label>
                    <label class="flex items-center gap-1 text-xs cursor-pointer shrink-0" title="{{ __('merge_preview.same') }}">
                        <input type="checkbox" :checked="filters.catOther" @change="toggleFilter('catOther')"
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
                            {{-- Capture-order index (toggleable, sortable) --}}
                            {{-- The line's identity travels with it. Scrolling sideways used to
                                 carry the key off screen, and past the third column nobody could
                                 tell which line they were looking at. --}}
                            {{-- The width is PINNED, not suggested. A table lays its columns out
                                 to fit their content, so "w-16" was a hint the browser was free
                                 to ignore — and the key column, frozen at a hard left-16, then
                                 left a strip of nothing where the scrolled columns showed
                                 through. --}}
                            <th x-show="showIndexColumn" x-cloak
                                class="px-2 py-3 text-right text-gray-400 font-medium w-16 min-w-[4rem] max-w-[4rem] cursor-pointer hover:text-white transition sticky left-0 z-30 bg-gray-900"
                                @click="toggleSort('index')" title="{{ __('editor.capture_order_hint') }}">
                                <div class="flex items-center justify-end gap-1">
                                    <span class="text-xs">#</span>
                                    <i class="fas text-xs" :class="getSortIcon('index')"></i>
                                </div>
                            </th>
                            {{-- A right edge, because a frozen column is not an overlapping one:
                                 without it, the columns sliding underneath read as a rendering
                                 fault rather than as content passing behind a fixed edge. --}}
                            <th data-col="key"
                                class="relative px-4 py-3 text-left text-gray-400 font-medium cursor-pointer hover:text-white transition sticky z-30 bg-gray-900 border-r border-gray-700 shadow-[4px_0_8px_-4px_rgba(0,0,0,0.6)]"
                                :class="showIndexColumn ? 'left-16' : 'left-0'"
                                @click="toggleSort('key')">
                                <div class="flex items-center gap-2">
                                    {{ __('merge.key') }}
                                    <i class="fas" :class="getSortIcon('key')"></i>
                                </div>
                                <x-editor.col-resize col="key" />
                            </th>
                            {{-- data-col on the tag column too: the pin freezes the pair, since a
                                 value without its tag says only half of what the row holds. --}}
                            <th data-col="mainTag"
                                class="px-2 py-3 text-center border-l border-gray-700 w-12 cursor-pointer hover:text-white transition"
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
                                <td x-show="showIndexColumn" x-cloak
                                    class="px-2 py-2 text-right font-mono text-xs text-gray-600 tabular-nums align-top sticky left-0 z-10 bg-gray-800 w-16 min-w-[4rem] max-w-[4rem]"
                                    x-text="indexCellText(key)"></td>

                                {{-- Key --}}
                                <td data-col="key"
                                    class="px-4 py-2 font-mono text-xs text-gray-500 break-words sticky z-10 bg-gray-800 border-r border-gray-700 shadow-[4px_0_8px_-4px_rgba(0,0,0,0.6)]"
                                    :class="showIndexColumn ? 'left-16' : 'left-0'">
                                    <span class="editor-text" :class="isDeleted(key) ? 'line-through text-red-400' : ''" x-safe-html="highlightKey(key)"></span>
                                </td>

                                {{-- Main Tag (clickable for tag change) --}}
                                <td data-col="mainTag" class="px-2 py-2 text-center border-l border-gray-700"
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
                                <td data-col="main" class="px-4 py-2 border-l border-gray-700 merge-cell"
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
                            </tr>
                        </template>

                        <tr x-show="filteredKeys.length === 0">
                            <td :colspan="(showIndexColumn ? 4 : 3) + branches.length * 2" class="py-12 text-center text-gray-500">
                            {{-- Kept where the eye is, not where the table is: see .grid-visible-center --}}
                            <div class="grid-visible-center">
                                <i class="fas fa-search text-4xl mb-3 opacity-50"></i>
                                <p>{{ __('merge.no_keys_found') }}</p>
                            </div>
                        </td>
                        </tr>

                        <tr x-show="hiddenCount > 0">
                            <td :colspan="(showIndexColumn ? 4 : 3) + branches.length * 2" class="py-3 text-center">
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
                <input type="hidden" id="tagChangesJson" name="tag_changes_json" value="">
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
            // 🔴 On by default now that the screen arrives with choices already made. Landing on
            // a whole file to look for the handful of rows something happened to is work the
            // screen can do itself; the box is right there to widen it back out.
            modifiedOnly: true
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
        settingsRows: [],
        // row id -> the branch whose value is taken. Absent means the Main keeps its own.
        settingsPick: {},
        hasSettingsRows: false,
        // Folded: this screen is a line-by-line merge, and a block sitting open above the table
        // pushes the actual work off the screen for something usually empty.
        settingsOpen: false,

        // Same shape for what the contributions SAY about their work. One difference: a taken
        // value can be reworded first, so the text is held apart from the choice.
        publicationRows: [],
        publicationPick: {},
        publicationValues: {},
        hasPublicationRows: false,
        publicationOpen: false,
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

            this.buildSettingsRows(payload);
            this.buildPublicationRows(payload);

            this.calculateStats();
            this.applySmartDefaults();
            this.loaded = true;
        },

        /**
         * Pre-select what a Main owner would almost always pick anyway.
         *
         * 🔴 The other comparison screen has done this since it existed; this one never has, so
         * the same file opened from two places asked for two different amounts of work. Same
         * rule, ported: a line only a contribution has is taken, and where both hold one the
         * better tag wins — human over validated over machine — with the Main keeping ties.
         *
         * ⚠ Keys already chosen are skipped, so a review interrupted and resumed (the pending
         * state is restored on load) does not have its decisions overwritten by the defaults.
         *
         * ⚠ Nothing is written by this: it fills the same selection map a click fills, and the
         * owner unpicks whatever they disagree with before applying. A default is a starting
         * point, not a decision taken on somebody's behalf.
         */
        applySmartDefaults() {
            const tagPriority = { 'H': 3, 'V': 2, 'A': 1, 'M': 0, 'S': 0 };

            for (const key of this.allKeys) {
                if (key in this.selections) continue;
                if (this.isDeleted(key)) continue;

                const mainEntry = this.mainData[key];
                const mainTag = mainEntry === undefined ? -1
                    : (tagPriority[this.getTag(mainEntry)] ?? 0);

                // The best a contribution offers for this line. Several branches can hold it, so
                // this is a comparison among them before any comparison with the Main.
                let best = null;
                for (const branch of this.branches) {
                    const entry = branch.content[key];
                    if (entry === undefined) continue;
                    if (mainEntry !== undefined
                        && this.getValue(entry) === this.getValue(mainEntry)) continue;

                    const rank = tagPriority[this.getTag(entry)] ?? 0;
                    if (!best || rank > best.rank) {
                        best = { branch, entry, rank };
                    }
                }

                if (!best) continue;
                // The Main holds it at least as well: it keeps it, and no selection is recorded —
                // an unmarked row means "nothing changes here", which is what it does.
                if (best.rank <= mainTag) continue;

                this.selections[key] = {
                    source: 'branch_' + best.branch.id,
                    value: this.getValue(best.entry),
                    tag: this.getTag(best.entry),
                };
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

            for (const row of this.publicationRows) {
                if (this.publicationPick[row.field] !== undefined) continue;
                if (row.mineRaw) continue;

                const branch = this.branches.find((b) => row.byBranch[b.id] !== undefined);
                if (branch) this.publicationPick[row.field] = branch.id;
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
        buildSettingsRows(payload) {
            const labels = @js([
                'fonts' => __('file_settings.label.fonts'),
                'font_rules' => __('file_settings.label.font_rules'),
                'images' => __('file_settings.label.images'),
                'exclusions' => __('file_settings.label.exclusions'),
                'variables' => __('file_settings.label.variables'),
                'game_settings' => __('file_settings.game_settings'),
            ]);
            const absent = @js(__('merge_preview.settings_absent'));
            const mainSettings = payload.main_settings || {};
            const byKey = {};

            for (const branch of (payload.branches || [])) {
                const branchSettings = branch.settings || {};
                for (const key of Object.keys(branchSettings)) {
                    const mine = mainSettings[key];
                    const theirs = branchSettings[key];
                    // Identical on both sides: nothing to decide, and a row per agreeing setting
                    // would bury the few that actually differ.
                    if (mine && mine.value === theirs.value) continue;

                    if (!byKey[key]) {
                        byKey[key] = {
                            id: key,
                            key,
                            label: (labels[theirs.section] || theirs.section)
                                   + ' \u203a ' + theirs.label,
                            mineValue: mine ? mine.value : absent,
                            // What the Main actually holds, as opposed to what the cell shows
                            // when it holds nothing. Telling the two apart is what says whether
                            // a contribution is ADDING something or disagreeing with something.
                            mineRaw: mine ? mine.value : '',
                            byBranch: {},
                        };
                    }
                    byKey[key].byBranch[branch.id] = theirs.value;
                }
            }

            const rows = Object.values(byKey);
            rows.sort((a, b) => a.label.localeCompare(b.label));

            this.settingsRows = rows;
            this.hasSettingsRows = rows.length > 0;
        },

        /**
         * What each contribution says about its work, when it differs from the Main's.
         *
         * \u26a0 Two fields and no more. Whether a translation is finished descends from a Main to
         * its contributions and never travels back, so it is not offered here at all: a row that
         * cannot be taken is worse than an absent one.
         */
        buildPublicationRows(payload) {
            const labels = @js([
                'notes' => __('upload.notes'),
                'resources_url' => __('upload.resources_url'),
            ]);
            const absent = @js(__('merge_preview.settings_absent'));

            const mine = {
                notes: (payload.main_notes || '').trim(),
                resources_url: (payload.main_resources_url || '').trim(),
            };

            const rows = [];
            for (const field of ['notes', 'resources_url']) {
                const byBranch = {};
                for (const branch of (payload.branches || [])) {
                    const theirs = (branch[field] || '').trim();
                    // Nothing said, or the same thing said: no decision to put on screen.
                    if (!theirs || theirs === mine[field]) continue;
                    byBranch[branch.id] = theirs;
                }
                if (Object.keys(byBranch).length === 0) continue;

                rows.push({
                    id: field,
                    field,
                    label: labels[field],
                    // What is SHOWN when nothing is staged, and what the Main actually holds.
                    // They differ when it holds nothing: the cell then shows a placeholder, and
                    // comparing an edit against that placeholder would call it a change.
                    mineValue: mine[field] || absent,
                    mineRaw: mine[field],
                    byBranch,
                });
            }

            this.publicationRows = rows;
            this.hasPublicationRows = rows.length > 0;
        },

        // \u2500\u2500 Taking a value, on the gestures the lines already use \u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500
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
            this.publicationPick = { ...this.publicationPick, [row.field]: branchId };

            // A pending rewording is dropped: it was written against another wording, and
            // keeping it would show the Main's cell disagreeing with the cell just chosen.
            const values = { ...this.publicationValues };
            delete values[row.field];
            this.publicationValues = values;
        },

        publicationKeepMine(row) {
            const pick = { ...this.publicationPick };
            delete pick[row.field];
            this.publicationPick = pick;

            const values = { ...this.publicationValues };
            delete values[row.field];
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
        get visibleSettingsRows() {
            return this.settingsRows;
        },

        get visiblePublicationRows() {
            return this.publicationRows;
        },

        /**
         * What the Main's cell shows: its own value, or a rewording staged over it.
         *
         * ⚠ Never the value taken from a contribution. That one is shown where it is, in the
         * contribution's own column, and only resolved into a value when the merge is applied.
         */
        publicationResult(row) {
            return this.publicationValues[row.field] ?? row.mineValue;
        },

        /**
         * Core hook: the shared edit box was used on a description or a link.
         *
         * \u26a0 Deliberately NOT the lines' edit map. That one becomes a line selection on save,
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
            const picked = this.publicationPick[row.field];
            // Only a rewording lights the Main's cell, because only a rewording put something
            // there. Taking a contribution lights the contribution's cell, where the chosen
            // words actually are.
            if (branchId === null) return picked === 'manual' ? 'selected-manual' : '';
            return picked === branchId ? 'selected-branch' : '';
        },

        /**
         * Whether a value may be rendered as a clickable link.
         *
         * ⚠ http(s) and nothing else, checked here rather than trusted from the server. Every
         * write path validates the scheme already, so this cannot currently be reached — which
         * is exactly why it is cheap to keep: the day a fifth way to store one appears, an
         * href built from it is a hole, and a plain span is not.
         */
        isWebLink(value) {
            return typeof value === 'string'
                && (value.startsWith('https://') || value.startsWith('http://'));
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
            // Tag included, not just the text: a branch that only validated a
            // line (A → V) has genuinely diverged from Main, and treating it as
            // identical hides the very change the branch was made for.
            const mainValue = this.getValue(this.mainData[key]);
            const mainTag = this.getTag(this.mainData[key]);
            for (const branch of this.branches) {
                if (!(key in branch.content)) continue;
                if (this.getValue(branch.content[key]) !== mainValue
                    || this.getTag(branch.content[key]) !== mainTag) {
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
            this.focusRow(key);
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

        get settingsTakenCount() {
            return Object.keys(this.settingsPick).length;
        },

        get publicationTakenCount() {
            return Object.keys(this.publicationPick).length;
        },

        get totalChanges() {
            // Settings count as changes: taking a branch's font without touching a single line
            // is a merge, and the Apply button must not stay disabled on it
            return this.selectionCount + this.deleteCount + this.tagChangeCount
                   + this.settingsTakenCount + this.publicationTakenCount;
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
                source: data.source,
                // The value this page loaded — the common ancestor. The server
                // refuses to overwrite a line whose stored value no longer
                // matches it, which is how a save from here stops clobbering
                // captures the game uploaded while the page was open.
                base: this.getValue(this.mainData[key]) ?? ''
            }));
            const deletionsArr = Object.keys(this.deletions);
            const tagChangesArr = Object.entries(this.tagChanges).map(([key, data]) => ({
                key,
                tag: data.newTag,
                value: data.value
            }));

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
                const pick = this.publicationPick[row.field];
                if (pick === undefined) continue;
                // Resolved here rather than copied on click, so the screen never had to hold the
                // chosen text in the Main's cell to remember it.
                publication[row.field] = pick === 'manual'
                    ? (this.publicationValues[row.field] ?? '')
                    : row.byBranch[pick];
            }
            document.getElementById('publicationJson').value = Object.keys(publication).length > 0
                ? JSON.stringify(publication) : '';

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
