@props([
    // Alpine prefix for this block's state: 'settings' or 'publication'. Everything else is
    // derived from it, so a third kind of metadata needs no new markup — only the state.
    'name',
    // The block's own name, shown on the fold header.
    'title',
    // One sentence under the table, shown only when there is something to take.
    'hint' => null,

    // 🔴 **The columns of THIS screen's grid, because the point is to line up with it.**
    //
    // The three editors do not name their columns the same way: the merge view reads
    // key/mainTag/main + one per contribution, the mod comparison reads key/localTag/local/online,
    // the live editor reads key/value. Widths are written as one stylesheet rule per [data-col],
    // so a block that assumed one screen's names lined up on that screen and floated free on the
    // next — which is the whole reason it is worth sharing at all.
    'valueCol' => 'main',
    // What that column is CALLED on this screen. 'Main' on the merge view and the reading screens,
    // where the translation being read is the Main; the target's own name on the comparison screen,
    // where "Main" names nothing the reader can see.
    'mineLabel' => 'Main',
    // ⚠ And its colour, because the colour names a FILE, not a role: on the comparison screen the
    // target is the local file one way round and the server's version the other, and the lines
    // grid colours each of them the same way whichever side it sits on. A label left green over a
    // blue column would be the one place the two tables disagree.
    'mineTone' => 'text-green-400',
    // The tag column, kept EMPTY and merged into the value cell: neither a setting nor a
    // description has a tag, and dropping the column would shift everything after it. Null on a
    // grid that has no tag column of its own.
    'tagCol' => 'mainTag',
    // Alpine expression yielding the other sides, as [{id, col, name}]. Empty on a screen that
    // reads or edits one translation.
    'others' => 'metaOtherColumns()',
    // How many grid columns one of those sides occupies. Two on the merge view, where a
    // contribution's tag and value share a header; one on the mod comparison, where the online
    // side has no tag column of its own. Getting it wrong shifts every column after it.
    'otherSpan' => 2,
])

@php
    $rows    = $name . 'Rows';
    $has     = 'has' . ucfirst($name) . 'Rows';
    $open    = $name . 'Open';
    $pick    = $name . 'Pick';
    $visible = 'visible' . ucfirst($name) . 'Rows()';
    $count   = $name . 'DifferenceCount()';
    $taken   = $name . 'TakenCount()';
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
                         key and the value: the two head one block, and the title lands exactly
                         where the lines grid puts its own. --}}
                    {{-- ⚠ w-20, the width the lines grid gives the same column. These two tables
                         align by column NAME, and only once widths have been declared; until then
                         each is laid out on its own content, so a class that disagreed with the
                         one below put the whole block half a chip off. --}}
                    @if($tagCol)
                        <th data-col="{{ $tagCol }}" class="px-2 py-3 border-l border-gray-700 w-20"></th>
                    @endif
                    <th data-col="{{ $valueCol }}"
                        class="relative px-4 py-3 text-left min-w-[250px] {{ $tagCol ? '' : 'border-l border-gray-700' }}">
                        <div class="flex items-center gap-2">
                            <span class="{{ $mineTone }} font-medium">{{ $mineLabel }}</span>
                            <span class="text-xs text-gray-500" x-show="mainOwner" x-text="'(' + mainOwner + ')'"></span>
                        </div>
                        <x-editor.col-resize col="{{ $valueCol }}" />
                    </th>
                    <template x-for="other in {{ $others }}" :key="other.id">
                        <th colspan="{{ $otherSpan }}" :data-col="other.col"
                            class="relative px-4 py-3 text-left border-l border-gray-700 min-w-[280px]">
                            {{-- Its colour comes with the column, for the reason spelled out on
                                 `mineTone`: on a screen whose two sides swap roles, blue is not a
                                 property of "the other one". --}}
                            <span class="font-medium" :class="other.tone || 'text-blue-400'"
                                  x-text="other.name"></span>
                            <x-editor.col-resize :bind="true" col="other.col" />
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
                        <td colspan="{{ $tagCol ? 2 : 1 }}" class="main-pair px-4 py-2 border-l border-gray-700"
                            :class="[canTakeContributions() && 'merge-cell', {{ $cell }}(row, null)]"
                            @click="canTakeContributions() && {{ $keep }}(row)">
                            {{-- The shown side's cell. A screen that can stage something over it
                                 passes a slot; one that only reads gets this, which is the same
                                 rendering the other columns use — a link shown AS a link, because
                                 it is the one value nobody can judge by reading it. --}}
                            @isset($mainCell)
                                {{ $mainCell }}
                            @else
                                <template x-if="isWebLink(row.mineValue)">
                                    <a :href="row.mineValue" target="_blank" @click.stop
                                       rel="noopener noreferrer nofollow"
                                       class="text-blue-400 hover:underline break-all"
                                       x-text="row.mineValue"></a>
                                </template>
                                <template x-if="!isWebLink(row.mineValue)">
                                    <span class="editor-text break-words" x-text="row.mineValue"></span>
                                </template>
                            @endisset
                        </td>

                        <template x-for="other in {{ $others }}" :key="other.id">
                            <td colspan="{{ $otherSpan }}" :data-col="other.col"
                                class="px-4 py-2 border-l border-gray-700 merge-cell"
                                :class="[{{ $cell }}(row, other.id), metaCellTint(row, other.id)]"
                                @click="{{ $take }}(row, other.id)">
                                <template x-if="row.byBranch[other.id] === undefined">
                                    <span class="text-gray-600 italic">—</span>
                                </template>
                                {{-- ⚠ A link is shown AS a link. It is the one value that cannot
                                     be judged by reading it: taking it puts it on the Main's
                                     page, and where it leads is the whole question. Only http(s)
                                     is ever made clickable. --}}
                                <template x-if="row.byBranch[other.id] !== undefined && isWebLink(row.byBranch[other.id])">
                                    <a :href="row.byBranch[other.id]" target="_blank"
                                       rel="noopener noreferrer nofollow" @click.stop
                                       class="hover:underline break-all"
                                       :class="metaLinkTint(row, other.id)"
                                       x-text="row.byBranch[other.id]"></a>
                                </template>
                                <template x-if="row.byBranch[other.id] !== undefined && !isWebLink(row.byBranch[other.id])">
                                    <span class="editor-text break-words"
                                          :class="metaTextTint(row, other.id)"
                                          x-text="row.byBranch[other.id]"></span>
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
