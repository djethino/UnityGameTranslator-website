@props(['user', 'size' => 32])

{{-- User avatar: generated seed wins (privacy-first, no hosting), then the
     OAuth avatar URL, then a generated fallback seeded by the user id.
     SVGs are built client-side by DiceBear (see hydrateAvatars in app.js). --}}
@php
    $seed = $user->avatar_seed ?: ($user->avatar ? null : 'user-' . $user->id);
@endphp
@if($seed)
    <span data-dicebear-seed="{{ $seed }}" data-dicebear-size="{{ $size }}"
          {{ $attributes->merge(['class' => 'inline-block align-middle leading-none']) }}
          style="width: {{ $size }}px; height: {{ $size }}px;"></span>
@else
    <img src="{{ $user->avatar }}" alt=""
         {{ $attributes->merge(['class' => 'inline-block align-middle rounded-full object-cover']) }}
         style="width: {{ $size }}px; height: {{ $size }}px;" loading="lazy">
@endif
