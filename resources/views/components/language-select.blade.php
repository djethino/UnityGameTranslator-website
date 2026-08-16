{{--
    A language picker that can show a flag.

    🔴 **A native `<select>` cannot.** Its `<option>` accepts text and nothing else — no image, no
    SVG, no markup — which is why the two settings in the profile had names and no pictures while
    the title bar had both. This is the same Alpine dropdown the title bar uses, with a hidden
    input so it still posts like a plain field.

    ⚠ **Sorted by name, not in catalogue order.** The catalogue lists its ninety languages by how
    much they are used, which is right for a picker you TYPE into — the mod's is searchable — and
    unreadable in one you scan with your eyes: English, French, German, Spanish, Italian… reads as
    no order at all. The site's pickers are scanned, so they are alphabetical.

    Props:
      name      the field name to post
      choices   [value => label]
      selected  the current value
      empty     label for the "no choice" entry, or null to omit it
      marks     true to draw each entry's flag through <x-language-mark> (labels are language
                names), false to treat the labels as plain text
      flags     [value => flag id] for lists whose labels are NOT catalogue language names — the
                interface locales are native spellings ("Português (Brasil)") that <x-language-mark>
                would not recognise, so they name their flag themselves
--}}
@props([
    'name',
    'choices' => [],
    'selected' => null,
    'empty' => null,
    'marks' => true,
    'flags' => [],
])

@php
    // Sorted here rather than by the caller: every picker on this site wants the same order, and
    // one that forgot would be the odd one out with no way to notice.
    $sorted = $choices;
    asort($sorted, SORT_NATURAL | SORT_FLAG_CASE);

    $currentLabel = $choices[$selected] ?? $empty;
@endphp

{{-- ⚠ **No displayed text goes through @js.** It encodes non-ASCII as ç, which a JS engine
     decodes but which puts "Français" into the page as an escape sequence — unreadable in the
     source, and wrong the moment anything reads the attribute as text. Alpine tracks the VALUE
     (an ASCII tag); the label is written by Blade and swapped from the clicked entry's own text,
     so accents never leave the HTML. --}}
{{-- 🔴 **Nothing here may leave the grammar @alpinejs/csp accepts.** A method declared in x-data,
     or a call with arguments in @click, is not an error it reports — the CSP parser evaluates it
     to NOTHING. `open` then never exists, x-show has nothing to hide, and every picker on the page
     is drawn open. Which is exactly what happened when this component was given a `pick()` helper
     so that it could fire a change event.

     ⚠ So the "behaves like a real field" half is done OUTSIDE Alpine: each entry carries
     data-language-choice, and app.js submits the surrounding auto-submitting form on click. Plain
     JS in a bundled file, where the CSP has nothing to say. --}}
<div x-data="{ open: false, value: @js((string) $selected) }" class="relative" data-language-picker>
    {{-- ⚠ data-language-field, so app.js can write the chosen value itself rather than wait for
         Alpine to flush it. Reading the picker's state from outside is guesswork about a
         scheduler; the clicked entry carries the answer. --}}
    <input type="hidden" name="{{ $name }}" :value="value" data-language-field>

    <button type="button" @click="open = !open" @click.away="open = false"
            class="w-full flex items-center gap-2 bg-gray-700 border border-gray-600 rounded-lg px-4 py-3 text-left text-white hover:border-gray-500 focus:ring-purple-500 focus:border-purple-500 transition">
        {{-- The current entry's flag. Rendered server-side for the value the page loaded with;
             Alpine swaps the label live, and a flag that lagged one choice behind would be worse
             than none — so the picker reloads its own state on save rather than pretending. --}}
        @if ($selected !== null && $selected !== '')
            @if (!empty($flags[$selected]))
                <x-flag :flag="$flags[$selected]" />
            @elseif ($marks && isset($choices[$selected]))
                <x-language-mark :language="$choices[$selected]" named />
            @endif
        @endif
        <span class="flex-1 truncate" x-ref="label">{{ $currentLabel }}</span>
        <i class="fas fa-chevron-down text-xs text-gray-400"></i>
    </button>

    <div x-show="open" x-cloak x-transition
         class="absolute left-0 right-0 mt-2 bg-gray-800 border border-gray-700 rounded-lg shadow-xl z-50 max-h-72 overflow-y-auto">
        @if ($empty !== null)
            <button type="button" data-label="{{ $empty }}" data-language-choice
                    @click="value = ''; $refs.label.textContent = $el.dataset.label; open = false"
                    class="w-full text-left px-4 py-2 text-sm text-gray-300 hover:bg-gray-700 transition">
                {{ $empty }}
            </button>
        @endif

        @foreach ($sorted as $value => $label)
            <button type="button" data-value="{{ $value }}" data-label="{{ $label }}" data-language-choice
                    @click="value = $el.dataset.value; $refs.label.textContent = $el.dataset.label; open = false"
                    class="w-full text-left flex items-center gap-2 px-4 py-2 text-sm hover:bg-gray-700 transition {{ (string) $selected === (string) $value ? 'bg-purple-900 text-purple-200' : 'text-gray-300' }}">
                @if (!empty($flags[$value]))
                    <x-flag :flag="$flags[$value]" />
                @elseif ($marks)
                    {{-- ⚠ named: the name is written right beside it, so the tag chip would say
                         the same thing twice. --}}
                    <x-language-mark :language="$label" named />
                @endif
                <span class="truncate">{{ $label }}</span>
            </button>
        @endforeach
    </div>
</div>
