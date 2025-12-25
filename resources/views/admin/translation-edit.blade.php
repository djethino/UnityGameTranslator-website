@extends('layouts.app')

@section('title', 'Edit Translation - Admin')

@section('content')
<div class="max-w-2xl mx-auto">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-3xl font-bold"><i class="fas fa-edit mr-2"></i> Edit Translation</h1>
        <a href="{{ route('games.show', $translation->game) }}" class="text-gray-400 hover:text-white">
            <i class="fas fa-arrow-left mr-1"></i> Back to Game
        </a>
    </div>

    <!-- Translation Info -->
    <div class="bg-gray-800 rounded-lg p-4 mb-6 border border-gray-700">
        <div class="flex items-center gap-4">
            @if($translation->game->image_url)
                <img src="{{ $translation->game->image_url }}" class="w-16 h-20 object-cover rounded">
            @endif
            <div>
                <p class="font-semibold text-lg">{{ $translation->game->name }}</p>
                <p class="text-sm text-gray-400">
                    Uploaded by {{ $translation->user->name }} on {{ $translation->created_at->format('M d, Y') }}
                </p>
                <p class="text-sm text-gray-500">
                    {{ number_format($translation->line_count) }} lines &bull; {{ $translation->download_count }} downloads
                </p>
            </div>
        </div>
    </div>

    @if($errors->any())
        <div class="bg-red-900 border border-red-700 text-red-100 px-4 py-3 rounded mb-6">
            <ul class="list-disc list-inside">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form action="{{ route('admin.translations.update', $translation) }}" method="POST" class="bg-gray-800 rounded-lg p-6 border border-gray-700">
        @csrf
        @method('PUT')

        <!-- Languages -->
        <div class="grid grid-cols-2 gap-4 mb-6">
            <div>
                <label class="block text-sm font-medium text-gray-300 mb-2">Source Language</label>
                <select name="source_language" required
                    class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-3 text-white focus:ring-purple-500 focus:border-purple-500">
                    @foreach($languages as $lang)
                        <option value="{{ $lang }}" {{ $translation->source_language == $lang ? 'selected' : '' }}>{{ $lang }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-300 mb-2">Target Language</label>
                <select name="target_language" required
                    class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-3 text-white focus:ring-purple-500 focus:border-purple-500">
                    @foreach($languages as $lang)
                        <option value="{{ $lang }}" {{ $translation->target_language == $lang ? 'selected' : '' }}>{{ $lang }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <!-- Translation Type -->
        <div class="mb-6">
            <label class="block text-sm font-medium text-gray-300 mb-2">Translation Type</label>
            <div class="grid grid-cols-3 gap-3">
                <label class="flex flex-col items-center p-3 bg-gray-700 rounded-lg cursor-pointer border-2 {{ $translation->type == 'ai' ? 'border-purple-500 bg-gray-600' : 'border-transparent' }} hover:border-purple-500 transition type-option">
                    <input type="radio" name="type" value="ai" {{ $translation->type == 'ai' ? 'checked' : '' }} class="hidden">
                    <i class="fas fa-robot text-2xl text-blue-400 mb-2"></i>
                    <span class="text-sm font-medium">Full AI</span>
                </label>
                <label class="flex flex-col items-center p-3 bg-gray-700 rounded-lg cursor-pointer border-2 {{ $translation->type == 'ai_corrected' ? 'border-purple-500 bg-gray-600' : 'border-transparent' }} hover:border-purple-500 transition type-option">
                    <input type="radio" name="type" value="ai_corrected" {{ $translation->type == 'ai_corrected' ? 'checked' : '' }} class="hidden">
                    <i class="fas fa-user-edit text-2xl text-purple-400 mb-2"></i>
                    <span class="text-sm font-medium">AI + Human</span>
                </label>
                <label class="flex flex-col items-center p-3 bg-gray-700 rounded-lg cursor-pointer border-2 {{ $translation->type == 'human' ? 'border-purple-500 bg-gray-600' : 'border-transparent' }} hover:border-purple-500 transition type-option">
                    <input type="radio" name="type" value="human" {{ $translation->type == 'human' ? 'checked' : '' }} class="hidden">
                    <i class="fas fa-user text-2xl text-green-400 mb-2"></i>
                    <span class="text-sm font-medium">Human</span>
                </label>
            </div>
        </div>

        <!-- Status -->
        <div class="mb-6">
            <label class="block text-sm font-medium text-gray-300 mb-2">Status</label>
            <div class="flex gap-4">
                <label class="flex items-center cursor-pointer">
                    <input type="radio" name="status" value="in_progress" {{ $translation->status == 'in_progress' ? 'checked' : '' }} class="mr-2 text-purple-600">
                    <span><i class="fas fa-clock text-yellow-400 mr-1"></i> In Progress</span>
                </label>
                <label class="flex items-center cursor-pointer">
                    <input type="radio" name="status" value="complete" {{ $translation->status == 'complete' ? 'checked' : '' }} class="mr-2 text-purple-600">
                    <span><i class="fas fa-check text-green-400 mr-1"></i> Complete</span>
                </label>
            </div>
        </div>

        <!-- Notes -->
        <div class="mb-6">
            <label class="block text-sm font-medium text-gray-300 mb-2">Notes</label>
            <textarea name="notes" rows="3" maxlength="1000"
                placeholder="Translation notes..."
                class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-3 text-white focus:ring-purple-500 focus:border-purple-500">{{ $translation->notes }}</textarea>
        </div>

        <div class="flex gap-4">
            <a href="{{ route('games.show', $translation->game) }}" class="flex-1 bg-gray-600 hover:bg-gray-500 text-white font-semibold py-3 rounded-lg transition text-center">
                Cancel
            </a>
            <button type="submit" class="flex-1 bg-purple-600 hover:bg-purple-700 text-white font-semibold py-3 rounded-lg transition">
                <i class="fas fa-save mr-2"></i> Save Changes
            </button>
        </div>
    </form>
</div>

<script>
document.querySelectorAll('.type-option').forEach(option => {
    option.addEventListener('click', () => {
        document.querySelectorAll('.type-option').forEach(o => {
            o.classList.remove('border-purple-500', 'bg-gray-600');
            o.classList.add('border-transparent');
        });
        option.classList.remove('border-transparent');
        option.classList.add('border-purple-500', 'bg-gray-600');
    });
});
</script>
@endsection
