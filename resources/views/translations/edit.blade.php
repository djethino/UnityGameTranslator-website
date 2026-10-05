@extends('layouts.app')

{{-- No ambient glitch here: the translation settings form shows real data, or takes it in.
     See the note on data-no-glitch in layouts/app.blade.php. --}}
@section('quiet-screen', true)

@section('title', __('my_translations.edit_title') . ' - UnityGameTranslator')

@section('content')
<div class="max-w-2xl mx-auto">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-3xl font-bold"><i class="fas fa-edit mr-2"></i> {{ __('my_translations.edit_title') }}</h1>
        @if($fromAdmin ?? false)
            <a href="{{ route('admin.translations.show', $translation) }}" class="text-gray-400 hover:text-white">
                <i class="fas fa-arrow-left mr-1"></i> {{ __('common.back') }}
            </a>
        @else
            <a href="{{ route('translations.mine') }}" class="text-gray-400 hover:text-white">
                <i class="fas fa-arrow-left mr-1"></i> {{ __('my_translations.back_to_mine') }}
            </a>
        @endif
    </div>

    <!-- Translation Info -->
    <div class="bg-gray-800 rounded-lg p-4 mb-6 border border-gray-700">
        <div class="flex items-center gap-4">
            @if($translation->game->image_url)
                <x-game-cover :src="$translation->game->image_url" class="w-16 h-20 rounded" />
            @endif
            <div>
                <p class="font-semibold text-lg">{{ $translation->game->name }}</p>
                <p class="text-sm text-gray-400">
                    {{ __('translation.published_on', ['date' => $translation->created_at->isoFormat('LL')]) }}
                </p>
                <p class="text-sm text-gray-500">
                    {{ trans_choice('my_translations.lines_count', $translation->line_count, ['count' => number_format($translation->line_count)]) }} &bull; {{ trans_choice('my_translations.downloads_count', $translation->download_count, ['count' => number_format($translation->download_count)]) }}
                </p>
            </div>
        </div>

        {{-- Changing the game: in this card, under the game's name, because that is where the eye
             goes to check "is this the right game?". An act of its own (its own form and route,
             App\Services\LineageGame), never a field of the settings form below. Drawn only where it
             can act: a branch is filed with its Main and gets nothing; a fork only follows the game
             of its original, and only when that differs. --}}
        @php($gameRoute = ($fromAdmin ?? false) ? route('admin.translations.game', $translation) : route('translations.game', $translation))

        {{-- The move asked contradicts a Steam app this translation's own uploads read on disk
             (LineageGame::contradictedRead). Warned, not refused: an edition filed with another, a
             wrong steam_appid.txt are honest. The fact, then the one act — the same move,
             confirmed. --}}
        @if($contradiction = session('game_contradiction'))
            <form action="{{ $gameRoute }}" method="POST" class="mt-4 bg-amber-900/30 border border-amber-700 rounded-lg px-4 py-3 flex flex-wrap items-center gap-3">
                @csrf
                <input type="hidden" name="confirmed" value="1">
                @if($contradiction['align'])
                    <input type="hidden" name="align" value="1">
                @else
                    <input type="hidden" name="game_name" value="{{ $contradiction['game_name'] }}">
                    <input type="hidden" name="game_pick[source]" value="{{ $contradiction['pick']['source'] ?? '' }}">
                    <input type="hidden" name="game_pick[id]" value="{{ $contradiction['pick']['id'] ?? '' }}">
                @endif
                <p class="flex-1 min-w-0 text-sm text-amber-200">
                    <i class="fas fa-exclamation-triangle mr-1"></i>
                    {{ __('my_translations.game_contradiction', ['read' => $contradiction['read'], 'game' => $contradiction['game']]) }}
                </p>
                <button type="submit" class="text-sm bg-amber-700 hover:bg-amber-600 text-white font-semibold px-3 py-1.5 rounded-lg transition">
                    {{ __('my_translations.move_anyway') }}
                </button>
            </form>
        @endif
        @if($mayChangeGame === \App\Services\LineageGame::AnyGame)
            <form action="{{ $gameRoute }}" method="POST" class="mt-4 pt-4 border-t border-gray-700">
                @csrf
                <div class="relative">
                    <input type="text" id="change_game_search" autocomplete="off"
                        placeholder="{{ __('upload.search_game') }}"
                        class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-3 text-white focus:ring-purple-500 focus:border-purple-500 pl-12">
                    <i class="fas fa-search absolute left-4 top-1/2 -translate-y-1/2 text-gray-400"></i>
                    <i id="change_game_loading" class="fas fa-spinner fa-spin absolute right-4 top-1/2 -translate-y-1/2 text-gray-400 hidden"></i>
                    <div id="change_game_list" class="absolute w-full bg-gray-700 border border-gray-600 rounded-lg mt-1 hidden z-10 max-h-80 overflow-y-auto shadow-xl"></div>
                </div>
                {{-- The game picked, with what tells it apart and its store pages: what the button
                     below will move the translation to. --}}
                <div id="change_game_chosen" class="mt-2 hidden"></div>
                <input type="hidden" name="game_name" id="change_game_name" value="">
                <input type="hidden" name="game_pick[source]" id="change_game_source" value="">
                <input type="hidden" name="game_pick[id]" id="change_game_id" value="">
                <p class="text-xs text-gray-500 mt-1">{{ __('my_translations.change_game_hint') }}</p>
                <button type="submit" id="change_game_submit" disabled
                    class="mt-3 w-full bg-gray-600 hover:bg-gray-500 text-white font-semibold py-3 rounded-lg transition disabled:opacity-50 disabled:cursor-not-allowed">
                    <i class="fas fa-exchange-alt mr-2"></i> <span id="change_game_label">{{ __('my_translations.change_game') }}</span>
                </button>
            </form>
        @elseif($mayChangeGame === \App\Services\LineageGame::OriginalsGame && $originalsGame && $originalsGame->id !== $translation->game_id)
            <form action="{{ $gameRoute }}" method="POST" class="mt-4 pt-4 border-t border-gray-700">
                @csrf
                <input type="hidden" name="align" value="1">
                <p class="text-xs text-gray-500">{{ __('my_translations.originals_game_hint', ['game' => $originalsGame->name]) }}</p>
                <button type="submit"
                    class="mt-3 w-full bg-gray-600 hover:bg-gray-500 text-white font-semibold py-3 rounded-lg transition">
                    <i class="fas fa-exchange-alt mr-2"></i> {{ __('my_translations.use_originals_game', ['game' => $originalsGame->name]) }}
                </button>
            </form>
        @endif
    </div>

    @if($errors->any())
        <div class="bg-red-900 border border-red-700 text-red-100 px-4 py-3 rounded mb-6">
            <ul class="list-disc list-inside">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form action="{{ ($fromAdmin ?? false) ? route('admin.translations.update', $translation) : route('translations.update', $translation) }}" method="POST" class="bg-gray-800 rounded-lg p-6 border border-gray-700">
        @csrf
        @method('PUT')

        <!-- Languages (read-only, set at upload time) -->
        <div class="grid grid-cols-2 gap-4 mb-6">
            <div>
                <label class="block text-sm font-medium text-gray-300 mb-2">{{ __('upload.source_language') }}</label>
                <div class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-3 text-white opacity-75">
                    @langflag($translation->source_language) {{ $translation->source_language }}
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-300 mb-2">{{ __('upload.target_language') }}</label>
                <div class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-3 text-white opacity-75">
                    @langflag($translation->target_language) {{ $translation->target_language }}
                </div>
            </div>
        </div>

        {{-- Composition (read-only, computed from the file).

             Three cards over H+V+A used to stand here, each showing a share of a total that left
             out everything captured and everything kept as is: an author whose file is mostly
             captured read "Human 100%" on the very screen where they publish it, while their own
             game page said 15%. The shared bar says the same thing as every other screen, and its
             key names the bands that exist. --}}
        <div class="mb-6">
            <label class="block text-sm font-medium text-gray-300 mb-2">{{ __('upload.translation_composition') }}</label>
            <x-progress-bar :translation="$translation" />
            <x-quality-legend :translation="$translation" />
            <p class="text-xs text-gray-500 mt-2 text-center">{{ __('upload.composition_auto') }}</p>
        </div>

        <!-- Status (only for Main translations - branches inherit from Main) -->
        @if($translation->visibility !== 'branch')
        <div class="mb-6">
            <label class="block text-sm font-medium text-gray-300 mb-2">{{ __('upload.status') }}</label>
            <div class="flex gap-4">
                <label class="flex items-center cursor-pointer">
                    <input type="radio" name="status" value="in_progress" {{ old('status', $translation->status) == 'in_progress' ? 'checked' : '' }} class="mr-2 text-purple-600">
                    <span><i class="fas fa-clock text-yellow-400 mr-1"></i> {{ __('translation.in_progress') }}</span>
                </label>
                <label class="flex items-center cursor-pointer">
                    <input type="radio" name="status" value="complete" {{ old('status', $translation->status) == 'complete' ? 'checked' : '' }} class="mr-2 text-purple-600">
                    <span><i class="fas fa-check text-green-400 mr-1"></i> {{ __('translation.complete') }}</span>
                </label>
            </div>
        </div>

        {{-- Contributions: the Main's own decision, beside the other one only they can take.
             ⚠ Off by default when nobody has said otherwise — keeping a translation open is work
             nobody agreed to by publishing. The reminder says what a branch IS, in one line: the
             word means nothing to somebody publishing their first translation. --}}
        <div class="mb-6">
            <label class="block text-sm font-medium text-gray-300 mb-2">{{ __('upload.contributions') }}</label>
            <label class="flex items-start cursor-pointer gap-2">
                <input type="checkbox" name="accepts_branches" value="1"
                       {{ old('accepts_branches', $translation->accepts_branches) ? 'checked' : '' }}
                       class="mt-1 text-purple-600">
                <span class="text-sm text-gray-300">
                    {{ __('upload.accepts_branches') }}
                    <span class="block text-xs text-gray-500 mt-1">{{ __('upload.accepts_branches_hint') }}</span>
                </span>
            </label>
        </div>
        @else
        <div class="mb-6">
            <label class="block text-sm font-medium text-gray-300 mb-2">{{ __('upload.status') }}</label>
            <div class="bg-gray-700 rounded-lg px-4 py-3 text-gray-400">
                <i class="fas fa-lock mr-2"></i>
                @if($translation->status == 'complete')
                    <i class="fas fa-check text-green-400 mr-1"></i> {{ __('translation.complete') }}
                @else
                    <i class="fas fa-clock text-yellow-400 mr-1"></i> {{ __('translation.in_progress') }}
                @endif
                <span class="text-xs ml-2">({{ __('upload.inherited_from_main') }})</span>
            </div>
        </div>
        @endif

        <!-- Notes -->
        <div class="mb-6">
            <label class="block text-sm font-medium text-gray-300 mb-2">{{ __('upload.notes') }}</label>
            <textarea name="notes" rows="3" maxlength="1000"
                placeholder="{{ __('upload.notes_placeholder') }}"
                class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-3 text-white focus:ring-purple-500 focus:border-purple-500">{{ old('notes', $translation->notes) }}</textarea>
        </div>

        <!-- Resources URL -->
        <div class="mb-6">
            <label class="block text-sm font-medium text-gray-300 mb-2">{{ __('upload.resources_url') }}</label>
            <input type="url" name="resources_url" maxlength="2048"
                value="{{ old('resources_url', $translation->resources_url) }}"
                placeholder="{{ __('upload.resources_url_placeholder') }}"
                class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-3 text-white focus:ring-purple-500 focus:border-purple-500">
            <p class="text-xs text-gray-500 mt-1">{{ __('upload.resources_url_hint') }}</p>
        </div>

        <div class="flex gap-4">
            <a href="{{ ($fromAdmin ?? false) ? route('admin.translations.show', $translation) : route('translations.mine') }}" class="flex-1 bg-gray-600 hover:bg-gray-500 text-white font-semibold py-3 rounded-lg transition text-center">
                {{ __('common.cancel') }}
            </a>
            <button type="submit" class="flex-1 bg-purple-600 hover:bg-purple-700 text-white font-semibold py-3 rounded-lg transition">
                <i class="fas fa-save mr-2"></i> {{ __('common.save') }}
            </button>
        </div>
    </form>
</div>

@if($mayChangeGame === \App\Services\LineageGame::AnyGame)
<script nonce="{{ $cspNonce }}">
// window.UGT is set by the bundled app.js, a deferred module: it exists once Alpine starts.
document.addEventListener('alpine:init', () => {
    const name = document.getElementById('change_game_name');
    const source = document.getElementById('change_game_source');
    const id = document.getElementById('change_game_id');
    const submit = document.getElementById('change_game_submit');
    const label = document.getElementById('change_game_label');
    const idle = label.textContent;
    // The button names where the translation goes: "Move to <game>" once a game is picked.
    const moveTo = @js(__('my_translations.use_originals_game'));

    window.UGT.attachGamePicker({
        input: document.getElementById('change_game_search'),
        list: document.getElementById('change_game_list'),
        loading: document.getElementById('change_game_loading'),
        chosenBox: document.getElementById('change_game_chosen'),
        emptyText: @js(__('upload.no_game_found')),
        // The game the translation is filed under: shown in the list, never offered.
        current: { source: 'local', id: @js((string) $translation->game_id) },
        currentText: @js(__('my_translations.current_game')),
        // Typing again withdraws the pick: what is sent is always a hit, never a typed title.
        onType: () => {
            name.value = source.value = id.value = '';
            submit.disabled = true;
            label.textContent = idle;
        },
        onPick: (hit) => {
            const pick = window.UGT.pickOf(hit);
            document.getElementById('change_game_search').value = hit.name;
            name.value = hit.name;
            source.value = pick.source;
            id.value = pick.id;
            submit.disabled = pick.source === '' || pick.id === '';
            label.textContent = moveTo.replace(':game', hit.name);
        },
    });
});
</script>
@endif

@endsection
