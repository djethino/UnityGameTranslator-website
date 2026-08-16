@props([
    // Alpine prefix for this block's state: 'settings' or 'publication'. Everything else is
    // derived from it, so a third kind of metadata needs no new markup — only the state.
    'name',
    // The block's own name, shown on the fold header.
    'title',
    // One sentence under the table, shown only when there is something to take.
    'hint' => null,
])

@php
    $rows    = $name . 'Rows';
    $has     = 'has' . ucfirst($name) . 'Rows';
    $open    = $name . 'Open';
    $pick    = $name . 'Pick';
    $visible = 'visible' . ucfirst($name) . 'Rows';
    $count   = $name . 'DifferenceCount()';
    $taken   = $name . 'TakenCount';
    $cell    = $name . 'CellClass';
    $take    = $name . 'Take';
    $keep    = $name . 'KeepMine';
@endphp

{{--
    What a translation carries besides its lines, on the grid its lines are read in.

    🔴 **One block, used for both kinds and reusable by every screen.** The settings and the
    description were the same table written twice in one file — same columns, same gestures, same
    marks — and each copy was free to drift from the other. One did: the pin froze one table's
    header and not the next one's body, for weeks, because a rule written per column could not
    reach a cell that had been merged in only one of them.

    ⚠ **Same columns as the lines, and that is not a resemblance.** Widths are written as one
    stylesheet rule per [data-col], so carrying the same names is what makes these columns line up
    with the lines, follow the same drag and freeze with the same pin.

    ⚠ **Nothing here decides whether a value CAN be taken.** That is `canTakeContributions()`, and
    a screen that reads or edits one translation answers no: no hand cursor over a cell that
    answers no question, no hint describing a click nobody can make.
--}}
<div x-show="{{ $has }}" x-cloak class="mb-4">
    <button type="button" @click="{{ $open }} = !{{ $open }}"
        class="w-full flex items-center gap-2 px-4 py-3 text-sm text-gray-300 bg-gray-800 border border-gray-700 rounded-lg hover:bg-gray-750 transition">
        <i class="fas text-gray-500 w-3"
           :class="{{ $open }} ? 'fa-chevron-down' : 'fa-chevron-right'"></i>
        <span>{{ $title }}</span>

        {{-- Numeric badges, like the branch counter on the translations list. The first counts
             DISPUTED rows, not rows: the table lists what the file carries, and "this translation
             has fonts" is not news. Both stay readable folded, or choosing something and folding
             would hide it. --}}
        <span x-show="{{ $count }} > 0" x-cloak
              class="bg-yellow-600 text-white text-xs rounded-full min-w-[1.25rem] h-5 flex items-center justify-center font-bold px-1"
              title="{{ __('merge.filter_differences') }}" x-text="{{ $count }}"></span>
        <span x-show="{{ $taken }} > 0" x-cloak
              class="bg-purple-600 text-white text-xs rounded-full min-w-[1.25rem] h-5 flex items-center justify-center font-bold px-1"
              title="{{ __('merge.modifications') }}" x-text="{{ $taken }}"></span>
    </button>

    <div x-show="{{ $open }}" x-cloak data-hscroll-follow
         class="mt-2 overflow-x-auto bg-gray-800 rounded-lg border border-gray-700">
        <table class="editor-grid w-full text-sm border-separate border-spacing-0"
           :class="[showLineBreaks && 'show-linebreaks', pinMain && !resizingColumns && 'pin-main', columnsSized && 'cols-sized']">
            <thead class="bg-gray-900">
                <tr>
                    {{-- The index column holds no number here, and is kept all the same: dropping
                         it would shift every column after it and the tables would stop lining up
                         the moment it is shown. --}}
                    <th x-show="showIndexColumn" x-cloak
                        class="px-2 py-3 w-16 min-w-[4rem] max-w-[4rem] sticky left-0 z-30 bg-gray-900"></th>
                    <th data-col="key"
                        class="relative px-4 py-3 text-left text-gray-400 font-medium sticky z-30 bg-gray-900 border-r border-gray-700 shadow-[4px_0_8px_-4px_rgba(0,0,0,0.6)]"
                        :class="showIndexColumn ? 'left-16' : 'left-0'">
                        {{ __('merge_preview.settings_column') }}
                        <x-editor.col-resize col="key" />
                    </th>
                    {{-- The tag column is kept, empty, and its border is the only one between the
                         key and the Main: the two head one block, and the title lands exactly
                         where the lines grid puts its own. --}}
                    <th data-col="mainTag" class="px-2 py-3 border-l border-gray-700 w-12"></th>
                    <th data-col="main" class="relative px-4 py-3 text-left min-w-[250px]">
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
                    {{-- The far end of the scrolling area. Zero width, frozen to the right edge of
                         the box: it takes no room and carries no data, it is only somewhere for a
                         mark to be. --}}
                    <th class="answer-rail"></th>
                </tr>
            </thead>
            <tbody>
                <template x-for="row in {{ $visible }}" :key="row.id">
                    <tr class="hover:bg-gray-750 transition-colors">
                        <td x-show="showIndexColumn" x-cloak
                            class="px-2 py-2 w-16 min-w-[4rem] max-w-[4rem] sticky left-0 z-10 bg-gray-800"></td>

                        <td data-col="key"
                            class="relative px-4 py-2 font-mono text-xs text-gray-500 break-words sticky z-10 bg-gray-800 border-r border-gray-700 shadow-[4px_0_8px_-4px_rgba(0,0,0,0.6)]"
                            :class="showIndexColumn ? 'left-16' : 'left-0'">
                            <span x-text="row.label"></span>
                            {{-- Which way the answer is, when it is off screen. Rides the last
                                 frozen cell, so it follows the pin on its own. --}}
                            <template x-if="!pinMain">
                                <button type="button"
                                    x-show="answerLeft({{ $pick }}[row.id])" x-cloak
                                    @click.stop="goToAnswer({{ $pick }}[row.id])"
                                    class="absolute left-full top-1/2 -translate-y-1/2 ml-1 z-20"
                                    :title="offScreenHint"
                                    ><i class="fas answer-mark" :class="answerIconClass({{ $pick }}[row.id])"></i></button>
                            </template>
                        </td>

                        {{-- 🔴 One cell over the tag column and the value column.

                             Neither a setting nor a description has a tag, so that column held a
                             dash — an empty stripe down the table, in the one place where the
                             lines below carry something. Kept as a COLUMN, because dropping it
                             would shift everything after it; merged as a CELL, because there is
                             nothing to put in it.

                             ⚠ No data-col on the merged cell, deliberately: the width rules are
                             written per column and one of them would squeeze the pair to a single
                             column's width. The two headers above still carry them, and fixed
                             layout reads the widths from there. It carries `main-pair` instead,
                             which is what the pin looks for. --}}
                        <td colspan="2" class="main-pair px-4 py-2 border-l border-gray-700"
                            :class="[canTakeContributions() && 'merge-cell', {{ $cell }}(row, null)]"
                            @click="canTakeContributions() && {{ $keep }}(row)">
                            {{ $mainCell }}
                        </td>

                        <template x-for="branch in branches" :key="branch.id">
                            <td colspan="2" :data-col="'branch-' + branch.id"
                                class="px-4 py-2 border-l border-gray-700 merge-cell"
                                :class="[{{ $cell }}(row, branch.id), metaCellTint(row, branch.id)]"
                                @click="{{ $take }}(row, branch.id)">
                                <template x-if="row.byBranch[branch.id] === undefined">
                                    <span class="text-gray-600 italic">—</span>
                                </template>
                                {{-- ⚠ A link is shown AS a link. It is the one value that cannot
                                     be judged by reading it: taking it puts it on the Main's
                                     page, and where it leads is the whole question. Only http(s)
                                     is ever made clickable. --}}
                                <template x-if="row.byBranch[branch.id] !== undefined && isWebLink(row.byBranch[branch.id])">
                                    <a :href="row.byBranch[branch.id]" target="_blank"
                                       rel="noopener noreferrer nofollow" @click.stop
                                       class="hover:underline break-all"
                                       :class="metaLinkTint(row, branch.id)"
                                       x-text="row.byBranch[branch.id]"></a>
                                </template>
                                <template x-if="row.byBranch[branch.id] !== undefined && !isWebLink(row.byBranch[branch.id])">
                                    <span class="editor-text break-words"
                                          :class="metaTextTint(row, branch.id)"
                                          x-text="row.byBranch[branch.id]"></span>
                                </template>
                            </td>
                        </template>

                        <td class="answer-rail">
                            <button type="button"
                                x-show="answerRight({{ $pick }}[row.id])" x-cloak
                                @click.stop="goToAnswer({{ $pick }}[row.id])"
                                class="absolute right-1 top-1/2 -translate-y-1/2 z-20"
                                :title="offScreenHint"
                                ><i class="fas answer-mark" :class="answerIconClass({{ $pick }}[row.id])"></i></button>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>

    @if($hint)
        {{-- ⚠ Only when there IS something to take. It describes a gesture — clicking a
             contribution's value — and on a translation being read or worked on by itself there
             are no contributions to click. A hint for a gesture that does not exist is worse than
             no hint: it sends somebody looking. --}}
        <p x-show="{{ $open }} && canTakeContributions()" x-cloak
           class="text-xs text-gray-500 mt-2">{{ $hint }}</p>
    @endif
</div>
