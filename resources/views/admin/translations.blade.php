@extends('layouts.app')

@section('title', __('admin.translations') . ' - Admin - UnityGameTranslator')

@section('content')
<div class="mb-6 flex justify-between items-center">
    <h1 class="text-3xl font-bold"><i class="fas fa-file-alt mr-2"></i> {{ __('admin.translations') }}</h1>
    <a href="{{ route('admin.dashboard') }}" class="text-gray-400 hover:text-white">
        <i class="fas fa-arrow-left mr-1"></i> {{ __('common.back') }}
    </a>
</div>

<!-- Filters -->
<div class="bg-gray-800 rounded-lg p-4 border border-gray-700 mb-6">
    <form action="{{ route('admin.translations.index') }}" method="GET" class="flex flex-wrap gap-4 items-end">
        <div class="flex-1 min-w-[200px]">
            <label class="block text-sm text-gray-400 mb-1">{{ __('common.search') }}</label>
            <input type="text" name="search" value="{{ request('search') }}"
                placeholder="{{ __('admin.search_translations') }}"
                class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:ring-purple-500 focus:border-purple-500">
        </div>
        <div class="min-w-[150px]">
            <label class="block text-sm text-gray-400 mb-1">{{ __('admin.game') }}</label>
            <select name="game_id" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:ring-purple-500 focus:border-purple-500">
                <option value="">{{ __('common.all') }}</option>
                @foreach($games as $game)
                    <option value="{{ $game->id }}" {{ request('game_id') == $game->id ? 'selected' : '' }}>{{ $game->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="min-w-[150px]">
            <label class="block text-sm text-gray-400 mb-1">{{ __('games.target_language') }}</label>
            <select name="language" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:ring-purple-500 focus:border-purple-500">
                <option value="">{{ __('common.all') }}</option>
                @foreach($languages as $lang)
                    <option value="{{ $lang }}" {{ request('language') == $lang ? 'selected' : '' }}>@langflag($lang) {{ $lang }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded-lg">
            <i class="fas fa-search mr-1"></i> {{ __('common.search') }}
        </button>
        @if(request()->hasAny(['search', 'game_id', 'language']))
            <a href="{{ route('admin.translations.index') }}" class="bg-gray-600 hover:bg-gray-500 text-white px-4 py-2 rounded-lg">
                <i class="fas fa-times mr-1"></i> {{ __('common.cancel') }}
            </a>
        @endif
    </form>
</div>

<!-- Results -->
<div class="bg-gray-800 rounded-lg border border-gray-700 overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead class="bg-gray-750 text-gray-400 text-sm">
                <tr>
                    <th class="text-left py-3 px-4">{{ __('admin.game') }}</th>
                    <th class="text-left py-3 px-4">{{ __('games.target_language') }}</th>
                    <th class="text-left py-3 px-4">{{ __('admin.uploader') }}</th>
                    <th class="text-left py-3 px-4">{{ __('my_translations.lines') }}</th>
                    <th class="text-left py-3 px-4">{{ __('my_translations.downloads') }}</th>
                    <th class="text-left py-3 px-4">{{ __('admin.created_at') }}</th>
                    <th class="text-right py-3 px-4">{{ __('admin.actions') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-700">
                @forelse($translations as $translation)
                    <tr class="hover:bg-gray-750">
                        <td class="py-3 px-4">
                            <div class="flex items-center gap-3">
                                @if($translation->game->image_url)
                                    <img src="{{ $translation->game->image_url }}" class="w-10 h-14 object-cover rounded">
                                @else
                                    <div class="w-10 h-14 bg-gray-700 rounded flex items-center justify-center">
                                        <i class="fas fa-gamepad text-gray-500"></i>
                                    </div>
                                @endif
                                <div>
                                    <a href="{{ route('games.show', $translation->game) }}" class="font-medium hover:text-purple-400">
                                        {{ $translation->game->name }}
                                    </a>
                                    @if($translation->isFork())
                                        <span class="text-xs text-gray-500 block"><i class="fas fa-code-branch"></i> Fork</span>
                                    @endif
                                </div>
                            </div>
                        </td>
                        <td class="py-3 px-4">
                            <span class="bg-blue-900 text-blue-200 px-2 py-0.5 rounded text-sm">
                                @langflag($translation->source_language) {{ $translation->source_language }} → @langflag($translation->target_language) {{ $translation->target_language }}
                            </span>
                        </td>
                        <td class="py-3 px-4">
                            <span class="text-gray-300">{{ $translation->user->name ?? '[Deleted]' }}</span>
                        </td>
                        <td class="py-3 px-4 text-gray-400">
                            {{ number_format($translation->line_count) }}
                        </td>
                        <td class="py-3 px-4 text-gray-400">
                            {{ number_format($translation->download_count) }}
                        </td>
                        <td class="py-3 px-4 text-gray-400 text-sm">
                            {{ $translation->created_at->format('M d, Y') }}
                        </td>
                        <td class="py-3 px-4 text-right">
                            <div class="flex justify-end gap-2">
                                <a href="{{ route('admin.translations.show', $translation) }}" class="bg-gray-700 hover:bg-gray-600 text-white px-3 py-1.5 rounded text-sm" title="{{ __('admin.view_json') }}">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <a href="{{ route('admin.translations.edit', $translation) }}" class="bg-gray-700 hover:bg-gray-600 text-white px-3 py-1.5 rounded text-sm" title="{{ __('common.edit') }}">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <form action="{{ route('admin.translations.destroy', $translation) }}" method="POST" class="inline"
                                    onsubmit="return confirm('{{ __('admin.delete_confirm') }}')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="bg-red-900 hover:bg-red-800 text-white px-3 py-1.5 rounded text-sm" title="{{ __('common.delete') }}">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="py-8 text-center text-gray-500">
                            {{ __('admin.no_translations') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<!-- Pagination -->
@if($translations->hasPages())
    <div class="mt-6">
        {{ $translations->appends(request()->query())->links() }}
    </div>
@endif
@endsection
