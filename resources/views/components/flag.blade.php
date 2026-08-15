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
@endphp

@if ($drawn)
    <svg width="{{ round($height * $drawn['width'] / max($drawn['height'], 1)) }}"
         height="{{ $height }}"
         viewBox="0 0 {{ $drawn['width'] }} {{ $drawn['height'] }}"
         shape-rendering="crispEdges"
         role="img" aria-hidden="true" focusable="false"
         {{ $attributes->merge(['class' => 'inline-block align-middle rounded-[1px] shrink-0']) }}>
        @foreach ($drawn['rows'] as $y => $row)
            @php
                // One rect per RUN, never one per pixel — see language-mark for the arithmetic.
                $runs = [];
                $length = 0;
                $current = null;

                foreach (str_split($row) as $x => $key) {
                    if ($key === $current) { $length++; continue; }
                    if ($current !== null && $current !== '.') {
                        $runs[] = [$x - $length, $length, $current];
                    }
                    $current = $key;
                    $length = 1;
                }

                if ($current !== null && $current !== '.') {
                    $runs[] = [$drawn['width'] - $length, $length, $current];
                }
            @endphp

            @foreach ($runs as [$x, $width, $key])
                @if (isset($drawn['palette'][$key]))
                    <rect x="{{ $x }}" y="{{ $y }}" width="{{ $width }}" height="1"
                          fill="{{ $drawn['palette'][$key] }}" />
                @endif
            @endforeach
        @endforeach
    </svg>
@else
    <span aria-hidden="true">🌐</span>
@endif
