@props(['translation'])

@php
    // What the file is MADE OF, not how far it has come. The shares come from the model
    // (Translation::qualityShares) and the drawing from x-quality-bar: this file only decides
    // what goes AROUND the bar.
    $captureCount = $translation->capture_count ?? 0;
    $percents = $translation->qualityShares();
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
