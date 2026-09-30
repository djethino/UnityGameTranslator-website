{{--
    One flag, by its id, drawn from catalogs/flags.json.

    ⚠ For places that already know WHICH FLAG they want — the interface-language switcher, where
    the locale config names a country directly. Anything naming a LANGUAGE wants
    <x-language-mark> instead, which also decides whether the tag has to come with it.

    ⚠ **This replaces `<span class="fi fi-xx">`.** Those classes come from flag-icons, an external
    stylesheet under its own licence; these are ours, they need no stylesheet, and they are the
    same drawings a game shows — which is the point of having one catalogue.

    Props:
      flag    the flag id ("gb", "br", "es-ct")
      height  pixels tall, default 11. The width follows the grid's ratio.
--}}
@props(['flag' => null, 'height' => 11])

@php
    $drawn = \App\Services\CatalogStore::flag($flag);
    $src = $drawn ? \App\Services\CatalogStore::flagUrl($flag) : null;
@endphp

@if ($drawn)
    {{-- A file fetched once for the whole site, not an SVG written into the page — see
         CatalogStore::flagSvg. Decorative (alt=""): whatever shows it names the language or the
         locale in words.

         ⚠ Neither loading="lazy" nor decoding="async" (removed 2026-09-30): with them Firefox paints
         the page first and the flags after, one by one, even from the cache — they visibly popped
         in on every page where they used to be there at once. A cached flag must arrive with the
         first paint. --}}
    <img src="{{ $src }}" alt=""
         width="{{ round($height * $drawn['width'] / max($drawn['height'], 1)) }}"
         height="{{ $height }}"
         {{ $attributes->merge(['class' => 'inline-block align-middle rounded-[1px] shrink-0']) }}>
@else
    <span aria-hidden="true">🌐</span>
@endif
