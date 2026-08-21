{{--
    The capture-order column's header — the order in which the game met these lines.

    🔴 **The width is PINNED, not suggested.** A table lays its columns out to fit their content, so
    `w-16` alone was a hint the browser was free to ignore — and the key column, frozen at a hard
    `left-16` beside it, then left a strip of nothing where the scrolled columns showed through.

    ⚠ Frozen at `left-0`, with the key column freezing right after it. The two offsets are a pair:
    change one and the other has to follow, which is precisely why they live in one place now.
--}}
<th x-show="showIndexColumn" x-cloak
    class="px-2 py-3 text-right text-gray-400 font-medium w-16 min-w-[4rem] max-w-[4rem] cursor-pointer hover:text-white transition sticky left-0 z-30 bg-gray-900"
    @click="toggleSort('index')" title="{{ __('editor.capture_order_hint') }}">
    <div class="flex items-center justify-end gap-1">
        <span class="text-xs">#</span>
        <i class="fas text-xs" :class="getSortIcon('index')"></i>
    </div>
</th>
