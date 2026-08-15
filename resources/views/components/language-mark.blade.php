{{--
    A language, shown as its flag and — when the flag cannot name it alone — its tag beside it.

    ⚠ **The same control as the mod's and the manager's.** The rule lives in
    UnityGameTranslator.Common.Flags.Mark, which PHP cannot consume, so CatalogStore::languageMark
    mirrors it and says so. What is mirrored is one sentence: a flag names a COUNTRY and this names
    a LANGUAGE, so a flag shared by several of them (ten Indian languages, the two Norwegians) is
    not enough on its own and the tag comes with it.

    ⚠ **The flags are drawn by us, as pixels, from catalogs/flags.json.** A national flag is an
    official symbol rather than a copyrighted work; what the usual icon sets license is their
    artwork. Rendering the same grid here as rects keeps one source for the three products —
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
@endphp

@if ($flag || $mark['showTag'])
    <span class="inline-flex items-center gap-1 align-middle shrink-0"
          @if ($language) title="{{ $language }}" @endif>
        @if ($flag)
            {{-- shape-rendering=crispEdges: these are drawn as pixels, and a browser smoothing a
                 16-wide flag turns the half of them that differ by one edge into the same smudge. --}}
            <svg width="{{ round($height * $flag['width'] / max($flag['height'], 1)) }}"
                 height="{{ $height }}"
                 viewBox="0 0 {{ $flag['width'] }} {{ $flag['height'] }}"
                 shape-rendering="crispEdges"
                 role="img" aria-hidden="true" focusable="false"
                 class="rounded-[1px]">
                @foreach ($flag['rows'] as $y => $row)
                    @php
                        // One rect per RUN of identical pixels, never one per pixel: a flag of flat
                        // bands becomes three rects instead of a hundred and seventy-six, and a
                        // page listing ninety languages is the difference between 300 nodes and
                        // 16 000.
                        $runs = [];
                        $length = 0;
                        $current = null;

                        foreach (str_split($row) as $x => $key) {
                            if ($key === $current) {
                                $length++;
                                continue;
                            }

                            if ($current !== null && $current !== '.') {
                                $runs[] = [$x - $length, $length, $current];
                            }

                            $current = $key;
                            $length = 1;
                        }

                        if ($current !== null && $current !== '.') {
                            $runs[] = [$flag['width'] - $length, $length, $current];
                        }
                    @endphp

                    @foreach ($runs as [$x, $width, $key])
                        @if (isset($flag['palette'][$key]))
                            <rect x="{{ $x }}" y="{{ $y }}" width="{{ $width }}" height="1"
                                  fill="{{ $flag['palette'][$key] }}" />
                        @endif
                    @endforeach
                @endforeach
            </svg>
        @endif

        @if ($mark['showTag'] && $mark['tag'])
            <span class="text-[10px] leading-none text-gray-400">{{ $mark['tag'] }}</span>
        @endif
    </span>
@endif
