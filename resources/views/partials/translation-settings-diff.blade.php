{{--
    Which settings sections differ between two translations, and by how much.

    Counts only, never a row-by-row diff: these settings are edited in the mod
    and published by a full upload, so there is nothing to pick here. Saying
    "they differ" without saying WHERE was the previous behaviour, and it left
    the reader with no idea whether it mattered.

    Requires: $left, $right (Translation), $leftLabel, $rightLabel (string)
--}}
@php
    $leftCounts = $left->settingsSectionCounts();
    $rightCounts = $right->settingsSectionCounts();
    $differing = array_values(array_filter(
        App\Models\Translation::SETTINGS_SECTIONS,
        fn ($section) => $leftCounts[$section] !== $rightCounts[$section]
    ));
    $sectionLabels = [
        'fonts' => __('file_settings.label.fonts'),
        'font_rules' => __('file_settings.label.font_rules'),
        'images' => __('file_settings.label.images'),
        'exclusions' => __('file_settings.label.exclusions'),
        'variables' => __('file_settings.label.variables'),
        'game_settings' => __('file_settings.game_settings'),
    ];
@endphp

@if(!empty($differing))
    <div class="bg-gray-800 border border-gray-700 rounded-lg px-4 py-3 text-sm">
        <div class="flex items-center gap-2 text-gray-300 mb-2">
            <i class="fas fa-sliders text-gray-500"></i>
            <span>{{ __('merge_preview.settings_differ_title') }}</span>
        </div>
        <div class="flex flex-wrap gap-x-6 gap-y-1 text-gray-400">
            @foreach($differing as $section)
                <span>
                    {{ $sectionLabels[$section] }} :
                    <span class="text-gray-300">{{ $leftCounts[$section] }}</span>
                    <span class="text-gray-600">/</span>
                    <span class="text-gray-300">{{ $rightCounts[$section] }}</span>
                </span>
            @endforeach
        </div>
        <p class="text-xs text-gray-500 mt-2">
            {{ $leftLabel }} <span class="text-gray-600">/</span> {{ $rightLabel }} &bull;
            {{ __('merge_preview.settings_differ') }}
        </p>
    </div>
@endif
