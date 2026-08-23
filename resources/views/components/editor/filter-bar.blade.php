@props([
    // The five HVASM boxes. Every grid shows them today; the flag exists so a screen without
    // tags can reuse the bar rather than fork it.
    'tags' => true,
])

{{--
    The filter bar of every grid.

    TWO ZONES, and that is the whole point: what to show on the left, how to show it on the
    right. The left wraps within itself when a language runs long; the right group is pinned and
    never gets separated from the bar it belongs to. Before this, one flow held both and the wrap
    fell wherever it fell — a filter on one line, another on the next, and the workbench button
    stranded beside them.

    Three slots, because what a screen filters on differs but where it goes does not:
      - `before`  categories, ahead of the tags (what kind of row this is)
      - default   whatever follows the tags (modified only, pending, select-all…)
    Each slot brings its own <x-editor.filter-sep /> so a screen with nothing to add leaves no
    dangling divider.

    The right group is not a slot: view options and the workbench are the same on every screen,
    and the day they are not, the difference belongs in those components.
--}}
<div class="mb-4 flex gap-4 items-start text-sm bg-gray-800 p-4 rounded-lg border border-gray-700">
    {{-- gap-x-3, not 4: the filters are dense little checkboxes and the row was overflowing by a
         couple of pixels at a common window width. Row gap stays larger so the two lines, when a
         language forces them, read as two lines. --}}
    <div class="flex flex-wrap gap-x-3 gap-y-2 items-center flex-1 min-w-0">
        <span class="text-gray-500 shrink-0">{{ __('merge_preview.show') }}:</span>

        {{-- ⚠ **Each family is one block that wraps whole.** Flat in a single flex-wrap, a tag
             checkbox could end up on a second line under a category one, and the two read as a
             set each: what kind of row this is, then how good its translation is. What wraps is a
             family, never half of one. --}}
        <div class="flex items-center gap-x-3 shrink-0">{{ $before ?? '' }}</div>

        @if($tags)
        <div class="flex items-center gap-x-3 shrink-0">
            {{-- Tag filters in HVASM order --}}
            <label class="flex items-center gap-2 cursor-pointer" title="{{ __('merge.legend_human') }}">
                <input type="checkbox" :checked="filters.tagH" @change="toggleFilter('tagH')"
                    class="rounded bg-gray-700 border-gray-600 text-green-600">
                <span class="tag-H">H</span>
            </label>
            <label class="flex items-center gap-2 cursor-pointer" title="{{ __('merge.legend_validated') }}">
                <input type="checkbox" :checked="filters.tagV" @change="toggleFilter('tagV')"
                    class="rounded bg-gray-700 border-gray-600 text-blue-600">
                <span class="tag-V">V</span>
            </label>
            <label class="flex items-center gap-2 cursor-pointer" title="{{ __('merge.legend_ai') }}">
                <input type="checkbox" :checked="filters.tagA" @change="toggleFilter('tagA')"
                    class="rounded bg-gray-700 border-gray-600 text-orange-600">
                <span class="tag-A">A</span>
            </label>
            {{-- The box takes the tag's own colour — see .tag-S / .tag-M in app.css for why
                 purple belongs to S and teal to M. --}}
            <label class="flex items-center gap-2 cursor-pointer" title="{{ __('merge.legend_skipped') }}">
                <input type="checkbox" :checked="filters.tagS" @change="toggleFilter('tagS')"
                    class="rounded bg-gray-700 border-gray-600 text-purple-600">
                <span class="tag-S">S</span>
            </label>
            <label class="flex items-center gap-2 cursor-pointer" title="{{ __('merge.legend_mod_ui') }}">
                <input type="checkbox" :checked="filters.tagM" @change="toggleFilter('tagM')"
                    class="rounded bg-gray-700 border-gray-600 text-teal-600">
                <span class="tag-M">M</span>
            </label>
        </div>
        @endif

        <div class="flex flex-wrap items-center gap-x-3 gap-y-2 min-w-0">{{ $slot }}</div>
    </div>

    {{-- How a row is drawn, and how much room the grid gets. Pinned: this group is never what a
         long language pushes onto a second line. --}}
    <div class="flex items-center gap-3 shrink-0 border-l border-gray-700 pl-4">
        <x-editor.view-options />
        <x-editor.workbench-toggle />
    </div>
</div>
