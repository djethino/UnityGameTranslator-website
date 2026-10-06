@props(['game', 'truncate' => true])

{{-- A game's names in the other stores, under its title — wherever the title is shown as a
     heading or a card (user, 2026-10-06: "le sous-titre partout où on affiche le titre"). Inline,
     in a sentence or a page title, the same names go in brackets: Game::titleWithOtherNames().
     Nothing is drawn for a game that has none.

     Small and grey unless the caller sizes it: a class given REPLACES that look rather than adding
     to it — two text sizes on one element are decided by the stylesheet's order, not by the page.
     A heading with room passes `:truncate="false"` so it wraps instead. --}}
@php
    $look = $attributes->has('class') ? '' : 'text-xs text-gray-400 ';
@endphp
@if($game && ($names = $game->otherNames()))
    <p {{ $attributes->merge(['class' => $look . ($truncate ? 'truncate' : 'break-words')]) }}
       title="{{ implode(' / ', $names) }}">{{ implode(' / ', $names) }}</p>
@endif
