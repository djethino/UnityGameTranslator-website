@props([
    'save' => null,
    'saveLabel' => null,
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
<div x-show="wide" x-cloak
     class="fixed top-0 inset-x-0 h-12 z-[60] flex items-center gap-2 overflow-x-auto
            border-b border-gray-700 bg-gray-900/95 backdrop-blur px-3 text-sm">

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

    <span class="w-px h-5 bg-gray-700 shrink-0"></span>

    {{-- Screen-specific categories --}}
    {{ $slot }}

    {{-- Whole class names, never "text-{$colour}-600": Tailwind reads the source as text and
         only emits what it can see written out. A class assembled at render time is a class
         that ships without its rule. --}}
    @foreach([
        'H' => ['text-green-600', __('merge.legend_human')],
        'V' => ['text-blue-600', __('merge.legend_validated')],
        'A' => ['text-orange-600', __('merge.legend_ai')],
        'S' => ['text-gray-600', __('merge.legend_skipped')],
        'M' => ['text-purple-600', __('merge.legend_mod_ui')],
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

    <span class="w-px h-5 bg-gray-700 shrink-0"></span>

    @if($save)
        <button type="button" @click="{{ $save }}" :disabled="{{ $saveDisabled }}"
                class="shrink-0 bg-purple-600 hover:bg-purple-700 disabled:bg-gray-800 disabled:text-gray-600
                       text-white px-3 py-1 rounded text-xs transition">
            <i class="fas fa-save mr-1"></i>{{ $saveLabel ?? __('common.save') }} (<span x-text="totalChanges">0</span>)
        </button>
    @endif

    <button type="button" @click="toggleWide()"
            class="shrink-0 text-gray-400 hover:text-white px-2 py-1 rounded hover:bg-gray-800 transition"
            title="{{ __('merge.wide_off') }} (Échap)">
        <i class="fas fa-compress"></i>
    </button>
</div>
