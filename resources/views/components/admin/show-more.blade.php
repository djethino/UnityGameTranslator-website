@props(['count', 'visible'])

{{-- Reveal the rest of a card that shows its first few rows.

     ⚠ The gesture and the wording are the editors' ("Show more (N)", chevron down, purple text —
     `components/editor/readonly-grid.blade.php`), not a new one invented for this page.

     🔴 **It toggles a state it does not own.** `expanded` lives on the row of cards, so both cards
     open at once and stay the same height. A card that folded on its own would leave its neighbour
     half its size, and the reader reads that gap as a fault rather than as their own doing.

     ⚠ Renders nothing when there is nothing hidden: a control that cannot do anything does not
     appear — the rule this program applies everywhere else. --}}
@if($count > $visible)
    <div class="text-center mt-3">
        <button type="button" @click="expanded = !expanded"
                class="text-purple-400 hover:text-purple-300 text-sm transition">
            <i class="fas mr-1" :class="expanded ? 'fa-chevron-up' : 'fa-chevron-down'"></i>
            <span x-text="expanded ? 'Show less' : 'Show more ({{ $count - $visible }})'">Show more ({{ $count - $visible }})</span>
        </button>
    </div>
@endif
