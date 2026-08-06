{{--
    "+N more" line for a bounded settings preview.

    Never silently truncate: the stored count is exact, the preview is not, and
    a list that stops without saying so reads as complete.

    Requires: $section  — {count, items} from settings_summary
--}}
@php $hidden = ($section['count'] ?? 0) - count($section['items'] ?? []); @endphp
@if($hidden > 0)
    <p class="text-xs text-gray-500 mt-2">{{ trans_choice('file_settings.more', $hidden, ['count' => $hidden]) }}</p>
@endif
