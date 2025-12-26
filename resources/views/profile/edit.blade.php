@extends('layouts.app')

@section('title', __('profile.title') . ' - UnityGameTranslator')

@section('content')
<div class="max-w-xl mx-auto">
    <h1 class="text-3xl font-bold mb-8"><i class="fas fa-user-cog mr-3"></i>{{ __('profile.title') }}</h1>

    @if($errors->any())
        <div class="bg-red-900 border border-red-700 text-red-100 px-4 py-3 rounded mb-6">
            <ul class="list-disc list-inside">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="bg-gray-800 rounded-lg p-6 border border-gray-700">
        <!-- Current info -->
        <div class="flex items-center gap-4 mb-6 pb-6 border-b border-gray-700">
            @if($user->avatar)
                <img src="{{ $user->avatar }}" alt="" class="w-16 h-16 rounded-full">
            @else
                <div class="w-16 h-16 rounded-full bg-gray-700 flex items-center justify-center">
                    <i class="fas fa-user text-2xl text-gray-500"></i>
                </div>
            @endif
            <div>
                <p class="text-lg font-semibold">{{ $user->name }}</p>
                <p class="text-sm text-gray-400">{{ $user->email }}</p>
                <p class="text-xs text-gray-500 mt-1">
                    <i class="fab fa-{{ $user->provider }} mr-1"></i>
                    {{ __('profile.connected_via', ['provider' => ucfirst($user->provider)]) }}
                </p>
            </div>
        </div>

        <!-- Edit form -->
        <form action="{{ route('profile.update') }}" method="POST">
            @csrf
            @method('PUT')

            <div class="mb-6">
                <label class="block text-sm font-medium text-gray-300 mb-2">{{ __('profile.display_name') }}</label>
                <input type="text" name="name" value="{{ old('name', $user->name) }}"
                    required
                    minlength="2"
                    maxlength="50"
                    pattern="[a-zA-Z0-9_\-]+"
                    class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-3 text-white focus:ring-purple-500 focus:border-purple-500">
                <p class="text-xs text-gray-500 mt-1">{{ __('profile.name_help') }}</p>
            </div>

            <div class="mb-6">
                <label class="block text-sm font-medium text-gray-300 mb-2">{{ __('profile.email') }}</label>
                <input type="email" value="{{ $user->email }}" disabled
                    class="w-full bg-gray-600 border border-gray-600 rounded-lg px-4 py-3 text-gray-400 cursor-not-allowed">
                <p class="text-xs text-gray-500 mt-1">{{ __('profile.email_managed', ['provider' => ucfirst($user->provider)]) }}</p>
            </div>

            <div class="mb-6">
                <label class="block text-sm font-medium text-gray-300 mb-2">{{ __('profile.language') }}</label>
                <select name="locale" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-3 text-white focus:ring-purple-500 focus:border-purple-500">
                    @foreach(config('locales.supported', []) as $code => $locale)
                        <option value="{{ $code }}" {{ (old('locale', $user->locale) ?? app()->getLocale()) === $code ? 'selected' : '' }}>
                            {{ $locale['flag'] }} {{ $locale['native'] }} ({{ $locale['name'] }})
                        </option>
                    @endforeach
                </select>
            </div>

            <button type="submit" class="w-full bg-purple-600 hover:bg-purple-700 text-white font-semibold py-3 rounded-lg transition">
                <i class="fas fa-save mr-2"></i> {{ __('profile.save') }}
            </button>
        </form>
    </div>

    <!-- Stats -->
    <div class="mt-6 bg-gray-800 rounded-lg p-6 border border-gray-700">
        <h2 class="font-semibold mb-4"><i class="fas fa-chart-bar mr-2 text-purple-400"></i>{{ __('profile.statistics') }}</h2>
        <div class="grid grid-cols-2 gap-4 text-center">
            <div class="bg-gray-700 rounded-lg p-4">
                <p class="text-2xl font-bold text-purple-400">{{ $user->translations()->count() }}</p>
                <p class="text-sm text-gray-400">{{ __('profile.translations') }}</p>
            </div>
            <div class="bg-gray-700 rounded-lg p-4">
                <p class="text-2xl font-bold text-green-400">{{ $user->translations()->sum('download_count') }}</p>
                <p class="text-sm text-gray-400">{{ __('profile.total_downloads') }}</p>
            </div>
        </div>
    </div>

    <!-- Member since -->
    <p class="text-center text-gray-500 text-sm mt-6">
        {{ __('profile.member_since', ['date' => $user->created_at->format('F Y')]) }}
    </p>

    <!-- GDPR Section -->
    <div class="mt-6 bg-gray-800 rounded-lg p-6 border border-gray-700">
        <h2 class="font-semibold mb-4"><i class="fas fa-shield-alt mr-2 text-purple-400"></i>{{ __('profile.your_data') }}</h2>
        <p class="text-gray-400 text-sm mb-4">{{ __('profile.gdpr_info') }}</p>

        <div class="flex flex-col sm:flex-row gap-3">
            <!-- Export data -->
            <a href="{{ route('profile.export') }}" class="flex-1 bg-gray-700 hover:bg-gray-600 text-white text-center py-3 rounded-lg transition">
                <i class="fas fa-download mr-2"></i> {{ __('profile.export_data') }}
            </a>

            <!-- Delete account -->
            <button type="button" onclick="openDeleteModal()" class="flex-1 bg-red-900 hover:bg-red-800 text-red-200 py-3 rounded-lg transition">
                <i class="fas fa-trash-alt mr-2"></i> {{ __('profile.delete_account') }}
            </button>
        </div>
    </div>
</div>

<!-- Delete Account Modal -->
<div id="deleteModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
    <div class="bg-gray-800 rounded-lg p-6 max-w-md w-full mx-4 border border-gray-700">
        <h3 class="text-xl font-semibold text-red-400 mb-4">
            <i class="fas fa-exclamation-triangle mr-2"></i>{{ __('profile.delete_confirm_title') }}
        </h3>
        <p class="text-gray-300 mb-4">{{ __('profile.delete_confirm_text') }}</p>
        <ul class="text-gray-400 text-sm mb-6 list-disc list-inside">
            <li>{{ __('profile.delete_warning_translations') }}</li>
            <li>{{ __('profile.delete_warning_votes') }}</li>
            <li>{{ __('profile.delete_warning_irreversible') }}</li>
        </ul>

        <form action="{{ route('profile.destroy') }}" method="POST">
            @csrf
            @method('DELETE')
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-300 mb-2">{{ __('profile.delete_confirm_input', ['name' => $user->name]) }}</label>
                <input type="text" name="confirm_name" required autocomplete="off"
                    class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-3 text-white focus:ring-red-500 focus:border-red-500"
                    placeholder="{{ $user->name }}">
            </div>
            <div class="flex gap-3">
                <button type="button" onclick="closeDeleteModal()" class="flex-1 bg-gray-600 hover:bg-gray-500 text-white py-2 rounded-lg">
                    {{ __('common.cancel') }}
                </button>
                <button type="submit" class="flex-1 bg-red-600 hover:bg-red-700 text-white py-2 rounded-lg">
                    {{ __('profile.delete_account') }}
                </button>
            </div>
        </form>
    </div>
</div>

<script nonce="{{ $cspNonce }}">
function openDeleteModal() {
    document.getElementById('deleteModal').classList.remove('hidden');
    document.getElementById('deleteModal').classList.add('flex');
}
function closeDeleteModal() {
    document.getElementById('deleteModal').classList.add('hidden');
    document.getElementById('deleteModal').classList.remove('flex');
}
document.getElementById('deleteModal').onclick = function(e) { if(e.target===this) closeDeleteModal(); };
</script>
@endsection
