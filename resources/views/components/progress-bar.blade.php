@props(['translation'])

@php
    $effective = $translation->effective_lines;
    $captureCount = $translation->capture_count ?? 0;
    $totalWithCaptures = $effective + $captureCount;
    $isCaptureOnly = $effective === 0 && $captureCount > 0;

    if ($totalWithCaptures > 0) {
        $humanPercent = ($translation->human_count / $totalWithCaptures) * 100;
        $validatedPercent = ($translation->validated_count / $totalWithCaptures) * 100;
        $aiPercent = ($translation->ai_count / $totalWithCaptures) * 100;
        $capturePercent = ($captureCount / $totalWithCaptures) * 100;
    } else {
        $humanPercent = $validatedPercent = $aiPercent = $capturePercent = 0;
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
             'capture' => $captureCount,
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
        @if($capturePercent > 0)
            <div class="bg-gray-500 h-full" style="width: {{ $capturePercent }}%"></div>
        @endif
    </div>

    {{-- Legend (optional, shown when slot has content) --}}
    @if($slot->isNotEmpty())
        {{ $slot }}
    @endif
</div>
