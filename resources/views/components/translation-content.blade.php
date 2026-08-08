@props([
    'translation',
    'jsonContent',
    'pagedKeys',
    'filters',
    'page',
    'totalPages',
    'totalKeys',
    // Route this screen lives on, so filters, search and paging link back to themselves
    'route',
])

@php
    use App\Support\TranslationContentReader as Reader;

    // Every link on this screen has to carry the state the others set, or filtering would
    // silently drop your search and sorting would drop your filters.
    $baseParams = array_merge(
        ['translation' => $translation->id],
        array_filter($filters),
        request('search') ? ['search' => request('search')] : []
    );
    $sortParams = array_merge($baseParams, request('sort') ? ['sort' => request('sort'), 'dir' => request('dir')] : []);
    $currentSort = request('sort', 'key');
    $currentDir = request('dir', 'asc');
    $sortIcon = fn ($column) => $currentSort === $column
        ? ($currentDir === 'asc' ? 'fa-sort-up text-purple-400' : 'fa-sort-down text-purple-400')
        : 'fa-sort text-gray-600';
    $sortLink = fn ($column) => route($route, array_merge($baseParams, [
        'sort' => $column,
        'dir' => ($currentSort === $column && $currentDir === 'asc') ? 'desc' : 'asc',
    ]));
@endphp

{{--
    Reading a translation's lines: filters, search, sort, pagination.

    Shared by the admin inspection screen and the public read-only view, because they show the
    same thing from the same file and only differ in who may reach them. Two copies would have
    drifted the day one of them gained a column.

    Server-rendered on purpose, unlike the three editors: nothing here is edited, so there is no
    pending state to hold, and a page of a hundred rows costs nothing to send. Only the
    line-break switch is client-side, and it is the same preference as everywhere else.
--}}
@if($jsonContent)
    <div x-data="editorTextMode">
        {{-- Tag filters --}}
        <form method="GET" id="filterForm" class="mb-4 flex flex-wrap gap-3 items-center text-sm">
            @if(request('search'))<input type="hidden" name="search" value="{{ request('search') }}">@endif
            @if(request('sort'))<input type="hidden" name="sort" value="{{ request('sort') }}">@endif
            @if(request('dir'))<input type="hidden" name="dir" value="{{ request('dir') }}">@endif

            <span class="text-gray-500">{{ __('merge.filters') }}</span>

            @foreach([
                ['human', 'H', 'text-green-600', __('merge.legend_human')],
                ['validated', 'V', 'text-blue-600', __('merge.legend_validated')],
                ['ai', 'A', 'text-orange-600', __('merge.legend_ai')],
                ['skipped', 'S', 'text-gray-600', __('merge.legend_skipped')],
                ['mod_ui', 'M', 'text-purple-600', __('merge.legend_mod_ui')],
            ] as [$name, $tag, $colour, $legend])
                <label class="flex items-center gap-2 cursor-pointer" title="{{ $legend }}">
                    <input type="checkbox" name="{{ $name }}" value="1" {{ $filters[$name] ? 'checked' : '' }}
                        class="filter-checkbox rounded bg-gray-700 border-gray-600 {{ $colour }}">
                    <span class="tag-{{ $tag }}">{{ $tag }}</span>
                </label>
            @endforeach

            @if(array_filter($filters))
                <a href="{{ route($route, $translation) }}" class="text-gray-400 hover:text-white text-xs">
                    <i class="fas fa-times"></i> {{ __('merge.reset_filters') }}
                </a>
            @endif

            {{-- Same switch, same preference, as the editors and the admin screen --}}
            <label class="flex items-center gap-2 cursor-pointer ml-auto" title="{{ __('editor.line_breaks_hint') }}">
                <input type="checkbox" :checked="showLineBreaks" @change="toggleLineBreaks()"
                    class="rounded bg-gray-700 border-gray-600 text-gray-500">
                <span class="text-gray-400"><i class="fas fa-paragraph mr-1"></i>{{ __('editor.line_breaks') }}</span>
            </label>
        </form>

        {{-- Search --}}
        <div class="mb-4">
            <form method="GET" class="relative">
                @foreach(array_filter($filters) as $filterKey => $filterValue)
                    <input type="hidden" name="{{ $filterKey }}" value="{{ $filterValue }}">
                @endforeach
                @if(request('sort'))<input type="hidden" name="sort" value="{{ request('sort') }}">@endif
                @if(request('dir'))<input type="hidden" name="dir" value="{{ request('dir') }}">@endif

                <input type="text" name="search" value="{{ request('search') }}"
                    placeholder="{{ __('merge.search_placeholder') }}"
                    class="w-full px-4 py-2 pl-10 bg-gray-800 border border-gray-700 rounded-lg text-white placeholder-gray-500 focus:border-purple-500 focus:ring-1 focus:ring-purple-500">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-500"></i>
                @if(request('search'))
                    <a href="{{ route($route, $sortParams) }}"
                        class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-500 hover:text-white">
                        <i class="fas fa-times"></i>
                    </a>
                @endif
            </form>
        </div>

        <div class="bg-gray-800 rounded-lg border border-gray-700">
            <div class="p-4 border-b border-gray-700 flex justify-between items-center gap-4">
                <h3 class="text-lg font-semibold"><i class="fas fa-code mr-2"></i> {{ $slot->isNotEmpty() ? $slot : __('admin.translation_content') }}</h3>
                <span class="text-sm text-gray-400">{{ number_format($totalKeys) }} {{ __('admin.translation_entries') }}</span>
            </div>

            <div class="overflow-x-auto max-h-[600px] overflow-y-auto">
                <table class="w-full text-sm" :class="showLineBreaks && 'show-linebreaks'">
                    <thead class="bg-gray-900 sticky top-0 z-10">
                        <tr>
                            <th class="px-4 py-3 text-left text-gray-400 font-medium w-1/2">
                                <a href="{{ $sortLink('key') }}" class="flex items-center gap-2 hover:text-white transition">
                                    {{ __('admin.original') }}
                                    <i class="fas {{ $sortIcon('key') }}"></i>
                                </a>
                            </th>
                            <th class="px-2 py-3 text-center w-12">
                                <a href="{{ $sortLink('tag') }}" class="flex items-center justify-center gap-1 hover:text-white transition">
                                    <span class="text-gray-400 font-medium text-xs">Tag</span>
                                    <i class="fas text-xs {{ $sortIcon('tag') }}"></i>
                                </a>
                            </th>
                            <th class="px-4 py-3 text-left text-gray-400 font-medium w-1/2">
                                <a href="{{ $sortLink('value') }}" class="flex items-center gap-2 hover:text-white transition">
                                    {{ __('admin.translated') }}
                                    <i class="fas {{ $sortIcon('value') }}"></i>
                                </a>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($pagedKeys as $key)
                            @php
                                $value = Reader::valueOf($jsonContent, $key);
                                $tag = Reader::tagOf($jsonContent, $key);
                            @endphp
                            <tr class="border-t border-gray-700 hover:bg-gray-750">
                                <td class="px-4 py-2 text-gray-300 break-words align-top">
                                    <span class="editor-text font-mono text-xs">{{ Str::limit($key, 150) }}</span>
                                </td>
                                <td class="px-2 py-2 text-center align-top">
                                    <span class="tag-{{ $tag }}">{{ $tag }}</span>
                                </td>
                                <td class="px-4 py-2 text-white break-words align-top">
                                    {{-- An empty value is a captured line, not a translated one:
                                         saying so is the difference between "nothing here" and
                                         "work still to do". --}}
                                    @if($value === '')
                                        <span class="text-gray-600 italic">{{ __('progress.capture') }}</span>
                                    @else
                                        <span class="editor-text">{{ Str::limit($value, 150) }}</span>
                                    @endif
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

            @if($totalPages > 1)
                <div class="p-4 border-t border-gray-700 flex justify-between items-center">
                    <span class="text-gray-400 text-sm">
                        {{ __('merge.page_info', ['page' => $page, 'total' => $totalPages, 'keys' => $totalKeys]) }}
                    </span>
                    <div class="flex gap-2">
                        @if($page > 1)
                            <a href="{{ route($route, array_merge($sortParams, ['page' => $page - 1])) }}"
                                class="px-4 py-2 bg-gray-700 hover:bg-gray-600 rounded-lg text-white transition">
                                <i class="fas fa-chevron-left mr-1"></i> {{ __('common.previous') }}
                            </a>
                        @endif
                        @if($page < $totalPages)
                            <a href="{{ route($route, array_merge($sortParams, ['page' => $page + 1])) }}"
                                class="px-4 py-2 bg-gray-700 hover:bg-gray-600 rounded-lg text-white transition">
                                {{ __('common.next') }} <i class="fas fa-chevron-right ml-1"></i>
                            </a>
                        @endif
                    </div>
                </div>
            @endif
        </div>

        {{-- What the letters mean. Without it a column of H/V/A/S/M is a code nobody was given. --}}
        <div class="mt-4 text-xs text-gray-500 flex flex-wrap gap-4">
            <span><span class="tag-H">H</span> {{ __('merge.legend_human') }}</span>
            <span><span class="tag-V">V</span> {{ __('merge.legend_validated') }}</span>
            <span><span class="tag-A">A</span> {{ __('merge.legend_ai') }}</span>
            <span><span class="tag-S">S</span> {{ __('merge.legend_skipped') }}</span>
            <span><span class="tag-M">M</span> {{ __('merge.legend_mod_ui') }}</span>
        </div>
    </div>
@else
    <div class="bg-gray-800 rounded-lg p-6 border border-gray-700 text-center text-gray-500">
        <i class="fas fa-exclamation-triangle text-2xl mb-2"></i>
        <p>{{ __('translation.content_unavailable') }}</p>
    </div>
@endif
