{{-- Quality progress bar shared by the three translation editors.

     Counts the PROJECTED tags over the whole file (pending edits,
     validations and deletions already move the bar), computed in the
     shared core's memoized effect. H+V — the human-reviewed share —
     is the number that matters for a translation's quality. --}}
<div class="mb-4 bg-gray-800 rounded-lg p-3 border border-gray-700" x-show="tagCounts.total > 0" x-cloak>
    <div class="flex items-center justify-between text-xs text-gray-400 mb-2">
        <span><i class="fas fa-chart-simple mr-1"></i>{{ __('merge.quality_progress') }}</span>
        <span class="tabular-nums">
            <span class="text-green-400 font-bold" x-text="qualityPercent"></span>% <span class="tag-H">H</span>+<span class="tag-V">V</span>
        </span>
    </div>
    <div class="flex h-2 rounded overflow-hidden bg-gray-700" title="H / V / A / S / M">
        <div class="bg-green-600" :style="'width: ' + tagPercent('H') + '%'"></div>
        <div class="bg-blue-600" :style="'width: ' + tagPercent('V') + '%'"></div>
        <div class="bg-orange-600" :style="'width: ' + tagPercent('A') + '%'"></div>
        <div class="bg-gray-500" :style="'width: ' + tagPercent('S') + '%'"></div>
        <div class="bg-purple-600" :style="'width: ' + tagPercent('M') + '%'"></div>
    </div>
</div>
