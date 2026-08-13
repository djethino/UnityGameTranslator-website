@extends('layouts.app')

@section('title', 'UnityGameTranslator - ' . __('home.hero_title'))

@push('head')
<script type="application/ld+json">
{
    "@@context": "https://schema.org",
    "@@type": "WebSite",
    "name": "UnityGameTranslator",
    "url": "{{ url('/') }}",
    {{-- Its own sentence: the visible pitch is now four short lines, and a search engine needs
         one complete statement rather than a fragment of a layout. --}}
    "description": "{{ __('seo.home_description') }}",
    "inLanguage": "{{ app()->getLocale() }}",
    "potentialAction": {
        "@@type": "SearchAction",
        "target": "{{ route('games.index') }}?q={search_term_string}",
        "query-input": "required name=search_term_string"
    }
}
</script>
<script type="application/ld+json">
{
    "@@context": "https://schema.org",
    "@@type": "Organization",
    "name": "UnityGameTranslator",
    "url": "{{ url('/') }}",
    "logo": "{{ asset('logo.svg') }}",
    "sameAs": [
        "https://github.com/djethino/UnityGameTranslator"
    ]
}
</script>
@endpush

@section('content')
    <!-- Beta Banner -->
    <div class="bg-purple-900/50 border border-purple-600 text-purple-200 px-4 py-3 rounded-lg mb-6 flex items-center justify-center">
        <span class="mr-2">🚀</span>
        <span>{{ __('home.beta_banner') }}</span>
    </div>

    <!-- Hero Section -->
    <div class="text-center py-12 mb-12 relative">
        <!-- Gradient Background -->
        <div class="absolute inset-0 bg-gradient-to-b from-purple-900/20 via-transparent to-transparent rounded-3xl -z-10"></div>
        <h1 class="glitch-text text-4xl md:text-5xl font-bold text-white mb-4">
            <i class="fas fa-language text-purple-400 mr-3"></i>{{ __('home.hero_title') }}
        </h1>
        {{-- Two beats, and they are not interchangeable.

             The first is passive and it is what makes people stay: the game changes language on
             its own. The second is where the reader enters — correcting a line, and deciding
             whether it goes any further. Half the product (the editors, the review tags, the
             community) used to be missing from the first thing anyone read.

             What this replaces was a single 45-word sentence in which "free" sat inside a
             subordinate clause and "with your own key" — the only mention of a cost — occupied
             the last three words, which is the position nobody reads. --}}
        <p class="text-xl md:text-2xl text-white font-medium max-w-3xl mx-auto mb-3">
            {{ __('home.hero_lead') }}
        </p>
        <p class="text-base md:text-lg text-gray-300 max-w-2xl mx-auto mb-8">
            {{ __('home.hero_lead_secondary') }}
        </p>

        {{-- Where a translation comes from, and what it costs.

             Ordered 2 + 2: the first two ask for nothing beyond the mod, the last two need
             something plugged in. It also puts the handwritten path right after the community
             one — the place it actually holds in this project, rather than last after the
             machines.

             The price teaches itself by ALIGNMENT: three lines open on "free", the fourth on
             "your key, their price", all starting at the same x. No warning box, no colour
             coding — the contrast does the work. --}}
        @php
            $sources = [
                ['home.source_community', 'home.source_community_detail', null, null],
                ['home.source_manual', 'home.source_manual_detail', 'home.link_editors', '#editing'],
                ['home.source_local', 'home.source_local_detail', 'home.link_install_engine', '#configuration'],
                // Both land on the settings panel, not on the JSON block that used to greet
                // whoever clicked "connect your key" — an answer for someone who already knows,
                // offered to someone who has just decided to try.
                ['home.source_online', 'home.source_online_detail', 'home.link_connect_key', '#configuration'],
            ];
        @endphp
        {{-- The label column is CAPPED, not sized by its longest entry.
             Measured: the longest label runs to 33 characters in French, 43 in Polish and 52 in
             Turkish. Sized on its content, it would take half the width in those languages and
             push the detail — the part that carries the price — onto four lines. Capped, a long
             label wraps within its own column instead, and the alignment that teaches the price
             without a word of explanation survives all nineteen. --}}
        <div class="max-w-3xl mx-auto mb-8 text-left grid gap-x-6 gap-y-3 sm:grid-cols-[minmax(0,15rem)_1fr]">
            @foreach($sources as [$label, $detail, $linkLabel, $anchor])
                <div class="text-white font-medium">{{ __($label) }}</div>
                <div class="text-sm text-gray-400 sm:pt-0.5">
                    {{ __($detail) }}@if($linkLabel)
                        <a href="{{ route('docs') }}{{ $anchor }}" class="text-purple-400 hover:text-purple-300 whitespace-nowrap">&rarr;&nbsp;{{ __($linkLabel) }}</a>
                    @endif
                </div>
            @endforeach
        </div>

        {{-- One primary action, one secondary, one link. Three buttons of equal weight are no
             hierarchy at all, and the download lost its force among them. --}}
        <div class="flex flex-wrap justify-center items-center gap-4">
            <a href="https://github.com/djethino/UnityGameTranslator/releases/latest" target="_blank" rel="noopener" class="bg-purple-600 hover:bg-purple-700 text-white font-semibold px-6 py-3 rounded-lg transition-all duration-200 hover:-translate-y-0.5 flex items-center">
                <i class="fas fa-download mr-2"></i>
                {{ __('home.download_mod') }}
            </a>
            <a href="{{ route('games.index') }}" class="bg-gray-700 hover:bg-gray-600 text-white font-semibold px-6 py-3 rounded-lg transition-all duration-200 hover:-translate-y-0.5 flex items-center">
                <i class="fas fa-gamepad mr-2"></i>
                {{ __('home.view_games') }}
            </a>
            <a href="{{ route('docs') }}" class="text-gray-300 hover:text-white underline underline-offset-4 decoration-gray-600 hover:decoration-gray-300 transition">
                {{ __('home.read_docs') }}
            </a>
        </div>

        {{-- The prerequisite sits where the decision to act is made: it does not slow the
             reading of the pitch, and it surprises nobody at install time. --}}
        <p class="text-sm text-gray-500 mt-4">
            {{ __('home.requires_loader') }} —
            <a href="{{ route('docs') }}#installation" class="text-gray-400 hover:text-gray-200 underline underline-offset-2">{{ __('home.link_install_guide') }}</a>
        </p>
    </div>

    <!-- Search Bar -->
    <div class="bg-gray-800 rounded-lg p-6 border border-gray-700 mb-12">
        <form action="{{ route('games.index') }}" method="GET" class="flex flex-col sm:flex-row gap-4">
            <input type="text" name="q" placeholder="{{ __('home.search_games') }}"
                   class="flex-1 bg-gray-700 border border-gray-600 rounded-lg px-4 py-3 text-white placeholder-gray-400 focus:outline-none focus:border-purple-500">
            <button type="submit" class="bg-purple-600 hover:bg-purple-700 text-white font-semibold px-6 py-3 rounded-lg transition">
                <i class="fas fa-search mr-2"></i>
                {{ __('home.search_redirect') }}
            </button>
        </form>
    </div>

    <!-- How it works - 3 columns -->
    <div class="mb-12">
        <h2 class="glitch-text text-2xl font-bold text-white text-center mb-8">
            <i class="fas fa-cogs text-purple-400 mr-2"></i>
            {{ __('home.how_it_works') }}
        </h2>
        <div class="grid md:grid-cols-3 gap-6">
            <!-- The Mod -->
            <div class="bg-gray-800 rounded-lg p-6 border border-gray-700 flex flex-col">
                <div class="text-center mb-4">
                    <div class="inline-flex items-center justify-center w-12 h-12 rounded-full bg-purple-600/20 mb-3">
                        <i class="fas fa-puzzle-piece text-purple-400 text-xl"></i>
                    </div>
                    <h3 class="text-xl font-bold text-white">{{ __('home.the_mod') }}</h3>
                    <p class="text-sm text-purple-400">{{ __('home.mod_subtitle') }}</p>
                </div>
                <ul class="space-y-2 text-gray-400 text-sm flex-grow">
                    <li class="flex items-start">
                        <i class="fas fa-check text-green-400 mr-2 mt-0.5"></i>
                        <span>{{ __('home.mod_feature_overlay') }}</span>
                    </li>
                    <li class="flex items-start">
                        <i class="fas fa-check text-green-400 mr-2 mt-0.5"></i>
                        <span>{{ __('home.mod_feature_ai') }}</span>
                    </li>
                    <li class="flex items-start">
                        <i class="fas fa-check text-green-400 mr-2 mt-0.5"></i>
                        <span>{{ __('home.mod_feature_detect') }}</span>
                    </li>
                    <li class="flex items-start">
                        <i class="fas fa-check text-green-400 mr-2 mt-0.5"></i>
                        <span>{{ __('home.mod_feature_platform') }}</span>
                    </li>
                    <li class="flex items-start">
                        <i class="fas fa-check text-green-400 mr-2 mt-0.5"></i>
                        <span>{{ __('home.mod_feature_privacy') }}</span>
                    </li>
                    <li class="flex items-start">
                        <i class="fas fa-check text-green-400 mr-2 mt-0.5"></i>
                        <span>{{ __('home.mod_feature_login') }}</span>
                    </li>
                    <li class="flex items-start">
                        <i class="fas fa-check text-green-400 mr-2 mt-0.5"></i>
                        <span>{{ __('home.mod_feature_numbers') }}</span>
                    </li>
                </ul>
                <!-- Screenshot -->
                <div class="mt-4 rounded-lg overflow-hidden border border-gray-700">
                    <picture>
                        <source srcset="{{ asset('images/screenshots/ModWizard1.webp') }}" type="image/webp">
                        <img src="{{ asset('images/screenshots/ModWizard1.png') }}"
                             alt="{{ __('home.mod_screenshot_alt') }}"
                             class="w-full h-auto cursor-zoom-in"
                             width="551" height="434"
                             loading="lazy"
                             data-zoomable>
                    </picture>
                </div>
            </div>

            <!-- Community (center) -->
            <div class="bg-gray-800 rounded-lg p-6 border border-purple-500/50 flex flex-col">
                <div class="text-center mb-4">
                    <div class="inline-flex items-center justify-center w-12 h-12 rounded-full bg-purple-600/20 mb-3">
                        <i class="fas fa-users text-purple-400 text-xl"></i>
                    </div>
                    <h3 class="text-xl font-bold text-white">{{ __('home.community_title') }}</h3>
                    <p class="text-sm text-purple-400">{{ __('home.community_subtitle') }}</p>
                </div>
                <ul class="space-y-2 text-gray-400 text-sm flex-grow">
                    <li class="flex items-start">
                        <i class="fas fa-download text-blue-400 mr-2 mt-0.5"></i>
                        <span>{{ __('home.community_download') }}</span>
                    </li>
                    <li class="flex items-start">
                        <i class="fas fa-upload text-green-400 mr-2 mt-0.5"></i>
                        <span>{{ __('home.community_upload') }}</span>
                    </li>
                    <li class="flex items-start">
                        <i class="fas fa-sync text-cyan-400 mr-2 mt-0.5"></i>
                        <span>{{ __('home.community_sync') }}</span>
                    </li>
                    <li class="flex items-start">
                        <i class="fas fa-code-branch text-yellow-400 mr-2 mt-0.5"></i>
                        <span>{{ __('home.community_merge') }}</span>
                    </li>
                    <li class="flex items-start">
                        <i class="fas fa-code-fork text-orange-400 mr-2 mt-0.5"></i>
                        <span>{{ __('home.community_branch') }}</span>
                    </li>
                    <li class="flex items-start">
                        <i class="fas fa-bell text-purple-400 mr-2 mt-0.5"></i>
                        <span>{{ __('home.community_notify') }}</span>
                    </li>
                    <li class="flex items-start">
                        <i class="fab fa-github text-gray-400 mr-2 mt-0.5"></i>
                        <span>{{ __('home.community_opensource') }}</span>
                    </li>
                    <li class="flex items-start">
                        <a href="https://github.com/djethino/UnityGameTranslator/discussions?discussions_q=" target="_blank" class="flex items-start text-purple-400 hover:text-purple-300 transition">
                            <i class="fas fa-comments mr-2 mt-0.5"></i>
                            <span>{{ __('home.community_discussions') }}</span>
                        </a>
                    </li>
                </ul>
                <!-- Quality Tags Section -->
                <div class="mt-4 p-4 bg-gray-900 rounded-lg border border-gray-700">
                    <h4 class="text-sm font-semibold text-white mb-3 flex items-center">
                        <i class="fas fa-certificate text-yellow-400 mr-2"></i>
                        {{ __('home.quality_title') }}
                    </h4>
                    <div class="flex justify-center gap-2 mb-3">
                        <span class="px-2 py-1 rounded text-xs font-bold bg-green-600 text-white" title="Human">H</span>
                        <span class="px-2 py-1 rounded text-xs font-bold bg-blue-600 text-white" title="Validated">V</span>
                        <span class="px-2 py-1 rounded text-xs font-bold bg-orange-600 text-white" title="AI">A</span>
                        <span class="px-2 py-1 rounded text-xs font-bold bg-gray-600 text-white" title="Skip">S</span>
                    </div>
                    <p class="text-xs text-gray-400 text-center">{{ __('home.quality_desc') }}</p>
                    <p class="text-xs text-gray-400 text-center mt-2">{{ __('home.quality_capture') }}</p>
                </div>
            </div>

            <!-- The Website -->
            <div class="bg-gray-800 rounded-lg p-6 border border-gray-700 flex flex-col">
                <div class="text-center mb-4">
                    <div class="inline-flex items-center justify-center w-12 h-12 rounded-full bg-purple-600/20 mb-3">
                        <i class="fas fa-globe text-purple-400 text-xl"></i>
                    </div>
                    <h3 class="text-xl font-bold text-white">{{ __('home.the_website') }}</h3>
                    <p class="text-sm text-purple-400">{{ __('home.website_subtitle') }}</p>
                </div>
                <ul class="space-y-2 text-gray-400 text-sm flex-grow">
                    <li class="flex items-start">
                        <i class="fas fa-search text-blue-400 mr-2 mt-0.5"></i>
                        <span>{{ __('home.website_feature_browse') }}</span>
                    </li>
                    <li class="flex items-start">
                        <i class="fas fa-edit text-cyan-400 mr-2 mt-0.5"></i>
                        <span>{{ __('home.website_feature_edit') }}</span>
                    </li>
                    <li class="flex items-start">
                        <i class="fas fa-check-circle text-green-400 mr-2 mt-0.5"></i>
                        <span>{{ __('home.website_feature_validate') }}</span>
                    </li>
                    <li class="flex items-start">
                        <i class="fas fa-star text-yellow-400 mr-2 mt-0.5"></i>
                        <span>{{ __('home.website_feature_vote') }}</span>
                    </li>
                    <li class="flex items-start">
                        <i class="fas fa-code-branch text-orange-400 mr-2 mt-0.5"></i>
                        <span>{{ __('home.website_feature_fork') }}</span>
                    </li>
                    <li class="flex items-start">
                        <i class="fas fa-object-group text-purple-400 mr-2 mt-0.5"></i>
                        <span>{{ __('home.website_feature_merge') }}</span>
                    </li>
                    <li class="flex items-start">
                        <i class="fas fa-language text-gray-400 mr-2 mt-0.5"></i>
                        {{-- Counted, never written down: this said "19 languages" and stayed at 19
                             the day a twentieth was added. A number nobody recomputes is a number
                             that becomes wrong without anyone touching it. --}}
                        <span>{{ __('home.website_feature_languages', ['count' => count(config('locales.supported', []))]) }}</span>
                    </li>
                </ul>
                <!-- Screenshot -->
                <div class="mt-4 rounded-lg overflow-hidden border border-gray-700">
                    <picture>
                        <source srcset="{{ asset('images/screenshots/WebHumanEditAndValidation.webp') }}" type="image/webp">
                        <img src="{{ asset('images/screenshots/WebHumanEditAndValidation.png') }}"
                             alt="{{ __('home.website_screenshot_alt') }}"
                             class="w-full h-auto cursor-zoom-in"
                             width="1421" height="1276"
                             loading="lazy"
                             data-zoomable>
                    </picture>
                </div>
            </div>
        </div>
    </div>

    {{-- What the project holds, shown from the first day.
         There used to be a threshold below which these numbers stayed hidden. A beta that hides
         its size until the size flatters is a beta that will be caught doing it; and a visitor
         reads small numbers on a young project as young, not as failing.

         The pair "translations / of which finished" is the point of the block: one says what can
         be picked up and played tonight, the other says by contrast that the rest is under way —
         which is where someone joins in. --}}
    {{-- Six numbers, all at the same level: none of them is a footnote to another.
         "Finished" and "under way" are the pair that does the work — one says what can be played
         tonight, the other says where someone joins in — and neither should have to be worked out
         by subtracting. --}}
    <div class="bg-gray-800/80 backdrop-blur-sm rounded-lg p-6 border border-gray-700 mb-12">
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-4 text-center">
            @foreach([
                ['games', 'home.stats_games'],
                ['translations', 'home.stats_translations'],
                ['completed', 'home.stats_completed'],
                ['in_progress', 'home.stats_in_progress'],
                ['users', 'home.stats_users'],
                ['downloads', 'home.stats_downloads'],
            ] as [$key, $label])
                <div>
                    <div class="text-3xl font-bold text-purple-400">{{ number_format($stats[$key]) }}</div>
                    <div class="text-gray-400 text-sm">{{ __($label) }}</div>
                </div>
            @endforeach
        </div>

        {{-- The numbers say what exists; this says what is missing, which is the only part a
             visitor can do something about. --}}
        <p class="mt-5 pt-4 border-t border-gray-700 text-center text-sm text-gray-400">
            {{ __('home.stats_join') }}
            <a href="{{ route('docs') }}#quick-start" class="text-purple-400 hover:text-purple-300 underline underline-offset-2">{{ __('home.stats_join_link') }}</a>
        </p>
    </div>

    <!-- Popular Games -->
    @if($popularGames->count() > 0)
    <div class="mb-12">
        <div class="flex items-baseline justify-between gap-4 mb-6">
            <h2 class="glitch-text text-2xl font-bold text-white flex items-center">
                <i class="fas fa-fire text-purple-400 mr-2"></i>
                {{ __('home.popular_games') }}
            </h2>
            {{-- Straight to the catalogue sorted the same way, so the list continues rather than
                 starts again. --}}
            <a href="{{ route('games.index', ['sort' => 'downloads']) }}" class="text-sm text-purple-400 hover:text-purple-300 whitespace-nowrap">
                {{ __('home.see_more') }} <i class="fas fa-arrow-right text-xs ml-1"></i>
            </a>
        </div>
        <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-4">
            @foreach($popularGames as $game)
            <a href="{{ route('games.show', $game) }}" class="bg-gray-800 rounded-lg p-4 border border-gray-700 hover:border-purple-500 transition block">
                <div class="flex items-start space-x-3">
                    @if($game->image_url)
                    <img src="{{ $game->image_url }}" alt="{{ $game->name }}" class="w-12 h-16 object-cover rounded">
                    @else
                    <div class="w-12 h-16 bg-gray-700 rounded flex items-center justify-center">
                        <i class="fas fa-gamepad text-gray-400"></i>
                    </div>
                    @endif
                    <div class="flex-1 min-w-0">
                        <h3 class="font-semibold text-white truncate">{{ $game->name }}</h3>
                        {{-- The two facts side by side: what ordered this list, and how many
                             hands are on the game. Neither is worth guessing at. --}}
                        <div class="text-sm text-gray-400">
                            {{ trans_choice('home.translations_count', $game->translations_count, ['count' => $game->translations_count]) }}
                            @if($game->downloads_total)
                                <span class="text-gray-600">·</span>
                                {{ __('translation.downloads', ['count' => number_format($game->downloads_total)]) }}
                            @endif
                        </div>
                        @if(!empty($game->language_states))
                        <div class="flex flex-wrap gap-1 mt-1">
                            @php
                                $maxFlags = 5;
                                // Finished first, then under way, then merely collected: the five
                                // that fit should be the five worth seeing.
                                $order = ['finished' => 0, 'progress' => 1, 'capture' => 2];
                                $languages = collect($game->language_states)
                                    ->sortBy(fn ($state, $language) => [$order[$state] ?? 3, $language])
                                    ->all();
                                $remaining = max(0, count($languages) - $maxFlags);
                            @endphp
                            @foreach(array_slice($languages, 0, $maxFlags, true) as $lang => $state)
                                <x-language-flag :language="$lang" :state="$state" />
                            @endforeach
                            @if($remaining > 0)
                                <span class="text-xs text-gray-400 ml-1">+{{ $remaining }}</span>
                            @endif
                        </div>
                        @endif
                    </div>
                </div>
            </a>
            @endforeach
        </div>
    </div>
    @endif

    {{-- Two lists that partition the same set: what is finished, and what is under way.
         Finished comes first — it is the one thing on this page someone can play tonight, and the
         counters above promise it. Three cards each: a front page is a doorway, and the catalogue
         sorts the same two ways for whoever wants the rest. --}}
    {{-- Both lists continue in the catalogue, and each link SAYS SO in its own words.
         "See more" under a list of translations landed on a page of games, and the reader had to
         work out the change of object for themselves — so the link names its destination instead.
         The catalogue is a catalogue of games; "all translations" is not a view this site has. --}}
    @foreach([
        ['finished', $finished, 'home.finished_translations', 'fa-circle-check',
            ['completed' => 1, 'sort' => 'finished'], 'home.see_all_finished_games', true],
        // Ordered on the newest translation; the catalogue's closest reading is games by last
        // content change. The two agree on the first four games here and drift after that, which
        // is why the link promises "recently translated games" and not "these, continued".
        ['latest', $latestTranslations, 'home.latest_translations', 'fa-clock',
            ['sort' => 'updated'], 'home.see_all_recent_games', false],
    ] as [$slug, $list, $heading, $icon, $params, $linkLabel, $byContentDate])
        @if($list->count() > 0)
        <div class="mb-12">
            <div class="flex items-baseline justify-between gap-4 mb-6">
                <h2 class="glitch-text text-2xl font-bold text-white flex items-center">
                    <i class="fas {{ $icon }} text-purple-400 mr-2"></i>
                    {{ __($heading) }}
                </h2>
                <a href="{{ route('games.index', $params) }}" class="text-sm text-purple-400 hover:text-purple-300 whitespace-nowrap">
                    {{ __($linkLabel) }} <i class="fas fa-arrow-right text-xs ml-1"></i>
                </a>
            </div>
            <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-4">
                @foreach($list as $translation)
                    <x-home.translation-card :translation="$translation"
                        :date="$byContentDate ? $translation->contentChangedAt() : $translation->created_at" />
                @endforeach
            </div>
        </div>
        @endif
    @endforeach
@endsection
