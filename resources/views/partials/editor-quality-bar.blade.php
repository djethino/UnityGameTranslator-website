{{-- Quality progress bar shared by the four grids.

     Counts the PROJECTED tags over the whole file — pending edits, validations and deletions
     already move the bar, which is the one thing the server-rendered card cannot do. Everything
     else about the bar (segments, order, colours) comes from x-quality-bar, the single
     definition the cards and the game pages render too. --}}
<div class="mb-4 bg-gray-800 rounded-lg p-3 border border-gray-700" x-show="tagCounts.total > 0" x-cloak>
    <div class="flex items-center justify-between text-xs text-gray-400 mb-2">
        <span><i class="fas fa-chart-simple mr-1"></i>{{ __('merge.quality_progress') }}</span>
        <span class="tabular-nums">
            <span class="text-green-400 font-bold" x-text="qualityPercent"></span>% <span class="tag-H">H</span>+<span class="tag-V">V</span>
        </span>
    </div>
    <x-quality-bar percent-fn="tagPercent" title="H / V / A / S" />

    {{-- Rows whose placeholders no longer match their source, PROJECTED like the bar: a repair
         staged above lowers it before anything is saved. Same amber strip as the live editor's
         "game disconnected" notice, same three parts — the fact, what it costs, the way out.
         Never a refusal (2026-09-11): the server counts the same thing on the saved file and
         the card states it; here the lines can be found and fixed. Hidden at zero: the absence
         is the information. --}}
    <div x-show="tagCounts.broken > 0" x-cloak
         class="mt-3 flex flex-wrap items-center gap-x-2 gap-y-1 bg-amber-900/80 border border-amber-500 rounded-lg px-3 py-1.5">
        <i class="fas fa-triangle-exclamation text-amber-300 shrink-0"></i>
        <span class="text-amber-200 font-semibold text-sm shrink-0">
            <span x-text="tagCounts.broken"></span> {{ __('merge.broken_placeholder_lines') }}
        </span>
        <span class="text-amber-100/90 text-xs leading-snug">{{ __('merge.broken_placeholder_hint') }}</span>
        <button type="button" @click="showBrokenPlaceholders()" x-show="!filters.brokenOnly"
                class="ml-auto shrink-0 bg-amber-700 hover:bg-amber-600 text-white px-2.5 py-1 rounded text-xs transition">
            <i class="fas fa-filter mr-1"></i>{{ __('common.show') }}
        </button>
    </div>
</div>
