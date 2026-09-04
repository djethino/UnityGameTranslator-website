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

<form action="{{ route('admin.games') }}" method="GET"
      class="bg-gray-800 rounded-lg p-4 mb-6 flex flex-wrap gap-4 items-end">
    <div class="flex-1 min-w-[240px]">
        <label class="block text-sm text-gray-400 mb-1">Search</label>
        <input type="text" name="q" value="{{ request('q') }}" placeholder="Title, Unity name or Steam id..."
            class="w-full bg-gray-700 border border-gray-600 rounded px-3 py-2 text-white">
    </div>
    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded">
        <i class="fas fa-search mr-1"></i> Search
    </button>
</form>

<div class="bg-gray-800 rounded-lg overflow-hidden">
    <table class="w-full text-sm">
        <thead class="bg-gray-900 text-gray-400 uppercase text-xs">
            <tr>
                <th class="px-4 py-3 text-left">Game</th>
                <th class="px-4 py-3 text-left">Steam id</th>
                <th class="px-4 py-3 text-left">Translations</th>
                <th class="px-4 py-3 text-left">Resolved by</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-700">
            @forelse($games as $game)
                <tr class="hover:bg-gray-750">
                    <td class="px-4 py-3">
                        <a href="{{ route('games.show', $game->slug) }}" class="text-white hover:text-blue-400">
                            {{ $game->name }}
                        </a>
                    </td>
                    <td class="px-4 py-3 text-gray-400">{{ $game->steam_id ?: '—' }}</td>
                    <td class="px-4 py-3 text-gray-400">{{ $game->translations_count }}</td>
                    <td class="px-4 py-3">
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
                </tr>
            @empty
                <tr>
                    <td colspan="4" class="px-4 py-6 text-center text-gray-500">No game matches.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-6">
    {{ $games->links() }}
</div>
@endsection
