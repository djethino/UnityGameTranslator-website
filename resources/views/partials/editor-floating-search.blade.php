{{-- Compact floating search for the translation editors (shared by the
     merge view, merge-preview and edit-session — one source, same UX).

     Shown only while the MAIN search bar is scrolled off-screen (tracked
     by the core via x-ref="searchBar") and a search or the replace panel
     is active: prev/next navigation centers rows deep in the page, and
     without this the user would have to scroll back up for every query
     tweak or replacement. Same Alpine state as the main bar. --}}
<div x-show="searchBarOffscreen && (hasQuery || replaceOpen)" x-cloak
    class="fixed top-2 left-1/2 -translate-x-1/2 z-30 bg-gray-800/95 backdrop-blur border border-gray-600 rounded-lg shadow-xl p-2 space-y-2">
    <div class="flex items-center gap-2">
        <div class="relative">
            <input type="text" x-model="searchQuery" @keydown.enter.prevent="onSearchEnter($event)"
                placeholder="{{ __('merge.search_placeholder') }}"
                class="w-64 px-3 py-1.5 pl-8 bg-gray-900 border border-gray-700 rounded text-sm text-white placeholder-gray-500 focus:border-purple-500 focus:ring-1 focus:ring-purple-500">
            <i class="fas fa-search absolute left-2.5 top-1/2 -translate-y-1/2 text-gray-500 text-xs"></i>
        </div>
        <span class="text-xs text-gray-400 tabular-nums" x-text="matchCounterText"></span>
        <button type="button" @click="prevMatch()"
            class="text-gray-400 hover:text-white transition" title="{{ __('merge.search_prev') }}">
            <i class="fas fa-chevron-up"></i>
        </button>
        <button type="button" @click="nextMatch()"
            class="text-gray-400 hover:text-white transition" title="{{ __('merge.search_next') }}">
            <i class="fas fa-chevron-down"></i>
        </button>
        <button x-show="searchQuery" x-cloak type="button" @click="searchQuery = ''"
            class="text-gray-400 hover:text-white transition">
            <i class="fas fa-times"></i>
        </button>
    </div>
    <div x-show="replaceOpen" class="flex items-center gap-2">
        <input type="text" x-model="replaceValue" @keydown.enter.prevent="replaceCurrent()"
            placeholder="{{ __('merge.replace_with') }}"
            class="w-64 px-3 py-1.5 bg-gray-900 border border-gray-700 rounded text-sm text-white placeholder-gray-500 focus:border-purple-500 focus:ring-1 focus:ring-purple-500">
        <button type="button" @click="replaceCurrent()" :disabled="replaceDisabled"
            class="bg-purple-600 hover:bg-purple-700 disabled:bg-gray-700 disabled:text-gray-500 disabled:cursor-not-allowed px-3 py-1.5 rounded text-white text-sm transition">
            {{ __('merge.replace') }}
        </button>
    </div>
</div>
