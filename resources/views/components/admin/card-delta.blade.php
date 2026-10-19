@props(['percent' => null, 'added' => null, 'tone' => 'text-green-300', 'title' => null])

{{-- What moved, beside a dashboard card's figure (user, 2026-10-20): the change against the 7 days
     before (`percent`, green up, red down), or what is new this week (`added`, as "+N" in the card's
     tone). One piece for every card, so the eye learns it once. Nothing is drawn when there is
     nothing to say — no "+0", no percentage of an empty week. --}}
@if($percent !== null)
    <span class="text-base font-semibold {{ $percent >= 0 ? 'text-green-400' : 'text-red-400' }}"
          title="{{ $title ?? 'Against the 7 days before' }}">{{ $percent >= 0 ? '+' : '' }}{{ $percent }} %</span>
@elseif($added !== null && $added > 0)
    <span class="text-base font-semibold {{ $tone }}" title="{{ $title ?? 'New in 7 days' }}">+{{ number_format($added) }}</span>
@endif
