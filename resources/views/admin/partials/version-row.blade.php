{{-- One row of the version inventory.

     ⚠ Shared by the three tables of the card (versions, buckets, adapters) on purpose: they answer
     different questions with the SAME columns, so a reader learns the shape once. Two of them
     rendering their dates differently would read as two unrelated screens.

     Expects: $line (from VersionInventory), $data (the product block, for the band scale). --}}
@php
    $isBucket = in_array($line['name'], [\App\Models\ClientUsageDaily::LEGACY, \App\Models\ClientUsageDaily::UNRECOGNISED, ''], true);

    $lastSeen = $line['last_seen'];
    $daysSince = $lastSeen ? (int) \Carbon\Carbon::parse($lastSeen)->startOfDay()->diffInDays(now()->startOfDay()) : null;
@endphp
<tr class="border-b border-gray-750 last:border-0 {{ $line['active'] ? '' : 'opacity-60' }}">
    <td class="py-2 pr-4">
        @if ($line['name'] === \App\Models\ClientUsageDaily::LEGACY)
            {{-- 🔴 The one row that decides whether compression can be turned on: these builds ask
                 for gzip and cannot read it. --}}
            <span class="text-amber-400">before versioning</span>
            <span class="block text-xs text-gray-500">cannot read compressed answers</span>
        @elseif ($line['name'] === \App\Models\ClientUsageDaily::UNRECOGNISED)
            {{-- ⚠ Not a version: everything matching no published release lands here together —
                 local builds, and anyone inventing a number. Kept apart from the row above on
                 purpose: conflating them would make a local build look like one that cannot
                 decompress. --}}
            <span class="text-gray-400">unrecognised</span>
            <span class="block text-xs text-gray-500">no matching release</span>
        @elseif ($line['name'] === '' && $isAdapters)
            {{-- ⚠ Neither a loader nor an unrecognised one: an absence. Either a build from before
                 the User-Agent carried a loader, or one whose version we could not place — the
                 loader is then dropped on purpose, so it cannot be counted as a real adapter. --}}
            <span class="text-gray-400">no loader named</span>
        @elseif ($line['name'] === '')
            {{-- 🔴 The empty string means two different things depending on the column it came from,
                 and rendering both the same way loses one of them. Here it is a VERSION: a row
                 written by the first version of this collector, before the marker had a name — the
                 same thing as `legacy`. A blank cell says nothing at all, which reads as a bug in
                 the page rather than as a build nobody can identify. --}}
            <span class="text-amber-400">before versioning</span>
            <span class="block text-xs text-gray-500">cannot read compressed answers</span>
        @else
            <span class="text-gray-200">{{ $line['name'] }}</span>
            @if ($line['prerelease'])
                <span class="ml-1 px-1.5 py-0.5 rounded bg-gray-700 text-gray-400 text-[10px] align-middle">beta</span>
            @endif
        @endif
    </td>

    <td class="py-2 pr-4 text-gray-400 whitespace-nowrap">
        @if ($line['published_at'])
            <span title="{{ $line['published_at']->format('Y-m-d') }}">
                {{ (int) $line['published_at']->startOfDay()->diffInDays(now()->startOfDay()) }} d ago
            </span>
        @elseif ($isVersions && !$isBucket && $line['first_seen'])
            {{-- ⚠ Said rather than left blank: a version that calls without appearing among our
                 releases is either one we withdrew or one whose date we never learned. Both are
                 worth a second look; an empty cell reads as a bug in the page.

                 ⚠ Versions only. A loader is not published by us, so the same words there would
                 answer a question nobody asked. --}}
            <span class="text-gray-600 text-xs">not in releases</span>
        @else
            <span class="text-gray-600">—</span>
        @endif
    </td>

    @if ($data['band'])
        <td class="py-2 pr-4">
            {{-- The band replaces the old progress bar, which only restated in pixels the number
                 written beside it. This carries since when, until when, how regularly and which way
                 it is going.

                 ⚠ Scaled against the busiest day of THIS table, never of the card: the loader block
                 counts every version together, so using one scale for both flattened every version
                 bar into a line a pixel tall.

                 ⚠ And a generous floor (40%), because the first thing to read is PRESENCE — was
                 there a call that day at all. The exact height is a secondary reading; the number is
                 written beside it anyway.

                 ⚠ The empty days are drawn, not skipped: without a visible rail the bars float with
                 nothing to place them against, and "it stops halfway" — the shape that says a
                 version died — cannot be seen at all. --}}
            {{-- ⚠ Segments share the width instead of taking a fixed one: 30 days and 52 weeks do
                 not draw the same number of bars, and a fixed width left the band ending in the
                 middle of its own column — which reads as data stopping, not as layout. --}}
            <span class="flex items-end gap-px h-7 w-full" title="{{ $line['days_in_span'] }} day(s) with a call">
                @foreach ($line['segments'] as $copies)
                    @php
                        $height = $copies > 0 ? max(40, (int) round($copies / max(1, $peak) * 100)) : 14;
                    @endphp
                    <span class="flex-1 min-w-[2px] {{ $copies > 0 ? 'bg-cyan-500' : 'bg-gray-600/60' }} rounded-sm"
                          style="height: {{ $height }}%"></span>
                @endforeach
            </span>
            @if ($data['weekly'])
                <span class="block text-[10px] text-gray-600 leading-none">1 bar = 1 week</span>
            @endif
        </td>
    @endif

    <td class="py-2 pr-4 text-right">
        @if ($line['copies'] > 0)
            <span class="text-gray-200">{{ number_format($line['copies']) }}</span>
        @else
            <span class="text-gray-600">—</span>
        @endif
    </td>

    <td class="py-2 text-right whitespace-nowrap">
        @if ($lastSeen === null)
            {{-- 🔴 Published and never run. It has no usage row at all, so the old card could not
                 show it — and "nobody took the update" is exactly what one wants to know. --}}
            <span class="text-amber-400/80">never</span>
        @elseif ($daysSince === 0)
            <span class="text-green-400">today</span>
        @else
            <span class="{{ $line['active'] ? 'text-gray-300' : 'text-gray-500' }}"
                  title="{{ $lastSeen }} — first seen {{ $line['first_seen'] }}">
                {{ $daysSince }} d ago
            </span>
        @endif
    </td>
</tr>
