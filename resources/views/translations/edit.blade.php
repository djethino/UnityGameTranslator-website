@extends('layouts.app')

@section('title', __('my_translations.edit_title') . ' - UnityGameTranslator')

@section('content')
<div class="max-w-2xl mx-auto">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-3xl font-bold"><i class="fas fa-edit mr-2"></i> {{ __('my_translations.edit_title') }}</h1>
        @if($fromAdmin ?? false)
            <a href="{{ route('admin.translations.show', $translation) }}" class="text-gray-400 hover:text-white">
                <i class="fas fa-arrow-left mr-1"></i> {{ __('common.back') }}
            </a>
        @else
            <a href="{{ route('translations.mine') }}" class="text-gray-400 hover:text-white">
                <i class="fas fa-arrow-left mr-1"></i> {{ __('my_translations.back_to_mine') }}
            </a>
        @endif
    </div>

    <!-- Translation Info -->
    <div class="bg-gray-800 rounded-lg p-4 mb-6 border border-gray-700">
        <div class="flex items-center gap-4">
            @if($translation->game->image_url)
                <img src="{{ $translation->game->image_url }}" class="w-16 h-20 object-cover rounded">
            @endif
            <div>
                <p class="font-semibold text-lg">{{ $translation->game->name }}</p>
                <p class="text-sm text-gray-400">
                    {{ __('translation.published_on', ['date' => $translation->created_at->isoFormat('LL')]) }}
                </p>
                <p class="text-sm text-gray-500">
                    {{ number_format($translation->line_count) }} {{ __('my_translations.lines') }} &bull; {{ $translation->download_count }} {{ __('my_translations.downloads') }}
                </p>
            </div>
        </div>
    </div>

    @if($errors->any())
        <div class="bg-red-900 border border-red-700 text-red-100 px-4 py-3 rounded mb-6">
            <ul class="list-disc list-inside">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form action="{{ ($fromAdmin ?? false) ? route('admin.translations.update', $translation) : route('translations.update', $translation) }}" method="POST" class="bg-gray-800 rounded-lg p-6 border border-gray-700">
        @csrf
        @method('PUT')

        <!-- Languages (read-only, set at upload time) -->
        <div class="grid grid-cols-2 gap-4 mb-6">
            <div>
                <label class="block text-sm font-medium text-gray-300 mb-2">{{ __('upload.source_language') }}</label>
                <div class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-3 text-white opacity-75">
                    @langflag($translation->source_language) {{ $translation->source_language }}
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-300 mb-2">{{ __('upload.target_language') }}</label>
                <div class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-3 text-white opacity-75">
                    @langflag($translation->target_language) {{ $translation->target_language }}
                </div>
            </div>
        </div>

        {{-- Composition (read-only, computed from the file).

             Three cards over H+V+A used to stand here, each showing a share of a total that left
             out everything captured and everything kept as is: an author whose file is mostly
             captured read "Human 100%" on the very screen where they publish it, while their own
             game page said 15%. The shared bar says the same thing as every other screen, and its
             key names the bands that exist. --}}
        <div class="mb-6">
            <label class="block text-sm font-medium text-gray-300 mb-2">{{ __('upload.translation_composition') }}</label>
            <x-progress-bar :translation="$translation" />
            <x-quality-legend :translation="$translation" />
            <p class="text-xs text-gray-500 mt-2 text-center">{{ __('upload.composition_auto') }}</p>
        </div>

        <!-- Status (only for Main translations - branches inherit from Main) -->
        @if($translation->visibility !== 'branch')
        <div class="mb-6">
            <label class="block text-sm font-medium text-gray-300 mb-2">{{ __('upload.status') }}</label>
            <div class="flex gap-4">
                <label class="flex items-center cursor-pointer">
                    <input type="radio" name="status" value="in_progress" {{ $translation->status == 'in_progress' ? 'checked' : '' }} class="mr-2 text-purple-600">
                    <span><i class="fas fa-clock text-yellow-400 mr-1"></i> {{ __('translation.in_progress') }}</span>
                </label>
                <label class="flex items-center cursor-pointer">
                    <input type="radio" name="status" value="complete" {{ $translation->status == 'complete' ? 'checked' : '' }} class="mr-2 text-purple-600">
                    <span><i class="fas fa-check text-green-400 mr-1"></i> {{ __('translation.complete') }}</span>
                </label>
            </div>
        </div>
        @else
        <div class="mb-6">
            <label class="block text-sm font-medium text-gray-300 mb-2">{{ __('upload.status') }}</label>
            <div class="bg-gray-700 rounded-lg px-4 py-3 text-gray-400">
                <i class="fas fa-lock mr-2"></i>
                @if($translation->status == 'complete')
                    <i class="fas fa-check text-green-400 mr-1"></i> {{ __('translation.complete') }}
                @else
                    <i class="fas fa-clock text-yellow-400 mr-1"></i> {{ __('translation.in_progress') }}
                @endif
                <span class="text-xs ml-2">({{ __('upload.inherited_from_main') }})</span>
            </div>
        </div>
        @endif

        <!-- Notes -->
        <div class="mb-6">
            <label class="block text-sm font-medium text-gray-300 mb-2">{{ __('upload.notes') }}</label>
            <textarea name="notes" rows="3" maxlength="1000"
                placeholder="{{ __('upload.notes_placeholder') }}"
                class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-3 text-white focus:ring-purple-500 focus:border-purple-500">{{ $translation->notes }}</textarea>
        </div>

        <!-- Resources URL -->
        <div class="mb-6">
            <label class="block text-sm font-medium text-gray-300 mb-2">{{ __('upload.resources_url') }}</label>
            <input type="url" name="resources_url" maxlength="2048"
                value="{{ $translation->resources_url }}"
                placeholder="{{ __('upload.resources_url_placeholder') }}"
                class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-3 text-white focus:ring-purple-500 focus:border-purple-500">
            <p class="text-xs text-gray-500 mt-1">{{ __('upload.resources_url_hint') }}</p>
        </div>

        <div class="flex gap-4">
            <a href="{{ ($fromAdmin ?? false) ? route('admin.translations.show', $translation) : route('translations.mine') }}" class="flex-1 bg-gray-600 hover:bg-gray-500 text-white font-semibold py-3 rounded-lg transition text-center">
                {{ __('common.cancel') }}
            </a>
            <button type="submit" class="flex-1 bg-purple-600 hover:bg-purple-700 text-white font-semibold py-3 rounded-lg transition">
                <i class="fas fa-save mr-2"></i> {{ __('common.save') }}
            </button>
        </div>
    </form>
</div>

@endsection
