@props([
    // Server side: ['H' => 12.5, 'V' => 0, ...] — shares in percent, already computed.
    'percents' => null,
    // Client side: the name of an Alpine method taking a segment key and returning a percent
    // (the editors' shared core exposes tagPercent). Mutually exclusive with `percents`.
    'percentFn' => null,
    'title' => null,
    // Whole class names, never "h-{$n}": Tailwind reads the source as text. And a prop rather
    // than a merged class, because two competing heights would be settled by CSS order rather
    // than by the caller.
    'height' => 'h-2',
])

@php
    /**
     * THE quality bar of this site. One definition of what the bar is made of, in what order,
     * and in what colour — the same role QualityBar.cs plays in the mod.
     *
     * It existed twice: once rendered from the database for the cards and the game pages, once
     * rendered from Alpine for the four editing grids. They drifted, as two copies do — the
     * editors had swapped purple and grey, dropped the captured share entirely, and so answered
     * 100% on a file the game page called 15% translated. Changing a colour now means changing
     * it here, and every screen follows.
     *
     * Order: settled first, still-to-do last. The grey always ends the bar, so its length reads
     * as the work left without any arithmetic — the mod says the same thing in the same words.
     *
     * Mod-UI lines (tag M) have no band. They are the mod's own interface rather than the game's
     * text; the database keeps no counter for them and the mod's own bar ignores them too.
     */
    $segments = [
        'H' => 'bg-green-500',
        'V' => 'bg-blue-500',
        'A' => 'bg-orange-500',
        // Kept as is: met, looked at, and settled — a decision, not a gap. Its own colour, before
        // the grey, because a filled bar must mean "everything met has been dealt with".
        'S' => 'bg-purple-500',
        // Captured: met, still waiting for someone.
        'C' => 'bg-gray-500',
    ];
@endphp

<div {{ $attributes->merge(['class' => $height . ' bg-gray-700 rounded-full overflow-hidden flex']) }}
    @if($title) title="{{ $title }}" @endif>
    @foreach($segments as $key => $colour)
        @if($percentFn)
            <div class="{{ $colour }} h-full" :style="'width: ' + {{ $percentFn }}('{{ $key }}') + '%'"></div>
        @elseif(($percents[$key] ?? 0) > 0)
            <div class="{{ $colour }} h-full" style="width: {{ $percents[$key] }}%"></div>
        @endif
    @endforeach
</div>
