<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'UnityGameTranslator - Community Game Translations')</title>
    <meta name="description" content="@yield('description', 'Free automatic AI translation for Unity games. Download community translations or generate your own with local AI. No API costs.')">
    <meta name="keywords" content="Unity game translation, automatic game translation, AI game localization, free game translation, Unity mod, Ollama translation">
    <link rel="canonical" href="{{ url()->current() }}">

    <!-- Open Graph -->
    <meta property="og:type" content="@yield('og_type', 'website')">
    <meta property="og:title" content="@yield('title', 'UnityGameTranslator - Community Game Translations')">
    <meta property="og:description" content="@yield('description', 'Free automatic AI translation for Unity games. Download community translations or generate your own with local AI. No API costs.')">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta property="og:site_name" content="UnityGameTranslator">
    <meta property="og:locale" content="{{ app()->getLocale() }}">
    @hasSection('og_image')
    <meta property="og:image" content="@yield('og_image')">
    @endif

    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    @stack('head')
</head>
<body class="bg-gray-900 text-gray-100 min-h-screen">
    <nav class="bg-gray-800 border-b border-gray-700">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between h-16">
                <div class="flex items-center">
                    <a href="{{ route('home') }}" class="text-xl font-bold text-purple-400">
                        <i class="fas fa-language mr-2"></i>UnityGameTranslator
                    </a>
                    <div class="ml-10 flex space-x-4">
                        <a href="{{ route('games.index') }}" class="text-gray-300 hover:text-white px-3 py-2">
                            <i class="fas fa-gamepad mr-1"></i> {{ __('nav.games') }}
                        </a>
                        <a href="{{ route('docs') }}" class="text-gray-300 hover:text-white px-3 py-2">
                            <i class="fas fa-book mr-1"></i> {{ __('nav.docs') }}
                        </a>
                        @auth
                        <a href="{{ route('translations.create') }}" class="text-gray-300 hover:text-white px-3 py-2">
                            <i class="fas fa-upload mr-1"></i> {{ __('nav.upload') }}
                        </a>
                        <a href="{{ route('translations.mine') }}" class="text-gray-300 hover:text-white px-3 py-2">
                            <i class="fas fa-folder mr-1"></i> {{ __('nav.my_translations') }}
                        </a>
                        @if(auth()->user()->isAdmin())
                        <a href="{{ route('admin.dashboard') }}" class="text-yellow-400 hover:text-yellow-300 px-3 py-2">
                            <i class="fas fa-shield-alt mr-1"></i> {{ __('nav.admin') }}@if($pendingReportsCount > 0) <span class="bg-red-600 text-white text-xs font-bold px-1.5 py-0.5 rounded-full">{{ $pendingReportsCount }}</span>@endif
                        </a>
                        @endif
                        @endauth
                    </div>
                </div>
                <div class="flex items-center space-x-4">
                    <!-- Language Switcher -->
                    <div class="relative" x-data="{ open: false }">
                        <button @click="open = !open" @click.away="open = false" class="flex items-center text-gray-300 hover:text-white px-2 py-1 rounded transition">
                            <span class="text-lg mr-1">{{ config('locales.supported')[app()->getLocale()]['flag'] ?? '🌐' }}</span>
                            <span class="hidden sm:inline text-sm">{{ config('locales.supported')[app()->getLocale()]['native'] ?? 'Language' }}</span>
                            <i class="fas fa-chevron-down text-xs ml-1"></i>
                        </button>
                        <div x-show="open" x-transition class="absolute right-0 mt-2 w-48 bg-gray-800 border border-gray-700 rounded-lg shadow-xl z-50 max-h-80 overflow-y-auto">
                            @foreach(config('locales.supported', []) as $code => $locale)
                                <a href="{{ route('locale.switch', $code) }}"
                                   class="flex items-center px-4 py-2 text-sm hover:bg-gray-700 transition {{ app()->getLocale() === $code ? 'bg-purple-900 text-purple-200' : 'text-gray-300' }}">
                                    <span class="text-lg mr-2">{{ $locale['flag'] }}</span>
                                    <span>{{ $locale['native'] }}</span>
                                </a>
                            @endforeach
                        </div>
                    </div>

                    @guest
                        <a href="{{ route('auth.redirect', 'google') }}" class="bg-red-600 hover:bg-red-700 text-white p-2 rounded-lg" title="Google">
                            <i class="fab fa-google text-lg"></i>
                        </a>
                        <a href="{{ route('auth.redirect', 'discord') }}" class="bg-indigo-600 hover:bg-indigo-700 text-white p-2 rounded-lg" title="Discord">
                            <i class="fab fa-discord text-lg"></i>
                        </a>
                        <a href="{{ route('auth.redirect', 'github') }}" class="bg-gray-700 hover:bg-gray-600 text-white p-2 rounded-lg" title="GitHub">
                            <i class="fab fa-github text-lg"></i>
                        </a>
                        <a href="{{ route('auth.redirect', 'twitch') }}" class="bg-purple-600 hover:bg-purple-700 text-white p-2 rounded-lg" title="Twitch">
                            <i class="fab fa-twitch text-lg"></i>
                        </a>
                        <a href="{{ route('auth.redirect', 'steam') }}" class="bg-gray-800 hover:bg-gray-900 text-white p-2 rounded-lg border border-gray-600" title="Steam">
                            <i class="fab fa-steam text-lg"></i>
                        </a>
                        <a href="{{ route('auth.redirect', 'epicgames') }}" class="bg-black hover:bg-gray-900 text-white p-2 rounded-lg border border-gray-600" title="Epic Games">
                            <img src="https://www.epicgames.com/favicon.ico" alt="Epic Games" class="w-5 h-5">
                        </a>
                    @else
                        <div class="flex items-center space-x-3">
                            <a href="{{ route('profile.edit') }}" class="flex items-center space-x-2 hover:text-purple-400 transition">
                                @if(auth()->user()->avatar)
                                    <img src="{{ auth()->user()->avatar }}" alt="" class="w-8 h-8 rounded-full">
                                @endif
                                <span class="text-gray-300">{{ auth()->user()->name }}</span>
                            </a>
                            <form action="{{ route('logout') }}" method="POST" class="inline">
                                @csrf
                                <button type="submit" class="text-gray-400 hover:text-white" title="{{ __('nav.logout') }}">
                                    <i class="fas fa-sign-out-alt"></i>
                                </button>
                            </form>
                        </div>
                    @endguest
                </div>
            </div>
        </div>
    </nav>

    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        @if(session('success'))
            <div class="bg-green-900 border border-green-700 text-green-100 px-4 py-3 rounded mb-6">
                {{ session('success') }}
            </div>
        @endif
        @if(session('error'))
            <div class="bg-red-900 border border-red-700 text-red-100 px-4 py-3 rounded mb-6">
                {{ session('error') }}
            </div>
        @endif
        @yield('content')
    </main>

    <footer class="bg-gray-800 border-t border-gray-700 mt-auto py-6">
        <div class="max-w-7xl mx-auto px-4 text-center text-gray-400">
            <p class="mb-2">{{ __('footer.share') }} - <a href="https://github.com/djethino/UnityGameTranslator" class="text-purple-400 hover:text-purple-300">GitHub</a></p>
            <p class="text-sm text-gray-500">
                <a href="{{ route('legal.mentions') }}" class="hover:text-gray-300">{{ __('footer.legal') }}</a>
                <span class="mx-2">|</span>
                <a href="{{ route('legal.privacy') }}" class="hover:text-gray-300">{{ __('footer.privacy') }}</a>
                <span class="mx-2">|</span>
                <a href="{{ route('legal.terms') }}" class="hover:text-gray-300">{{ __('footer.terms') }}</a>
            </p>
        </div>
    </footer>

    <!-- Cookie Consent Banner -->
    <div id="cookie-banner" class="fixed bottom-0 left-0 right-0 bg-gray-800 border-t border-gray-700 p-4 z-50 hidden">
        <div class="max-w-7xl mx-auto flex flex-col sm:flex-row items-center justify-between gap-4">
            <p class="text-gray-300 text-sm">
                <i class="fas fa-cookie-bite text-purple-400 mr-2"></i>
                {{ __('cookies.message') }}
            </p>
            <div class="flex gap-3">
                <button onclick="acceptCookies()" class="bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded text-sm font-medium">
                    {{ __('cookies.accept') }}
                </button>
                <button onclick="declineCookies()" class="bg-gray-700 hover:bg-gray-600 text-white px-4 py-2 rounded text-sm">
                    {{ __('cookies.decline') }}
                </button>
            </div>
        </div>
    </div>

    <script>
        // Check if user has already made a choice
        if (!localStorage.getItem('cookie-consent')) {
            document.getElementById('cookie-banner').classList.remove('hidden');
        }

        function acceptCookies() {
            localStorage.setItem('cookie-consent', 'accepted');
            document.getElementById('cookie-banner').classList.add('hidden');
        }

        function declineCookies() {
            localStorage.setItem('cookie-consent', 'declined');
            document.getElementById('cookie-banner').classList.add('hidden');
        }
    </script>
</body>
</html>
