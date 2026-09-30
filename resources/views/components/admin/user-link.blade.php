@props(['user'])

{{-- An account's name on an admin screen, leading to that account's page (admin.users.show) — so
     from any screen that names somebody, their translations are one click away.

     A translation whose account row is gone has no user at all: "[Deleted]", as these screens
     always wrote it, and nothing to open. --}}
@if($user)
    <a href="{{ route('admin.users.show', $user) }}" {{ $attributes->merge(['class' => 'hover:text-purple-400 hover:underline']) }}>{{ $user->name }}</a>
@else
    <span {{ $attributes }}>[Deleted]</span>
@endif
