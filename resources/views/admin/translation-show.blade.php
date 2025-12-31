@extends('layouts.app')

@section('title', __('admin.translation_details') . ' - Admin - UnityGameTranslator')

@section('content')
<div class="mb-6">
    <a href="{{ route('admin.translations.index') }}" class="text-purple-400 hover:text-purple-300">
        <i class="fas fa-arrow-left mr-2"></i> {{ __('admin.back_to_translations') }}
    </a>
</div>

<div class="max-w-5xl">
    <h1 class="text-3xl font-bold mb-8"><i class="fas fa-file-alt mr-2"></i> {{ __('admin.translation_details') }}</h1>

    <!-- Translation Info Card -->
    <div class="bg-gray-800 rounded-lg p-6 border border-gray-700 mb-6">
        <div class="flex items-start gap-6">
            @if($translation->game->image_url)
                <img src="{{ $translation->game->image_url }}" class="w-24 h-32 object-cover rounded-lg">
            @else
                <div class="w-24 h-32 bg-gray-700 rounded-lg flex items-center justify-center">
                    <i class="fas fa-gamepad text-4xl text-gray-500"></i>
                </div>
            @endif
            <div class="flex-1">
                <h2 class="text-2xl font-semibold mb-2">
                    <a href="{{ route('games.show', $translation->game) }}" class="hover:text-purple-400">
                        {{ $translation->game->name }}
                    </a>
                </h2>

                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mt-4">
                    <div>
                        <p class="text-gray-400 text-sm">{{ __('games.target_language') }}</p>
                        <p class="font-medium">
                            <span class="bg-blue-900 text-blue-200 px-2 py-0.5 rounded text-sm">
                                @langflag($translation->source_language) {{ $translation->source_language }} → @langflag($translation->target_language) {{ $translation->target_language }}
                            </span>
                        </p>
                    </div>
                    <div>
                        <p class="text-gray-400 text-sm">{{ __('admin.uploader') }}</p>
                        <p class="font-medium">{{ $translation->user->name ?? '[Deleted]' }}</p>
                    </div>
                    <div>
                        <p class="text-gray-400 text-sm">{{ __('my_translations.lines') }}</p>
                        <p class="font-medium">{{ number_format($translation->line_count) }}</p>
                    </div>
                    <div>
                        <p class="text-gray-400 text-sm">{{ __('my_translations.downloads') }}</p>
                        <p class="font-medium">{{ number_format($translation->download_count) }}</p>
                    </div>
                    @php
                        $total = $translation->human_count + $translation->validated_count + $translation->ai_count;
                        $humanPct = $total > 0 ? round($translation->human_count / $total * 100) : 0;
                        $validatedPct = $total > 0 ? round($translation->validated_count / $total * 100) : 0;
                        $aiPct = $total > 0 ? round($translation->ai_count / $total * 100) : 0;
                    @endphp
                    <div>
                        <p class="text-gray-400 text-sm">{{ __('upload.translation_composition') }}</p>
                        <p class="font-medium text-sm">
                            <span class="text-green-400">H:{{ $humanPct }}%</span>
                            <span class="text-blue-400 ml-1">V:{{ $validatedPct }}%</span>
                            <span class="text-orange-400 ml-1">A:{{ $aiPct }}%</span>
                        </p>
                    </div>
                    <div>
                        <p class="text-gray-400 text-sm">{{ __('admin.status') }}</p>
                        <p class="font-medium">
                            @if($translation->isComplete())
                                <span class="text-green-400"><i class="fas fa-check"></i> {{ __('translation.complete') }}</span>
                            @else
                                <span class="text-yellow-400"><i class="fas fa-clock"></i> {{ __('translation.in_progress') }}</span>
                            @endif
                        </p>
                    </div>
                    <div>
                        <p class="text-gray-400 text-sm">{{ __('admin.created_at') }}</p>
                        <p class="font-medium">{{ $translation->created_at->format('M d, Y H:i') }}</p>
                    </div>
                    <div>
                        <p class="text-gray-400 text-sm">{{ __('admin.updated_at') }}</p>
                        <p class="font-medium">{{ $translation->updated_at->format('M d, Y H:i') }}</p>
                    </div>
                </div>

                @if($translation->notes)
                    <div class="mt-4 p-3 bg-gray-750 rounded">
                        <p class="text-gray-400 text-sm mb-1">{{ __('upload.notes') }}</p>
                        <p class="text-gray-300">{{ $translation->notes }}</p>
                    </div>
                @endif

                @if($translation->isFork() && $translation->parent)
                    <div class="mt-4 p-3 bg-purple-900/30 border border-purple-700 rounded">
                        <p class="text-purple-300 text-sm">
                            <i class="fas fa-code-branch mr-1"></i>
                            Fork of {{ $translation->parent->user->name ?? '[Deleted]' }}'s translation
                        </p>
                    </div>
                @endif

                @if($translation->forks->isNotEmpty())
                    <div class="mt-4 p-3 bg-gray-750 rounded">
                        <p class="text-gray-400 text-sm mb-2">
                            <i class="fas fa-code-branch mr-1"></i>
                            {{ $translation->forks->count() }} {{ __('translation.forks') }}
                        </p>
                        <div class="flex flex-wrap gap-2">
                            @foreach($translation->forks as $fork)
                                <a href="{{ route('admin.translations.show', $fork) }}" class="text-sm text-purple-400 hover:text-purple-300">
                                    {{ $fork->user->name ?? '[Deleted]' }}
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
        </div>

        <!-- Actions -->
        <div class="flex gap-3 mt-6 pt-6 border-t border-gray-700">
            <a href="{{ route('translations.download', $translation) }}" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded">
                <i class="fas fa-download mr-1"></i> {{ __('translation.download') }}
            </a>
            <a href="{{ route('admin.translations.edit', $translation) }}" class="bg-orange-600 hover:bg-orange-700 text-white px-4 py-2 rounded">
                <i class="fas fa-edit mr-1"></i> {{ __('common.edit') }}
            </a>
            <form action="{{ route('admin.translations.destroy', $translation) }}" method="POST" class="inline delete-form">
                @csrf
                @method('DELETE')
                <button type="submit" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded">
                    <i class="fas fa-trash mr-1"></i> {{ __('common.delete') }}
                </button>
            </form>
        </div>
    </div>

    <!-- JSON Content -->
    @if($jsonContent)
        {{-- Metadata Section --}}
        @if(!empty($metadata))
        <div class="bg-gray-800 rounded-lg p-6 border border-gray-700 mb-6">
            <h3 class="text-lg font-semibold mb-4"><i class="fas fa-info-circle mr-2 text-blue-400"></i> {{ __('admin.metadata') }}</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                @if(isset($metadata['_uuid']))
                <div>
                    <span class="text-gray-500">UUID:</span>
                    <span class="text-gray-300 font-mono text-xs ml-2">{{ $metadata['_uuid'] }}</span>
                </div>
                @endif
                @if(isset($metadata['_game']))
                <div>
                    <span class="text-gray-500">{{ __('admin.game') }}:</span>
                    <span class="text-gray-300 ml-2">{{ $metadata['_game']['name'] ?? 'N/A' }}</span>
                    @if(isset($metadata['_game']['steam_id']))
                        <span class="text-gray-500 text-xs">(Steam: {{ $metadata['_game']['steam_id'] }})</span>
                    @endif
                </div>
                @endif
                @if(isset($metadata['_source']))
                <div>
                    <span class="text-gray-500">{{ __('admin.source') }}:</span>
                    @if(isset($metadata['_source']['uploader']))
                        <span class="text-gray-300 ml-2">{{ $metadata['_source']['uploader'] }}</span>
                    @endif
                    @if(isset($metadata['_source']['hash']))
                        <span class="text-gray-500 text-xs">({{ Str::limit($metadata['_source']['hash'], 20) }})</span>
                    @endif
                </div>
                @endif
                @if(isset($metadata['_local_changes']))
                <div>
                    <span class="text-gray-500">{{ __('admin.local_changes') }}:</span>
                    <span class="text-gray-300 ml-2">{{ $metadata['_local_changes'] }}</span>
                </div>
                @endif
            </div>
        </div>
        @endif

        {{-- Tag Filters --}}
        <form method="GET" id="filterForm" class="mb-4 flex flex-wrap gap-4 items-center text-sm">
            <span class="text-gray-500">{{ __('merge.filters') }}</span>

            <label class="flex items-center gap-2 cursor-pointer">
                <input type="checkbox" name="human" value="1" {{ $filters['human'] ? 'checked' : '' }}
                    class="filter-checkbox rounded bg-gray-700 border-gray-600 text-green-600">
                <span class="tag-H">H</span>
            </label>

            <label class="flex items-center gap-2 cursor-pointer">
                <input type="checkbox" name="validated" value="1" {{ $filters['validated'] ? 'checked' : '' }}
                    class="filter-checkbox rounded bg-gray-700 border-gray-600 text-blue-600">
                <span class="tag-V">V</span>
            </label>

            <label class="flex items-center gap-2 cursor-pointer">
                <input type="checkbox" name="ai" value="1" {{ $filters['ai'] ? 'checked' : '' }}
                    class="filter-checkbox rounded bg-gray-700 border-gray-600 text-orange-600">
                <span class="tag-A">A</span>
            </label>

            <label class="flex items-center gap-2 cursor-pointer">
                <input type="checkbox" name="skipped" value="1" {{ $filters['skipped'] ? 'checked' : '' }}
                    class="filter-checkbox rounded bg-gray-700 border-gray-600 text-gray-600">
                <span class="tag-S">S</span>
            </label>

            <label class="flex items-center gap-2 cursor-pointer">
                <input type="checkbox" name="mod_ui" value="1" {{ $filters['mod_ui'] ? 'checked' : '' }}
                    class="filter-checkbox rounded bg-gray-700 border-gray-600 text-purple-600">
                <span class="tag-M">M</span>
            </label>

            @if(array_filter($filters))
            <a href="{{ route('admin.translations.show', $translation) }}" class="text-gray-400 hover:text-white text-xs">
                <i class="fas fa-times"></i> {{ __('merge.reset_filters') }}
            </a>
            @endif
        </form>

        {{-- Search --}}
        <div class="mb-4">
            <form method="GET" class="relative">
                {{-- Preserve existing params --}}
                @foreach(array_filter($filters) as $filterKey => $filterValue)
                <input type="hidden" name="{{ $filterKey }}" value="{{ $filterValue }}">
                @endforeach
                @if(request('sort'))
                <input type="hidden" name="sort" value="{{ request('sort') }}">
                @endif
                @if(request('dir'))
                <input type="hidden" name="dir" value="{{ request('dir') }}">
                @endif

                <input type="text" name="search" value="{{ request('search') }}"
                    placeholder="{{ __('merge.search_placeholder') }}"
                    class="w-full px-4 py-2 pl-10 bg-gray-800 border border-gray-700 rounded-lg text-white placeholder-gray-500 focus:border-purple-500 focus:ring-1 focus:ring-purple-500">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-500"></i>
                @if(request('search'))
                <a href="{{ route('admin.translations.show', array_merge(
                    ['translation' => $translation->id],
                    array_filter($filters),
                    request('sort') ? ['sort' => request('sort'), 'dir' => request('dir')] : []
                )) }}" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-500 hover:text-white">
                    <i class="fas fa-times"></i>
                </a>
                @endif
            </form>
        </div>

        {{-- Translation Table --}}
        <div class="bg-gray-800 rounded-lg border border-gray-700">
            <div class="p-4 border-b border-gray-700 flex justify-between items-center">
                <h3 class="text-lg font-semibold"><i class="fas fa-code mr-2"></i> {{ __('admin.translation_content') }}</h3>
                <span class="text-sm text-gray-400">{{ number_format($totalKeys) }} {{ __('admin.translation_entries') }}</span>
            </div>

            @php
                $currentSort = request('sort', 'key');
                $currentDir = request('dir', 'asc');
                $sortParams = array_merge(
                    ['translation' => $translation->id],
                    array_filter($filters),
                    request('search') ? ['search' => request('search')] : []
                );
            @endphp

            <div class="overflow-x-auto max-h-[600px] overflow-y-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-900 sticky top-0 z-10">
                        <tr>
                            {{-- Key column with sort --}}
                            <th class="px-4 py-3 text-left text-gray-400 font-medium w-1/2">
                                <a href="{{ route('admin.translations.show', array_merge($sortParams, ['sort' => 'key', 'dir' => ($currentSort === 'key' && $currentDir === 'asc') ? 'desc' : 'asc'])) }}"
                                    class="flex items-center gap-2 hover:text-white transition">
                                    {{ __('admin.original') }}
                                    <i class="fas {{ $currentSort === 'key' ? ($currentDir === 'asc' ? 'fa-sort-up text-purple-400' : 'fa-sort-down text-purple-400') : 'fa-sort text-gray-600' }}"></i>
                                </a>
                            </th>
                            {{-- Tag column with sort --}}
                            <th class="px-2 py-3 text-center w-12">
                                <a href="{{ route('admin.translations.show', array_merge($sortParams, ['sort' => 'tag', 'dir' => ($currentSort === 'tag' && $currentDir === 'asc') ? 'desc' : 'asc'])) }}"
                                    class="flex items-center justify-center gap-1 hover:text-white transition">
                                    <span class="text-gray-400 font-medium text-xs">Tag</span>
                                    <i class="fas text-xs {{ $currentSort === 'tag' ? ($currentDir === 'asc' ? 'fa-sort-up text-purple-400' : 'fa-sort-down text-purple-400') : 'fa-sort text-gray-600' }}"></i>
                                </a>
                            </th>
                            {{-- Value column with sort --}}
                            <th class="px-4 py-3 text-left text-gray-400 font-medium w-1/2">
                                <a href="{{ route('admin.translations.show', array_merge($sortParams, ['sort' => 'value', 'dir' => ($currentSort === 'value' && $currentDir === 'asc') ? 'desc' : 'asc'])) }}"
                                    class="flex items-center gap-2 hover:text-white transition">
                                    {{ __('admin.translated') }}
                                    <i class="fas {{ $currentSort === 'value' ? ($currentDir === 'asc' ? 'fa-sort-up text-purple-400' : 'fa-sort-down text-purple-400') : 'fa-sort text-gray-600' }}"></i>
                                </a>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($pagedKeys as $key)
                            @php
                                $entry = $jsonContent[$key] ?? null;
                                $value = is_array($entry) ? ($entry['v'] ?? '') : ($entry ?? '');
                                $tag = is_array($entry) ? ($entry['t'] ?? 'A') : 'A';
                            @endphp
                            <tr class="border-t border-gray-700 hover:bg-gray-750">
                                <td class="px-4 py-2 text-gray-300 break-words align-top">
                                    <span class="font-mono text-xs">{{ Str::limit($key, 150) }}</span>
                                </td>
                                <td class="px-2 py-2 text-center align-top">
                                    <span class="tag-{{ $tag }}">{{ $tag }}</span>
                                </td>
                                <td class="px-4 py-2 text-white break-words align-top">
                                    {{ Str::limit($value, 150) }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="px-4 py-12 text-center text-gray-500">
                                    <i class="fas fa-search text-4xl mb-3 opacity-50"></i>
                                    <p>{{ __('merge.no_keys_found') }}</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Pagination --}}
            @if($totalPages > 1)
            <div class="p-4 border-t border-gray-700 flex justify-between items-center">
                <span class="text-gray-400 text-sm">
                    {{ __('merge.page_info', ['page' => $page, 'total' => $totalPages, 'keys' => $totalKeys]) }}
                </span>
                <div class="flex gap-2">
                    @if($page > 1)
                    <a href="?page={{ $page - 1 }}{{ array_filter($filters) ? '&' . http_build_query(array_filter($filters)) : '' }}{{ request('search') ? '&search=' . urlencode(request('search')) : '' }}{{ request('sort') ? '&sort=' . request('sort') . '&dir=' . request('dir') : '' }}"
                        class="px-4 py-2 bg-gray-700 hover:bg-gray-600 rounded-lg text-white transition">
                        <i class="fas fa-chevron-left mr-1"></i> {{ __('common.previous') }}
                    </a>
                    @endif
                    @if($page < $totalPages)
                    <a href="?page={{ $page + 1 }}{{ array_filter($filters) ? '&' . http_build_query(array_filter($filters)) : '' }}{{ request('search') ? '&search=' . urlencode(request('search')) : '' }}{{ request('sort') ? '&sort=' . request('sort') . '&dir=' . request('dir') : '' }}"
                        class="px-4 py-2 bg-gray-700 hover:bg-gray-600 rounded-lg text-white transition">
                        {{ __('common.next') }} <i class="fas fa-chevron-right ml-1"></i>
                    </a>
                    @endif
                </div>
            </div>
            @endif
        </div>

        {{-- Legend (HVASM order) --}}
        <div class="mt-4 text-xs text-gray-500 flex flex-wrap gap-4">
            <span><span class="tag-H">H</span> {{ __('merge.legend_human') }}</span>
            <span><span class="tag-V">V</span> {{ __('merge.legend_validated') }}</span>
            <span><span class="tag-A">A</span> {{ __('merge.legend_ai') }}</span>
            <span><span class="tag-S">S</span> {{ __('merge.legend_skipped') }}</span>
            <span><span class="tag-M">M</span> {{ __('merge.legend_mod_ui') }}</span>
        </div>
    @else
        <div class="bg-gray-800 rounded-lg p-6 border border-gray-700 text-center text-gray-500">
            <i class="fas fa-exclamation-triangle text-2xl mb-2"></i>
            <p>Could not load translation file content.</p>
        </div>
    @endif
</div>

@push('head')
<style>
    /* Tag badges */
    .tag-H {
        background-color: rgb(22 163 74);
        color: white;
        padding: 0.125rem 0.375rem;
        border-radius: 0.25rem;
        font-size: 0.75rem;
        font-weight: 700;
    }
    .tag-A {
        background-color: rgb(234 88 12);
        color: white;
        padding: 0.125rem 0.375rem;
        border-radius: 0.25rem;
        font-size: 0.75rem;
        font-weight: 700;
    }
    .tag-V {
        background-color: rgb(37 99 235);
        color: white;
        padding: 0.125rem 0.375rem;
        border-radius: 0.25rem;
        font-size: 0.75rem;
        font-weight: 700;
    }
    .tag-M {
        background-color: rgb(147 51 234);
        color: white;
        padding: 0.125rem 0.375rem;
        border-radius: 0.25rem;
        font-size: 0.75rem;
        font-weight: 700;
    }
    .tag-S {
        background-color: rgb(75 85 99);
        color: white;
        padding: 0.125rem 0.375rem;
        border-radius: 0.25rem;
        font-size: 0.75rem;
        font-weight: 700;
    }
</style>
@endpush

<script nonce="{{ $cspNonce }}">
document.querySelectorAll('.delete-form').forEach(function(form) {
    form.addEventListener('submit', function(e) {
        if (!confirm('{{ __('admin.delete_confirm') }}')) {
            e.preventDefault();
        }
    });
});

// Auto-submit filter checkboxes
document.querySelectorAll('.filter-checkbox').forEach(function(checkbox) {
    checkbox.addEventListener('change', function() {
        document.getElementById('filterForm').submit();
    });
});
</script>
@endsection
