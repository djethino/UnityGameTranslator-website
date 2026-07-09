{{-- Hidden inputs mirroring the shared view state ($stateParams in merge/show.blade.php).
     Pass $params = the state array minus the key(s) the enclosing form controls. --}}
@foreach($params as $param => $value)
    @if(is_array($value))
        @foreach($value as $item)
            <input type="hidden" name="{{ $param }}[]" value="{{ $item }}">
        @endforeach
    @else
        <input type="hidden" name="{{ $param }}" value="{{ $value }}">
    @endif
@endforeach
