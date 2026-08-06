{{--
    Read-only detail of everything a translation file carries beyond its lines.

    Read-only on purpose: these settings are edited in the mod and published by
    a full upload. The site never writes them back, so showing an input here
    would promise something the sync cannot keep.

    No Alpine, no card chrome: the caller places it (game card, comparison
    screens) and owns the surrounding container.

    Requires: $translation
--}}
@php
    $summary = $translation->settings_summary ?? [];
    $fonts = $translation->font_config ?? [];
    $rules = $summary['font_overrides'] ?? null;
    $images = $summary['image_replacements'] ?? null;
    $exclusions = $summary['exclusions'] ?? null;
    $variables = $summary['variables'] ?? null;
    $gameSettings = $summary['game_settings'] ?? [];
    $resourcesUrl = $translation->getEffectiveResourcesUrl();
    $resourcesHost = $translation->getResourcesHost();
    // The link may come from the parent when a branch did not set its own
    $resourcesInherited = $resourcesUrl && empty($translation->resources_url);
@endphp

<div class="space-y-5">

    {{-- External resources: first, because it is the only part the reader may
         have to act on before downloading --}}
    @if($resourcesUrl)
        <div class="p-3 bg-gray-800 rounded-lg border border-cyan-800/60">
            <h4 class="text-sm font-medium text-gray-300 mb-1">
                <i class="fas fa-link mr-2 text-cyan-400"></i>{{ __('file_settings.resources') }}
                @if($resourcesInherited)
                    <span class="text-xs text-gray-500 ml-1">{{ __('file_settings.resources_inherited') }}</span>
                @endif
            </h4>
            {{-- Full URL, never shortened: the reader must see where it leads
                 before opening it (same rule as the mod's panel) --}}
            <a href="{{ $resourcesUrl }}" target="_blank" rel="nofollow noopener noreferrer external"
               class="text-cyan-400 hover:text-cyan-300 break-all text-sm">
                {{ $resourcesUrl }} <i class="fas fa-external-link-alt text-xs"></i>
            </a>
            <p class="text-xs text-amber-300/90 mt-2">
                <i class="fas fa-exclamation-triangle mr-1"></i>{{ __('file_settings.resources_warning', ['host' => $resourcesHost ?? '']) }}
            </p>
        </div>
    @endif

    @if($translation->hasUnreachableImageAssets())
        <p class="text-xs text-amber-300/90 p-3 bg-amber-900/20 rounded-lg border border-amber-800/50">
            <i class="fas fa-exclamation-triangle mr-1"></i>{{ __('file_settings.images_missing_resources') }}
        </p>
    @endif

    {{-- Fonts --}}
    @if(!empty($fonts))
        <div>
            <h4 class="text-sm font-medium text-gray-400 mb-1">
                <i class="fas fa-font mr-2"></i>{{ trans_choice('file_settings.fonts', count($fonts), ['count' => count($fonts)]) }}
            </h4>
            <p class="text-xs text-gray-500 mb-2">{{ __('fonts.tooltip') }}</p>
            <div class="space-y-1.5">
                @foreach($fonts as $fontName => $settings)
                    <div class="flex items-center gap-3 p-2 bg-gray-800 rounded-lg border border-gray-700 text-sm {{ !($settings['enabled'] ?? true) ? 'opacity-50' : '' }}">
                        <span class="font-medium text-gray-200 min-w-0 truncate" title="{{ $fontName }}">{{ $fontName }}</span>
                        @if(!empty($settings['type']))
                            <span class="bg-gray-700 text-gray-400 px-1.5 py-0.5 rounded text-xs flex-shrink-0">{{ $settings['type'] }}</span>
                        @endif
                        @if(!empty($settings['fallback']))
                            <span class="text-gray-500 flex-shrink-0"><i class="fas fa-arrow-right text-xs"></i></span>
                            <span class="text-cyan-400 truncate" title="{{ $settings['fallback'] }}">{{ $settings['fallback'] }}</span>
                        @endif
                        @if(isset($settings['scale']) && abs($settings['scale'] - 1.0) > 0.001)
                            <span class="bg-yellow-900/50 text-yellow-300 px-1.5 py-0.5 rounded text-xs flex-shrink-0">
                                {{ __('fonts.scale') }} &times;{{ number_format($settings['scale'], 1) }}
                            </span>
                        @endif
                        @if(!($settings['enabled'] ?? true))
                            <span class="bg-red-900/50 text-red-400 px-1.5 py-0.5 rounded text-xs flex-shrink-0">
                                <i class="fas fa-ban mr-1"></i>{{ __('fonts.disabled') }}
                            </span>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Font rules (pattern-based substitutions) --}}
    @if($rules)
        <div>
            <h4 class="text-sm font-medium text-gray-400 mb-1">
                <i class="fas fa-i-cursor mr-2"></i>{{ trans_choice('file_settings.font_rules', $rules['count'], ['count' => $rules['count']]) }}
            </h4>
            <p class="text-xs text-gray-500 mb-2">{{ __('file_settings.font_rules_desc') }}</p>
            <div class="space-y-1.5">
                @foreach($rules['items'] as $rule)
                    <div class="flex items-center gap-3 p-2 bg-gray-800 rounded-lg border border-gray-700 text-sm {{ !($rule['enabled'] ?? true) ? 'opacity-50' : '' }}">
                        <span class="font-mono text-gray-200 min-w-0 truncate" title="{{ $rule['match'] }}">{{ $rule['match'] }}</span>
                        @if(!empty($rule['replacement']))
                            <span class="text-gray-500 flex-shrink-0"><i class="fas fa-arrow-right text-xs"></i></span>
                            <span class="text-cyan-400 truncate" title="{{ $rule['replacement'] }}">{{ $rule['replacement'] }}</span>
                        @endif
                        @if(!empty($rule['size_multiplier']))
                            <span class="bg-yellow-900/50 text-yellow-300 px-1.5 py-0.5 rounded text-xs flex-shrink-0">
                                {{ __('fonts.scale') }} &times;{{ number_format($rule['size_multiplier'], 1) }}
                            </span>
                        @endif
                        @if(!($rule['enabled'] ?? true))
                            <span class="bg-red-900/50 text-red-400 px-1.5 py-0.5 rounded text-xs flex-shrink-0">{{ __('fonts.disabled') }}</span>
                        @endif
                    </div>
                @endforeach
            </div>
            @include('partials.translation-settings-more', ['section' => $rules])
        </div>
    @endif

    {{-- Image replacements --}}
    @if($images)
        <div>
            <h4 class="text-sm font-medium text-gray-400 mb-1">
                <i class="fas fa-image mr-2"></i>{{ trans_choice('file_settings.images', $images['count'], ['count' => $images['count']]) }}
            </h4>
            <p class="text-xs text-gray-500 mb-2">{{ __('file_settings.images_desc') }}</p>
            <div class="flex flex-wrap gap-2">
                @foreach($images['items'] as $image)
                    <span class="px-2 py-1 bg-gray-800 rounded border border-gray-700 text-xs text-gray-300" title="{{ $image['name'] }}">
                        {{ $image['name'] }}
                        @if(!empty($image['width']) && !empty($image['height']))
                            <span class="text-gray-500">{{ $image['width'] }}&times;{{ $image['height'] }}</span>
                        @endif
                    </span>
                @endforeach
            </div>
            @include('partials.translation-settings-more', ['section' => $images])
        </div>
    @endif

    {{-- Exclusions --}}
    @if($exclusions)
        <div>
            <h4 class="text-sm font-medium text-gray-400 mb-1">
                <i class="fas fa-ban mr-2"></i>{{ trans_choice('file_settings.exclusions', $exclusions['count'], ['count' => $exclusions['count']]) }}
            </h4>
            <p class="text-xs text-gray-500 mb-2">{{ __('file_settings.exclusions_desc') }}</p>
            <div class="flex flex-wrap gap-2">
                @foreach($exclusions['items'] as $pattern)
                    <span class="px-2 py-1 bg-gray-800 rounded border border-gray-700 text-xs font-mono text-gray-300 break-all">{{ $pattern }}</span>
                @endforeach
            </div>
            @include('partials.translation-settings-more', ['section' => $exclusions])
        </div>
    @endif

    {{-- Variables --}}
    @if($variables)
        <div>
            <h4 class="text-sm font-medium text-gray-400 mb-1">
                <i class="fas fa-code mr-2"></i>{{ trans_choice('file_settings.variables', $variables['count'], ['count' => $variables['count']]) }}
            </h4>
            <p class="text-xs text-gray-500 mb-2">{{ __('file_settings.variables_desc') }}</p>
            <div class="space-y-1.5">
                @foreach($variables['items'] as $variable)
                    <div class="flex items-center gap-3 p-2 bg-gray-800 rounded-lg border border-gray-700 text-sm">
                        <span class="text-gray-200 truncate">{{ $variable['name'] }}</span>
                        @if(!empty($variable['source']))
                            <span class="font-mono text-xs text-gray-500 truncate" title="{{ $variable['source'] }}">{{ $variable['source'] }}</span>
                        @endif
                    </div>
                @endforeach
            </div>
            @include('partials.translation-settings-more', ['section' => $variables])
        </div>
    @endif

    {{-- Game settings: the mod only writes these when they leave their default,
         so each line present is a deliberate choice by the author --}}
    @if(!empty($gameSettings))
        <div>
            <h4 class="text-sm font-medium text-gray-400 mb-1">
                <i class="fas fa-sliders-h mr-2"></i>{{ __('file_settings.game_settings') }}
            </h4>
            <p class="text-xs text-gray-500 mb-2">{{ __('file_settings.game_settings_desc') }}</p>
            <ul class="space-y-1 text-sm text-gray-300">
                @if(($gameSettings['typewriting_detection'] ?? true) === false)
                    <li><i class="fas fa-circle text-[6px] align-middle mr-2 text-gray-500"></i>{{ __('file_settings.setting.typewriting_off') }}</li>
                @endif
                @if(($gameSettings['concat_detection'] ?? true) === false)
                    <li><i class="fas fa-circle text-[6px] align-middle mr-2 text-gray-500"></i>{{ __('file_settings.setting.concat_off') }}</li>
                @endif
                @if(($gameSettings['disable_eventsystem_override'] ?? false) === true)
                    <li><i class="fas fa-circle text-[6px] align-middle mr-2 text-gray-500"></i>{{ __('file_settings.setting.eventsystem_off') }}</li>
                @endif
                @if(!empty($gameSettings['ui_font']))
                    <li><i class="fas fa-circle text-[6px] align-middle mr-2 text-gray-500"></i>{{ __('file_settings.setting.ui_font', ['font' => $gameSettings['ui_font']]) }}</li>
                @endif
            </ul>
        </div>
    @endif
</div>
