{{--
    The capture-order cell. Same pinned width as its header, for the reason written there.

    ⚠ `indexCellText` is the core's, and it reads the target first, then the sources: a row only a
    contribution carries still has an index, and this column exists to order them. Three screens
    used to answer this question three ways — see the commit that removed them.
--}}
<td x-show="showIndexColumn" x-cloak
    class="px-2 py-2 text-right font-mono text-xs text-gray-600 tabular-nums align-top sticky left-0 z-10 bg-gray-800 w-16 min-w-[4rem] max-w-[4rem]"
    x-text="indexCellText(key)"></td>
