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
</div>
