@props(['translation', 'plain' => false, 'hideMain' => false])

@php
    /*
     * Which of the three roles this file plays in its lineage.
     *
     * The model already answers it (isBranch / isFork / isMain) but nothing displayed it, so the
     * admin list showed twenty-eight rows with no way to tell a published Main from someone's
     * unpublished contribution — the single most useful thing to know before moderating one.
     *
     * A branch is the one worth spotting: it is not public, only its Main can see it, and acting
     * on it is not the same act as acting on something the whole site can read. Hence the colour
     * on the branch and the quiet grey on the ordinary case.
     *
     * 🔴 **The one place the role is written.** It used to be written three times: here, by hand in
     * my-translations (purple fork, grey branch, nothing for a Main) and by hand in the dashboard
     * (green crown for a Main, blue for a Branch, and no idea what a Fork was). Three colours and
     * three icons for one fact, on three screens somebody moves between — so the habit that makes
     * it readable at a glance could never form.
     *
     * `plain` is for rows carrying text-and-icon rather than chips, exactly as contributions-badge
     * has it. The WORDS and the ICON never change between the two: a reader meeting the same fact
     * on two pages must not have to work out that it is the same fact.
     *
     * `hideMain` is for a list of one's OWN translations, where leading the lineage is the ordinary
     * case and stamping it on every row buys nothing. It hides the Main and nothing else: a Fork is
     * a Main too, and the fact that it started from somebody else's work is exactly what a list of
     * one's own work must not swallow.
     */
    $role = $translation->isBranch()
        ? ['branch', 'fa-code-branch', 'bg-amber-900/60 text-amber-200', 'text-amber-300']
        : ($translation->isFork()
            ? ['fork', 'fa-code-branch', 'bg-indigo-900/60 text-indigo-200', 'text-indigo-300']
            : ['main', 'fa-star', 'bg-gray-700 text-gray-300', 'text-gray-400']);

    [$key, $icon, $chipColour, $plainColour] = $role;
@endphp

@if(!($hideMain && $key === 'main'))
    @if($plain)
        <span {{ $attributes->merge(['class' => 'text-sm ' . $plainColour]) }}
            title="{{ __('translation.role_' . $key . '_hint') }}">
            <i class="fas {{ $icon }}"></i> {{ __('translation.role_' . $key) }}
        </span>
    @else
        <span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium ' . $chipColour]) }}
            title="{{ __('translation.role_' . $key . '_hint') }}">
            <i class="fas {{ $icon }} text-[10px]"></i>{{ __('translation.role_' . $key) }}
        </span>
    @endif
@endif
