@extends('layouts.app')

@section('title', __('seo.games_title'))

@section('description', __('seo.games_description'))

@push('head')
<script type="application/ld+json">
{
    "@@context": "https://schema.org",
    "@@type": "ItemList",
    "name": {!! json_encode(__('games.browse'), JSON_UNESCAPED_UNICODE) !!},
    "description": {!! json_encode(__('seo.games_description'), JSON_UNESCAPED_UNICODE) !!},
    "numberOfItems": {{ $games->total() }},
    "itemListElement": [
        @foreach($games->take(10) as $index => $game)
        {
            "@@type": "ListItem",
            "position": {{ $index + 1 }},
            "item": {
                "@@type": "VideoGame",
                "name": {!! json_encode($game->name, JSON_UNESCAPED_UNICODE) !!},
                "url": "{{ route('games.show', $game) }}"
                @if($game->image_url)
                ,"image": "{{ $game->image_url }}"
                @endif
            }
        }{{ !$loop->last ? ',' : '' }}
        @endforeach
    ]
}
</script>
@endpush

@section('content')
<div class="mb-8">
    <h1 class="glitch-text text-3xl font-bold mb-6">{{ __('games.browse') }}</h1>

    <form action="{{ route('games.index') }}" method="GET" class="bg-gray-800 rounded-lg p-6 mb-8">
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-400 mb-2">{{ __('games.search_game') }}</label>
                <input type="text" name="q" value="{{ request('q') }}"
                    placeholder="{{ __('games.game_name_placeholder') }}"
                    class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:ring-purple-500 focus:border-purple-500">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-400 mb-2">{{ __('games.target_language') }}</label>
                <select name="target" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white">
                    <option value="">{{ __('games.all') }}</option>
                    @foreach($targetLanguages as $lang)
                        <option value="{{ $lang }}" {{ request('target') == $lang ? 'selected' : '' }}>@langflag($lang) {{ $lang }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-400 mb-2">{{ __('games.source_language') }}</label>
                <select name="source" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white">
                    <option value="">{{ __('games.all') }}</option>
                    @foreach($sourceLanguages as $lang)
                        <option value="{{ $lang }}" {{ request('source') == $lang ? 'selected' : '' }}>@langflag($lang) {{ $lang }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-400 mb-2">{{ __('games.sort_by') }}</label>
                <select name="sort" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white">
                    <option value="name" {{ $sort === 'name' ? 'selected' : '' }}>{{ __('games.sort.name') }}</option>
                    <option value="downloads" {{ $sort === 'downloads' ? 'selected' : '' }}>{{ __('games.sort.downloads') }}</option>
                    <option value="updated" {{ $sort === 'updated' ? 'selected' : '' }}>{{ __('games.sort.updated') }}</option>
                    <option value="new" {{ $sort === 'new' ? 'selected' : '' }}>{{ __('games.sort.new') }}</option>
                    <option value="translations" {{ $sort === 'translations' ? 'selected' : '' }}>{{ __('games.sort.translations') }}</option>
                </select>
            </div>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-4 mt-4">
            {{-- On by default. The value travels in the URL rather than in a cookie: a sort
                 order that is remembered becomes invisible, and a shared link would show
                 something else to whoever opens it. --}}
            @if($highlightLanguage)
                <label class="flex items-center gap-2 text-sm text-gray-300 cursor-pointer">
                    {{-- An unchecked box sends nothing at all, so without this the option could
                         be turned on but never off --}}
                    <input type="hidden" name="lang_first" value="0">
                    <input type="checkbox" name="lang_first" value="1" {{ $languageFirst ? 'checked' : '' }}
                        class="rounded bg-gray-700 border-gray-600 text-purple-600">
                    <span>{{ __('games.sort.language_first', ['language' => $highlightLanguage]) }}</span>
                </label>
            @else
                <span></span>
            @endif

            <button type="submit" class="bg-purple-600 hover:bg-purple-700 text-white px-6 py-2 rounded-lg">
                <i class="fas fa-search mr-2"></i> {{ __('common.search') }}
            </button>
        </div>
    </form>

    @if($games->isEmpty())
        <div class="text-center py-12 text-gray-400">
            <i class="fas fa-gamepad text-6xl mb-4"></i>
            <p class="text-xl">{{ __('home.no_games') }}</p>
            @auth
                <a href="{{ route('translations.create') }}" class="inline-block mt-4 bg-purple-600 hover:bg-purple-700 text-white px-6 py-3 rounded-lg">
                    <i class="fas fa-upload mr-2"></i> {{ __('games.upload_first') }}
                </a>
            @endauth
        </div>
    @else
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6 gap-4">
            @foreach($games as $game)
                <a href="{{ route('games.show', $game) }}" class="game-card bg-gray-800 rounded-lg overflow-hidden border border-gray-700 group">
                    <div class="aspect-[3/4] bg-gray-700 relative">
                        @if($game->image_url)
                            <img src="{{ $game->image_url }}" alt="{{ $game->name }}" class="w-full h-full object-cover">
                        @else
                            <div class="w-full h-full flex items-center justify-center">
                                <i class="fas fa-gamepad text-4xl text-gray-400"></i>
                            </div>
                        @endif
                        <!-- Badges top -->
                        <div class="absolute top-2 left-2 right-2 flex justify-between items-start">
                            <div class="flex flex-col gap-1">
                                @if($game->created_at >= now()->subDays(7))
                                    <span class="text-xs bg-green-600 text-white px-1.5 py-0.5 rounded font-medium shadow">{{ __('games.new') }}</span>
                                @endif
                                @if(($game->translations_sum_download_count ?? 0) >= 100)
                                    <span class="text-xs bg-orange-600 text-white px-1.5 py-0.5 rounded font-medium shadow"><i class="fas fa-fire text-[10px]"></i> {{ __('games.popular') }}</span>
                                @endif
                            </div>
                        </div>
                        <!-- Overlay gradient bottom -->
                        @php
                            // Target languages this game is available in. Deduplicated upstream:
                            // five French translations of the same game are one answer to
                            // "is it in my language?", not five.
                            $gameLanguages = $languagesByGame[$game->id] ?? collect();

                            // The visitor's language leads, and is never the one cut off by the
                            // "+N": on a card showing eight flags, finding your own should not
                            // take a scan — and it is the whole reason this card is being read.
                            if ($highlightLanguage && $gameLanguages->contains($highlightLanguage)) {
                                $gameLanguages = $gameLanguages
                                    ->reject(fn ($l) => $l === $highlightLanguage)
                                    ->prepend($highlightLanguage)
                                    ->values();
                            }

                            // Flags only: a popular game can carry dozens of languages, and their
                            // names would fill the card several times over. The name stays one
                            // hover away, and the whole list is on the game's own page.
                            $shownLanguages = $gameLanguages->take(8);
                            $extraLanguages = $gameLanguages->count() - $shownLanguages->count();
                        @endphp
                        <div class="absolute bottom-0 left-0 right-0 bg-gradient-to-t from-black/80 to-transparent p-2">
                            @if($shownLanguages->isNotEmpty())
                                {{-- Above the counters: which languages exist decides whether the
                                     rest is worth reading at all --}}
                                <div class="flex flex-wrap gap-1 mb-1.5">
                                    @foreach($shownLanguages as $language)
                                        <span class="text-[11px] px-1 py-0.5 rounded leading-none {{ $language === $highlightLanguage ? 'bg-purple-600 ring-1 ring-purple-300' : 'bg-black/60' }}"
                                            title="{{ $language }}">@langflag($language)</span>
                                    @endforeach
                                    @if($extraLanguages > 0)
                                        <span class="text-[10px] bg-black/60 text-gray-300 px-1.5 py-0.5 rounded leading-none"
                                            title="{{ $gameLanguages->implode(', ') }}">+{{ $extraLanguages }}</span>
                                    @endif
                                </div>
                            @endif
                            <div class="flex justify-between items-end">
                                <span class="text-xs bg-purple-600 px-2 py-0.5 rounded" title="{{ trans_choice('home.translations_count', $game->translations_count, ['count' => $game->translations_count]) }}">
                                    <i class="fas fa-language text-[10px] mr-0.5"></i> {{ $game->translations_count }}
                                </span>
                                @if(($game->translations_sum_download_count ?? 0) > 0)
                                    <span class="text-xs text-gray-300" title="{{ __('my_translations.downloads') }}">
                                        <i class="fas fa-download text-[10px]"></i>
                                        <span data-counter="{{ $game->translations_sum_download_count }}">{{ number_format($game->translations_sum_download_count) }}</span>
                                    </span>
                                @endif
                            </div>
                        </div>
                    </div>
                    <div class="p-3">
                        <h2 class="font-semibold text-sm truncate group-hover:text-purple-400 transition">{{ $game->name }}</h2>
                    </div>
                </a>
            @endforeach
        </div>

        <div class="mt-8">
            {{ $games->withQueryString()->links() }}
        </div>
    @endif
</div>
@endsection
