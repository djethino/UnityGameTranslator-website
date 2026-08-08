@props(['translation'])

@php
    // What the file is MADE OF, not how far it has come: a game's total line count
    // is unknowable (text is captured as it is met), so there is no progress to
    // measure. The denominator is everything that WAS captured — including lines
    // marked as not to translate, which were met, looked at, and settled.
    $captureCount = $translation->capture_count ?? 0;
    $skippedCount = $translation->skipped_count ?? 0;
    $total = $translation->effective_lines + $captureCount + $skippedCount;

    // Only the shares. What the bar looks like belongs to x-quality-bar, which the editors
    // render too — see that component for why there is exactly one of it.
    $percents = $total > 0 ? [
        'H' => ($translation->human_count / $total) * 100,
        'V' => ($translation->validated_count / $total) * 100,
        'A' => ($translation->ai_count / $total) * 100,
        'S' => ($skippedCount / $total) * 100,
        'C' => ($captureCount / $total) * 100,
    ] : [];
@endphp

<div {{ $attributes->merge(['class' => 'progress-bar-wrapper']) }}>
    {{-- The tooltip carries counts and nothing else. It used to end with "Quality: X/3", which
         was the only place that score reached a visitor at all — it is an author-facing number,
         and a scale nobody could read besides.

         Kept-as-is is deliberately NOT listed here, though it owns a band in the bar: this
         string has no way to drop a line when its count is zero, and "Kept as is: 0" on every
         translation that has none is exactly the noise the legend avoids. The legend names it
         where it exists. --}}
    <x-quality-bar :percents="$percents" :title="__('progress.tooltip', [
        'human' => $translation->human_count,
        'validated' => $translation->validated_count,
        'ai' => $translation->ai_count,
        'capture' => $captureCount,
    ])" />

    {{-- Legend (optional, shown when slot has content) --}}
    @if($slot->isNotEmpty())
        {{ $slot }}
    @endif
</div>
