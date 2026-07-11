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
            <x-avatar :user="$user" :size="64" />
            <div class="flex-1">
                <p class="text-lg font-semibold">{{ $user->name }}</p>
                @if($user->email)
                    <p class="text-sm text-gray-400">{{ $user->email }}</p>
                @endif
                <p class="text-xs text-gray-500 mt-1">
                    @if($user->isLocalAccount())
                        <i class="fas fa-user-shield mr-1"></i> {{ __('profile.local_account') }}
                    @else
                        <i class="fab fa-{{ $user->provider }} mr-1"></i>
                        {{ __('profile.connected_via', ['provider' => ucfirst($user->provider)]) }}
                    @endif
                </p>
                <div class="flex gap-3 mt-2">
                    <form method="POST" action="{{ route('profile.avatar') }}">
                        @csrf
                        <button type="submit" class="text-xs text-purple-400 hover:text-purple-300 transition">
                            <i class="fas fa-dice mr-1"></i>{{ __('profile.avatar_reroll') }}
                        </button>
                    </form>
                    @if($user->avatar && $user->avatar_seed)
                    <form method="POST" action="{{ route('profile.avatar') }}">
                        @csrf
                        <input type="hidden" name="action" value="platform">
                        <button type="submit" class="text-xs text-gray-500 hover:text-gray-300 transition">
                            {{ __('profile.avatar_platform') }}
                        </button>
                    </form>
                    @endif
                </div>
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

            @if(!$user->isLocalAccount())
            <div class="mb-6">
                <label class="block text-sm font-medium text-gray-300 mb-2">{{ __('profile.email') }}</label>
                <input type="email" value="{{ $user->email }}" disabled
                    class="w-full bg-gray-600 border border-gray-600 rounded-lg px-4 py-3 text-gray-400 cursor-not-allowed">
                <p class="text-xs text-gray-500 mt-1">{{ __('profile.email_managed', ['provider' => ucfirst($user->provider)]) }}</p>
            </div>
            @endif

            <div class="mb-6">
                <label class="block text-sm font-medium text-gray-300 mb-2">{{ __('profile.language') }}</label>
                <select name="locale" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-3 text-white focus:ring-purple-500 focus:border-purple-500">
                    @foreach(config('locales.supported', []) as $code => $locale)
                        <option value="{{ $code }}" {{ (old('locale', $user->locale) ?? app()->getLocale()) === $code ? 'selected' : '' }}>
                            {{ strtoupper($code) }} — {{ $locale['native'] }} ({{ $locale['name'] }})
                        </option>
                    @endforeach
                </select>
            </div>

            <button type="submit" class="w-full bg-purple-600 hover:bg-purple-700 text-white font-semibold py-3 rounded-lg transition">
                <i class="fas fa-save mr-2"></i> {{ __('profile.save') }}
            </button>
        </form>
    </div>

    @if($user->isLocalAccount())
    <!-- Recovery codes (local accounts only) -->
    <div class="mt-6 bg-gray-800 rounded-lg p-6 border border-gray-700">
        <h2 class="font-semibold mb-2"><i class="fas fa-key mr-2 text-yellow-400"></i>{{ __('auth.codes_title') }}</h2>
        <p class="text-sm text-gray-400 mb-4">{{ __('profile.codes_regenerate_hint') }}</p>
        <form method="POST" action="{{ route('local.recovery-codes.regenerate') }}" class="flex gap-3">
            @csrf
            <input type="password" name="password" required placeholder="{{ __('auth.password') }}" autocomplete="current-password"
                   class="flex-1 bg-gray-700 border border-gray-600 rounded-lg px-4 py-2 text-white focus:outline-none focus:border-purple-500">
            <button type="submit" class="bg-gray-700 hover:bg-gray-600 text-white px-4 py-2 rounded-lg transition text-sm">
                {{ __('profile.codes_regenerate') }}
            </button>
        </form>
        @error('password')<p class="text-red-400 text-sm mt-2">{{ $message }}</p>@enderror
    </div>
    @endif

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
            <button type="button" id="openDeleteModalBtn" class="flex-1 bg-red-900 hover:bg-red-800 text-red-200 py-3 rounded-lg transition">
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
                <button type="button" id="closeDeleteModalBtn" class="flex-1 bg-gray-600 hover:bg-gray-500 text-white py-2 rounded-lg">
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
(function() {
    var modal = document.getElementById('deleteModal');

    function openDeleteModal() {
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    }

    function closeDeleteModal() {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }

    document.getElementById('openDeleteModalBtn').addEventListener('click', openDeleteModal);
    document.getElementById('closeDeleteModalBtn').addEventListener('click', closeDeleteModal);
    modal.addEventListener('click', function(e) { if(e.target === modal) closeDeleteModal(); });
})();
</script>
@endsection
