@extends('layouts.app')

@section('title', __('nav.my_translations') . ' - UnityGameTranslator')

@section('content')
<div class="flex justify-between items-center mb-8">
    <h1 class="text-3xl font-bold"><i class="fas fa-folder mr-2"></i> {{ __('my_translations.title') }}</h1>
    <a href="{{ route('translations.create') }}" class="bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded-lg">
        <i class="fas fa-plus mr-2"></i> {{ __('my_translations.new_upload') }}
    </a>
</div>

@if($translations->isEmpty())
    <div class="text-center py-12 text-gray-400">
        <i class="fas fa-folder-open text-6xl mb-4"></i>
        <p class="text-xl">{{ __('my_translations.empty') }}</p>
        <a href="{{ route('translations.create') }}" class="inline-block mt-4 bg-purple-600 hover:bg-purple-700 text-white px-6 py-3 rounded-lg">
            <i class="fas fa-upload mr-2"></i> {{ __('my_translations.upload_first') }}
        </a>
    </div>
@else
    <div class="space-y-4">
        @foreach($translations as $translation)
            <div class="bg-gray-800 rounded-lg p-5 border border-gray-700 flex justify-between items-center">
                <div class="flex items-center gap-4">
                    @if($translation->game->image_url)
                        <img src="{{ $translation->game->image_url }}" alt="{{ $translation->game->name }}" class="w-12 h-16 object-cover rounded">
                    @else
                        <div class="w-12 h-16 bg-gray-700 rounded flex items-center justify-center">
                            <i class="fas fa-gamepad text-gray-500"></i>
                        </div>
                    @endif
                    <div>
                        <a href="{{ route('games.show', $translation->game) }}" class="text-lg font-semibold hover:text-purple-400">
                            {{ $translation->game->name }}
                        </a>
                    <div class="flex items-center gap-3 mt-1">
                        <span class="bg-blue-900 text-blue-200 px-2 py-0.5 rounded text-sm">
                            {{ $translation->source_language }} → {{ $translation->target_language }}
                        </span>
                        @if($translation->isComplete())
                            <span class="text-green-400 text-sm"><i class="fas fa-check"></i> {{ __('translation.complete') }}</span>
                        @else
                            <span class="text-yellow-400 text-sm"><i class="fas fa-clock"></i> {{ __('translation.in_progress') }}</span>
                        @endif
                        @if($translation->isFork())
                            <span class="text-gray-400 text-sm"><i class="fas fa-code-branch"></i> Fork</span>
                        @endif
                    </div>
                    <div class="text-sm text-gray-400 mt-1">
                        {{ number_format($translation->line_count) }} {{ __('my_translations.lines') }} •
                        {{ $translation->download_count }} {{ __('my_translations.downloads') }} •
                        {{ $translation->forks->count() }} {{ __('my_translations.forks') }} •
                        {{ $translation->updated_at->format('M d, Y') }}
                    </div>
                    </div>
                </div>
                <div class="flex gap-2">
                    <a href="{{ route('translations.download', $translation) }}" class="bg-gray-700 hover:bg-gray-600 text-white px-3 py-2 rounded" title="{{ __('translation.download') }}">
                        <i class="fas fa-download"></i>
                    </a>
                    <a href="{{ route('translations.edit', $translation) }}" class="bg-gray-700 hover:bg-gray-600 text-white px-3 py-2 rounded" title="{{ __('translation.edit') }}">
                        <i class="fas fa-edit"></i>
                    </a>
                    <form action="{{ route('translations.destroy', $translation) }}" method="POST"
                        onsubmit="return confirm('{{ __('my_translations.delete_confirm') }}')">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="bg-red-900 hover:bg-red-800 text-white px-3 py-2 rounded" title="{{ __('translation.delete') }}">
                            <i class="fas fa-trash"></i>
                        </button>
                    </form>
                </div>
            </div>
        @endforeach
    </div>
@endif
@endsection
