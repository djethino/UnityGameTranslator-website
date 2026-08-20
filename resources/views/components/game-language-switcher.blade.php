{{--
    The language somebody plays in, beside the one they read this site in.

    🔴 **Two selectors because they are two questions.** The one next to this picks the twenty
    languages this site is translated INTO; this one picks any of the catalogue's ninety, and says
    which translations should come first when browsing games. An English interface with French
    subtitles is ordinary, and a Tamil player has no interface language to be inferred from at all.

    ⚠ **Shown to everybody, account or not.** It used to be @auth, on the argument that a visitor
    had nowhere to keep the preference — while the interface switch beside it has always written
    the session for everybody. The effect was a site that guesses which language you play in and
    gives you no way to correct the guess. Where the value lives, and in which order: App\Services\GameLanguage.

    ⚠ Told apart from its neighbour by the CONTROLLER icon, not by a different flag style: two
    flag buttons side by side with nothing between them are two of the same thing.
--}}
@php
    $__gameTag = \App\Services\GameLanguage::tag();
    // The chosen one, or what we detected — the same answer the ranking uses, so the flag in the
    // bar always names the language the list is actually ordered by.
    $__gameMark = \App\Services\CatalogStore::languageMark(\App\Services\GameLanguage::name());
@endphp

{{-- ⚠ Takes whatever class the caller passes so the same component sits in the desktop row and in
     the mobile bar without a second copy of itself. --}}
<div {{ $attributes->merge(['class' => 'relative']) }} x-data="{ open: false }">
    <button @click="open = !open" @click.away="open = false"
            title="{{ __('profile.game_language') }}"
            class="flex items-center gap-1 text-gray-300 hover:text-white px-2 py-1 rounded transition">
        <i class="fas fa-gamepad text-xs"></i>
        <x-flag :flag="$__gameMark['flag']" />
        {{-- ⚠ Said to be DETECTED when nothing was chosen. The flag then shows a guess, and
             letting a guess look like a decision somebody made is the small lie this avoids —
             especially now that the guess comes from the browser and can name a language the
             site has no interface for at all. --}}
        {{-- ⚠ Hidden below xl, like the account name and the sign-in label beside it: between the
             width the desktop bar appears at (lg) and the width everything fits in, something has
             to give. Measured in GERMAN, the widest of the nineteen — "Spiele · Dokumentation"
             against "Games · Docs" costs about 80px — this word is what keeps the row inside 1024.
             The title on the button says the same thing at any width. --}}
        @unless ($__gameTag)
            <span class="hidden xl:inline text-[9px] text-gray-500 leading-none">{{ __('profile.game_language_detected_short') }}</span>
        @endunless
        <i class="fas fa-chevron-down text-xs"></i>
    </button>

    <div x-show="open" x-cloak x-transition
         class="absolute right-0 mt-2 w-56 bg-gray-800 border border-gray-700 rounded-lg shadow-xl z-50 max-h-80 overflow-y-auto">
        <form method="POST" action="{{ route('game-language.switch') }}">
            @csrf
            <button type="submit" name="game_language" value=""
                    class="w-full text-left flex items-center px-4 py-2 text-sm hover:bg-gray-700 transition {{ $__gameTag ? 'text-gray-300' : 'bg-purple-900 text-purple-200' }}">
                {{ __('profile.game_language_follows') }}
            </button>

            @foreach (\App\Services\CatalogStore::languageChoices() as $tag => $name)
                <button type="submit" name="game_language" value="{{ $tag }}"
                        class="w-full text-left flex items-center gap-2 px-4 py-2 text-sm hover:bg-gray-700 transition {{ $__gameTag === $tag ? 'bg-purple-900 text-purple-200' : 'text-gray-300' }}">
                    <x-language-mark :language="$name" named />
                    <span>{{ $name }}</span>
                </button>
            @endforeach
        </form>
    </div>
</div>
