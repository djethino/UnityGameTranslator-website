@props([
    // Column id, matching the data-col carried by that column's cells. Pass an Alpine expression
    // instead of a literal with :bind — the branch columns only exist once their data has loaded.
    'col',
    'bind' => false,
])

{{--
    The grab edge of a resizable column. Sits inside a <th> that carries `relative`.

    It names its own column rather than reading the header it sits in: a branch header spans the
    tag and the value together, and what the edge moves is the pair.

    stop on both mouse events, because the header cell is also a sort button — without it, every
    drop on the edge would flip the sort and send the rows being measured somewhere else.

    Six pixels, not one: an edge that has to be hit exactly is an edge nobody uses. The colour on
    hover is what says it is there at all; a cursor change alone is only ever found by accident.
--}}
<span class="absolute top-0 right-0 h-full w-1.5 cursor-col-resize select-none
             hover:bg-purple-500/60 active:bg-purple-500 transition-colors"
      @if($bind) :data-resize-col="{{ $col }}" @else data-resize-col="{{ $col }}" @endif
      @mousedown.stop="startColumnResize($event)"
      @click.stop
      @dblclick.stop="resetColumnWidth($event)"
      title="{{ __('editor.resize_column') }}"></span>
