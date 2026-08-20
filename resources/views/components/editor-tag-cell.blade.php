{{--
    The tag button, for every editor that previews what a save will store.

    🔴 **It shows what the row is LEAVING, not only what it becomes.** The commonest contribution
    of all moves no text: somebody re-reads a machine line, marks it correct, and offers that. The
    cell used to show the outcome alone, so it simply turned V beside an unchanged sentence — the
    screen asked its owner to settle a line that read as already settled, and what was being
    offered was the one thing not on screen.

    ⚠ **Two real chips, not a shorthand.** The colours are the vocabulary of these screens (one
    colour, one meaning, in the mod and on the site alike); saying "was A" in grey text would have
    invented a second way of writing a tag on the very screen where tags are read.

    ⚠ No props: everything comes from the shared core (`translation-editor.js`), which is also what
    stops the three editors from drifting apart. A page adjusts by overriding `entryOnFile`,
    `tagAfterSave`, `tagCellDisabled` or `tagChipExtraClass` — never by rewriting this cell.
--}}
<button type="button"
    @click.stop="openTagDropdown($event, key, tagAfterSave(key), storedValue(key))"
    :disabled="tagCellDisabled(key)"
    :class="tagCellDisabled(key) ? 'opacity-40 cursor-not-allowed' : 'cursor-pointer hover:ring-2 hover:ring-purple-400 hover:ring-offset-1 hover:ring-offset-gray-800'"
    class="tag-transition transition rounded"
    :title="tagWillChange(key)
        ? @js(__('merge.click_to_change_tag')) + ' (' + tagOnFile(key) + ' → ' + tagAfterSave(key) + ')'
        : @js(__('merge.click_to_change_tag'))">
    {{-- The chip on file, then the arrow. Absent entirely when nothing changes, so an untouched
         row reads exactly as it always has. --}}
    <template x-if="tagWillChange(key)">
        <span class="tag-transition-from">
            <span :class="'tag-' + tagOnFile(key)" x-text="tagOnFile(key)"></span>
            <i class="fas fa-arrow-right tag-arrow"></i>
        </span>
    </template>
    <span :class="'tag-' + tagAfterSave(key) + (isCaptureRow(key) ? ' opacity-40' : '') + tagChipExtraClass(key)"
        x-text="tagAfterSave(key)"></span>
</button>
