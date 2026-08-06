{{--
    Toggle button summarizing everything a translation file carries beyond its
    lines. Sits in the card's expandable-sections bar, next to versions/forks,
    and drives `showSettings` in the card's Alpine scope.

    Counts come from the stored columns (font_config + settings_summary), never
    from reading the file: a game page renders every translation of the game.

    Requires: $translation
--}}
@php
    // Configured fonts only: font_config also holds every font the mod merely
    // met in-game, which says nothing about what the author set up
    $fontCount = count($translation->configuredFonts());
    $ruleCount = $translation->settingsCount('font_overrides');
    $imageCount = $translation->settingsCount('image_replacements');
    $exclusionCount = $translation->settingsCount('exclusions');
    $variableCount = $translation->settingsCount('variables');
    $hasGameSettings = !empty($translation->settings_summary['game_settings'] ?? []);
    $resourcesUrl = $translation->getEffectiveResourcesUrl();
@endphp

<button @click="showSettings = !showSettings"
        class="flex items-center gap-3 text-sm text-gray-400 hover:text-white transition flex-wrap">
    @if($fontCount > 0)
        <span class="flex items-center gap-1"><i class="fas fa-font"></i> {{ trans_choice('file_settings.fonts', $fontCount, ['count' => $fontCount]) }}</span>
    @endif
    @if($ruleCount > 0)
        <span class="flex items-center gap-1"><i class="fas fa-i-cursor"></i> {{ trans_choice('file_settings.font_rules', $ruleCount, ['count' => $ruleCount]) }}</span>
    @endif
    @if($resourcesUrl)
        <span class="flex items-center gap-1 text-cyan-400"><i class="fas fa-link"></i> {{ __('file_settings.resources') }}</span>
    @endif
    @if($imageCount > 0)
        <span class="flex items-center gap-1"><i class="fas fa-image"></i> {{ trans_choice('file_settings.images', $imageCount, ['count' => $imageCount]) }}</span>
    @endif
    @if($exclusionCount > 0)
        <span class="flex items-center gap-1"><i class="fas fa-ban"></i> {{ trans_choice('file_settings.exclusions', $exclusionCount, ['count' => $exclusionCount]) }}</span>
    @endif
    @if($variableCount > 0)
        <span class="flex items-center gap-1"><i class="fas fa-code"></i> {{ trans_choice('file_settings.variables', $variableCount, ['count' => $variableCount]) }}</span>
    @endif
    @if($hasGameSettings)
        <span class="flex items-center gap-1"><i class="fas fa-sliders-h"></i> {{ __('file_settings.game_settings') }}</span>
    @endif
    <i class="fas fa-chevron-down text-xs transition-transform" :class="showSettings && 'rotate-180'"></i>
</button>
