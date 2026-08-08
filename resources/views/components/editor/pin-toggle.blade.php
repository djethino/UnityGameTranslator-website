{{--
    Freeze the reference column beside the key, or let it scroll again.

    On the header of the column it pins, because that is what it acts on — a switch in a toolbar
    would have to name the column, and the column is right here. It covers the pair (tag + value):
    a value frozen without its tag says only half of what the row holds.

    stop on the click, because the header is also a sort button: pinning a column must not
    reshuffle the very rows you pinned it to compare.
--}}
{{-- ml-auto: pushed to the far end of the header rather than trailing the sort arrow. It acts on
     the whole column, not on the label, and the column's right edge is where its boundary is —
     which is exactly what freezing moves. --}}
<button type="button" @click.stop="togglePinMain()"
        class="ml-auto px-1 rounded transition"
        :class="pinMain ? 'text-purple-300' : 'text-gray-600 hover:text-gray-300'"
        :title="pinMain ? '{{ __('merge.unpin_column') }}' : '{{ __('merge.pin_column') }}'">
    <i class="fas fa-thumbtack text-xs" :class="pinMain && 'rotate-45'"></i>
</button>
