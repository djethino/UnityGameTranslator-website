@props(['translation', 'compact' => false])

{{--
    The colour key for x-progress-bar. It used to be copy-pasted under each bar and
    the copies had already drifted — one of them silently omitted the captured
    lines — so a reader could not tell whether a missing entry meant "none" or
    "this page forgot it". One definition now, so every bar is read the same way.

    Entries with nothing in them are left out: "Captured: 0" is noise, and the
    absence is itself the information.

    🔴 **Two forms and no more: the word, or the LETTER.** The compact form used to be a coloured
    dot and a bare number — the only place in the whole product where a share of the bar was named
    by neither. So the same five bands were written five different ways across the three products,
    and the one vocabulary every editor, merge screen and contribution count already uses — H, V,
    A, S — appeared in none of them. Compact now carries the tag chip itself, the very square the
    editing grids draw.

    ⚠ **Captured keeps its dot, and that is not an inconsistency.** A captured line holds NO tag:
    inventing a letter for it would teach a tag the file does not contain. The dot IS the absence.
--}}
@php
    $captureCount = $translation->capture_count ?? 0;
    $skippedCount = $translation->skipped_count ?? 0;

    $entries = [
        ['color' => 'bg-green-500',  'tag' => 'H', 'label' => __('progress.human'),     'count' => $translation->human_count],
        ['color' => 'bg-blue-500',   'tag' => 'V', 'label' => __('progress.validated'), 'count' => $translation->validated_count],
        ['color' => 'bg-orange-500', 'tag' => 'A', 'label' => __('progress.ai'),        'count' => $translation->ai_count],
        ['color' => 'bg-purple-500', 'tag' => 'S', 'label' => __('progress.skipped'),   'count' => $skippedCount, 'only_if_any' => true],
        ['color' => 'bg-gray-500',   'tag' => null, 'label' => __('progress.capture'),  'count' => $captureCount, 'only_if_any' => true],
    ];
@endphp

<div {{ $attributes->merge(['class' => 'flex items-center ' . ($compact ? 'gap-2' : 'gap-3 flex-wrap') . ' text-xs text-gray-400 mt-1']) }}>
    @foreach($entries as $entry)
        @continue(($entry['only_if_any'] ?? false) && $entry['count'] < 1)
        <span class="flex items-center gap-1" @if($compact) title="{{ $entry['label'] }}" @endif>
            @if($compact && $entry['tag'])
                <span class="tag-{{ $entry['tag'] }}">{{ $entry['tag'] }}</span>
            @else
                <span class="{{ $compact ? 'w-1.5 h-1.5' : 'w-2 h-2' }} {{ $entry['color'] }} rounded-full shrink-0"></span>
            @endif
            @if($compact)
                {{ number_format($entry['count']) }}
            @else
                {{ $entry['label'] }}: {{ number_format($entry['count']) }}
            @endif
        </span>
    @endforeach

    {{ $slot }}
</div>
