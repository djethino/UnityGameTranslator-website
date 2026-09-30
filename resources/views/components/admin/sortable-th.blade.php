@props(['column', 'label', 'default' => 'created_at', 'align' => 'left', 'first' => 'desc'])
@php
    // `first` is the direction a column opens on — what somebody clicking it wants to see first
    // (asked 2026-09-30). A date or a count opens on the latest or the most, "Adults only" on the
    // marked games: `desc`, the default. A name opens on A-Z: `first="asc"`. Clicking again
    // turns it the other way, as every file list and mail client does.
    //
    // ⚠ Every column used to open on `asc`, so "Joined" showed the oldest accounts first and
    // needed a second click on every visit.
    $currentSort = request('sort', $default);
    $currentDir = request('dir', 'desc');
    $isActive = $currentSort === $column;
    $other = $first === 'asc' ? 'desc' : 'asc';
    $nextDir = ($isActive && $currentDir === $first) ? $other : $first;
    $icon = !$isActive
        ? 'fa-sort text-gray-600'
        : ($currentDir === 'asc' ? 'fa-sort-up text-purple-400' : 'fa-sort-down text-purple-400');
    // Preserve existing filters, reset pagination when sort changes.
    $params = array_merge(request()->query(), ['sort' => $column, 'dir' => $nextDir]);
    unset($params['page']);
@endphp
<th class="text-{{ $align }} py-3 px-4">
    <a href="?{{ http_build_query($params) }}"
       class="inline-flex items-center gap-1 hover:text-white {{ $isActive ? 'text-white' : '' }}">
        {{ $label }}
        <i class="fas {{ $icon }} text-xs"></i>
    </a>
</th>
