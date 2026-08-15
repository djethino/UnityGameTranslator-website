{{--
    Which copy this page writes to: published, both, or the machine on the other end.

    ⚠ **The same control appears in the mod, in the manager and here**, and somebody who learns it
    in one must not relearn it in the next. Its positions, their order and their words come from
    UnityGameTranslator.Common.EditScope — the socle the two C# programs read.

    ⚠ **PHP cannot consume that library**, so the table below is the one place this rule exists
    twice. Anything changed there has to be changed here, and the wording is deliberately taken
    from EditScope.Name / EditScope.Effect rather than reinvented.

    ⚠ **A position out of reach keeps its place and says why.** Hiding it would make the control
    look different from one screen to the next, which is precisely what it exists to prevent.

    Props:
      side  'server' | 'both' | 'local'  — the one this page acts on
      why   map of side => reason, for the ones that cannot be reached from here
--}}
@props(['side' => 'local', 'why' => [], 'compact' => false])

@php
    // ⚠ **The icons are what make the two sizes one control.** The full strip is shown where there
    // is room — beside a page title — and a single mark beside an action where there is not. They
    // are recognisably the same thing only if the picture is identical, so the metaphor is fixed
    // here and never varies: a cloud for what is published, a screen for this machine, and the two
    // linked for both.
    $icons = [
        'server' => 'fa-cloud',
        'both' => 'fa-link',
        'local' => 'fa-display',
    ];
@endphp

@php
    // Order is fixed and shared: published, both, on this machine. It is the order of the switch
    // everywhere else, and a control read left to right must not be mirrored between screens.
    $sides = [
        'server' => __('edit_scope.server'),
        'both' => __('edit_scope.both'),
        'local' => __('edit_scope.local'),
    ];

    $effects = [
        'server' => __('edit_scope.server_effect'),
        'both' => __('edit_scope.both_effect'),
        'local' => __('edit_scope.local_effect'),
    ];
@endphp

@if ($compact)
    {{-- ⚠ **All three marks, one lit — not the chosen one on its own.** The small form has to be
         recognised as the strip above, and a single icon with a word beside it is a new label to
         learn rather than the same control read at a glance. Same pictures, same order, no words:
         that is the entire difference between the two sizes. --}}
    <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded border border-gray-700
                 bg-gray-800 whitespace-nowrap shrink-0"
          title="{{ $effects[$side] ?? '' }}">
        @foreach ($icons as $key => $icon)
            {{-- ⚠ cyan-200, and it must stay outside every colour a button is filled with. The lit
                 mark used to be a light purple, which on the purple buttons of the mod and the
                 manager scored LESS against the fill than the two dimmed marks — the control read
                 backwards. UnityGameTranslator.Common.Theme.MarkLit holds the value and the
                 measurement for the two C# programs; this is the copy PHP cannot consume. --}}
            <i class="fas {{ $icon }} text-[10px] {{ $key === $side ? 'text-cyan-200' : 'text-gray-600' }}"></i>
        @endforeach
    </span>
@else
    <div class="inline-flex rounded-lg overflow-hidden border border-gray-700 shrink-0"
         title="{{ $effects[$side] ?? '' }}">
        @foreach ($sides as $key => $label)
            @php
                $active = $key === $side;
                $blocked = array_key_exists($key, $why);
            @endphp
            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 text-xs whitespace-nowrap
                         {{ $active ? 'bg-purple-900/60 text-purple-100 font-semibold' : 'bg-gray-800 text-gray-400' }}
                         {{ $blocked ? 'opacity-40' : '' }}"
                  @if ($blocked) title="{{ $why[$key] }}" @endif>
                {{-- The picture says "lit" the same way it does in the compact form and on a
                     button; the segment's own fill and weight say it a second time, which is what
                     the full form has room for. --}}
                <i class="fas {{ $icons[$key] ?? '' }} text-[10px] {{ $active ? 'text-cyan-200' : '' }}"></i>{{ $label }}
            </span>
        @endforeach
    </div>
@endif
