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

            {{-- 🔴 **Two languages, because they are two questions.** The one this site is read in
                 is one of twenty; the one somebody plays in is one of the catalogue's ninety, and
                 the two are routinely different — an English interface with French subtitles is
                 ordinary, and a Tamil player has no interface language to be inferred from. They
                 used to be one setting, which quietly told that player nothing existed for them. --}}
            <div class="mb-6">
                <label class="block text-sm font-medium text-gray-300 mb-2">{{ __('profile.language') }}</label>
                {{-- ⚠ marks=false: these are INTERFACE locales, and their labels are native
                     names ("Português (Brasil)"), not the catalogue's language names. Asking
                     <x-language-mark> to recognise them would draw nothing, quietly. The flag
                     comes from the locale's own entry instead. --}}
                <x-language-select
                    name="locale"
                    :choices="collect(config('locales.supported', []))->mapWithKeys(fn ($l, $code) => [$code => strtoupper($code) . ' — ' . $l['native']])->all()"
                    :selected="old('locale', $user->locale) ?? app()->getLocale()"
                    :flags="collect(config('locales.supported', []))->mapWithKeys(fn ($l, $code) => [$code => $l['flag'] ?? null])->all()"
                    :marks="false" />
                <p class="text-xs text-gray-500 mt-1">{{ __('profile.language_hint') }}</p>
            </div>

            <div class="mb-6">
                <label class="block text-sm font-medium text-gray-300 mb-2">{{ __('profile.game_language') }}</label>
                {{-- ⚠ "Follow the interface" is a real answer and it is the default: it is what
                     the site did before this setting existed, so nobody's ordering changes until
                     they say otherwise. --}}
                <x-language-select
                    name="game_language"
                    :choices="\App\Services\CatalogStore::languageChoices()"
                    :selected="old('game_language', $user->game_language)"
                    :empty="__('profile.game_language_follows')" />
                <p class="text-xs text-gray-500 mt-1">{{ __('profile.game_language_hint') }}</p>
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

    {{-- Linked devices — the way in, and the only one.

         ⚠ A card here rather than an entry in the navigation bar as well: this opens a screen that
         cuts accesses, and an action has one door. The screen itself is a page of its own because
         this column is `max-w-xl`, far too narrow for a list grouped by machine. --}}
    <div class="mt-6 bg-gray-800 rounded-lg p-6 border border-gray-700">
        <h2 class="font-semibold mb-4"><i class="fas fa-link mr-2 text-purple-400"></i>{{ __('connections.page_title') }}</h2>
        <p class="text-gray-400 text-sm mb-4">{{ __('connections.profile_card_hint') }}</p>

        {{-- ⚠ The figures, not just the door. "Open linked devices" is a label, not a reason: what
             makes somebody look is seeing a count they do not recognise. Same two-box grid as the
             Statistics card just below, because that is how this page already shows numbers. --}}
        <div class="grid grid-cols-2 gap-4 text-center mb-4">
            <div class="bg-gray-700 rounded-lg p-4">
                <p class="text-2xl font-bold text-purple-400">{{ $linkedDevices }}</p>
                <p class="text-sm text-gray-400">{{ __('connections.card_devices') }}</p>
            </div>
            <div class="bg-gray-700 rounded-lg p-4">
                <p class="text-2xl font-bold text-gray-200">{{ $otherBrowsers }}</p>
                <p class="text-sm text-gray-400">{{ __('connections.card_browsers') }}</p>
            </div>
        </div>

        <a href="{{ route('profile.connections') }}" class="block w-full bg-gray-700 hover:bg-gray-600 text-white text-center py-3 rounded-lg transition">
            <i class="fas fa-sliders mr-2"></i> {{ __('connections.profile_card_open') }}
        </a>
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

            {{-- 🔴 Nobody's files are held hostage here: what somebody published is theirs to
                 withdraw. Off by default all the same, because the list above has just said the
                 translations stay under a name nobody can trace — and because withdrawing a Main
                 takes with it what other people built on it.

                 ⚠ The consequence for OTHERS is spelled out, not just the one for the reader.
                 "Your translations will be deleted" is true and is not the whole truth: branches
                 lose what they were contributing to, and forks lose the credit of where they came
                 from. Somebody choosing this should be choosing that too. --}}
            @if($ownTranslations->isNotEmpty())
            <label class="flex items-start gap-3 mb-2 p-3 bg-gray-900/50 border border-gray-700 rounded-lg cursor-pointer">
                <input type="checkbox" name="delete_translations" value="1"
                       class="mt-1 rounded bg-gray-700 border-gray-600 text-red-500 focus:ring-red-500">
                <span class="text-sm">
                    <span class="text-gray-200">{{ __('profile.delete_translations') }}</span>
                    <span class="block text-gray-400 text-xs mt-1">{{ __('profile.delete_translations_hint') }}</span>
                </span>
            </label>

            {{-- 🔴 What the box would take, named. It said "my translations" and showed none of
                 them: somebody with a dozen across as many games had to remember what they had
                 before agreeing to destroy it.

                 ⚠ The role is on every line because it decides who ELSE is affected — removing a
                 Main takes with it what its branches were contributing to, removing a branch
                 withdraws an offer nobody had accepted. Same act, different consequences.

                 ⚠ Scrolls rather than truncates: a list cut at five would hide exactly what
                 somebody needs to see before agreeing to lose it. --}}
            <div class="mb-4 max-h-40 overflow-y-auto rounded-lg border border-gray-700 bg-gray-900/30">
                <ul class="divide-y divide-gray-700/60 text-xs">
                    @foreach($ownTranslations as $own)
                        <li class="flex items-center justify-between gap-3 px-3 py-2">
                            <span class="text-gray-300 truncate">
                                {{ $own->game->name ?? '—' }}
                                <span class="text-gray-500">· {{ $own->target_language }}</span>
                            </span>
                            {{-- The words this site already uses for the two roles, everywhere
                                 else. A second pair here would be the same fact under two names. --}}
                            <span class="shrink-0 {{ $own->lineageRole() === 'main' ? 'text-purple-300' : 'text-gray-400' }}">
                                {{ $own->lineageRole() === 'main' ? __('translation.role_main') : __('translation.role_branch') }}
                            </span>
                        </li>
                    @endforeach
                </ul>
            </div>
            @endif

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
