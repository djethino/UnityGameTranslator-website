{{--
    The key cell. Frozen at the same offset as its header, for the same reason.

    ⚠ **`editor-text` sits on a SPAN, not on the cell.** The class turns on `pre-wrap`, so anything
    else inside the cell would have its own indentation rendered as visible whitespace — and this
    cell holds more than the key on screens that mark an off-screen answer. The span keeps the
    whitespace rule around the text it is meant for.

    🔴 A deleted row is struck through HERE too, not only on its value. Two of the three editors
    marked the value and left the key untouched, so a row on its way out read as ordinary in the one
    column that never scrolls away — the column somebody keeps in sight precisely to know which line
    they are looking at.

    The slot is for what a screen adds beside the key: the mark saying its answer is off to one
    side. Nothing else belongs here.
--}}
<td data-col="key"
    class="relative px-4 py-2 font-mono text-xs text-gray-500 break-words sticky z-10 bg-gray-800 border-r border-gray-700 shadow-[4px_0_8px_-4px_rgba(0,0,0,0.6)]"
    :class="showIndexColumn ? 'left-16' : 'left-0'">
    <span class="editor-text"
          :class="isDeleted(key) ? 'line-through text-red-400' : ''"
          x-safe-html="highlightKey(key)"></span>
    {{ $slot }}
</td>
