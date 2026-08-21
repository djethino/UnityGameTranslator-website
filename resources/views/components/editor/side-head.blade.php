{{--
    The two headers of ONE side of a two-file comparison: its tag column and its value column.

    🔴 **Side and role are two different things, and this component is why.** The side says which
    file (local or online) — it carries the colour and the label, and it never moves between runs.
    The role says whether that file is the one being WRITTEN, and it swaps with the direction: the
    game receives when comparing into it, the server receives when publishing.

    What follows the ROLE:
      · the place — the target is always the left pair, so the column being built reads first;
      · the tag width — `w-20` previews a transition (`A → V`), `w-12` only ever shows what is
        stored, and giving both the wide one would say a transition can happen on a file nothing
        writes to;
      · `data-col` on the tag — that is what the pin freezes, and freezing a value without its tag
        says half of what the row holds;
      · the pin toggle, which belongs on the column somebody keeps in sight.

    What follows the SIDE: `data-col` on the value (so a width somebody set stays attached to the
    file they set it on, whichever way the screen next opens), the colour, the label, the byline.
--}}
@props(['side', 'target' => false, 'label', 'byline' => null])

@php
    $tagCol = $side . 'Tag';
    $tone = $side === 'local' ? 'text-green-400' : 'text-blue-400';
@endphp

<th @if ($target) data-col="{{ $tagCol }}" @endif
    class="px-2 py-3 text-center border-l border-gray-700 cursor-pointer hover:text-white transition {{ $target ? 'w-20' : 'w-12' }}"
    @click="toggleSort('{{ $tagCol }}')">
    <div class="flex items-center justify-center gap-1">
        <span class="{{ $tone }} font-medium text-xs">Tag</span>
        <i class="fas text-xs" :class="getSortIcon('{{ $tagCol }}')"></i>
    </div>
</th>

{{-- min-w on both value columns, like every other grid: a key that exists on one side only leaves
     the other cell empty, and with automatic layout that column would shrink to nothing — exactly
     when the reader needs to see what is missing. --}}
<th data-col="{{ $side }}"
    class="relative px-4 py-3 text-left border-l border-gray-700 min-w-[250px] cursor-pointer hover:text-white transition"
    @click="toggleSort('{{ $side }}Value')">
    <div class="flex items-center gap-2">
        <span class="{{ $tone }} font-medium">{{ $label }}</span>
        @if ($byline)
            <span class="text-xs text-gray-500">({{ $byline }})</span>
        @endif
        <i class="fas" :class="getSortIcon('{{ $side }}Value')"></i>
        @if ($target)
            <x-editor.pin-toggle />
        @endif
    </div>
    <x-editor.col-resize col="{{ $side }}" />
</th>
