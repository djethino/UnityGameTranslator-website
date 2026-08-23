@props([
    'save' => null,
    'saveLabel' => null,
    // ⚠ The comparison screen runs BOTH ways, and this button is the same one as the page's:
    // returning a result to the game is not "Save", and an icon of a diskette says the wrong
    // direction. Whatever the screen writes on its own button, it writes here too — the strip
    // hides the page behind it, so the two can never be read side by side and disagree.
    'saveIcon' => 'fa-save',
    'saveTitle' => null,
    'saveDisabled' => 'totalChanges === 0',
    // Same filter, one name per screen: pending work is "modifiedOnly" where two versions are
    // being reconciled, "pendingOnly" where a single file is being written
    'modifiedFilter' => 'modifiedOnly',
])

{{--
    The workbench's own strip: everything the page chrome carried, on one line.

    Shared by every editing screen, because the mode hides the page behind it — anything not
    reported here is simply unreachable while it is on. That lesson was learnt twice: first the
    search and the save button were missing outright, then the replace toggle flipped a state
    whose field lived in the hidden page and so answered nothing.

    A reserved strip rather than a pill floating over the grid. A row hidden by an overlay comes
    back as you scroll; a HEADER does not — it is unique, and the name of the column beneath was
    gone for good. Forty-eight pixels against the four hundred this mode exists to remove.

    The controls that vary between screens come through the default slot: the merge view filters
    by new keys / differences / identical, the mod comparison by local / online / same. Everything
    else is the shared editor core and lives here once — searchQuery, searchScope, replaceOpen,
    filters.tag*, showIndexColumn — bound to the very same properties as the panel behind, so the
    two copies can never disagree.
--}}
{{--
    🔴 **THREE ZONES, and a stated order for who gives way.**

    It was twenty-five controls as siblings, every one `shrink-0`, on a bar of fixed height with
    `overflow-x-auto`. Nothing could wrap as a block, so narrowing the window produced a horizontal
    scrollbar over the toolbar itself — measured: 53px of overflow at 1085. And a scrollbar on a
    toolbar hides controls without saying so, which is the one thing this strip must never do,
    since it is all there is while the workbench covers the page.

    Who gives way, in order:
      1. the REPLACE field drops under the search it belongs to — same zone, so it never lands
         beside a filter;
      2. the FILTERS take a line of their own, whole. They are the many small things, and the ones
         you read as a set;
      3. the ACTIONS never wrap and never split: they lose their words instead, becoming icons.

    ⚠ The bar's height is no longer fixed, so the grid below cannot be anchored at a constant
    `top-12` any more — it reads `--wb-bar-h`, which editor-workbench measures. A second row that
    hid the first line of the table would be a worse bargain than the scrollbar.
--}}
<div x-show="wide" x-cloak x-ref="workbenchBar"
     class="fixed top-0 inset-x-0 z-[60] flex flex-wrap items-center gap-x-2 gap-y-1
            border-b border-gray-700 bg-gray-900/95 backdrop-blur px-3 py-1.5 text-sm">

    {{-- ── 1. Search, with its replace field as part of it ────────────────── --}}
    <div class="flex flex-wrap items-center gap-2 shrink-0">
    <div class="relative shrink-0">
        <i class="fas fa-search absolute left-2 top-1/2 -translate-y-1/2 text-gray-500 text-xs"></i>
        <input type="text" x-model="searchQuery"
               @keydown.enter.prevent="onSearchEnter($event)"
               placeholder="{{ __('merge.search_placeholder') }}"
               class="w-40 focus:w-64 transition-all bg-gray-800 border border-gray-700 rounded pl-7 pr-2 py-1
                      text-white text-xs placeholder-gray-500 focus:outline-none focus:border-purple-500">
    </div>
    <select x-model="searchScope" title="{{ __('merge.search_scope_title') }}"
            class="shrink-0 bg-gray-800 border border-gray-700 rounded px-1 py-1 text-xs text-gray-300">
        <option value="both">{{ __('merge.search_scope_both') }}</option>
        <option value="keys">{{ __('merge.search_scope_keys') }}</option>
        <option value="values">{{ __('merge.search_scope_values') }}</option>
    </select>

    {{-- A search that finds ninety-eight matches and cannot take you to any of them is no use --}}
    <span x-show="hasQuery" x-cloak class="shrink-0 text-xs text-gray-500 tabular-nums" x-text="matchCounterText"></span>
    <button x-show="hasQuery" x-cloak type="button" @click="prevMatch()" title="{{ __('merge.search_prev') }}"
            class="shrink-0 text-gray-500 hover:text-white px-1 transition text-xs">
        <i class="fas fa-chevron-up"></i>
    </button>
    <button x-show="hasQuery" x-cloak type="button" @click="nextMatch()" title="{{ __('merge.search_next') }}"
            class="shrink-0 text-gray-500 hover:text-white px-1 transition text-xs">
        <i class="fas fa-chevron-down"></i>
    </button>

    <button type="button" @click="toggleReplace()" title="{{ __('merge.replace') }}"
            class="shrink-0 px-1.5 py-1 rounded transition text-xs"
            :class="replaceOpen ? 'bg-purple-700 text-white' : 'text-gray-400 hover:text-white hover:bg-gray-800'">
        <i class="fas fa-right-left"></i>
    </button>
    <template x-if="replaceOpen">
        <div class="flex items-center gap-1 shrink-0">
            <input type="text" x-model="replaceValue" @keydown.enter.prevent="replaceCurrent()"
                   placeholder="{{ __('merge.replace_with') }}"
                   class="w-40 bg-gray-800 border border-gray-700 rounded px-2 py-1 text-white text-xs
                          placeholder-gray-500 focus:outline-none focus:border-purple-500">
            <button type="button" @click="replaceCurrent()" :disabled="replaceDisabled"
                    class="bg-gray-700 hover:bg-gray-600 disabled:bg-gray-800 disabled:text-gray-600
                           text-white px-2 py-1 rounded text-xs transition">
                {{ __('merge.replace') }}
            </button>
        </div>
    </template>
    </div>

    {{-- ── 2. Filters — the zone that takes a line of its own when room runs out ──
         ⚠ **A breakpoint, and 2xl rather than lg.** Flexbox wraps whatever comes LAST in visual
         order, which is the actions — the opposite of what is wanted, and what a first attempt at
         `lg` produced: measured at 1085, the filters kept the row and Save went to the second
         line. So the row is decided rather than discovered. The three zones measure 305 + 748 +
         241 on the merge view, so a single line needs about 1330px: `xl` (1280) would still
         overflow, `2xl` (1536) always fits. Between 1330 and 1536 there is a second row that
         could have been avoided — the price of a rule that never puts Save where it is missed. --}}
    <div class="flex flex-wrap items-center gap-x-3 gap-y-1 min-w-0
                basis-full order-last 2xl:basis-auto 2xl:order-none 2xl:flex-1">

    <span class="w-px h-5 bg-gray-700 shrink-0 hidden 2xl:inline-block"></span>

    {{-- Screen-specific categories --}}
    {{ $slot }}

    {{-- Whole class names, never "text-{$colour}-600": Tailwind reads the source as text and
         only emits what it can see written out. A class assembled at render time is a class
         that ships without its rule. --}}
    @foreach([
        'H' => ['text-green-600', __('merge.legend_human')],
        'V' => ['text-blue-600', __('merge.legend_validated')],
        'A' => ['text-orange-600', __('merge.legend_ai')],
        'S' => ['text-purple-600', __('merge.legend_skipped')],
        'M' => ['text-teal-600', __('merge.legend_mod_ui')],
    ] as $tag => [$checkboxColour, $legend])
        <label class="flex items-center gap-1 text-xs cursor-pointer shrink-0" title="{{ $legend }}">
            <input type="checkbox" :checked="filters.tag{{ $tag }}" @change="toggleFilter('tag{{ $tag }}')"
                   class="rounded bg-gray-700 border-gray-600 {{ $checkboxColour }}">
            <span class="tag-{{ $tag }}">{{ $tag }}</span>
        </label>
    @endforeach

    <span class="w-px h-5 bg-gray-700 shrink-0"></span>

    <label class="flex items-center gap-1 text-xs cursor-pointer shrink-0" title="{{ __('merge.modifications') }}">
        <input type="checkbox" :checked="filters.{{ $modifiedFilter }}" @change="toggleFilter('{{ $modifiedFilter }}')"
               class="rounded bg-gray-700 border-gray-600 text-purple-600">
        <i class="fas fa-pen text-purple-400"></i>
    </label>
    {{-- The same two icons, from the same file, as the ordinary bar behind --}}
    <x-editor.view-options />
    </div>

    {{-- ── 3. Actions — never wrapped, never split, pinned right ──────────────
         ⚠ They lose their WORDS before they lose their line: a label is decoration next to an
         icon everyone already recognises, and a Save button on a second row is a Save button
         somebody scrolls past. Each keeps its title attribute, so the word is a hover away. --}}
    <div class="flex flex-nowrap items-center gap-2 shrink-0 ml-auto">

    {{-- 🔴 Everything the bottom bar offers besides Save. That bar is COVERED while this strip is
         on (it is z-40 under a z-50 grid), so an action left out of here cannot be reached at all.
         The same component renders it in both, guards included — see editor-actions. --}}
    {{ $actions ?? '' }}

    @if($save)
        <button type="button" @click="{{ $save }}" :disabled="{{ $saveDisabled }}"
                {{-- ⚠ One title, not two: a screen that supplies a reason for being refused keeps
                     it, the others fall back to the label the button hides below xl. Written as
                     an either/or because two `title` attributes on one element is a silent
                     overwrite, and the Alpine one wins with an empty string when nothing is
                     wrong. --}}
                @if($saveTitle) :title="{{ $saveTitle }}"
                @else title="{{ $saveLabel ?? __('common.save') }}" @endif
                class="shrink-0 bg-purple-600 hover:bg-purple-700 disabled:bg-gray-800 disabled:text-gray-600
                       text-white px-3 py-1 rounded text-xs transition whitespace-nowrap">
            <i class="fas {{ $saveIcon }} mr-1"></i><span class="hidden xl:inline">{{ $saveLabel ?? __('common.save') }} </span>(<span x-text="totalChanges">0</span>)
        </button>
    @endif

    <button type="button" @click="toggleWide()"
            class="shrink-0 text-gray-400 hover:text-white px-2 py-1 rounded hover:bg-gray-800 transition"
            title="{{ __('merge.wide_off') }} (Échap)">
        <i class="fas fa-compress"></i>
    </button>
    </div>
</div>
