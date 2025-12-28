{{--
    JSON Translation Table Partial

    @param array $jsonContent - The parsed JSON content
    @param int $limit - Max entries to show (default: 100)
    @param bool $collapsible - Whether to show toggle button (default: false)
--}}

@php
    $limit = $limit ?? 100;
    $collapsible = $collapsible ?? false;
    $uniqueId = 'json-' . uniqid();

    // Separate metadata from translations
    $metadata = [];
    $translations = [];

    foreach ($jsonContent as $key => $value) {
        if (str_starts_with($key, '_')) {
            $metadata[$key] = $value;
        } else {
            $translations[$key] = $value;
        }
    }

    $translationCount = count($translations);
    $displayTranslations = array_slice($translations, 0, $limit, true);
@endphp

<div class="bg-gray-800 rounded-lg p-6 border border-gray-700">
    <div class="flex justify-between items-center mb-4">
        <h2 class="text-xl font-semibold"><i class="fas fa-code mr-2"></i> {{ __('admin.translation_content') }}</h2>
        @if($collapsible)
            <button type="button" class="json-toggle-btn text-gray-400 hover:text-white text-sm" data-target="{{ $uniqueId }}">
                <i id="toggleIcon-{{ $uniqueId }}" class="fas fa-chevron-down mr-1"></i>
                <span id="toggleText-{{ $uniqueId }}">{{ __('common.show') }}</span>
            </button>
        @endif
    </div>

    <div id="jsonPreview-{{ $uniqueId }}" class="{{ $collapsible ? 'hidden' : '' }}">
        {{-- Metadata Section --}}
        @if(!empty($metadata))
            <div class="mb-4 p-4 bg-gray-900 rounded-lg">
                <h3 class="text-sm font-semibold text-gray-400 mb-3"><i class="fas fa-info-circle mr-1"></i> {{ __('admin.metadata') }}</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-sm">
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
                            @if(isset($metadata['_source']['site_id']))
                                <span class="text-gray-500 text-xs">(ID: {{ $metadata['_source']['site_id'] }})</span>
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

        {{-- Translations Table --}}
        <div class="text-sm text-gray-400 mb-3">
            {{ number_format($translationCount) }} {{ __('admin.translation_entries') }}
        </div>

        <div class="bg-gray-900 rounded-lg max-h-[600px] overflow-auto">
            <table class="w-full text-sm">
                <thead class="text-gray-400 border-b border-gray-700 sticky top-0 bg-gray-900">
                    <tr>
                        <th class="text-left py-3 px-4 w-1/2">{{ __('admin.original') }}</th>
                        <th class="text-left py-3 px-4 w-1/2">{{ __('admin.translated') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($displayTranslations as $original => $translated)
                        <tr class="border-b border-gray-800 hover:bg-gray-800/50">
                            <td class="py-2 px-4 text-gray-300 break-words align-top">
                                <span class="font-mono text-xs">{{ Str::limit($original, 150) }}</span>
                            </td>
                            <td class="py-2 px-4 text-white break-words align-top">
                                {{ Str::limit($translated, 150) }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="2" class="py-8 text-center text-gray-500">
                                {{ __('admin.no_translations') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($translationCount > $limit)
            <p class="text-gray-500 text-sm mt-4 text-center">
                {{ __('admin.and_more_entries', ['count' => number_format($translationCount - $limit)]) }}
            </p>
        @endif
    </div>
</div>

@if($collapsible)
<script nonce="{{ $cspNonce ?? '' }}">
(function() {
    var btn = document.querySelector('.json-toggle-btn[data-target="{{ $uniqueId }}"]');
    if (btn) {
        btn.addEventListener('click', function() {
            var id = this.dataset.target;
            var preview = document.getElementById('jsonPreview-' + id);
            var icon = document.getElementById('toggleIcon-' + id);
            var text = document.getElementById('toggleText-' + id);

            if (preview.classList.contains('hidden')) {
                preview.classList.remove('hidden');
                icon.classList.remove('fa-chevron-down');
                icon.classList.add('fa-chevron-up');
                text.textContent = '{{ __('common.hide') }}';
            } else {
                preview.classList.add('hidden');
                icon.classList.remove('fa-chevron-up');
                icon.classList.add('fa-chevron-down');
                text.textContent = '{{ __('common.show') }}';
            }
        });
    }
})();
</script>
@endif
