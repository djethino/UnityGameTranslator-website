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

    {{-- One line, like the controls on a game's own page.

         Stacked labelled groups cost roughly 200px of height — on a 1080p screen that is most
         of the first row of games, on a page whose entire job is to show games. The grouping is
         carried by a separator and by placement instead: search and its button together, the
         two language filters next, then what only reorders.

         Labels are dropped in favour of aria-label and placeholders: a select showing "French"
         does not need "Target language" written above it, and screen readers still get the name. --}}
    <form action="{{ route('games.index') }}" method="GET"
        class="bg-gray-800 rounded-lg p-4 mb-6 flex flex-wrap items-center gap-3"
        data-auto-submit>

        {{-- The button submits THIS field and nothing else, so it sits against it.
             min-w-48 and not 64: at sixteen rem the field refused to give way and pushed the sort
             group onto a second row, then grew to fill the gap it had just made. Twelve rem still
             holds a game name, and the whole bar fits on one line. --}}
        <div class="flex min-w-[12rem] flex-1">
            <input type="text" name="q" value="{{ request('q') }}"
                placeholder="{{ __('games.game_name_placeholder') }}"
                aria-label="{{ __('games.search_game') }}"
                data-no-auto-submit
                class="w-full bg-gray-700 border border-gray-600 rounded-l-lg px-3 py-2 text-white focus:ring-purple-500 focus:border-purple-500">
            <button type="submit" class="bg-purple-600 hover:bg-purple-700 text-white px-4 rounded-r-lg"
                aria-label="{{ __('common.search') }}">
                <i class="fas fa-search"></i>
            </button>
        </div>

        {{-- 🔴 **The two language filters carry their flags, like every other language on the
             site.** They were plain `<select>`s, and an `<option>` accepts text and nothing else —
             no SVG, no markup. That is exactly why <x-language-select> exists; it was already used
             on the profile and in the title bar, and these two were simply never moved over.

             ⚠ They keep submitting on their own: the picker fires a `change` on its hidden field,
             which is what `data-auto-submit` above listens for. Without that it would look right
             and filter nothing.

             ⚠ The flag is named from the VALUE, never from the label: these labels are native
             spellings ("Français") and the mark resolves a flag from the catalogue name behind
             them ("French"). Left to guess from the label it would draw nothing, quietly. --}}
        @php
            $flagOf = fn ($langs) => collect($langs)
                ->mapWithKeys(fn ($lang) => [$lang => \App\Services\CatalogStore::languageMark($lang)['flag']])
                ->all();
        @endphp
        <div class="min-w-[11rem]">
            <x-language-select
                name="target"
                :choices="collect($targetLanguages)->mapWithKeys(fn ($lang) => [$lang => $languageNames[$lang] ?? $lang])->all()"
                :selected="request('target')"
                :flags="$flagOf($targetLanguages)"
                :marks="false"
                :empty="__('games.target_language') . ': ' . __('games.all')" />
        </div>

        <div class="min-w-[11rem]">
            <x-language-select
                name="source"
                :choices="collect($sourceLanguages)->mapWithKeys(fn ($lang) => [$lang => $languageNames[$lang] ?? $lang])->all()"
                :selected="request('source')"
                :flags="$flagOf($sourceLanguages)"
                :marks="false"
                :empty="__('games.source_language') . ': ' . __('games.all')" />
        </div>

        {{-- Games somebody has declared finished. A filter and not a sort, because it answers yes
             or no — but a QUIET one, and never on by default.

             It hides most of the catalogue for a reason that is weaker than it looks: "finished"
             is a declaration, and a translation still under way at ninety percent is a game you
             can play through. Nobody knows that share — the total text of a game is unknowable —
             so the filter cannot be presented as the difference between playable and not. Small,
             last, and explained on hover. --}}
        <label class="flex items-center gap-1.5 text-xs text-gray-500 hover:text-gray-300 transition cursor-pointer whitespace-nowrap"
            title="{{ __('games.filter.completed_hint') }}">
            {{-- An unchecked box sends nothing at all, so without this the filter could be
                 turned on but never off --}}
            <input type="hidden" name="completed" value="0">
            <input type="checkbox" name="completed" value="1" {{ $completedOnly ? 'checked' : '' }}
                class="rounded bg-gray-700 border-gray-600 text-gray-500">
            <span>{{ __('games.filter.completed') }}</span>
        </label>

        {{-- What follows only reorders — the border says so without a heading --}}
        <div class="flex flex-wrap items-center gap-3 md:border-l md:border-gray-700 md:pl-3">
            <select name="sort" aria-label="{{ __('games.sort_by') }}"
                class="bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white">
                <option value="name" {{ $sort === 'name' ? 'selected' : '' }}>{{ __('games.sort.name') }}</option>
                <option value="downloads" {{ $sort === 'downloads' ? 'selected' : '' }}>{{ __('games.sort.downloads') }}</option>
                <option value="updated" {{ $sort === 'updated' ? 'selected' : '' }}>{{ __('games.sort.updated') }}</option>
                <option value="new" {{ $sort === 'new' ? 'selected' : '' }}>{{ __('games.sort.new') }}</option>
                <option value="translations" {{ $sort === 'translations' ? 'selected' : '' }}>{{ __('games.sort.translations') }}</option>
                <option value="finished" {{ $sort === 'finished' ? 'selected' : '' }}>{{ __('games.sort.finished') }}</option>
            </select>

            @if($highlightLanguage)
                <label class="flex items-center gap-2 text-sm text-gray-300 cursor-pointer whitespace-nowrap">
                    {{-- An unchecked box sends nothing at all, so without this the option could
                         be turned on but never off --}}
                    <input type="hidden" name="lang_first" value="0">
                    <input type="checkbox" name="lang_first" value="1" {{ $languageFirst ? 'checked' : '' }}
                        class="rounded bg-gray-700 border-gray-600 text-purple-600">
                    <span>{{ __('games.sort.language_first', ['language' => $languageNames[$highlightLanguage] ?? $highlightLanguage]) }}</span>
                </label>
            @endif
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
                                        <x-language-flag :language="$language"
                                            :state="$languageStates[$game->id][$language] ?? 'progress'"
                                            :name="$languageNames[$language] ?? $language"
                                            :highlight="$language === $highlightLanguage" />
                                    @endforeach
                                    @if($extraLanguages > 0)
                                        <span class="text-[10px] bg-black/60 text-gray-300 px-1.5 py-0.5 rounded leading-none"
                                            title="{{ $gameLanguages->map(fn ($l) => $languageNames[$l] ?? $l)->implode(', ') }}">+{{ $extraLanguages }}</span>
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
