{{--
    The button that hands the window to the grid.

    It lives at the end of the filter bar on every editing screen, with the other view options
    (which columns, which rows) rather than in the page header: how much room the grid gets is a
    view option, not a page mode, and one place across the three editors means the gesture is
    learnt once.
--}}
<button type="button" @click="toggleWide()"
        {{ $attributes->merge(['class' => 'flex items-center gap-2 px-3 py-1 rounded border border-gray-700 bg-gray-900 text-gray-400 hover:text-white transition']) }}
        :class="wide && 'bg-purple-600 border-purple-500 text-white'"
        :title="wide ? '{{ __('merge.wide_off') }}' : '{{ __('merge.wide_on') }}'">
    <i class="fas" :class="wide ? 'fa-compress' : 'fa-expand'"></i>
    <span x-text="wide ? '{{ __('merge.wide_off') }}' : '{{ __('merge.wide_on') }}'"></span>
</button>
