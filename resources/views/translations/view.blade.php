@extends('layouts.app')

@section('title', __('translation.view_title', ['game' => $translation->game->name]))

@push('head')
    {{-- noindex, decided deliberately. The file has always been downloadable by anyone, so this
         page discloses nothing new — but making a commercial game's whole script SEARCHABLE is a
         different act from serving a JSON file to a mod, and it brings nothing: nobody looks up a
         line of dialogue hoping to find a translation mod. The game pages stay indexed. --}}
    <meta name="robots" content="noindex, nofollow">
@endpush

@section('content')
<div class="max-w-7xl mx-auto">
    {{-- Enough of a header to know WHOSE work this is and how far it goes. Arriving on a bare
         table of six thousand lines with no idea who wrote it, in what direction, and how much of
         it is machine output, answers none of the question that brought you here — which of these
         translations do I take? --}}
    <div class="mb-6">
        <a href="{{ route('games.show', $translation->game) }}" class="text-purple-400 hover:text-purple-300 text-sm">
            <i class="fas fa-arrow-left mr-1"></i>{{ $translation->game->name }}
        </a>

        <div class="mt-2 flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                {{-- ⚠ Reading, not writing — and saying WHICH copy is being read matters as much:
                     somebody arriving from an in-game editor is looking at the published version,
                     not at the file in their game, and the two can differ by everything they have
                     translated since. The same control as everywhere, so the question is answered
                     in the same place whatever screen they came from. --}}
                <div class="flex items-center gap-3 flex-wrap mb-1">
                    <x-editor.scope-badge side="server" :why="[
                        'local' => __('edit_scope.why_page_is_server'),
                        'both' => __('edit_scope.why_page_is_server'),
                    ]" />
                </div>
                <h1 class="text-2xl font-bold text-white">
                    <i class="fas fa-eye mr-2 text-purple-400"></i>{{ __('translation.view_heading') }}
                </h1>
                <p class="text-gray-400 mt-1">
                    {{ $translation->source_language }}
                    <i class="fas fa-arrow-right text-xs"></i>
                    {{ $translation->target_language }}
                    <span class="text-gray-600 mx-1">·</span>
                    <span class="text-gray-300">{{ $translation->user->name }}</span>
                </p>

                {{-- Where it came from, when it came from somewhere --}}
                <x-translation-origin :translation="$translation" />
            </div>

            <div class="shrink-0 flex items-center gap-3">
                {{-- The way out of looking and into using. A branch never reaches this page for
                     anyone but the Main owner, so this button can never be the one that 403s. --}}
                <a href="{{ route('translations.download', $translation) }}"
                    class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded font-medium">
                    <i class="fas fa-download mr-1"></i>{{ __('translation.download') }}
                </a>
            </div>
        </div>

        @if($translation->notes)
            <div class="mt-3 text-sm text-gray-400 bg-gray-750 rounded p-3 border-l-2 border-purple-500">
                <i class="fas fa-quote-left text-purple-500 mr-2"></i>{{ $translation->notes }}
            </div>
        @endif

        <p class="mt-3 text-xs text-gray-500">
            <i class="fas fa-lock mr-1"></i>{{ __('translation.view_readonly') }}
        </p>
    </div>

    <x-editor.readonly-grid component="translationViewer"
        :source-label="$translation->source_language"
        :target-label="$translation->target_language" />
</div>
{{-- Everything this screen does lives in the shared editor core; only the file to read and the
     message for a file that cannot be read are page-specific. --}}
<script nonce="{{ $cspNonce }}">
document.addEventListener('alpine:init', () => {
    Alpine.data('translationViewer', () => window.UGT.createViewer({
        translationId: @js($translation->id),
        dataUrl: @js(route('translations.view.data', $translation)),
        unreadableMessage: @js(__('translation.content_unavailable')),
        owner: @js($translation->user->name),
        // The words for the settings block. Translated server-side, so they are handed to the
        // shared module rather than built inside it.
        metadataLabels: {
            sections: @js([
                'fonts' => __('file_settings.label.fonts'),
                'font_rules' => __('file_settings.label.font_rules'),
                'images' => __('file_settings.label.images'),
                'exclusions' => __('file_settings.label.exclusions'),
                'variables' => __('file_settings.label.variables'),
                'game_settings' => __('file_settings.game_settings'),
            ]),
            fields: @js([
                'notes' => __('upload.notes'),
                'resources_url' => __('upload.resources_url'),
            ]),
            absent: @js(__('merge_preview.settings_absent')),
        },
    }));
});
</script>
@endsection

@push('head')
<style>
    [x-cloak] { display: none !important; }
</style>
@endpush
