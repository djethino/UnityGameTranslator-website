@props(['card' => 'bg-gray-800 rounded-lg border border-gray-700 overflow-hidden'])

{{-- An admin table that may be wider than the screen, with its horizontal scrollbar always within
     reach (user, 2026-10-05: "pourquoi l'admin doit avoir une expérience moins bien que la partie
     user ?"). The editors already solved this — js/components/editor-hscroll.js — and the same code
     runs here (adminTableScroll in app.js), with the same rule: never two bars for one movement.

     ⚠ The mirror sits OUTSIDE the card: the card clips its corners (`overflow-hidden`), and a
     sticky element inside a clipping box sticks to that box, never to the screen. Rows first: a
     table that fits shows no mirror at all — making it fit is the first answer, this is for when
     it cannot.

     Slot: the table. `$below`: what belongs in the card under it (pagination). --}}
<div x-data="adminTableScroll">
    <div {{ $attributes->merge(['class' => $card]) }}>
        <div x-ref="gridBox" class="overflow-x-auto">
            {{ $slot }}
        </div>
        {{ $below ?? '' }}
    </div>
    <div class="sticky bottom-0 z-20 pt-2">
        <x-editor.h-scrollbar />
    </div>
</div>
