@extends('layouts.app')

@section('title', __('nav.my_translations') . ' - UnityGameTranslator')

@section('content')
<div class="flex justify-between items-center mb-8">
    <h1 class="text-3xl font-bold"><i class="fas fa-folder mr-2"></i> {{ __('my_translations.title') }}</h1>
    <a href="{{ route('translations.create') }}" class="bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded-lg">
        <i class="fas fa-plus mr-2"></i> {{ __('my_translations.new_upload') }}
    </a>
</div>

@if($translations->isEmpty())
    <div class="text-center py-12 text-gray-400">
        <i class="fas fa-folder-open text-6xl mb-4"></i>
        <p class="text-xl">{{ __('my_translations.empty') }}</p>
        <a href="{{ route('translations.create') }}" class="inline-block mt-4 bg-purple-600 hover:bg-purple-700 text-white px-6 py-3 rounded-lg">
            <i class="fas fa-upload mr-2"></i> {{ __('my_translations.upload_first') }}
        </a>
    </div>
@else
    {{-- What this page owes its author, and says nowhere else.

         Both are addressed to them alone: a translation that helps nobody is theirs to fix, not
         something to display in front of others. And both speak of THEIR interest rather than of
         our tidiness — the first because an empty file earns them the votes of disappointed
         players, the second because their work is sitting behind a door nobody is opening. --}}
    @if($emptyPublished->isNotEmpty())
        <div class="bg-amber-900/25 border border-amber-700/60 rounded-lg p-4 mb-6">
            <p class="text-amber-200 font-medium mb-1">
                <i class="fas fa-hourglass-half mr-2"></i>{{ __('my_translations.empty_published_title') }}
            </p>
            <p class="text-sm text-gray-300 mb-2">
                {{ __('my_translations.empty_published', ['days' => \App\Models\Translation::EMPTY_GRACE_DAYS]) }}
            </p>
            <ul class="text-sm text-gray-400 list-disc list-inside">
                @foreach($emptyPublished as $t)
                    <li>
                        <a href="{{ route('translations.dashboard', $t) }}" class="text-amber-300 hover:text-amber-200 underline underline-offset-2">
                            {{ $t->game->name }} — {{ $t->target_language }}
                        </a>
                        <span class="text-gray-500">
                            ({{ __('my_translations.pending_lines', ['count' => number_format($t->capture_count)]) }})
                        </span>
                        {{-- The other honest way out, said plainly: somebody who published by
                             mistake should not have to hunt for the row among twenty to undo it.
                             It leads to the card, where the delete button already lives with its
                             confirmation — the banner never deletes anything itself. --}}
                        <a href="#translation-{{ $t->id }}" class="text-gray-400 hover:text-gray-200 underline underline-offset-2 ml-1">
                            {{ __('my_translations.empty_published_remove') }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Orphaned: the Main is gone, not quiet. Nobody can merge this work, ever — so this is
         the one banner that says what to DO, and the strongest of the three. --}}
    @if($orphanBranches->isNotEmpty())
        <div class="bg-red-900/25 border border-red-700/60 rounded-lg p-4 mb-6">
            <p class="text-red-200 font-medium mb-1">
                <i class="fas fa-unlink mr-2"></i>{{ __('my_translations.orphan_title') }}
            </p>
            <p class="text-sm text-gray-300 mb-2">{{ __('my_translations.orphan') }}</p>
            <ul class="text-sm text-gray-400 list-disc list-inside">
                @foreach($orphanBranches as $t)
                    <li>
                        {{-- To the dashboard, not to the card: that is where "become
                             independent" lives, with its warning and its confirmation. --}}
                        <a href="{{ route('translations.dashboard', $t) }}" class="text-red-300 hover:text-red-200 underline underline-offset-2">
                            {{ $t->game->name }} — {{ $t->target_language }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Not silence but indifference: they came back, they worked, they left it aside. The
         wording says "does not seem to", because we do not know their reasons — and it lists
         what the contributor can do rather than pushing one of them. --}}
    @if($ignoredBranches->isNotEmpty())
        <div class="bg-gray-800 border border-amber-700/50 rounded-lg p-4 mb-6">
            <p class="text-white font-medium mb-1">
                <i class="fas fa-inbox mr-2 text-amber-400"></i>{{ __('my_translations.main_ignoring_title') }}
            </p>
            <p class="text-sm text-gray-300 mb-2">{{ __('my_translations.main_ignoring') }}</p>
            <ul class="text-sm text-gray-400 list-disc list-inside">
                @foreach($ignoredBranches as $t)
                    <li>
                        <a href="{{ route('translations.dashboard', $t) }}" class="text-amber-300 hover:text-amber-200 underline underline-offset-2">
                            {{ $t->game->name }} — {{ $t->target_language }}
                        </a>
                        @if($t->merged_lines_total > 0)
                            <span class="text-gray-500">
                                ({{ trans_choice('my_translations.merged_lines_total', $t->merged_lines_total, ['count' => number_format($t->merged_lines_total)]) }})
                            </span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Delisted: nothing is broken, and that is exactly what has to come across. The Main is
         still there and can still take the work in; it is simply invisible to players until its
         author translates a line. --}}
    @if($delistedMains->isNotEmpty())
        <div class="bg-gray-800 border border-gray-700 rounded-lg p-4 mb-6">
            <p class="text-white font-medium mb-1">
                <i class="fas fa-eye-slash mr-2 text-amber-400"></i>{{ __('my_translations.main_delisted_title') }}
            </p>
            <p class="text-sm text-gray-300 mb-2">{{ __('my_translations.main_delisted') }}</p>
            <ul class="text-sm text-gray-400 list-disc list-inside">
                @foreach($delistedMains as $t)
                    <li>
                        <a href="{{ route('translations.dashboard', $t) }}" class="text-amber-300 hover:text-amber-200 underline underline-offset-2">
                            {{ $t->game->name }} — {{ $t->target_language }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if($stalledBranches->isNotEmpty())
        <div class="bg-gray-800 border border-gray-700 rounded-lg p-4 mb-6">
            <p class="text-white font-medium mb-1">
                <i class="fas fa-moon mr-2 text-purple-400"></i>{{ __('my_translations.main_silent_title') }}
            </p>
            <p class="text-sm text-gray-300 mb-2">{{ __('my_translations.main_silent') }}</p>
            <ul class="text-sm text-gray-400 list-disc list-inside">
                @foreach($stalledBranches as $t)
                    <li>
                        <a href="{{ route('translations.dashboard', $t) }}" class="text-purple-400 hover:text-purple-300 underline underline-offset-2">
                            {{ $t->game->name }} — {{ $t->target_language }}
                        </a>
                        @if($t->daysSinceMainMoved() !== null)
                            <span class="text-gray-500">
                                ({{ __('my_translations.silent_for', ['days' => $t->daysSinceMainMoved()]) }})
                            </span>
                        @endif
                        {{-- Level 2 only: the way out is offered once the silence has lasted, and
                             never a word about it before. --}}
                        @if($t->shouldOfferFork())
                            <span class="text-purple-300">— {{ __('my_translations.can_go_independent') }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Only worth showing once there is something to reorder: a sort control above a single
         translation is furniture. Same auto-submit and same wording as the games list, so the
         same gesture works on both screens. --}}
    @if($translations->count() > 1)
        <form action="{{ route('translations.mine') }}" method="GET"
            class="flex items-center justify-end gap-2 mb-4" data-auto-submit>
            <label for="sort" class="text-sm text-gray-400">{{ __('games.sort_by') }}</label>
            <select name="sort" id="sort"
                class="bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white text-sm">
                <option value="updated" {{ $sort === 'updated' ? 'selected' : '' }}>{{ __('my_translations.sort.updated') }}</option>
                <option value="new" {{ $sort === 'new' ? 'selected' : '' }}>{{ __('my_translations.sort.new') }}</option>
                <option value="game" {{ $sort === 'game' ? 'selected' : '' }}>{{ __('my_translations.sort.game') }}</option>
                <option value="downloads" {{ $sort === 'downloads' ? 'selected' : '' }}>{{ __('my_translations.sort.downloads') }}</option>
                <option value="review" {{ $sort === 'review' ? 'selected' : '' }}>{{ __('my_translations.sort.review') }}</option>
            </select>
            {{-- Nothing left to click once the select applies on change. Kept in the markup and
                 removed by the script that makes it redundant: without JavaScript it is the only
                 way to reorder anything. --}}
            <button type="submit" data-hide-when-auto
                class="bg-purple-600 hover:bg-purple-700 text-white px-3 py-2 rounded text-sm">
                <i class="fas fa-sort"></i>
            </button>
        </form>
    @endif

    <div class="space-y-4">
        @foreach($translations as $translation)
            {{-- Anchored so the banners above can lead to the card they are talking about,
                 rather than leaving the reader to find it in a list of twenty. --}}
            <div id="translation-{{ $translation->id }}"
                 class="bg-gray-800 rounded-lg p-5 border border-gray-700 flex justify-between items-center scroll-mt-24">
                <div class="flex items-center gap-4">
                    @if($translation->game->image_url)
                        <img src="{{ $translation->game->image_url }}" alt="{{ $translation->game->name }}" class="w-12 h-16 object-cover rounded">
                    @else
                        <div class="w-12 h-16 bg-gray-700 rounded flex items-center justify-center">
                            <i class="fas fa-gamepad text-gray-500"></i>
                        </div>
                    @endif
                    <div>
                        <a href="{{ route('translations.dashboard', $translation) }}" class="text-lg font-semibold hover:text-purple-400">
                            {{ $translation->game->name }}
                        </a>
                    <div class="flex items-center gap-3 mt-1">
                        <span class="bg-blue-900 text-blue-200 px-2 py-0.5 rounded text-sm">
                            @langflag($translation->source_language) {{ $translation->source_language }} → @langflag($translation->target_language) {{ $translation->target_language }}
                        </span>
                        @if($translation->isComplete())
                            <span class="text-green-400 text-sm"><i class="fas fa-check"></i> {{ __('translation.complete') }}</span>
                        @else
                            <span class="text-yellow-400 text-sm"><i class="fas fa-clock"></i> {{ __('translation.in_progress') }}</span>
                        @endif
                        @if($translation->isFork())
                            <span class="text-purple-400 text-sm"><i class="fas fa-code-fork"></i> Fork</span>
                        @elseif($translation->isBranch())
                            <span class="text-gray-400 text-sm"><i class="fas fa-code-branch"></i> Branch</span>
                        @endif
                    </div>
                    @php
                        $forkCount = $translation->forks->where('visibility', 'public')->count();
                        $branchTotal = $translation->forks->where('visibility', 'branch')->count();
                    @endphp
                    <div class="text-sm text-gray-400 mt-1">
                        {{ number_format($translation->line_count) }} {{ __('my_translations.lines') }} •
                        {{ $translation->download_count }} {{ __('my_translations.downloads') }} •
                        {{-- The one number that says someone was GLAD to find it — downloads only
                             say they tried. Hidden at zero, like the other counts on this line:
                             a fresh upload does not need "+0" thrown at its author, and the
                             dashboard carries the figure in full either way. --}}
                        @if($translation->vote_count != 0)
                            <span class="{{ $translation->vote_count > 0 ? 'text-green-400' : 'text-red-400' }}">
                                {{ trans_choice('my_translations.votes', abs($translation->vote_count), [
                                    'count' => ($translation->vote_count > 0 ? '+' : '') . $translation->vote_count,
                                ]) }}
                            </span> •
                        @endif
                        @if($translation->isMain() && $branchTotal > 0)
                            {{ $branchTotal }} {{ __('my_translations.branches') }} •
                        @endif
                        @if($forkCount > 0)
                            {{ $forkCount }} {{ __('my_translations.forks') }} •
                        @endif
                        {{-- Two distinct facts, as on the game page: when you published it, and
                             whether you have touched it since. One date alone could not tell an
                             upload from this morning apart from one you have been maintaining
                             for a year. contentChangedAt, never updated_at: a vote or a download
                             must not make a translation look freshly worked on. --}}
                        <span title="{{ $translation->created_at->isoFormat('LLL') }}">
                            <i class="fas fa-calendar mr-1"></i>{{ __('translation.published_on', ['date' => $translation->created_at->isoFormat('LL')]) }}
                        </span>
                        @if($translation->hasBeenUpdatedSincePublication())
                            <span class="ml-2" title="{{ $translation->contentChangedAt()->isoFormat('LLL') }}">
                                <i class="fas fa-pen mr-1"></i>{{ __('translation.updated_on', ['date' => $translation->contentChangedAt()->isoFormat('LL')]) }}
                            </span>
                        @endif
                    </div>

                    {{-- What the file carries besides its lines. The game page shows this to
                         anonymous visitors; its own author had no sign of it here, and the
                         external link matters most to them — a translation that replaces images
                         does not work without it. Counts only: the detail lives on the
                         dashboard, this is a list. --}}
                    @if($translation->hasSettings())
                        <div class="text-xs text-gray-500 mt-1 flex items-center gap-3 flex-wrap">
                            @if($translation->getEffectiveResourcesUrl())
                                <span class="text-cyan-400"><i class="fas fa-link"></i> {{ __('file_settings.resources') }}</span>
                            @endif
                            @php $fontCount = count($translation->configuredFonts()); @endphp
                            @if($fontCount > 0)
                                <span><i class="fas fa-font"></i> {{ trans_choice('file_settings.fonts', $fontCount, ['count' => $fontCount]) }}</span>
                            @endif
                            @php $imageCount = $translation->settingsCount('image_replacements'); @endphp
                            @if($imageCount > 0)
                                <span><i class="fas fa-image"></i> {{ trans_choice('file_settings.images', $imageCount, ['count' => $imageCount]) }}</span>
                            @endif
                            @php $exclusionCount = $translation->settingsCount('exclusions'); @endphp
                            @if($exclusionCount > 0)
                                <span><i class="fas fa-ban"></i> {{ trans_choice('file_settings.exclusions', $exclusionCount, ['count' => $exclusionCount]) }}</span>
                            @endif
                        </div>
                    @endif
                    <div class="mt-2">
                        {{-- The step and what is left to read, above the bar that details it —
                             same order and same wording as the dashboard, so one screen does not
                             describe a file differently from the next. --}}
                        <div class="flex items-center gap-2 mb-1">
                            @if($translation->effective_lines > 0)
                                <x-review-stage :translation="$translation" />
                                @if($translation->ai_count > 0)
                                    <span class="text-xs text-gray-400">
                                        {{ __('progress.left_to_review', ['count' => number_format($translation->ai_count)]) }}
                                    </span>
                                @endif
                                <x-translation-completeness :translation="$translation" />
                                <x-game-coverage :translation="$translation"
                                    :game-max="$gameMaxes[$translation->game_id] ?? null" />
                            @else
                                <span class="text-xs text-gray-400" title="{{ __('progress.capture_only_desc') }}">
                                    <i class="fas fa-camera mr-1"></i>{{ __('progress.capture_only') }}
                                </span>
                            @endif
                        </div>
                        <x-progress-bar :translation="$translation" />
                        <x-quality-legend :translation="$translation" />
                    </div>
                    </div>
                </div>
                @php $branchCount = $branchCounts[$translation->file_uuid] ?? 0; @endphp
                <div class="flex gap-2">
                    {{-- Correcting one's own lines is not a Main privilege: a branch
                         author edits their work from the site like anyone else, without
                         the game running. Only the MERGE view below stays Main-only,
                         since a branch never sees another branch. --}}
                    <a href="{{ route('translations.merge', ['uuid' => $translation->file_uuid, 'mode' => 'edit']) }}" class="bg-purple-600 hover:bg-purple-700 text-white px-3 py-2 rounded" title="{{ __('my_translations.edit_translations') }}">
                        <i class="fas fa-pen"></i>
                    </a>
                    @if($translation->isMain())
                    {{-- Merge branches (highlighted when branches exist) --}}
                    @if($branchCount > 0)
                    <a href="{{ route('translations.merge', $translation->file_uuid) }}" class="bg-green-600 hover:bg-green-700 text-white px-3 py-2 rounded inline-flex items-center gap-1.5" title="{{ __('my_translations.merge_branches', ['count' => $branchCount]) }}">
                        <i class="fas fa-code-merge"></i>
                        <span class="bg-red-500 text-white text-xs rounded-full min-w-[1.25rem] h-5 flex items-center justify-center font-bold px-1">{{ $branchCount }}</span>
                    </a>
                    @else
                    <span class="bg-gray-700 text-gray-500 px-3 py-2 rounded cursor-not-allowed" title="{{ __('my_translations.no_branches') }}">
                        <i class="fas fa-code-merge"></i>
                    </span>
                    @endif
                    @endif
                    <a href="{{ route('translations.download', $translation) }}" class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-2 rounded" title="{{ __('translation.download') }}">
                        <i class="fas fa-download"></i>
                    </a>
                    {{-- Le detail, pas les reglages.
                         Cette place portait « Parametres », qui ouvrait un formulaire de quatre
                         champs. Deux pages parlaient donc de la meme traduction, separees par ce
                         qu'elles PERMETTENT et non par ce qu'elles disent : le tableau de bord
                         affichait les ressources externes, le formulaire les modifiait. Et le
                         tableau de bord n'etait atteignable que par le titre du jeu, un lien qui
                         ne ressemble pas a un lien, a cote de cinq boutons colores — on pouvait
                         utiliser le produit longtemps sans le decouvrir.
                         Le bouton mene donc la ou mene le titre, et le reglage est un bouton DANS
                         cette page (actions rapides). Un seul endroit par traduction. --}}
                    <a href="{{ route('translations.dashboard', $translation) }}" class="bg-gray-600 hover:bg-gray-500 text-white px-3 py-2 rounded" title="{{ __('dashboard.title') }}">
                        <i class="fas fa-circle-info"></i>
                    </a>
                    <form action="{{ route('translations.destroy', $translation) }}" method="POST" class="delete-form" data-confirm="{{ __('my_translations.delete_confirm') }}">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="bg-red-900 hover:bg-red-800 text-white px-3 py-2 rounded" title="{{ __('translation.delete') }}">
                            <i class="fas fa-trash"></i>
                        </button>
                    </form>
                </div>
            </div>
        @endforeach
    </div>
@endif
@endsection
