@props(['src', 'alt' => ''])

{{-- A game's picture in a portrait frame. A cover fills it; a Steam header or a RAWG screenshot
     is shown whole, centred, on a blurred copy of itself — decided once the picture has loaded, in
     resources/js/game-cover.js (gameCover() there builds the same markup for search hits).
     The frame's size and rounding come from the caller's classes. --}}
<span data-game-cover {{ $attributes->merge(['class' => 'relative block overflow-hidden bg-gray-700 flex-shrink-0']) }}>
    <img data-game-cover-backdrop alt="" aria-hidden="true" hidden
         class="absolute inset-0 w-full h-full object-cover blur-md scale-110 brightness-75">
    <img data-game-cover-image src="{{ $src }}" alt="{{ $alt }}"
         class="relative w-full h-full object-cover">
</span>
