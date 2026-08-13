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
    {{-- The mark alone, for beside an action. Same icon as the strip, so the two read as one
         control rather than as two unrelated decorations. --}}
    <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded border border-purple-700/60
                 bg-purple-900/40 text-purple-100 text-xs whitespace-nowrap shrink-0"
          title="{{ $effects[$side] ?? '' }}">
        <i class="fas {{ $icons[$side] ?? '' }} text-[10px]"></i>{{ $sides[$side] ?? '' }}
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
                <i class="fas {{ $icons[$key] ?? '' }} text-[10px]"></i>{{ $label }}
            </span>
        @endforeach
    </div>
@endif
