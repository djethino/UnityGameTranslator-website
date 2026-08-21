{{--
    The key column's header — frozen, resizable, and the row's identity.

    🔴 **It carries a right edge, because a frozen column is not an overlapping one.** Without it,
    the columns sliding underneath read as a rendering fault rather than as content passing behind a
    fixed edge.

    ⚠ Its offset follows the index column: `left-16` when that one is shown, `left-0` when it is
    not. The pair is written once here, and in the matching cell.

    ⚠ Resizable, which is also what tells the layout engine it may take up slack — see
    `_isFlexible` in editor-columns.js: a column offered for dragging is one whose width is a matter
    of taste, so it is also one that may be stretched.
--}}
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
