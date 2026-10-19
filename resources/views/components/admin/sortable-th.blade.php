@props(['column', 'label', 'default' => 'created_at', 'align' => 'left', 'first' => 'desc',
         'sortParam' => 'sort', 'dirParam' => 'dir', 'pageParam' => 'page'])
@php
    // `first` is the direction a column opens on — what somebody clicking it wants to see first
    // (asked 2026-09-30). A date or a count opens on the latest or the most, "Adults only" on the
    // marked games: `desc`, the default. A name opens on A-Z: `first="asc"`. Clicking again
    // turns it the other way, as every file list and mail client does.
    //
    // ⚠ Every column used to open on `asc`, so "Joined" showed the oldest accounts first and
    // needed a second click on every visit.
    //
    // ⚠ The three parameter names are for a screen with SEVERAL sortable tables (Mod usage): each
    // table sorts and pages on its own names, and touching one never resets the other.
    $currentSort = request($sortParam, $default);
    $currentDir = request($dirParam, 'desc');
    $isActive = $currentSort === $column;
    $other = $first === 'asc' ? 'desc' : 'asc';
    $nextDir = ($isActive && $currentDir === $first) ? $other : $first;
    $icon = !$isActive
        ? 'fa-sort text-gray-600'
        : ($currentDir === 'asc' ? 'fa-sort-up text-purple-400' : 'fa-sort-down text-purple-400');
    // Preserve existing filters, reset this table's pagination when its sort changes.
    $params = array_merge(request()->query(), [$sortParam => $column, $dirParam => $nextDir]);
    unset($params[$pageParam]);
@endphp
<th class="text-{{ $align }} py-3 px-4">
    {{-- data-keeps-scroll: the page reloads sorted, and the reader stays on the table they sorted
         (components/keep-scroll.js) rather than being thrown back to the top. --}}
    <a href="?{{ http_build_query($params) }}" data-keeps-scroll
       class="inline-flex items-center gap-1 hover:text-white {{ $isActive ? 'text-white' : '' }}">
        {{ $label }}
        <i class="fas {{ $icon }} text-xs"></i>
    </a>
</th>
