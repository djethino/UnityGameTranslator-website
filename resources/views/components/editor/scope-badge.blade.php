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
@props(['side' => 'local', 'why' => []])

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

<div class="inline-flex rounded-lg overflow-hidden border border-gray-700 shrink-0"
     title="{{ $effects[$side] ?? '' }}">
    @foreach ($sides as $key => $label)
        @php
            $active = $key === $side;
            $blocked = array_key_exists($key, $why);
        @endphp
        <span class="px-2.5 py-1 text-xs whitespace-nowrap
                     {{ $active ? 'bg-purple-900/60 text-purple-100 font-semibold' : 'bg-gray-800 text-gray-400' }}
                     {{ $blocked ? 'opacity-40' : '' }}"
              @if ($blocked) title="{{ $why[$key] }}" @endif>
            {{ $label }}
        </span>
    @endforeach
</div>
