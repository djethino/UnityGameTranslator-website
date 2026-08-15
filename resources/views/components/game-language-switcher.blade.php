{{--
    The language somebody plays in, beside the one they read this site in.

    🔴 **Two selectors because they are two questions.** The one next to this picks the twenty
    languages this site is translated INTO; this one picks any of the catalogue's ninety, and says
    which translations should come first when browsing games. An English interface with French
    subtitles is ordinary, and a Tamil player has no interface language to be inferred from at all.

    ⚠ **Only when signed in**, and that is not an oversight. The preference is stored on the
    account; for a visitor there is nowhere to keep it, and the site refuses a cookie for this on
    purpose — a sort order kept in a cookie comes back in an order nobody remembers choosing, and a
    shared link shows something else to whoever opens it. See GameController.

    ⚠ Told apart from its neighbour by the CONTROLLER icon, not by a different flag style: two
    flag buttons side by side with nothing between them are two of the same thing.
--}}
@auth
    @php
        $__gameLanguage = auth()->user()->game_language;
        // The chosen one, or what we detected — the same answer the ranking uses, so the flag
        // in the bar always names the language the list is actually ordered by.
        $__gameMark = \App\Services\CatalogStore::languageMark(auth()->user()->gameLanguage());
    @endphp

    <div class="relative" x-data="{ open: false }">
        <button @click="open = !open" @click.away="open = false"
                title="{{ __('profile.game_language') }}"
                class="flex items-center gap-1 text-gray-300 hover:text-white px-2 py-1 rounded transition">
            <i class="fas fa-gamepad text-xs"></i>
            <x-flag :flag="$__gameMark['flag']" />
            {{-- ⚠ Said to be DETECTED when nothing was chosen. The flag then shows a guess, and
                 letting a guess look like a decision somebody made is the small lie this avoids —
                 especially now that the guess comes from the browser and can name a language the
                 site has no interface for at all. --}}
            @unless ($__gameLanguage)
                <span class="text-[9px] text-gray-500 leading-none">{{ __('profile.game_language_detected_short') }}</span>
            @endunless
            <i class="fas fa-chevron-down text-xs"></i>
        </button>

        <div x-show="open" x-cloak x-transition
             class="absolute right-0 mt-2 w-56 bg-gray-800 border border-gray-700 rounded-lg shadow-xl z-50 max-h-80 overflow-y-auto">
            <form method="POST" action="{{ route('profile.game-language') }}">
                @csrf
                <button type="submit" name="game_language" value=""
                        class="w-full text-left flex items-center px-4 py-2 text-sm hover:bg-gray-700 transition {{ $__gameLanguage ? 'text-gray-300' : 'bg-purple-900 text-purple-200' }}">
                    {{ __('profile.game_language_follows') }}
                </button>

                @foreach (\App\Services\CatalogStore::languageChoices() as $tag => $name)
                    <button type="submit" name="game_language" value="{{ $tag }}"
                            class="w-full text-left flex items-center gap-2 px-4 py-2 text-sm hover:bg-gray-700 transition {{ $__gameLanguage === $tag ? 'bg-purple-900 text-purple-200' : 'text-gray-300' }}">
                        <x-language-mark :language="$name" named />
                        <span>{{ $name }}</span>
                    </button>
                @endforeach
            </form>
        </div>
    </div>
@endauth
