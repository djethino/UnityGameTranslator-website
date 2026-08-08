@props([
    // The three editors offer replace; the reading screens do not write, so they do not.
    'replace' => false,
])

{{--
    The search bar of every grid: live, no Enter needed, matches highlighted, Enter walking
    between them, and a scope select.

    It was copied into four screens, which is how the reading grid ended up with a search that
    demanded Enter and highlighted nothing while the editors' did the opposite. Everything it
    needs — searchQuery, searchScope, hasQuery, matchCounterText, prevMatch, nextMatch,
    onSearchEnter — comes from the shared editor core, so any screen composing it can show this.

    x-ref="searchBar" belongs here rather than on the caller: the workbench measures it to know
    what to leave on the page when it tears the grid out.

    One set of keys, merge.*: merge_preview.* held five copies with word-for-word identical
    values in all nineteen languages, so a wording change had to be made twice to stay coherent.
--}}
<div class="mb-4 space-y-2" x-ref="searchBar">
    <div class="flex gap-2">
        <div class="relative flex-1">
            <input type="text" x-model="searchQuery" @keydown.enter.prevent="onSearchEnter($event)"
                placeholder="{{ __('merge.search_placeholder') }}"
                class="w-full px-4 py-2 pl-10 pr-32 bg-gray-800 border border-gray-700 rounded-lg text-white placeholder-gray-500 focus:border-purple-500 focus:ring-1 focus:ring-purple-500">
            <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-500"></i>
            <div class="absolute right-3 top-1/2 -translate-y-1/2 flex items-center gap-2">
                <span x-show="hasQuery" x-cloak class="text-xs text-gray-500 tabular-nums" x-text="matchCounterText"></span>
                <button x-show="hasQuery" x-cloak @click="prevMatch()" type="button"
                    class="text-gray-500 hover:text-white transition" title="{{ __('merge.search_prev') }}">
                    <i class="fas fa-chevron-up"></i>
                </button>
                <button x-show="hasQuery" x-cloak @click="nextMatch()" type="button"
                    class="text-gray-500 hover:text-white transition" title="{{ __('merge.search_next') }}">
                    <i class="fas fa-chevron-down"></i>
                </button>
                <button x-show="searchQuery" x-cloak @click="searchQuery = ''" type="button"
                    class="text-gray-500 hover:text-white transition">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </div>
        <select x-model="searchScope"
            class="bg-gray-800 border border-gray-700 rounded-lg px-3 py-2 text-sm text-gray-300 focus:border-purple-500 focus:ring-1 focus:ring-purple-500"
            title="{{ __('merge.search_scope_title') }}">
            <option value="both">{{ __('merge.search_scope_both') }}</option>
            <option value="keys">{{ __('merge.search_scope_keys') }}</option>
            <option value="values">{{ __('merge.search_scope_values') }}</option>
        </select>
        @if($replace)
            <button type="button" @click="toggleReplace()"
                :class="replaceOpen ? 'bg-purple-700 text-white border-purple-500' : 'bg-gray-800 text-gray-300 border-gray-700 hover:text-white'"
                class="border rounded-lg px-3 py-2 text-sm transition" title="{{ __('merge.replace') }}">
                <i class="fas fa-right-left"></i>
            </button>
        @endif
    </div>
    @if($replace)
        {{-- Replace: single-row only, staged as a human edit (→ H), no replace-all.
             Applies to the editable column, which is the only one it could apply to. --}}
        <div x-show="replaceOpen" x-cloak class="flex gap-2">
            <div class="relative flex-1">
                <input type="text" x-model="replaceValue" @keydown.enter.prevent="replaceCurrent()"
                    placeholder="{{ __('merge.replace_with') }}"
                    class="w-full px-4 py-2 pl-10 bg-gray-800 border border-gray-700 rounded-lg text-white placeholder-gray-500 focus:border-purple-500 focus:ring-1 focus:ring-purple-500">
                <i class="fas fa-right-left absolute left-3 top-1/2 -translate-y-1/2 text-gray-500"></i>
            </div>
            <button type="button" @click="replaceCurrent()" :disabled="replaceDisabled"
                class="bg-purple-600 hover:bg-purple-700 disabled:bg-gray-700 disabled:text-gray-500 disabled:cursor-not-allowed px-4 py-2 rounded-lg text-white text-sm transition">
                {{ __('merge.replace') }}
            </button>
        </div>
    @endif
</div>
