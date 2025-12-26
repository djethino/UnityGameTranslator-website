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

    <!-- Favicon -->
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16.png">
    <link rel="apple-touch-icon" sizes="128x128" href="/icon-128.png">

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

    @vite(['resources/css/app.css', 'resources/js/app.js'])

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
                        @else
                        <a href="{{ route('login') }}?action=upload" class="text-gray-300 hover:text-white px-3 py-2">
                            <i class="fas fa-upload mr-1"></i> {{ __('nav.upload') }}
                        </a>
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
                        <a href="{{ route('auth.redirect', 'steam') }}" class="bg-gray-800 hover:bg-gray-900 text-white p-2 rounded-lg border border-gray-600" title="Steam">
                            <i class="fab fa-steam text-lg w-5 h-5 flex items-center justify-center"></i>
                        </a>
                        <a href="{{ route('auth.redirect', 'epicgames') }}" class="bg-black hover:bg-gray-900 text-white p-2 rounded-lg border border-gray-600 flex items-center justify-center" title="Epic Games">
                            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M3.537 0C2.165 0 1.66.506 1.66 1.879V18.44a4.262 4.262 0 00.02.433c.031.3.037.59.316.92.027.033.311.245.311.245.153.075.258.13.43.2l8.335 3.491c.433.199.614.276.928.27h.002c.314.006.495-.071.928-.27l8.335-3.492c.172-.07.277-.124.43-.2 0 0 .284-.211.311-.243.28-.33.285-.621.316-.92a4.261 4.261 0 00.02-.434V1.879c0-1.373-.506-1.88-1.878-1.88zm13.366 3.11h.68c1.138 0 1.688.553 1.688 1.696v1.88h-1.374v-1.8c0-.369-.17-.54-.523-.54h-.235c-.367 0-.537.17-.537.539v5.81c0 .369.17.54.537.54h.262c.353 0 .523-.171.523-.54V8.619h1.373v2.143c0 1.144-.562 1.71-1.7 1.71h-.694c-1.138 0-1.7-.566-1.7-1.71V4.82c0-1.144.562-1.709 1.7-1.709zm-12.186.08h3.114v1.274H6.117v2.603h1.648v1.275H6.117v2.774h1.74v1.275h-3.14zm3.816 0h2.198c1.138 0 1.7.564 1.7 1.708v2.445c0 1.144-.562 1.71-1.7 1.71h-.799v3.338h-1.4zm4.53 0h1.4v9.201h-1.4zm-3.13 1.235v3.392h.575c.354 0 .523-.171.523-.54V4.965c0-.368-.17-.54-.523-.54z"/></svg>
                        </a>
                        <a href="{{ route('auth.redirect', 'discord') }}" class="bg-indigo-600 hover:bg-indigo-700 text-white p-2 rounded-lg" title="Discord">
                            <i class="fab fa-discord text-lg w-5 h-5 flex items-center justify-center"></i>
                        </a>
                        <a href="{{ route('auth.redirect', 'twitch') }}" class="bg-purple-600 hover:bg-purple-700 text-white p-2 rounded-lg" title="Twitch">
                            <i class="fab fa-twitch text-lg w-5 h-5 flex items-center justify-center"></i>
                        </a>
                        <a href="{{ route('auth.redirect', 'github') }}" class="bg-gray-700 hover:bg-gray-600 text-white p-2 rounded-lg" title="GitHub">
                            <i class="fab fa-github text-lg w-5 h-5 flex items-center justify-center"></i>
                        </a>
                        <a href="{{ route('auth.redirect', 'google') }}" class="bg-red-600 hover:bg-red-700 text-white p-2 rounded-lg" title="Google">
                            <i class="fab fa-google text-lg w-5 h-5 flex items-center justify-center"></i>
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
                <button id="cookie-accept" class="bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded text-sm font-medium">
                    {{ __('cookies.accept') }}
                </button>
                <button id="cookie-decline" class="bg-gray-700 hover:bg-gray-600 text-white px-4 py-2 rounded text-sm">
                    {{ __('cookies.decline') }}
                </button>
            </div>
        </div>
    </div>

    <script nonce="{{ $cspNonce }}">
        // Check if user has already made a choice
        if (!localStorage.getItem('cookie-consent')) {
            document.getElementById('cookie-banner').classList.remove('hidden');
        }

        document.getElementById('cookie-accept')?.addEventListener('click', function() {
            localStorage.setItem('cookie-consent', 'accepted');
            document.getElementById('cookie-banner').classList.add('hidden');
        });

        document.getElementById('cookie-decline')?.addEventListener('click', function() {
            localStorage.setItem('cookie-consent', 'declined');
            document.getElementById('cookie-banner').classList.add('hidden');
        });
    </script>
</body>
</html>
