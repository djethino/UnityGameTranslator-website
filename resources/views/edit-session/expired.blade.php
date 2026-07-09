@extends('layouts.app')

@section('title', __('edit_session.expired_title'))

@section('content')
<div class="container mx-auto px-4 py-16 max-w-lg text-center">
    <div class="bg-gray-800 rounded-lg border border-gray-700 p-8">
        <i class="fas fa-hourglass-end text-4xl text-gray-500 mb-4"></i>
        <h1 class="text-2xl font-bold text-white mb-2">{{ __('edit_session.expired_title') }}</h1>
        <p class="text-gray-400">{{ __('edit_session.expired_help') }}</p>
    </div>
</div>
@endsection
