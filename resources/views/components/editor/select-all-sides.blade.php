@props(['sides'])

{{--
    "Take everything from this side", one button per column.

    ⚠ It sits beside the side FILTERS, in both bars, because that is where the eye already is when
    the question is "this column, then". Same order as the columns and as the boxes above: the
    result being built first, what is offered second.

    🔴 A component rather than the loop written twice: it was in the filter bar only, so the
    workbench — which covers that bar — could not sweep a column at all. Two copies of a loop is
    how the guard on one of them goes missing.
--}}
@foreach ($sides as $side)
    <button type="button" @click="selectAllFrom('{{ $side['id'] }}')"
        class="{{ $side['tone'] }} hover:text-white shrink-0">
        <i class="fas fa-check-double mr-1"></i> {{ $side['selectAllLabel'] }}
    </button>
@endforeach
