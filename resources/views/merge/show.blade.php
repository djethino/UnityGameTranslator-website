@extends('layouts.app')

@section('title', __('merge.title') . ' - ' . $main->game->name)

@section('content')
<div class="container mx-auto px-4 py-8" x-data="mergeTable('{{ $uuid }}')">
    {{-- Header --}}
    <div class="mb-6">
        <div class="flex items-center gap-4 mb-2">
            <a href="{{ route('games.show', $main->game) }}" class="text-purple-400 hover:text-purple-300">
                <i class="fas fa-arrow-left"></i> {{ $main->game->name }}
            </a>
        </div>
        <h1 class="text-2xl font-bold text-white">{{ __('merge.heading') }}</h1>
        <p class="text-gray-400">
            {{ $main->source_language }} <i class="fas fa-arrow-right text-xs"></i> {{ $main->target_language }}
            &bull; {{ __('merge.keys_count', ['count' => $totalKeys]) }}
        </p>
    </div>

    {{-- Success message --}}
    @if(session('success'))
    <div class="mb-6 bg-green-900/50 border border-green-600 rounded-lg p-4 text-green-300">
        <i class="fas fa-check-circle mr-2"></i> {{ session('success') }}
    </div>
    @endif

    {{-- Error messages --}}
    @if($errors->any())
    <div class="mb-6 bg-red-900/50 border border-red-600 rounded-lg p-4 text-red-300">
        <ul class="list-disc list-inside">
            @foreach($errors->all() as $error)
            <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
    @endif

    {{-- Branch Selection --}}
    @if($branches->isNotEmpty())
    <div class="mb-6 bg-gray-800 rounded-lg p-4 border border-gray-700">
        <form method="GET" class="flex flex-wrap gap-4 items-center">
            <span class="text-sm text-gray-400 font-medium">{{ __('merge.branches') }}</span>
            @foreach($branches as $branch)
            <div class="flex items-center gap-2 px-2 py-1 rounded bg-gray-700/50 border border-gray-600">
                <label class="flex items-center gap-2 cursor-pointer hover:text-white transition">
                    <input type="checkbox" name="branches[]" value="{{ $branch->id }}"
                        class="rounded bg-gray-700 border-gray-600 text-purple-600 focus:ring-purple-500"
                        {{ $selectedBranches->contains('id', $branch->id) ? 'checked' : '' }}>
                    <span class="text-gray-300">{{ $branch->user->name }}</span>
                    <span class="text-xs text-gray-500">({{ $branch->line_count }})</span>
                </label>
                {{-- Rating Stars --}}
                <div class="flex items-center gap-1 ml-2 branch-rating" data-branch-id="{{ $branch->id }}">
                    @for($i = 1; $i <= 5; $i++)
                    <button type="button"
                        class="rating-star text-sm {{ $branch->main_rating >= $i ? 'text-yellow-400' : 'text-gray-600' }} hover:text-yellow-300 transition"
                        data-rating="{{ $i }}"
                        title="{{ __('rating.rate_branch', ['stars' => $i]) }}">
                        <i class="fas fa-star"></i>
                    </button>
                    @endfor
                    @if($branch->wasModifiedSinceReview())
                    <span class="ml-1 text-xs text-orange-400" title="{{ __('rating.modified_since_review') }}">
                        <i class="fas fa-exclamation-circle"></i>
                    </span>
                    @endif
                </div>
            </div>
            @endforeach
            <button type="submit" class="bg-purple-600 hover:bg-purple-700 px-4 py-2 rounded-lg text-white text-sm transition">
                <i class="fas fa-filter mr-1"></i> {{ __('merge.filter') }}
            </button>
        </form>
    </div>
    @else
    <div class="mb-6 bg-gray-800 rounded-lg p-4 border border-gray-700">
        <p class="text-gray-400 text-sm">
            <i class="fas fa-info-circle mr-2 text-blue-400"></i>
            {{ __('merge.no_branches') }}
        </p>
    </div>
    @endif

    {{-- Filters --}}
    <form method="GET" id="filterForm" class="mb-4 flex flex-wrap gap-4 items-center text-sm">
        {{-- Preserve branch selection --}}
        @foreach($selectedBranches as $branch)
        <input type="hidden" name="branches[]" value="{{ $branch->id }}">
        @endforeach

        <span class="text-gray-500">{{ __('merge.filters') }}</span>

        <label class="flex items-center gap-2 cursor-pointer">
            <input type="checkbox" name="new_keys" value="1" {{ $filters['new_keys'] ? 'checked' : '' }}
                class="filter-checkbox rounded bg-gray-700 border-gray-600 text-green-600">
            <span class="text-gray-300">{{ __('merge.filter_new_keys') }}</span>
        </label>

        <label class="flex items-center gap-2 cursor-pointer">
            <input type="checkbox" name="difference" value="1" {{ $filters['difference'] ? 'checked' : '' }}
                class="filter-checkbox rounded bg-gray-700 border-gray-600 text-yellow-600">
            <span class="text-gray-300">{{ __('merge.filter_differences') }}</span>
        </label>

        <span class="text-gray-600">|</span>

        {{-- Tag filters in HVASM order --}}
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
        <a href="{{ route('translations.merge', ['uuid' => $uuid, 'branches' => $selectedBranches->pluck('id')->toArray()]) }}"
            class="text-gray-400 hover:text-white text-xs">
            <i class="fas fa-times"></i> {{ __('merge.reset_filters') }}
        </a>
        @endif
    </form>

    {{-- Search --}}
    <div class="mb-4">
        <form method="GET" class="relative">
            {{-- Preserve existing params --}}
            @foreach($selectedBranches as $branch)
            <input type="hidden" name="branches[]" value="{{ $branch->id }}">
            @endforeach
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
            <a href="{{ route('translations.merge', array_merge(
                ['uuid' => $uuid],
                $selectedBranches->isNotEmpty() ? ['branches' => $selectedBranches->pluck('id')->toArray()] : [],
                array_filter($filters),
                request('sort') ? ['sort' => request('sort'), 'dir' => request('dir')] : []
            )) }}" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-500 hover:text-white">
                <i class="fas fa-times"></i>
            </a>
            @endif
        </form>
    </div>

    {{-- Merge Form --}}
    <form method="POST" action="{{ route('translations.merge.apply', $uuid) }}" id="mergeForm">
        @csrf

        {{-- Table --}}
        <div class="overflow-x-auto bg-gray-800 rounded-lg border border-gray-700">
            <table class="w-full text-sm">
                @php
                    $currentSort = request('sort', 'key');
                    $currentDir = request('dir', 'asc');
                    $sortParams = array_merge(
                        ['uuid' => $uuid],
                        $selectedBranches->isNotEmpty() ? ['branches' => $selectedBranches->pluck('id')->toArray()] : [],
                        array_filter($filters),
                        request('search') ? ['search' => request('search')] : []
                    );
                @endphp
                <thead class="bg-gray-900 sticky top-0 z-10">
                    <tr>
                        {{-- Key column with sort --}}
                        <th class="px-4 py-3 text-left text-gray-400 font-medium">
                            <a href="{{ route('translations.merge', array_merge($sortParams, ['sort' => 'key', 'dir' => ($currentSort === 'key' && $currentDir === 'asc') ? 'desc' : 'asc'])) }}"
                                class="flex items-center gap-2 hover:text-white transition">
                                {{ __('merge.key') }}
                                <i class="fas {{ $currentSort === 'key' ? ($currentDir === 'asc' ? 'fa-sort-up text-purple-400' : 'fa-sort-down text-purple-400') : 'fa-sort text-gray-600' }}"></i>
                            </a>
                        </th>
                        {{-- Main Tag column --}}
                        <th class="px-2 py-3 text-center border-l border-gray-700 w-12">
                            <a href="{{ route('translations.merge', array_merge($sortParams, ['sort' => 'mainTag', 'dir' => ($currentSort === 'mainTag' && $currentDir === 'asc') ? 'desc' : 'asc'])) }}"
                                class="flex items-center justify-center gap-1 hover:text-white transition">
                                <span class="text-green-400 font-medium text-xs">Tag</span>
                                <i class="fas text-xs {{ $currentSort === 'mainTag' ? ($currentDir === 'asc' ? 'fa-sort-up text-purple-400' : 'fa-sort-down text-purple-400') : 'fa-sort text-gray-600' }}"></i>
                            </a>
                        </th>
                        {{-- Main Value column --}}
                        <th class="px-4 py-3 text-left border-l border-gray-700 min-w-[250px]">
                            <a href="{{ route('translations.merge', array_merge($sortParams, ['sort' => 'mainValue', 'dir' => ($currentSort === 'mainValue' && $currentDir === 'asc') ? 'desc' : 'asc'])) }}"
                                class="flex items-center gap-2 hover:text-white transition">
                                <span class="text-green-400 font-medium">Main</span>
                                <span class="text-xs text-gray-500">({{ $main->user->name ?? __('common.you') }})</span>
                                <i class="fas {{ $currentSort === 'mainValue' ? ($currentDir === 'asc' ? 'fa-sort-up text-purple-400' : 'fa-sort-down text-purple-400') : 'fa-sort text-gray-600' }}"></i>
                            </a>
                        </th>
                        @foreach($selectedBranches as $branch)
                        {{-- Branch Tag column --}}
                        <th class="px-2 py-3 text-center border-l border-gray-700 w-12">
                            <span class="text-blue-400 font-medium text-xs">Tag</span>
                        </th>
                        {{-- Branch Value column --}}
                        <th class="px-4 py-3 text-left border-l border-gray-700 min-w-[250px]">
                            <div class="flex items-center gap-2">
                                <span class="text-blue-400 font-medium">{{ $branch->user->name }}</span>
                                <span class="text-xs text-gray-500">
                                    ({{ $branch->human_count }}H / {{ $branch->validated_count }}V / {{ $branch->ai_count }}A)
                                </span>
                            </div>
                        </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse($pagedKeys as $key)
                    @php
                        $mainEntry = $mainContent[$key] ?? null;
                        $mainValue = is_array($mainEntry) ? ($mainEntry['v'] ?? '') : ($mainEntry ?? '');
                        $mainTag = is_array($mainEntry) ? ($mainEntry['t'] ?? 'A') : 'A';
                        $keyEscaped = e($key);
                        $keyJson = json_encode($key);
                    @endphp
                    <tr class="border-t border-gray-700 hover:bg-gray-750 transition-colors">
                        {{-- Key column --}}
                        <td class="px-4 py-2 font-mono text-xs text-gray-500 break-words">
                            <div class="flex items-center gap-2">
                                @if($mainEntry !== null)
                                <button type="button"
                                    @click="toggleDelete({{ $keyJson }})"
                                    :class="isDeleted({{ $keyJson }}) ? 'text-red-500' : 'text-gray-600 hover:text-red-400'"
                                    class="transition shrink-0"
                                    title="{{ __('merge.delete_key') }}">
                                    <i class="fas fa-trash-alt text-xs"></i>
                                </button>
                                @endif
                                <span :class="isDeleted({{ $keyJson }}) ? 'line-through text-red-400' : ''">{{ $key }}</span>
                            </div>
                        </td>

                        {{-- Main Tag column --}}
                        <td class="px-2 py-2 text-center border-l border-gray-700 merge-cell"
                            :class="[getCellClass({{ $keyJson }}, 'main'), isDeleted({{ $keyJson }}) ? 'deleted-cell' : '']"
                            @click="!isDeleted({{ $keyJson }}) && select({{ $keyJson }}, 'main', {{ json_encode($mainValue) }}, '{{ $mainTag }}')">
                            <span :class="isDeleted({{ $keyJson }}) ? 'opacity-40' : ''">
                                <span x-show="!isEdited({{ $keyJson }})" class="tag-{{ $mainTag }}">{{ $mainTag }}</span>
                                <span x-show="isEdited({{ $keyJson }})" class="tag-H">H</span>
                            </span>
                        </td>

                        {{-- Main Value column --}}
                        <td class="px-4 py-2 border-l border-gray-700 merge-cell"
                            :class="[getCellClass({{ $keyJson }}, 'main'), isDeleted({{ $keyJson }}) ? 'deleted-cell' : '']"
                            @click="!isDeleted({{ $keyJson }}) && select({{ $keyJson }}, 'main', {{ json_encode($mainValue) }}, '{{ $mainTag }}')"
                            @dblclick="!isDeleted({{ $keyJson }}) && editCell({{ $keyJson }}, {{ json_encode($mainValue) }})">
                            <span class="break-words" :class="[isEdited({{ $keyJson }}) ? 'text-purple-300' : '', isDeleted({{ $keyJson }}) ? 'line-through opacity-40' : '']">
                                <span x-show="isEdited({{ $keyJson }})" x-text="getEditedValue({{ $keyJson }})"></span>
                                <span x-show="!isEdited({{ $keyJson }})">{{ $mainValue !== '' ? $mainValue : __('merge.empty_value') }}</span>
                            </span>
                        </td>

                        {{-- Branch columns --}}
                        @foreach($selectedBranches as $branch)
                        @php
                            $branchEntry = $branchContents[$branch->id][$key] ?? null;
                            $branchValue = is_array($branchEntry) ? ($branchEntry['v'] ?? '') : ($branchEntry ?? '');
                            $branchTag = is_array($branchEntry) ? ($branchEntry['t'] ?? 'A') : 'A';
                            $isDiff = $branchValue !== $mainValue && $branchEntry !== null;
                            $isNew = $mainEntry === null && $branchEntry !== null;
                        @endphp
                        {{-- Branch Tag column --}}
                        <td class="px-2 py-2 text-center border-l border-gray-700 merge-cell {{ $isDiff ? 'bg-yellow-900/20' : '' }} {{ $isNew ? 'bg-green-900/20' : '' }}"
                            :class="getCellClass({{ $keyJson }}, 'branch_{{ $branch->id }}')"
                            @click="select({{ $keyJson }}, 'branch_{{ $branch->id }}', {{ json_encode($branchValue) }}, '{{ $branchTag }}')">
                            @if($branchEntry !== null)
                            <span class="tag-{{ $branchTag }}">{{ $branchTag }}</span>
                            @else
                            <span class="text-gray-600">—</span>
                            @endif
                        </td>
                        {{-- Branch Value column --}}
                        <td class="px-4 py-2 border-l border-gray-700 merge-cell {{ $isDiff ? 'bg-yellow-900/20' : '' }} {{ $isNew ? 'bg-green-900/20' : '' }}"
                            :class="getCellClass({{ $keyJson }}, 'branch_{{ $branch->id }}')"
                            @click="select({{ $keyJson }}, 'branch_{{ $branch->id }}', {{ json_encode($branchValue) }}, '{{ $branchTag }}')">
                            @if($branchEntry !== null)
                            <span class="break-words {{ $isDiff ? 'text-yellow-300' : '' }} {{ $isNew ? 'text-green-300' : '' }}">
                                {{ $branchValue }}
                            </span>
                            @else
                            <span class="text-gray-600 italic">—</span>
                            @endif
                        </td>
                        @endforeach
                    </tr>
                    @empty
                    <tr>
                        <td colspan="{{ 3 + ($selectedBranches->count() * 2) }}" class="px-4 py-12 text-center text-gray-500">
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
        <div class="mt-4 flex justify-between items-center">
            <span class="text-gray-400 text-sm">
                {{ __('merge.page_info', ['page' => $page, 'total' => $totalPages, 'keys' => $totalKeys]) }}
            </span>
            <div class="flex gap-2">
                @if($page > 1)
                <a href="?page={{ $page - 1 }}{{ $selectedBranches->isNotEmpty() ? '&branches[]=' . $selectedBranches->pluck('id')->implode('&branches[]=') : '' }}{{ array_filter($filters) ? '&' . http_build_query(array_filter($filters)) : '' }}"
                    class="px-4 py-2 bg-gray-700 hover:bg-gray-600 rounded-lg text-white transition">
                    <i class="fas fa-chevron-left mr-1"></i> {{ __('common.previous') }}
                </a>
                @endif
                @if($page < $totalPages)
                <a href="?page={{ $page + 1 }}{{ $selectedBranches->isNotEmpty() ? '&branches[]=' . $selectedBranches->pluck('id')->implode('&branches[]=') : '' }}{{ array_filter($filters) ? '&' . http_build_query(array_filter($filters)) : '' }}"
                    class="px-4 py-2 bg-gray-700 hover:bg-gray-600 rounded-lg text-white transition">
                    {{ __('common.next') }} <i class="fas fa-chevron-right ml-1"></i>
                </a>
                @endif
            </div>
        </div>
        @endif

        {{-- Footer with Save button --}}
        <div class="mt-6 flex justify-between items-center bg-gray-800 rounded-lg p-4 border border-gray-700 sticky bottom-4">
            <div class="text-sm text-gray-400">
                <span x-show="totalChanges > 0">
                    <span x-show="selectionCount > 0">
                        <span class="text-white font-bold" x-text="selectionCount"></span> {{ __('merge.modifications') }}
                    </span>
                    <span x-show="selectionCount > 0 && deleteCount > 0"> &bull; </span>
                    <span x-show="deleteCount > 0">
                        <span class="text-red-400 font-bold" x-text="deleteCount"></span> {{ __('merge.deletions') }}
                    </span>
                </span>
                <span x-show="totalChanges === 0" class="text-gray-500">
                    {{ __('merge.instructions') }}
                </span>
            </div>
            <div class="flex gap-4 items-center">
                <button type="button" @click="clearAll()" x-show="totalChanges > 0"
                    class="text-gray-400 hover:text-white text-sm transition">
                    <i class="fas fa-times mr-1"></i> {{ __('merge.cancel_all') }}
                </button>
                <button type="submit" :disabled="totalChanges === 0"
                    class="bg-green-600 hover:bg-green-700 disabled:bg-gray-600 disabled:cursor-not-allowed px-6 py-3 rounded-lg text-white font-bold transition">
                    <i class="fas fa-save mr-2"></i>
                    {{ __('common.save') }} (<span x-text="totalChanges">0</span>)
                </button>
            </div>
        </div>

        {{-- Hidden inputs container --}}
        <div id="selectionsContainer"></div>
        <div id="deletionsContainer"></div>
    </form>

    {{-- Legend (HVASM order) --}}
    <div class="mt-6 text-xs text-gray-500 flex flex-wrap gap-4">
        <span><span class="tag-H">H</span> {{ __('merge.legend_human') }}</span>
        <span><span class="tag-V">V</span> {{ __('merge.legend_validated') }}</span>
        <span><span class="tag-A">A</span> {{ __('merge.legend_ai') }}</span>
        <span><span class="tag-S">S</span> {{ __('merge.legend_skipped') }}</span>
        <span><span class="tag-M">M</span> {{ __('merge.legend_mod_ui') }}</span>
        <span class="text-gray-600">|</span>
        <span><span class="inline-block w-3 h-3 bg-green-900/50 rounded mr-1"></span> {{ __('merge.legend_selection_main') }}</span>
        <span><span class="inline-block w-3 h-3 bg-blue-900/50 rounded mr-1"></span> {{ __('merge.legend_selection_branch') }}</span>
        <span><span class="inline-block w-3 h-3 bg-purple-900/50 rounded mr-1"></span> {{ __('merge.legend_manual_edit') }}</span>
        <span><span class="inline-block w-3 h-3 bg-yellow-900/30 rounded mr-1"></span> {{ __('merge.legend_difference') }}</span>
        <span><span class="inline-block w-3 h-3 bg-green-900/30 rounded mr-1"></span> {{ __('merge.legend_new_key') }}</span>
        <span><span class="inline-block w-3 h-3 bg-red-900/50 rounded mr-1"></span> {{ __('merge.legend_deletion') }}</span>
    </div>

    {{-- Edit Modal --}}
    <div x-show="editModal.open" x-cloak
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/70"
        @click.self="closeEditModal()">
        <div class="bg-gray-800 rounded-lg shadow-xl border border-gray-700 w-full max-w-2xl mx-4"
            @keydown.ctrl.enter="saveEditModal()">
            {{-- Modal Header --}}
            <div class="px-6 py-4 border-b border-gray-700">
                <h3 class="text-lg font-semibold text-white">{{ __('merge.edit_translation') }}</h3>
                <p class="text-sm text-gray-400 font-mono mt-1 break-words" x-text="editModal.key"></p>
            </div>

            {{-- Modal Body --}}
            <div class="px-6 py-4">
                <textarea
                    id="editModalTextarea"
                    x-model="editModal.value"
                    class="w-full h-48 px-4 py-3 bg-gray-900 border border-gray-600 rounded-lg text-white placeholder-gray-500 focus:border-purple-500 focus:ring-1 focus:ring-purple-500 resize-y"
                    placeholder="{{ __('merge.enter_translation') }}"
                ></textarea>
                <p class="mt-2 text-xs text-gray-500">
                    <kbd class="px-1.5 py-0.5 bg-gray-700 rounded text-gray-300">Ctrl+Enter</kbd> {{ __('merge.save_shortcut') }} &bull;
                    <kbd class="px-1.5 py-0.5 bg-gray-700 rounded text-gray-300">Esc</kbd> {{ __('merge.cancel_shortcut') }}
                </p>
            </div>

            {{-- Modal Footer --}}
            <div class="px-6 py-4 border-t border-gray-700 flex justify-end gap-3">
                <button type="button" @click="closeEditModal()"
                    class="px-4 py-2 text-gray-400 hover:text-white transition">
                    {{ __('common.cancel') }}
                </button>
                <button type="button" @click="saveEditModal()"
                    class="px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-lg transition">
                    <i class="fas fa-check mr-1"></i> {{ __('common.save') }}
                </button>
            </div>
        </div>
    </div>
</div>

@push('head')
<style>
    /* Hide elements with x-cloak until Alpine initializes */
    [x-cloak] { display: none !important; }

    /* Tag badges - native CSS (no @apply for runtime styles) */
    .tag-H {
        background-color: rgb(22 163 74); /* green-600 */
        color: white;
        padding: 0.125rem 0.375rem;
        border-radius: 0.25rem;
        font-size: 0.75rem;
        font-weight: 700;
    }
    .tag-A {
        background-color: rgb(234 88 12); /* orange-600 */
        color: white;
        padding: 0.125rem 0.375rem;
        border-radius: 0.25rem;
        font-size: 0.75rem;
        font-weight: 700;
    }
    .tag-V {
        background-color: rgb(37 99 235); /* blue-600 */
        color: white;
        padding: 0.125rem 0.375rem;
        border-radius: 0.25rem;
        font-size: 0.75rem;
        font-weight: 700;
    }
    .tag-M {
        background-color: rgb(147 51 234); /* purple-600 */
        color: white;
        padding: 0.125rem 0.375rem;
        border-radius: 0.25rem;
        font-size: 0.75rem;
        font-weight: 700;
    }
    .tag-S {
        background-color: rgb(75 85 99); /* gray-600 */
        color: white;
        padding: 0.125rem 0.375rem;
        border-radius: 0.25rem;
        font-size: 0.75rem;
        font-weight: 700;
    }

    /* Merge cell interactivity */
    .merge-cell {
        cursor: pointer;
        transition: all 150ms;
        user-select: none;
        -webkit-user-select: none;
    }
    .merge-cell:hover {
        background-color: rgba(55, 65, 81, 0.5); /* gray-700/50 */
    }

    /* Selection states - visible feedback when clicked */
    .selected-main {
        background-color: rgba(20, 83, 45, 0.5) !important; /* green-900/50 */
        box-shadow: inset 0 0 0 2px rgb(34 197 94); /* ring-2 ring-green-500 */
    }
    .selected-branch {
        background-color: rgba(30, 58, 138, 0.5) !important; /* blue-900/50 */
        box-shadow: inset 0 0 0 2px rgb(59 130 246); /* ring-2 ring-blue-500 */
    }
    .selected-manual {
        background-color: rgba(88, 28, 135, 0.5) !important; /* purple-900/50 */
        box-shadow: inset 0 0 0 2px rgb(168 85 247); /* ring-2 ring-purple-500 */
    }
    .deleted-cell {
        background-color: rgba(127, 29, 29, 0.5) !important; /* red-900/50 */
        box-shadow: inset 0 0 0 2px rgb(239 68 68); /* ring-2 ring-red-500 */
        cursor: not-allowed;
    }
</style>
@endpush

{{-- JavaScript component is in resources/js/components/merge-table.js --}}
@endsection
