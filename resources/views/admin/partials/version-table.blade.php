{{-- One table of the version inventory, used by all three blocks of the card.

     🔴 The three blocks answer different questions with the SAME columns, in the same order, at the
     same widths. That is the point: the reader learns the shape once. Three tables laid out
     independently would drift apart in width and read as three unrelated screens.

     ⚠ Hence `table-fixed` and a shared colgroup — without them each table sizes itself from its own
     content and nothing lines up.

     Expects: $lines, $data, $heading (what the first column IS here), $divide (draw the extinct
     line, only meaningful where rows are ordered by publication). --}}
@php
    $divide = $divide ?? false;
    $columns = $data['band'] ? 5 : 4;
    // Bars are scaled against their own table — see the note in VersionInventory.
    $peak = $data['peaks'][$scale] ?? 1;
    // ⚠ Only a version can be "not in releases". Saying it of a loader would answer a question
    // nobody asked, in a column that exists here only to keep the three tables aligned.
    $isVersions = $heading === 'Version';
    // ⚠ The empty string is a loader that never said its name here, and a build from before
    // versioning everywhere else. Same value, two meanings, and only the block knows which.
    $isAdapters = $heading === 'Adapter';
@endphp
<div class="overflow-x-auto">
    <table class="w-full text-sm table-fixed min-w-[640px]">
        {{-- ⚠ It is the BAND that takes the leftover width, not the name: a name column left to
             expand pushed the publication date to the far right, so the eye had to cross an empty
             third of the row to pair a version with its date. The band is also the one column that
             genuinely improves with more room. --}}
        <colgroup>
            <col class="w-48">
            <col class="w-28">
            @if ($data['band'])
                <col>
            @endif
            <col class="w-36">
            <col class="w-24">
        </colgroup>
        <thead>
            <tr class="text-gray-400 text-left border-b border-gray-700">
                <th class="py-2 pr-4 font-medium">{{ $heading }}</th>
                {{-- ⚠ Kept as a column even where nothing can fill it (a loader is not published):
                     dropping it for one block would misalign that block against the others, and the
                     alignment is what makes the three readable as one thing. --}}
                <th class="py-2 pr-4 font-medium">{{ $heading === 'Version' ? 'Published' : '' }}</th>
                @if ($data['band'])
                    <th class="py-2 pr-4 font-medium">Activity</th>
                @endif
                <th class="py-2 pr-4 font-medium text-right whitespace-nowrap">Copies<span class="text-gray-600"> (busiest day)</span></th>
                <th class="py-2 font-medium text-right">Last seen</th>
            </tr>
        </thead>
        <tbody>
            @php $lineDrawn = false; @endphp
            @foreach ($lines as $line)
                {{-- 🔴 THE line of this screen. Everything below it has not called once inside the
                     chosen span — which is the definition of "gone" here, not a threshold anybody
                     had to invent. Below the line is what can be broken. --}}
                @if ($divide && $line['retired'] && !$lineDrawn)
                    @php $lineDrawn = true; @endphp
                    <tr>
                        <td colspan="{{ $columns }}" class="pt-4 pb-1">
                            <div class="flex items-center gap-3 text-xs text-amber-400/80">
                                <span class="h-px flex-1 bg-amber-400/30"></span>
                                nothing in the last {{ $spanLabel }}
                                <span class="h-px flex-1 bg-amber-400/30"></span>
                            </div>
                        </td>
                    </tr>
                @endif
                @include('admin.partials.version-row', [
                    'line' => $line,
                    'data' => $data,
                    'peak' => $peak,
                    'isVersions' => $isVersions,
                    'isAdapters' => $isAdapters,
                ])
            @endforeach
        </tbody>
    </table>
</div>
