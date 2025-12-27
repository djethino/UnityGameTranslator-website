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
                    <div>
                        <p class="text-gray-400 text-sm">{{ __('games.type') }}</p>
                        <p class="font-medium">{{ $translation->getTypeLabel() }}</p>
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
            <a href="{{ route('translations.download', $translation) }}" class="bg-gray-700 hover:bg-gray-600 text-white px-4 py-2 rounded">
                <i class="fas fa-download mr-1"></i> {{ __('translation.download') }}
            </a>
            <a href="{{ route('admin.translations.edit', $translation) }}" class="bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded">
                <i class="fas fa-edit mr-1"></i> {{ __('common.edit') }}
            </a>
            <form action="{{ route('admin.translations.destroy', $translation) }}" method="POST" class="inline"
                onsubmit="return confirm('{{ __('admin.delete_confirm') }}')">
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
        @include('partials.json-table', ['jsonContent' => $jsonContent, 'limit' => 200, 'collapsible' => false])
    @else
        <div class="bg-gray-800 rounded-lg p-6 border border-gray-700 text-center text-gray-500">
            <i class="fas fa-exclamation-triangle text-2xl mb-2"></i>
            <p>Could not load translation file content.</p>
        </div>
    @endif
</div>
@endsection
