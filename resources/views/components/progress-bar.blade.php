@props(['translation'])

@php
    // Calculate percentages based on effective lines (H + V + A)
    $effective = $translation->effective_lines;

    if ($effective > 0) {
        $humanPercent = ($translation->human_count / $effective) * 100;
        $validatedPercent = ($translation->validated_count / $effective) * 100;
        $aiPercent = ($translation->ai_count / $effective) * 100;
    } else {
        $humanPercent = $validatedPercent = $aiPercent = 0;
    }

    // Quality score (0-3 scale, show as percentage of max)
    $qualityPercent = ($translation->quality_score / 3) * 100;
@endphp

<div {{ $attributes->merge(['class' => 'progress-bar-wrapper']) }}>
    {{-- Progress bar --}}
    <div class="h-2 bg-gray-700 rounded-full overflow-hidden flex"
         title="{{ __('progress.tooltip', [
             'human' => $translation->human_count,
             'validated' => $translation->validated_count,
             'ai' => $translation->ai_count,
             'capture' => $translation->capture_count ?? 0,
             'quality' => number_format($translation->quality_score, 1)
         ]) }}">
        @if($humanPercent > 0)
            <div class="bg-green-500 h-full" style="width: {{ $humanPercent }}%"></div>
        @endif
        @if($validatedPercent > 0)
            <div class="bg-blue-500 h-full" style="width: {{ $validatedPercent }}%"></div>
        @endif
        @if($aiPercent > 0)
            <div class="bg-orange-500 h-full" style="width: {{ $aiPercent }}%"></div>
        @endif
    </div>

    {{-- Legend (optional, shown when slot has content) --}}
    @if($slot->isNotEmpty())
        {{ $slot }}
    @endif
</div>
