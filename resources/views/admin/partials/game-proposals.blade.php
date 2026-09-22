{{-- What the stores propose for ONE field of one card, drawn right under the value it would fill.

     Ticked by default only when it is the single candidate for that field: an exact title that
     answered once is as sure as a title can be. Two candidates for the same field come unticked —
     the admin picks, and ticking both is refused on apply.

     A value another card already carries cannot be ticked at all: applying it would make two
     cards claim one game, which is a merge (App\Services\StoreProposals). It stays visible, with
     the card that holds it, so the admin can see the duplicate — and Reject it to stop seeing it. --}}
@php
    $here = $game->proposals->where('field', $field);
    $tickedByDefault = $here->filter(fn ($p) => $p->isApplicable())->count() === 1;
    $sourceName = fn ($source) => match ($source) {
        'steam' => 'Steam',
        'igdb' => 'IGDB',
        default => $source,
    };
@endphp
@foreach($here as $proposal)
    {{-- One line per proposal, never wrapped: a "Reject" pushed alone onto the next line reads
         as belonging to the proposal below. The table scrolls sideways instead. --}}
    <div class="mt-1 flex items-center gap-2 text-xs whitespace-nowrap">
        @if($proposal->isApplicable())
            <input type="checkbox" name="proposals[]" value="{{ $proposal->id }}" form="apply-proposals"
                @checked($tickedByDefault)
                class="rounded bg-gray-700 border-gray-600 text-purple-600"
                title="Ticked proposals are written by Apply">
        @else
            <i class="fas fa-triangle-exclamation text-amber-400" title="Cannot be applied here"></i>
        @endif

        @if($field === 'image_url')
            {{-- The picture IS the label: it sits in the narrow title column, where "Steam cover"
                 written out pushed Reject across the next column. --}}
            <span class="text-emerald-300">&rarr;</span>
            <img src="{{ $proposal->value }}" alt="{{ $sourceName($proposal->source) }} cover"
                title="{{ $sourceName($proposal->source) }} cover" class="h-6 rounded">
        @else
            <span class="text-emerald-300">&rarr; {{ $proposal->value }}</span>
            @if($proposal->detail)
                {{-- The store's name for the game is what the admin decides on — but a long one
                     widened the whole table. Cut, and whole on hover. --}}
                <span class="text-gray-500 max-w-[11rem] truncate"
                    title="{{ $sourceName($proposal->source) }}: {{ $proposal->detail }}">{{ $sourceName($proposal->source) }}: {{ $proposal->detail }}</span>
            @endif
        @endif

        @if($proposal->conflictGame)
            <span class="text-amber-400">
                already on <a href="{{ route('games.show', $proposal->conflictGame->slug) }}" class="underline hover:text-amber-300">{{ $proposal->conflictGame->name }}</a>
            </span>
        @endif

        <form method="POST" action="{{ route('admin.games.proposals.reject', $proposal) }}" class="inline">
            @csrf
            <button type="submit" class="text-gray-500 hover:text-red-400"
                title="Never propose this value again for this game">Reject</button>
        </form>
    </div>
@endforeach
