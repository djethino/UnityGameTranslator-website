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
    <p class="mb-1">
        <strong class="text-white">Unity name</strong> is what a game calls itself on disk
        (<code class="text-gray-400">&lt;Game&gt;_Data/app.info</code>), sent by the mod and the
        Manager when they publish. Copies with no Steam id are resolved by it.
    </p>
    <p>
        Clearing it lets the next upload record the right one — nothing overwrites a value that is
        already there. It is never shown to players.
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
        <input type="text" name="search" value="{{ request('search') }}" placeholder="Title, Unity name or Steam id..."
            class="w-full bg-gray-700 border border-gray-600 rounded px-3 py-2 text-white">
    </div>
    <div>
        <label class="block text-sm text-gray-400 mb-1">Unity name</label>
        <select name="naming" class="bg-gray-700 border border-gray-600 rounded px-3 py-2 text-white">
            <option value="">All</option>
            <option value="missing" {{ request('naming') === 'missing' ? 'selected' : '' }}>Missing</option>
            <option value="set" {{ request('naming') === 'set' ? 'selected' : '' }}>Set</option>
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
    <form action="{{ route('admin.games.check-stores') }}" method="POST">
        @csrf
        <button type="submit" class="bg-gray-700 hover:bg-gray-600 text-white px-4 py-2 rounded"
            title="Asks Steam and IGDB what the cards lack. Nothing is written until you apply it.">
            <i class="fas fa-store mr-1"></i> Check stores{{ $neverChecked > 0 ? " ({$neverChecked})" : '' }}
        </button>
    </form>
    <form id="apply-proposals" action="{{ route('admin.games.proposals.apply') }}" method="POST"
          class="flex items-center gap-3">
        @csrf
        <span class="text-sm text-gray-400">{{ $pendingProposals }} pending</span>
        {{-- The norm: greyed and without a number when nothing waits. --}}
        <button type="submit" :disabled="none"
            class="px-4 py-2 rounded text-white transition bg-purple-600 hover:bg-purple-700 disabled:bg-gray-700 disabled:text-gray-500 disabled:cursor-not-allowed">
            <span x-text="label">Apply</span>
        </button>
    </form>
</div>

{{-- Results --}}
<div class="bg-gray-800 rounded-lg border border-gray-700 overflow-hidden">
    <div class="overflow-x-auto">
    <table class="w-full">
        <thead class="bg-gray-750 text-gray-400 text-sm">
            <tr>
                {{-- `default=""` and not a column name: asked for nothing, this list keeps the
                     order it exists for — games with no Unity name first — so NO header may light
                     up. Naming one would claim the list is sorted by it.

                     ⚠ The empty string, never `null`: Blade's @props fills a prop that is null
                     with its declared default (`$x = $x ?? $default`), so `:default="null"` here
                     silently resolved to `created_at` and lit the "Added" arrow up. --}}
                <x-admin.sortable-th column="name" label="Game" default="" />
                <th class="text-left py-3 px-4">Store ids</th>
                <x-admin.sortable-th column="translations_count" label="Translations" default="" />
                <th class="text-left py-3 px-4">Resolved by</th>
                <x-admin.sortable-th column="adult_checked_at" label="Adults only" default="" />
                <x-admin.sortable-th column="created_at" label="Added" default="" />
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-700">
            @forelse($games as $game)
                <tr class="hover:bg-gray-750">
                    {{-- The cover, like the translations screen: a title alone makes every row
                         look the same, and this list is read by scanning it. --}}
                    <td class="py-3 px-4">
                        <div class="flex items-center gap-3">
                            @if($game->image_url)
                                <img src="{{ $game->image_url }}" alt="" class="w-10 h-14 object-cover rounded flex-shrink-0">
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
                    {{-- Each id on its own line, and what a store proposes for it right under it:
                         the admin reads what changes where it changes. --}}
                    <td class="py-3 px-4 text-sm whitespace-nowrap">
                        <div class="text-gray-400"><span class="text-gray-500">Steam</span> {{ $game->steam_id ?: '—' }}</div>
                        @include('admin.partials.game-proposals', ['game' => $game, 'field' => 'steam_id'])
                        <div class="text-gray-400 mt-1"><span class="text-gray-500">IGDB</span> {{ $game->igdb_id ?: '—' }}</div>
                        @include('admin.partials.game-proposals', ['game' => $game, 'field' => 'igdb_id'])
                    </td>
                    <td class="py-3 px-4 text-gray-400">{{ $game->translations_count }}</td>
                    <td class="py-3 px-4">
                        <form action="{{ route('admin.games.names', $game->id) }}" method="POST"
                              class="flex flex-wrap gap-2 items-center">
                            @csrf
                            <input type="text" name="unity_name" value="{{ $game->unity_name }}"
                                placeholder="Unity name"
                                class="bg-gray-700 border border-gray-600 rounded px-2 py-1 text-white w-48">
                            <input type="text" name="unity_company" value="{{ $game->unity_company }}"
                                placeholder="Company"
                                class="bg-gray-700 border border-gray-600 rounded px-2 py-1 text-white w-40">
                            <button type="submit"
                                class="bg-gray-700 hover:bg-gray-600 text-white px-3 py-1 rounded">
                                Save
                            </button>
                        </form>
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
                    <td class="py-3 px-4 text-gray-400 text-sm whitespace-nowrap">
                        {{ $game->created_at->format('M d, Y') }}
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="py-8 text-center text-gray-500">No game matches.</td>
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
