@props(['column', 'label', 'default' => 'created_at', 'align' => 'left'])
@php
    $currentSort = request('sort', $default);
    $currentDir = request('dir', 'desc');
    $isActive = $currentSort === $column;
    $nextDir = ($isActive && $currentDir === 'asc') ? 'desc' : 'asc';
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
