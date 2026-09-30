{{--
    A language, shown as its flag and — when the flag cannot name it alone — its tag beside it.

    ⚠ **The same control as the mod's and the manager's.** The rule lives in
    UnityGameTranslator.Common.Flags.Mark, which PHP cannot consume, so CatalogStore::languageMark
    mirrors it and says so. What is mirrored is one sentence: a flag names a COUNTRY and this names
    a LANGUAGE, so a flag shared by several of them (ten Indian languages, the two Norwegians) is
    not enough on its own and the tag comes with it.

    ⚠ **The flags are drawn by us, as pixels, from catalogs/flags.json.** A national flag is an
    official symbol rather than a copyrighted work; what the usual icon sets license is their
    artwork. Rendering the same grid as rects (CatalogStore::flagSvg, served as /flags/{id}.svg)
    keeps one source for the three products —
    replacing this with an icon font would put the site back on somebody else's licence and let it
    drift from what a game shows.

    Props:
      language  the catalogue's language NAME (not its tag) — "French", "Norwegian Bokmål"
      height    pixels tall, default 11. The width follows the grid's ratio.
--}}
@props(['language' => null, 'height' => 11, 'named' => false])

@php
    $mark = \App\Services\CatalogStore::languageMark($language);

    // 🔴 The chip answers "which language is this flag" — and a name written beside it answers
    // that better. Asking for both produces "IN hi Hindi", the same thing said twice.
    if ($named) {
        $mark['showTag'] = false;
    }
    $flag = \App\Services\CatalogStore::flag($mark['flag']);
    $flagUrl = $flag ? \App\Services\CatalogStore::flagUrl($mark['flag']) : null;
@endphp

@if ($flag || $mark['showTag'])
    <span class="inline-flex items-center gap-1 align-middle shrink-0"
          @if ($language) title="{{ $language }}" @endif>
        @if ($flag)
            {{-- A file the browser fetches once for the whole site, not an SVG written into the page:
                 see CatalogStore::flagSvg for what that cost. Decorative (alt=""): the language is
                 named by the title above and, where needed, by the tag beside it. Not lazy, not
                 async: a cached flag must arrive with the first paint (see x-flag). --}}
            <img src="{{ $flagUrl }}" alt=""
                 width="{{ round($height * $flag['width'] / max($flag['height'], 1)) }}"
                 height="{{ $height }}"
                 class="rounded-[1px]">
        @endif

        @if ($mark['showTag'] && $mark['tag'])
            <span class="text-[10px] leading-none text-gray-400">{{ $mark['tag'] }}</span>
        @endif
    </span>
@endif
