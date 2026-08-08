@props(['translation'])

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
     */
    $role = $translation->isBranch()
        ? ['branch', 'fa-code-branch', 'bg-amber-900/60 text-amber-200']
        : ($translation->isFork()
            ? ['fork', 'fa-code-branch', 'bg-indigo-900/60 text-indigo-200']
            : ['main', 'fa-star', 'bg-gray-700 text-gray-300']);

    [$key, $icon, $colour] = $role;
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium ' . $colour]) }}
    title="{{ __('translation.role_' . $key . '_hint') }}">
    <i class="fas {{ $icon }} text-[10px]"></i>{{ __('translation.role_' . $key) }}
</span>
