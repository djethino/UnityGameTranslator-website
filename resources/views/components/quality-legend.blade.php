@props(['translation', 'compact' => false])

{{--
    The colour key for x-progress-bar. It used to be copy-pasted under each bar and
    the copies had already drifted — one of them silently omitted the captured
    lines — so a reader could not tell whether a missing entry meant "none" or
    "this page forgot it". One definition now, so every bar is read the same way.

    Entries with nothing in them are left out: "Captured: 0" is noise, and the
    absence is itself the information.
--}}
@php
    $dot = $compact ? 'w-1.5 h-1.5' : 'w-2 h-2';
    $captureCount = $translation->capture_count ?? 0;
    $skippedCount = $translation->skipped_count ?? 0;

    $entries = [
        ['color' => 'bg-green-500',  'label' => __('progress.human'),     'count' => $translation->human_count],
        ['color' => 'bg-blue-500',   'label' => __('progress.validated'), 'count' => $translation->validated_count],
        ['color' => 'bg-orange-500', 'label' => __('progress.ai'),        'count' => $translation->ai_count],
        ['color' => 'bg-purple-500', 'label' => __('progress.skipped'),   'count' => $skippedCount, 'only_if_any' => true],
        ['color' => 'bg-gray-500',   'label' => __('progress.capture'),   'count' => $captureCount, 'only_if_any' => true],
    ];
@endphp

<div {{ $attributes->merge(['class' => 'flex items-center ' . ($compact ? 'gap-2' : 'gap-3 flex-wrap') . ' text-xs text-gray-400 mt-1']) }}>
    @foreach($entries as $entry)
        @continue(($entry['only_if_any'] ?? false) && $entry['count'] < 1)
        <span class="flex items-center gap-1">
            <span class="{{ $dot }} {{ $entry['color'] }} rounded-full shrink-0"></span>
            @if($compact)
                {{ number_format($entry['count']) }}
            @else
                {{ $entry['label'] }}: {{ number_format($entry['count']) }}
            @endif
        </span>
    @endforeach

    {{ $slot }}
</div>
