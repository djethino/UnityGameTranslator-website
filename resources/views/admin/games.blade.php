@extends('layouts.app')

@section('title', 'Games - Admin')

@section('content')
<div class="flex justify-between items-center mb-6">
    <h1 class="text-3xl font-bold"><i class="fas fa-gamepad mr-2"></i> Games</h1>
    <a href="{{ route('admin.dashboard') }}" class="text-gray-400 hover:text-white">
        <i class="fas fa-arrow-left mr-1"></i> Back to Dashboard
    </a>
</div>

{{--
    What this screen is for, said once at the top rather than left to be guessed: it is the only
    place a name a machine declared can be corrected. Everything else refuses a bad value at the
    door and nothing could repair one already stored.
--}}
<div class="bg-gray-800 border-l-4 border-blue-500 rounded p-4 mb-6 text-sm text-gray-300">
    {{-- Two facts, each one a thing the admin acts on: what the column is for, and why Clear is
         the only thing to do with it. The mechanics (app.info, never overwritten) are in
         AdminController::clearGameNames, not on the screen. --}}
    <p class="mb-1">
        <strong class="text-white">Name on disk</strong> is the name a game gives itself in its own
        files. A copy without a Steam id (GOG, Epic, a disc) finds this card with it.
    </p>
    <p>
        It is recorded by the first upload from such a copy. If it is wrong, clear it: the next
        upload records it again.
    </p>
    {{-- Asked 2026-09-30: "je me demandais ce qu'étaient les propositions" — the button's tooltip
         was the only place that said it. --}}
    <p class="mt-3">
        <strong class="text-white">Proposals</strong> come from <strong class="text-white">Check stores</strong>:
        a Steam or IGDB id the card lacks, found by its exact title, or a cover from the Steam page of its id.
        Each one is in a green frame, under the value it would fill. Nothing is written until you tick it
        and click Apply. Reject hides that value for good.
    </p>
</div>

{{-- Filters --}}
<form action="{{ route('admin.games') }}" method="GET"
      class="bg-gray-800 rounded-lg p-4 mb-6 flex flex-wrap gap-4 items-end">
    {{-- A filter must not silently undo the column somebody clicked --}}
    @if(request('sort'))<input type="hidden" name="sort" value="{{ request('sort') }}">@endif
    @if(request('dir'))<input type="hidden" name="dir" value="{{ request('dir') }}">@endif
    <div class="flex-1 min-w-[240px]">
        <label class="block text-sm text-gray-400 mb-1">Search</label>
        <input type="text" name="search" value="{{ request('search') }}" placeholder="Title, name on disk or Steam id..."
            class="w-full bg-gray-700 border border-gray-600 rounded px-3 py-2 text-white">
    </div>
    <div>
        <label class="block text-sm text-gray-400 mb-1">Name on disk</label>
        <select name="naming" class="bg-gray-700 border border-gray-600 rounded px-3 py-2 text-white">
            <option value="">All</option>
            <option value="set" {{ request('naming') === 'set' ? 'selected' : '' }}>Recorded</option>
            <option value="missing" {{ request('naming') === 'missing' ? 'selected' : '' }}>Not recorded</option>
        </select>
    </div>
    <div>
        <label class="block text-sm text-gray-400 mb-1">Adults only</label>
        <select name="adult" class="bg-gray-700 border border-gray-600 rounded px-3 py-2 text-white">
            <option value="">All</option>
            <option value="yes" {{ request('adult') === 'yes' ? 'selected' : '' }}>Marked</option>
            <option value="no" {{ request('adult') === 'no' ? 'selected' : '' }}>Not marked</option>
        </select>
    </div>
    <div>
        <label class="block text-sm text-gray-400 mb-1">Proposals</label>
        <select name="proposals" class="bg-gray-700 border border-gray-600 rounded px-3 py-2 text-white">
            <option value="">All</option>
            <option value="pending" {{ request('proposals') === 'pending' ? 'selected' : '' }}>Pending only</option>
        </select>
    </div>
    <button type="submit" class="bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded">
        <i class="fas fa-search mr-1"></i> Search
    </button>
    @if(request()->hasAny(['search', 'naming', 'adult', 'proposals']))
        <a href="{{ route('admin.games') }}" class="bg-gray-600 hover:bg-gray-500 text-white px-4 py-2 rounded">
            <i class="fas fa-times mr-1"></i> Clear
        </a>
    @endif
</form>

{{-- What the stores can tell the cards, and accepting it.

     🔴 Nothing a store finds by TITLE is written into a card without being ticked here: a title is
     a guess, an id decides which card every later upload of that game is filed under
     (App\Services\StoreProposals). An exact title match comes ticked; several candidates for one
     field come unticked, and ticking two of them is refused.

     The ticks live in the rows, and the rows already hold forms of their own (names, the adult
     mark) — forms cannot nest, so every tick names this form through `form="apply-proposals"`.

     ⚠ The counter is a registered component, not an inline expression: the site runs Alpine's
     CSP build, whose parser refuses methods and arrow functions inside an attribute — written
     inline, it threw, and the button kept saying "Apply" over twenty ticked boxes. --}}
<div class="flex flex-wrap items-center justify-between gap-3 mb-3" x-data="proposalApply">
    {{-- It goes out to Steam and IGDB for every card due and answers when they all have, so it
         can take a while: the button says it is working and cannot be pressed twice — the same
         motif as Refresh on the analytics catalogue card (x-admin.refresh-catalogues). --}}
    <form action="{{ route('admin.games.check-stores') }}" method="POST"
          x-data="{ running: false }" @submit="running = true">
        @csrf
        <button type="submit" :disabled="running"
            class="bg-gray-700 hover:bg-gray-600 text-white px-4 py-2 rounded disabled:opacity-50 disabled:cursor-wait transition"
            title="Asks Steam and IGDB what the cards lack. Nothing is written until you apply it.">
            <span x-show="!running"><i class="fas fa-store mr-1"></i> Check stores{{ $neverChecked > 0 ? " ({$neverChecked})" : '' }}</span>
            <span x-show="running" x-cloak><i class="fas fa-rotate fa-spin mr-1"></i> Checking stores…</span>
        </button>
    </form>
    <form id="apply-proposals" action="{{ route('admin.games.proposals.apply') }}" method="POST"
          class="flex items-center gap-3">
        @csrf
        {{-- Two different counts side by side, so each names what it counts (asked 2026-09-30:
             "8 pending" beside "Apply (3)" read as a contradiction). This one is every proposal
             awaiting Apply or Reject, across the catalogue; the button's is the ticked boxes — only
             a lone candidate for a field comes ticked. Leads to those games unless already there. --}}
        @if($pendingProposals > 0)
            @php
                $pendingLabel = $pendingProposals . ' ' . Str::plural('proposal', $pendingProposals)
                    . ' on ' . $pendingGames . ' ' . Str::plural('game', $pendingGames);
            @endphp
            @if(request('proposals') === 'pending')
                <span class="text-sm text-gray-400">{{ $pendingLabel }}</span>
            @else
                <a href="{{ route('admin.games', array_merge(collect(request()->query())->except('page')->all(), ['proposals' => 'pending'])) }}"
                   class="text-sm text-gray-400 hover:text-white underline decoration-dotted"
                   title="Show only these games">{{ $pendingLabel }}</a>
            @endif
        @endif
        {{-- The norm: greyed and without a number when nothing waits. --}}
        <button type="submit" :disabled="none" title="Writes the ticked proposals into the cards"
            class="px-4 py-2 rounded text-white transition bg-purple-600 hover:bg-purple-700 disabled:bg-gray-700 disabled:text-gray-500 disabled:cursor-not-allowed">
            <span x-text="label">Apply</span>
        </button>
    </form>
</div>

{{-- Check stores lands here with this filter on, so the proposals are never on a page nobody is
     looking at. Said above the list, with the way back to every game: a narrowed list that does
     not say so reads as games having disappeared. Only this filter is dropped — the search and
     the order stay. --}}
@if(request('proposals') === 'pending')
    <div class="bg-gray-800 border-l-4 border-emerald-500 rounded p-3 mb-3 flex flex-wrap items-center justify-between gap-3 text-sm">
        <span class="text-gray-300">
            <i class="fas fa-filter mr-1 text-emerald-400"></i>
            Only games with pending proposals ({{ $games->total() }}).
        </span>
        <a href="{{ route('admin.games', collect(request()->query())->except(['proposals', 'page'])->all()) }}"
           class="bg-gray-700 hover:bg-gray-600 text-white px-3 py-1.5 rounded">
            Show all games
        </a>
    </div>
@endif

{{-- Results --}}
<div class="bg-gray-800 rounded-lg border border-gray-700 overflow-hidden">
    <div class="overflow-x-auto">
    <table class="w-full">
        <thead class="bg-gray-750 text-gray-400 text-sm">
            <tr>
                {{-- Every header names the controller's default — last updated first — so the
                     arrow shown is the order applied: the component decides per header which one
                     is active when nothing was asked.

                     ⚠ Never `:default="null"` to mean "none": Blade's @props fills a prop that is
                     null with its declared default (`$x = $x ?? $default`), which silently lit up
                     the component's own default column instead. --}}
                <x-admin.sortable-th column="name" label="Game" default="last_update" first="asc" />
                <th class="text-left py-3 px-4">Store ids</th>
                <x-admin.sortable-th column="translations_count" label="Translations" default="last_update" />
                <th class="text-left py-3 px-4">Name on disk</th>
                <x-admin.sortable-th column="adult_checked_at" label="Adults only" default="last_update" />
                <x-admin.sortable-th column="last_update" label="Updated" default="last_update" />
                <x-admin.sortable-th column="created_at" label="Added" default="last_update" />
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-700">
            @forelse($games as $game)
                <tr class="hover:bg-gray-750">
                    {{-- The cover, like the translations screen: a title alone makes every row
                         look the same, and this list is read by scanning it.

                         ⚠ A floor on the width: the ids beside it never wrap, so the table took
                         the room from here, and a one-word title ran under the next column. --}}
                    <td class="py-3 px-4 min-w-[13rem]">
                        <div class="flex items-center gap-3">
                            @if($game->image_url)
                                {{-- Opens full size, so the cover in place can be compared with the
                                     one a store proposes below it. --}}
                                <a href="{{ \App\Support\StoreLinks::image($game->image_url) }}" target="_blank" rel="noopener noreferrer"
                                   class="flex-shrink-0" title="Current cover — open full size">
                                    <img src="{{ $game->image_url }}" alt="" class="w-10 h-14 object-cover rounded">
                                </a>
                            @else
                                <div class="w-10 h-14 bg-gray-700 rounded flex items-center justify-center flex-shrink-0">
                                    <i class="fas fa-gamepad text-gray-500"></i>
                                </div>
                            @endif
                            <a href="{{ route('games.show', $game->slug) }}" class="font-medium text-white hover:text-purple-400">
                                {{ $game->name }}
                            </a>
                        </div>
                        {{-- A cover is proposed where it would change: under the one it replaces.
                             ⚠ Outside the flex row above, as a block of the cell: inside it, the
                             row let it shrink below its own width and it spilled over the next
                             column; out here the column widens to fit it, as a table cell does. --}}
                        @include('admin.partials.game-proposals', ['game' => $game, 'field' => 'image_url'])
                    </td>
                    {{-- Each store on its own block: its name in a column of its own, the id the
                         card holds, then what that store proposes indented UNDER that id.

                         ⚠ Written as two plain lines before, a proposal for IGDB sat right under
                         the Steam id and read as a second Steam id — asked on 2026-09-22 why the
                         screen proposed "another Steam id" for a game whose Steam id was right. --}}
                    <td class="py-3 px-4 text-sm whitespace-nowrap">
                        @foreach(\App\Support\StoreLinks::Stores as $idField => $storeName)
                            <div class="{{ $loop->first ? '' : 'mt-2' }}">
                                <div class="flex items-baseline gap-2">
                                    <span class="w-10 text-gray-500">{{ $storeName }}</span>
                                    @php
                                        $current = $game->{$idField};
                                        $storePage = \App\Support\StoreLinks::forField($idField, $current);
                                    @endphp
                                    @if($storePage)
                                        {{-- The id already there opens too: checking what the card
                                             holds is the first thing to do before adding to it. --}}
                                        <a href="{{ $storePage }}" target="_blank" rel="noopener noreferrer"
                                           class="text-gray-300 hover:text-white underline decoration-dotted"
                                           title="Open this {{ $storeName }} page">{{ $current }}
                                            <i class="fas fa-arrow-up-right-from-square text-[0.6rem] ml-0.5"></i></a>
                                    @else
                                        <span class="text-gray-400">{{ $current ?: '—' }}</span>
                                    @endif
                                </div>
                                <div class="pl-12">
                                    @include('admin.partials.game-proposals', ['game' => $game, 'field' => $idField])
                                </div>
                            </div>
                        @endforeach
                    </td>
                    {{-- Remove is drawn only where it can act: a card nothing is filed under (every
                         translation counted, branches included — AdminController::destroyGame).
                         Such a card is never listed, but the publish list still offers it and
                         uploads are still filed under it. --}}
                    <td class="py-3 px-4 text-gray-400">
                        {{ $game->translations_count }}
                        @if($game->translations_count === 0)
                            <form action="{{ route('admin.games.destroy', $game->id) }}" method="POST" class="mt-1"
                                  data-confirm="Remove {{ $game->name }}? No translation is filed under it. Its store proposals, extra ids and visit counts go with it.">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="px-2 py-1 rounded text-xs bg-gray-700 hover:bg-gray-600 text-gray-300">
                                    Remove
                                </button>
                            </form>
                        @endif
                    </td>
                    {{-- Shown, never typed: the value comes from the game's own files, which an
                         admin does not have (decided 2026-09-22). The one act is Clear, and it is
                         only drawn when there is something to clear.

                         ⚠ No "missing" warning per row: a copy without a Steam id may still find
                         this card by its display name, so "cannot be found" would be a claim
                         nothing here can prove. --}}
                    <td class="py-3 px-4 text-sm">
                        @if($game->unity_name || $game->unity_company)
                            <div class="flex items-center gap-2 whitespace-nowrap">
                                <span class="text-gray-300">{{ $game->unity_name ?? '—' }}</span>
                                @if($game->unity_company)
                                    <span class="text-gray-500">&middot; {{ $game->unity_company }}</span>
                                @endif
                            </div>
                            <form action="{{ route('admin.games.names.clear', $game->id) }}" method="POST" class="mt-1"
                                  data-confirm="Clear the name on disk of {{ $game->name }}? The next upload from a copy without a Steam id will record it again.">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="px-2 py-1 rounded text-xs bg-gray-700 hover:bg-gray-600 text-gray-300">
                                    Clear
                                </button>
                            </form>
                        @else
                            <span class="text-gray-500">—</span>
                        @endif
                    </td>
                    {{-- The only place a game comes OUT of the mark. Three buttons rather than a
                         toggle, because "nothing" is a real answer and a different one from "no":
                         clearing hands the game back to the stores and the contributors, where
                         "no" pins it here for ever. The state says which source decided. --}}
                    <td class="py-3 px-4">
                        <p class="text-xs mb-1 {{ $game->adult ? 'text-amber-400' : 'text-gray-500' }}">
                            {{ $game->adult ? 'yes' : 'no' }}
                            <span class="text-gray-500">
                                &middot; {{ $game->adultSource() ?? ($game->adult_checked_at ? 'nothing found' : 'never checked') }}
                            </span>
                        </p>
                        @php
                            $override = match ($game->adult_override) {
                                true => 'yes',
                                false => 'no',
                                default => 'clear',
                            };
                        @endphp
                        <form action="{{ route('admin.games.adult', $game->id) }}" method="POST" class="flex gap-1">
                            @csrf
                            @foreach(['yes' => 'Mark', 'no' => 'Unmark', 'clear' => 'Clear'] as $value => $label)
                                <button type="submit" name="adult" value="{{ $value }}"
                                    class="px-2 py-1 rounded text-xs {{ $override === $value ? 'bg-gray-600 text-white' : 'bg-gray-700 hover:bg-gray-600 text-gray-300' }}">
                                    {{ $label }}
                                </button>
                            @endforeach
                        </form>
                    </td>
                    {{-- When one of its translations last changed — the "Updated" of the
                         translations screen, read across the game. None yet: "—". --}}
                    <td class="py-3 px-4 text-gray-400 text-sm whitespace-nowrap">
                        {{ $game->last_update?->format('M d, Y') ?? '—' }}
                    </td>
                    <td class="py-3 px-4 text-gray-400 text-sm whitespace-nowrap">
                        {{ $game->created_at->format('M d, Y') }}
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="py-8 text-center text-gray-500">No game matches.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
    </div>
</div>

@if($games->hasPages())
    <div class="mt-6">
        {{ $games->links() }}
    </div>
@endif

<script nonce="{{ $cspNonce }}">
document.addEventListener('alpine:init', () => {
    // `Apply (N)`: N is what the ticked boxes would write, greyed and without a number at 0.
    // The boxes are scattered through the table rows, so the count is read from the page rather
    // than bound to each one.
    Alpine.data('proposalApply', () => ({
        n: 0,
        get none() { return this.n === 0; },
        get label() { return this.n > 0 ? 'Apply (' + this.n + ')' : 'Apply'; },
        init() {
            this.count();
            document.addEventListener('change', (event) => {
                if (event.target.matches('input[form="apply-proposals"]')) {
                    this.count();
                }
            });
        },
        count() {
            this.n = document.querySelectorAll('input[form="apply-proposals"]:checked').length;
        },
    }));
});
</script>
@endsection
