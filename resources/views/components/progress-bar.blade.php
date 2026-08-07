@props(['translation'])

@php
    // What the file is MADE OF, not how far it has come: a game's total line count
    // is unknowable (text is captured as it is met), so there is no progress to
    // measure. The denominator is everything that WAS captured — including lines
    // marked as not to translate, which were met, looked at, and settled.
    $captureCount = $translation->capture_count ?? 0;
    $skippedCount = $translation->skipped_count ?? 0;
    $effective = $translation->effective_lines;
    $total = $effective + $captureCount + $skippedCount;

    if ($total > 0) {
        $humanPercent = ($translation->human_count / $total) * 100;
        $validatedPercent = ($translation->validated_count / $total) * 100;
        $aiPercent = ($translation->ai_count / $total) * 100;
        $skippedPercent = ($skippedCount / $total) * 100;
        $capturePercent = ($captureCount / $total) * 100;
    } else {
        $humanPercent = $validatedPercent = $aiPercent = $skippedPercent = $capturePercent = 0;
    }

@endphp

<div {{ $attributes->merge(['class' => 'progress-bar-wrapper']) }}>
    {{-- Composition bar.

         The tooltip carries counts and nothing else. It used to end with "Quality: X/3", which
         was the only place that score reached a visitor at all — it is an author-facing number,
         and a scale nobody could read besides.

         Kept-as-is is deliberately NOT listed here, though it owns a band in the bar: this
         string has no way to drop a line when its count is zero, and "Kept as is: 0" on every
         translation that has none is exactly the noise the legend avoids. The legend names it
         where it exists. --}}
    <div class="h-2 bg-gray-700 rounded-full overflow-hidden flex"
         title="{{ __('progress.tooltip', [
             'human' => $translation->human_count,
             'validated' => $translation->validated_count,
             'ai' => $translation->ai_count,
             'capture' => $captureCount,
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
        {{-- Settled, not missing: the author met these lines and decided they stay
             as they are. Its own colour, before the grey, because a filled bar must
             mean "everything met has been dealt with". --}}
        @if($skippedPercent > 0)
            <div class="bg-purple-500 h-full" style="width: {{ $skippedPercent }}%"></div>
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
