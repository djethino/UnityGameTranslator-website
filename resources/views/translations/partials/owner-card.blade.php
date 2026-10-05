{{-- One translation of one account, as a card: cover, title, chips, counts, progress.

     🔴 Shared by "My translations" and the admin's page for an account (admin.user-show), so an
     admin reads a translation exactly as its author does. Each caller keeps its own actions beside
     it; what differs here is passed in:
       $titleUrl  where the title leads — the author's dashboard, or the admin inspection screen
       $hideMain  true in a list of one's own work, where leading a lineage is the ordinary case
       $gameMaxes from OwnerTranslations::gameMaxes(), for the coverage badge --}}
                {{-- flex-1 min-w-0, here and on the text column: the card takes all the room up to
                     its buttons. Sized by its content, it made every progress bar (w-full) as wide
                     as the longest line of counts and dates above it, so each card's bar had its
                     own length (reported 2026-09-30). The cover and the icon keep their size. --}}
                <div class="flex items-center gap-4 flex-1 min-w-0">
                    @if($translation->game->image_url)
                        <x-game-cover :src="$translation->game->image_url" :alt="$translation->game->name" class="w-12 h-16 rounded" />
                    @else
                        <div class="w-12 h-16 bg-gray-700 rounded flex items-center justify-center flex-shrink-0">
                            <i class="fas fa-gamepad text-gray-500"></i>
                        </div>
                    @endif
                    <div class="flex-1 min-w-0">
                        <a href="{{ $titleUrl }}" class="text-lg font-semibold hover:text-purple-400">
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
                        <x-contributions-badge :translation="$translation" plain />

                        {{-- 🔴 The state this row said NOWHERE. The chip beside it is the Main's
                             own declaration and is shown on a Main only — a branch does not lead a
                             lineage and does not decide this — so from the branch side the door
                             closing was invisible here, readable only on that branch's dashboard.
                             It mattered the day notifications became deletable.

                             ⚠ Same words as the shared library gives the mod and the Manager
                             ("Main closed"), and the same reading: worth noticing, not an error.
                             Nothing is broken, the work is still its author's, and the way on is
                             one screen away. --}}
                        {{-- The other way a branch loses its reviewer: the Main is there, its owner's
                             account is not. Same words as the shared chip the mod and the Manager show
                             ("No owner"), in the tone they give it. Said before "Main closed": an
                             erased owner may also have closed, and the erasure is the whole story. --}}
                        @if($translation->mainIsAbandoned())
                            <span class="text-red-300 text-sm" title="{{ __('my_translations.main_abandoned_tip') }}">
                                <i class="fas fa-user-slash"></i> {{ __('translation.no_owner') }}
                            </span>
                        @elseif($translation->isFrozenBranch())
                            <span class="text-amber-300 text-sm" title="{{ __('my_translations.branch_frozen_tip') }}">
                                <i class="fas fa-lock"></i> {{ __('my_translations.branch_frozen') }}
                            </span>
                        {{-- The Main published since this branch last merged from it: the same fact
                             the game shows as a corner notice and the Manager on the game's card,
                             from the same hash the mod writes into the file. Last of the chain
                             because a wall outranks it — nobody merges from a Main that is gone,
                             ownerless or closed. The act itself stays in the game. --}}
                        @elseif($translation->mainHasMovedSinceMerge())
                            <span class="text-amber-300 text-sm" title="{{ __('translation.main_moved_body') }}">
                                <i class="fas fa-arrow-up"></i> {{ __('translation.main_moved') }}
                            </span>
                        @endif

                        {{-- Through the component, like the chip beside it. Written out here, this
                             row had its own purple fork and its own grey branch, neither of which
                             matched the admin screens or the dashboard — and the words were in
                             English only, hard-coded, on a page translated into nineteen. --}}
                        <x-translation-role :translation="$translation" plain :hide-main="$hideMain" />
                    </div>
                    @php
                        $forkCount = $translation->forks->where('visibility', 'public')->count();
                        $branchTotal = $translation->forks->where('visibility', 'branch')->count();
                    @endphp
                    <div class="text-sm text-gray-400 mt-1">
                        {{ trans_choice('my_translations.lines_count', $translation->line_count, [
                            'count' => number_format($translation->line_count),
                        ]) }} •
                        {{ trans_choice('my_translations.downloads_count', $translation->download_count, [
                            'count' => number_format($translation->download_count),
                        ]) }} •
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
                            {{ trans_choice('my_translations.branches_count', $branchTotal) }} •
                        @endif
                        @if($forkCount > 0)
                            {{ trans_choice('my_translations.forks_count', $forkCount) }} •
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
                            {{-- The stage component says "Capture only" itself on a file with nothing translated. --}}
                            <x-review-stage :translation="$translation" />
                            @if($translation->effective_lines > 0)
                                @if($translation->ai_count > 0)
                                    <span class="text-xs text-gray-400">
                                        {{ __('progress.left_to_review', ['count' => number_format($translation->ai_count)]) }}
                                    </span>
                                @endif
                                <x-translation-completeness :translation="$translation" />
                                <x-game-coverage :translation="$translation"
                                    :game-max="$gameMaxes[$translation->game_id] ?? null" />
                            @endif
                        </div>
                        <x-progress-bar :translation="$translation" />
                        <x-quality-legend :translation="$translation" />
                    </div>
                    </div>
                </div>
