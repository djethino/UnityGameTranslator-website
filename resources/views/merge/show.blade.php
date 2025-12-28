@extends('layouts.app')

@section('title', 'Merge - ' . $main->game->name)

@section('content')
<div class="container mx-auto px-4 py-8" x-data="mergeTable()">
    {{-- Header --}}
    <div class="mb-6">
        <div class="flex items-center gap-4 mb-2">
            <a href="{{ route('games.show', $main->game) }}" class="text-purple-400 hover:text-purple-300">
                <i class="fas fa-arrow-left"></i> {{ $main->game->name }}
            </a>
        </div>
        <h1 class="text-2xl font-bold text-white">Merge View</h1>
        <p class="text-gray-400">
            {{ $main->source_language }} <i class="fas fa-arrow-right text-xs"></i> {{ $main->target_language }}
            &bull; {{ $totalKeys }} cl&eacute;s
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
            <span class="text-sm text-gray-400 font-medium">Branches :</span>
            @foreach($branches as $branch)
            <label class="flex items-center gap-2 cursor-pointer hover:text-white transition">
                <input type="checkbox" name="branches[]" value="{{ $branch->id }}"
                    class="rounded bg-gray-700 border-gray-600 text-purple-600 focus:ring-purple-500"
                    {{ $selectedBranches->contains('id', $branch->id) ? 'checked' : '' }}>
                <span class="text-gray-300">{{ $branch->user->name }}</span>
                <span class="text-xs text-gray-500">({{ $branch->line_count }})</span>
            </label>
            @endforeach
            <button type="submit" class="bg-purple-600 hover:bg-purple-700 px-4 py-2 rounded-lg text-white text-sm transition">
                <i class="fas fa-filter mr-1"></i> Filtrer
            </button>
        </form>
    </div>
    @else
    <div class="mb-6 bg-gray-800 rounded-lg p-6 border border-gray-700 text-center">
        <i class="fas fa-code-branch text-4xl text-gray-600 mb-3"></i>
        <p class="text-gray-400">Aucune branch pour cette traduction.</p>
        <p class="text-sm text-gray-500 mt-2">Les branches appara&icirc;tront ici quand d'autres utilisateurs contribueront.</p>
    </div>
    @endif

    {{-- Filters --}}
    <form method="GET" id="filterForm" class="mb-4 flex flex-wrap gap-4 items-center text-sm">
        {{-- Preserve branch selection --}}
        @foreach($selectedBranches as $branch)
        <input type="hidden" name="branches[]" value="{{ $branch->id }}">
        @endforeach

        <span class="text-gray-500">Filtres :</span>

        <label class="flex items-center gap-2 cursor-pointer">
            <input type="checkbox" name="new_keys" value="1" {{ $filters['new_keys'] ? 'checked' : '' }}
                class="filter-checkbox rounded bg-gray-700 border-gray-600 text-green-600">
            <span class="text-gray-300">Nouvelles cl&eacute;s</span>
        </label>

        <label class="flex items-center gap-2 cursor-pointer">
            <input type="checkbox" name="difference" value="1" {{ $filters['difference'] ? 'checked' : '' }}
                class="filter-checkbox rounded bg-gray-700 border-gray-600 text-yellow-600">
            <span class="text-gray-300">Diff&eacute;rences</span>
        </label>

        <span class="text-gray-600">|</span>

        <label class="flex items-center gap-2 cursor-pointer">
            <input type="checkbox" name="human" value="1" {{ $filters['human'] ? 'checked' : '' }}
                class="filter-checkbox rounded bg-gray-700 border-gray-600 text-green-600">
            <span class="tag-H">H</span>
        </label>

        <label class="flex items-center gap-2 cursor-pointer">
            <input type="checkbox" name="ai" value="1" {{ $filters['ai'] ? 'checked' : '' }}
                class="filter-checkbox rounded bg-gray-700 border-gray-600 text-orange-600">
            <span class="tag-A">A</span>
        </label>

        <label class="flex items-center gap-2 cursor-pointer">
            <input type="checkbox" name="validated" value="1" {{ $filters['validated'] ? 'checked' : '' }}
                class="filter-checkbox rounded bg-gray-700 border-gray-600 text-blue-600">
            <span class="tag-V">V</span>
        </label>

        @if(array_filter($filters))
        <a href="{{ route('translations.merge', ['uuid' => $uuid, 'branches' => $selectedBranches->pluck('id')->toArray()]) }}"
            class="text-gray-400 hover:text-white text-xs">
            <i class="fas fa-times"></i> R&eacute;initialiser
        </a>
        @endif
    </form>

    {{-- Merge Form --}}
    <form method="POST" action="{{ route('translations.merge.apply', $uuid) }}" id="mergeForm">
        @csrf

        {{-- Table --}}
        <div class="overflow-x-auto bg-gray-800 rounded-lg border border-gray-700">
            <table class="w-full text-sm">
                <thead class="bg-gray-900 sticky top-0 z-10">
                    <tr>
                        <th class="px-4 py-3 text-left text-gray-400 font-medium w-64">Cl&eacute;</th>
                        <th class="px-4 py-3 text-left border-l border-gray-700 min-w-[300px]">
                            <div class="flex items-center gap-2">
                                <span class="text-green-400 font-medium">Main</span>
                                <span class="text-xs text-gray-500">({{ $main->user->name ?? 'Vous' }})</span>
                            </div>
                        </th>
                        @foreach($selectedBranches as $branch)
                        <th class="px-4 py-3 text-left border-l border-gray-700 min-w-[300px]">
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
                        <td class="px-4 py-2 font-mono text-xs text-gray-500 break-all" title="{{ $key }}">
                            {{ Str::limit($key, 40) }}
                        </td>

                        {{-- Main column --}}
                        <td class="px-4 py-2 border-l border-gray-700 merge-cell"
                            :class="getCellClass({{ $keyJson }}, 'main')"
                            @click="select({{ $keyJson }}, 'main', {{ json_encode($mainValue) }}, '{{ $mainTag }}')"
                            @dblclick="editCell({{ $keyJson }}, {{ json_encode($mainValue) }})">
                            @if($mainEntry !== null)
                            <div class="flex items-start gap-2">
                                <span class="tag-{{ $mainTag }} shrink-0">{{ $mainTag }}</span>
                                <span class="break-words" :class="isEdited({{ $keyJson }}) ? 'text-purple-300' : ''">
                                    <template x-if="isEdited({{ $keyJson }})">
                                        <span x-text="getEditedValue({{ $keyJson }})"></span>
                                    </template>
                                    <template x-if="!isEdited({{ $keyJson }})">
                                        <span>{{ Str::limit($mainValue, 80) }}</span>
                                    </template>
                                </span>
                            </div>
                            @else
                            <span class="text-gray-600 italic">- vide -</span>
                            @endif
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
                        <td class="px-4 py-2 border-l border-gray-700 merge-cell {{ $isDiff ? 'bg-yellow-900/20' : '' }} {{ $isNew ? 'bg-green-900/20' : '' }}"
                            :class="getCellClass({{ $keyJson }}, 'branch_{{ $branch->id }}')"
                            @click="select({{ $keyJson }}, 'branch_{{ $branch->id }}', {{ json_encode($branchValue) }}, '{{ $branchTag }}')">
                            @if($branchEntry !== null)
                            <div class="flex items-start gap-2">
                                <span class="tag-{{ $branchTag }} shrink-0">{{ $branchTag }}</span>
                                <span class="break-words {{ $isDiff ? 'text-yellow-300' : '' }} {{ $isNew ? 'text-green-300' : '' }}">
                                    {{ Str::limit($branchValue, 80) }}
                                </span>
                            </div>
                            @else
                            <span class="text-gray-600 italic">-</span>
                            @endif
                        </td>
                        @endforeach
                    </tr>
                    @empty
                    <tr>
                        <td colspan="{{ 2 + $selectedBranches->count() }}" class="px-4 py-12 text-center text-gray-500">
                            <i class="fas fa-search text-4xl mb-3 opacity-50"></i>
                            <p>Aucune cl&eacute; trouv&eacute;e avec ces filtres.</p>
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
                Page {{ $page }} / {{ $totalPages }}
                ({{ $totalKeys }} cl&eacute;s)
            </span>
            <div class="flex gap-2">
                @if($page > 1)
                <a href="?page={{ $page - 1 }}{{ $selectedBranches->isNotEmpty() ? '&branches[]=' . $selectedBranches->pluck('id')->implode('&branches[]=') : '' }}{{ array_filter($filters) ? '&' . http_build_query(array_filter($filters)) : '' }}"
                    class="px-4 py-2 bg-gray-700 hover:bg-gray-600 rounded-lg text-white transition">
                    <i class="fas fa-chevron-left mr-1"></i> Pr&eacute;c&eacute;dent
                </a>
                @endif
                @if($page < $totalPages)
                <a href="?page={{ $page + 1 }}{{ $selectedBranches->isNotEmpty() ? '&branches[]=' . $selectedBranches->pluck('id')->implode('&branches[]=') : '' }}{{ array_filter($filters) ? '&' . http_build_query(array_filter($filters)) : '' }}"
                    class="px-4 py-2 bg-gray-700 hover:bg-gray-600 rounded-lg text-white transition">
                    Suivant <i class="fas fa-chevron-right ml-1"></i>
                </a>
                @endif
            </div>
        </div>
        @endif

        {{-- Footer with Save button --}}
        <div class="mt-6 flex justify-between items-center bg-gray-800 rounded-lg p-4 border border-gray-700 sticky bottom-4">
            <div class="text-sm text-gray-400">
                <span x-show="selectionCount > 0">
                    <span class="text-white font-bold" x-text="selectionCount"></span> modification(s) s&eacute;lectionn&eacute;e(s)
                </span>
                <span x-show="selectionCount === 0" class="text-gray-500">
                    Cliquez sur une cellule pour s&eacute;lectionner une valeur. Double-cliquez pour &eacute;diter.
                </span>
            </div>
            <div class="flex gap-4 items-center">
                <button type="button" @click="clearSelections()" x-show="selectionCount > 0"
                    class="text-gray-400 hover:text-white text-sm transition">
                    <i class="fas fa-times mr-1"></i> Annuler
                </button>
                <button type="submit" :disabled="selectionCount === 0"
                    class="bg-green-600 hover:bg-green-700 disabled:bg-gray-600 disabled:cursor-not-allowed px-6 py-3 rounded-lg text-white font-bold transition">
                    <i class="fas fa-save mr-2"></i>
                    Sauvegarder (<span x-text="selectionCount">0</span>)
                </button>
            </div>
        </div>

        {{-- Hidden inputs container --}}
        <div id="selectionsContainer"></div>
    </form>

    {{-- Legend --}}
    <div class="mt-6 text-xs text-gray-500 flex flex-wrap gap-4">
        <span><span class="tag-H">H</span> Human - traduction manuelle</span>
        <span><span class="tag-A">A</span> AI - traduction IA</span>
        <span><span class="tag-V">V</span> Validated - IA valid&eacute;e par humain</span>
        <span class="text-gray-600">|</span>
        <span><span class="inline-block w-3 h-3 bg-green-900/50 rounded mr-1"></span> S&eacute;lection Main</span>
        <span><span class="inline-block w-3 h-3 bg-blue-900/50 rounded mr-1"></span> S&eacute;lection Branch</span>
        <span><span class="inline-block w-3 h-3 bg-purple-900/50 rounded mr-1"></span> &Eacute;dition manuelle</span>
        <span><span class="inline-block w-3 h-3 bg-yellow-900/30 rounded mr-1"></span> Diff&eacute;rence</span>
        <span><span class="inline-block w-3 h-3 bg-green-900/30 rounded mr-1"></span> Nouvelle cl&eacute;</span>
    </div>
</div>

@push('head')
<style>
    .tag-H { @apply bg-green-600 text-white px-1.5 py-0.5 rounded text-xs font-bold; }
    .tag-A { @apply bg-orange-600 text-white px-1.5 py-0.5 rounded text-xs font-bold; }
    .tag-V { @apply bg-blue-600 text-white px-1.5 py-0.5 rounded text-xs font-bold; }

    .merge-cell {
        @apply cursor-pointer transition-all duration-150;
    }
    .merge-cell:hover {
        @apply bg-gray-700/50;
    }

    .selected-main {
        @apply bg-green-900/50 ring-2 ring-green-500 ring-inset;
    }
    .selected-branch {
        @apply bg-blue-900/50 ring-2 ring-blue-500 ring-inset;
    }
    .selected-manual {
        @apply bg-purple-900/50 ring-2 ring-purple-500 ring-inset;
    }

    /* Prevent text selection when clicking cells */
    .merge-cell {
        user-select: none;
        -webkit-user-select: none;
    }
</style>
@endpush

@push('scripts')
<script nonce="{{ $cspNonce ?? '' }}">
// Filter checkboxes auto-submit
document.querySelectorAll('.filter-checkbox').forEach(function(checkbox) {
    checkbox.addEventListener('change', function() {
        this.form.submit();
    });
});

function mergeTable() {
    return {
        selections: {},

        get selectionCount() {
            return Object.keys(this.selections).length;
        },

        isSelected(key, source) {
            return this.selections[key]?.source === source;
        },

        isEdited(key) {
            return this.selections[key]?.source === 'manual';
        },

        getEditedValue(key) {
            return this.selections[key]?.value || '';
        },

        getCellClass(key, source) {
            const sel = this.selections[key];
            if (!sel) return '';
            if (sel.source === source) {
                if (source === 'main') return 'selected-main';
                if (sel.source === 'manual') return 'selected-manual';
                return 'selected-branch';
            }
            // If this key is selected but from a different source, show the edited value in main
            if (source === 'main' && sel.source === 'manual') {
                return 'selected-manual';
            }
            return '';
        },

        select(key, source, value, tag) {
            // Toggle if same selection
            if (this.selections[key]?.source === source && this.selections[key]?.source !== 'manual') {
                delete this.selections[key];
            } else {
                this.selections[key] = { source, value, tag };
            }
            this.updateHiddenInputs();
        },

        editCell(key, currentValue) {
            const existingValue = this.selections[key]?.value ?? currentValue;
            const newValue = prompt('Modifier la valeur :', existingValue);

            if (newValue !== null) {
                if (newValue === '') {
                    // Empty value = clear selection for this key
                    delete this.selections[key];
                } else {
                    this.selections[key] = {
                        source: 'manual',
                        value: newValue,
                        tag: 'H'
                    };
                }
                this.updateHiddenInputs();
            }
        },

        clearSelections() {
            if (confirm('Annuler toutes les selections ?')) {
                this.selections = {};
                this.updateHiddenInputs();
            }
        },

        updateHiddenInputs() {
            const container = document.getElementById('selectionsContainer');
            container.innerHTML = '';

            let i = 0;
            for (const [key, data] of Object.entries(this.selections)) {
                const keyInput = document.createElement('input');
                keyInput.type = 'hidden';
                keyInput.name = `selections[${i}][key]`;
                keyInput.value = key;

                const valueInput = document.createElement('input');
                valueInput.type = 'hidden';
                valueInput.name = `selections[${i}][value]`;
                valueInput.value = data.value;

                const tagInput = document.createElement('input');
                tagInput.type = 'hidden';
                tagInput.name = `selections[${i}][tag]`;
                tagInput.value = data.tag;

                const sourceInput = document.createElement('input');
                sourceInput.type = 'hidden';
                sourceInput.name = `selections[${i}][source]`;
                sourceInput.value = data.source;

                container.appendChild(keyInput);
                container.appendChild(valueInput);
                container.appendChild(tagInput);
                container.appendChild(sourceInput);

                i++;
            }
        }
    }
}
</script>
@endpush
@endsection
