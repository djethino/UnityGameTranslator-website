@props(['span', 'daysStored', 'route', 'keep' => [], 'title', 'note' => null])

{{-- The period bar of the admin screens that read over a span (Analytics, Flows): the offers —
     the last N days up to now — and two dates for any period already stored (App\Support\Span).

     🔴 Sticky, because everything it drives is BELOW it and most of it is off screen: comparing two
     spans otherwise meant scrolling a page and a half up and back down.

     ⚠ `keep` is what the screen's other filters carry along: touching the span must not silently
     reset them. An offer drops the two dates and the dates drop the offer — one span at a time.

     ⚠ Links carry `data-keeps-scroll`: a screen that remembers where the reader was (Analytics)
     listens for it; the others ignore it. --}}
@php
    $today = now()->toDateString();
    $oldest = now()->subDays(max(1, $daysStored) - 1)->toDateString();
    $keep = array_filter($keep, fn ($v) => $v !== null && $v !== '');
@endphp
<div class="sticky top-[var(--site-bar-offset,0px)] transition-[top] duration-200 z-30 -mx-4 px-4 mt-8 mb-3 py-2 bg-gray-900/95 backdrop-blur
            border-b border-gray-800 flex flex-wrap gap-3 justify-between items-center"
     id="period-bar">
    <h2 class="text-lg font-semibold text-gray-300">
        <i class="fas fa-calendar-days mr-2 text-purple-500"></i>
        {{ $title }}
        @if($note)
            <span class="text-sm font-normal text-gray-500 ml-2">— {{ $note }}</span>
        @endif
    </h2>

    <div class="flex flex-wrap items-center gap-2">
        @foreach (\App\Support\AnalyticsPeriods::choices($daysStored, $span->days) as $days => $label)
            <a href="{{ route($route, $keep + ['period' => $days]) }}" data-keeps-scroll
               class="px-3 py-1.5 rounded text-sm {{ $span->days === $days ? 'bg-purple-600' : 'bg-gray-700 hover:bg-gray-600' }}">
                {{ $label }}
            </a>
        @endforeach

        {{-- Two dates: any period stored, ending in the past or today. The fields open on the
             span shown, so a range is adjusted rather than typed again. --}}
        <form action="{{ route($route) }}" method="GET"
              class="flex items-center gap-1 rounded px-2 py-1 {{ $span->isRange() ? 'bg-purple-600/30 ring-1 ring-purple-500' : 'bg-gray-800' }}">
            @foreach($keep as $name => $value)
                <input type="hidden" name="{{ $name }}" value="{{ $value }}">
            @endforeach
            <input type="date" name="from" value="{{ $span->from->toDateString() }}" min="{{ $oldest }}" max="{{ $today }}"
                   aria-label="From" class="bg-gray-700 border border-gray-600 rounded px-2 py-1 text-sm text-white [color-scheme:dark]">
            <span class="text-gray-500 text-sm">→</span>
            <input type="date" name="to" value="{{ $span->to->toDateString() }}" min="{{ $oldest }}" max="{{ $today }}"
                   aria-label="To" class="bg-gray-700 border border-gray-600 rounded px-2 py-1 text-sm text-white [color-scheme:dark]">
            <button type="submit" data-keeps-scroll class="px-3 py-1 rounded text-sm bg-gray-700 hover:bg-gray-600">Show</button>
        </form>
    </div>
</div>
