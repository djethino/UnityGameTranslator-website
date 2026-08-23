{{--
    The two cells of ONE side of a two-file comparison, matching x-editor.side-head.

    Read that component's note first: side and role are two different things, and the same split
    governs here. What the ROLE decides:

    | | target (left) | source (right) |
    |---|---|---|
    | tag | the editor's own cell — a chip, its transition, and a menu to change it | the stored chip, read-only |
    | value | edit, delete, revert; struck through on its way out | the version being offered |

    ⚠ Both cells of a side carry the selection colour. Colouring only the value made a pick look
    half-selected — a side is one answer, not two.
--}}
@props(['side', 'target' => false])

@php
    $tagCol = $side . 'Tag';
@endphp

@if ($target)
    {{-- ⚠ Reads entryOnFile/tagArrives, never a side by name: those two already answer "the row
         being written" and "a tag is on its way in", which is exactly what this cell draws. --}}
    <td data-col="{{ $tagCol }}" class="px-2 py-2 text-center border-l border-gray-700"
        :class="[getCellClass(key, '{{ $side }}'), tagCellClass(key)]">
        {{-- A row this side does not hold yet still gets a tag cell once one is on its way in. The
             dash is for a line that would be written nowhere. --}}
        <template x-if="entryOnFile(key) !== undefined || tagArrives(key)">
            <x-editor-tag-cell />
        </template>
        <template x-if="entryOnFile(key) === undefined && !tagArrives(key)">
            <span class="text-gray-600">—</span>
        </template>
    </td>
@else
    <td class="px-2 py-2 text-center border-l border-gray-700 merge-cell"
        :class="getCellClass(key, '{{ $side }}')"
        @click="select(key, '{{ $side }}')">
        <template x-if="entryOf(key, '{{ $side }}') !== undefined">
            <span x-text="getTag(entryOf(key, '{{ $side }}'))"
                  :class="'tag-' + getTag(entryOf(key, '{{ $side }}')) + tagChipExtraClass(key)"></span>
        </template>
        <template x-if="entryOf(key, '{{ $side }}') === undefined">
            <span class="text-gray-600">—</span>
        </template>
    </td>
@endif

<td data-col="{{ $side }}" class="relative px-4 py-2 border-l border-gray-700 merge-cell"
    :class="[getCellClass(key, '{{ $side }}'), {{ $target ? "isDeleted(key) ? 'deleted-cell' : ''" : "''" }}]"
    @click="select(key, '{{ $side }}')"
    @if ($target) @dblclick="editCell(key, storedValue(key))" @endif>
    @if ($target)
        {{-- With the pin on, THIS is the last frozen cell, so the "answer is off to the left" mark
             rides here rather than on the key — same arrangement as the merge view's Main cell. A
             mark left on the key would be drawn under the frozen block. --}}
        <template x-if="pinMain">
            <button type="button"
                x-show="lineAnswerLeft(key)" x-cloak
                @click.stop="goToLineAnswer(key)"
                class="absolute left-full top-1/2 -translate-y-1/2 ml-1 z-20"
                title="{{ __('merge.answer_off_screen') }}"
                ><i class="fas answer-mark" :class="lineAnswerIconClass(key)"></i></button>
        </template>
        {{-- Each button asks its own question, like the merge view's: `canDelete` is false on a row
             the target does not hold — there is nothing to strike out — while writing one's own
             translation of such a row is exactly what this screen is for. --}}
        <span class="edit-affordance">
            <button type="button" x-show="rowHasPending(key)" @click.stop="revertRow(key)"
                    title="{{ __('merge.revert_row') }}"><i class="fas fa-undo"></i></button>
            <button type="button" x-show="canEdit(key)" @click.stop="editCell(key, storedValue(key))"
                    title="{{ __('translation.edit') }}"><i class="fas fa-pen"></i></button>
            <button type="button" class="delete-btn" x-show="canDelete(key)"
                    @click.stop="toggleDelete(key)"
                    title="{{ __('translation.delete') }}"><i class="fas fa-trash"></i></button>
        </span>
    @endif
    <template x-if="entryOf(key, '{{ $side }}') !== undefined || {{ $target ? 'isEdited(key)' : 'false' }}">
        <span class="break-words"
              :class="[
                  {{ $target ? "isEdited(key) ? 'text-purple-300' : ''" : "''" }},
                  {{ $target ? "isDeleted(key) ? 'line-through opacity-40' : ''" : "''" }},
                  valueUnchanged(key) ? 'opacity-50' : ''
              ]">
            @if ($target)
                {{-- Non-blocking guard: the pending edit altered [!v*N] placeholders --}}
                <span x-show="hasPlaceholderWarning(key)" x-cloak
                      class="inline-block mb-1 px-1.5 py-0.5 rounded bg-orange-900/60 text-orange-300 text-xs"
                      title="{{ __('merge.placeholder_warning') }}">
                    <i class="fas fa-exclamation-triangle mr-1"></i>Placeholders
                </span>
            @endif
            <span class="editor-text" x-safe-html="valueHtmlOf(key, '{{ $side }}')"></span>
        </span>
    </template>
    <template x-if="entryOf(key, '{{ $side }}') === undefined && {{ $target ? '!isEdited(key)' : 'true' }}">
        <span class="text-gray-600 italic">—</span>
    </template>
</td>
