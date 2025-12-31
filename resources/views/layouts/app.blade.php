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

    <!-- Hreflang for multilingual SEO -->
    @foreach(config('locales.supported', []) as $code => $locale)
    <link rel="alternate" hreflang="{{ $code }}" href="{{ url()->current() }}{{ str_contains(url()->current(), '?') ? '&' : '?' }}lang={{ $code }}">
    @endforeach
    <link rel="alternate" hreflang="x-default" href="{{ url()->current() }}">

    <!-- Open Graph -->
    <meta property="og:type" content="@yield('og_type', 'website')">
    <meta property="og:title" content="@yield('title', 'UnityGameTranslator - Community Game Translations')">
    <meta property="og:description" content="@yield('description', 'Free automatic AI translation for Unity games. Download community translations or generate your own with local AI. No API costs.')">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta property="og:site_name" content="UnityGameTranslator">
    <meta property="og:locale" content="{{ app()->getLocale() }}">
    @hasSection('og_image')
    <meta property="og:image" content="@yield('og_image')">
    @else
    <meta property="og:image" content="{{ asset('images/og-default.png') }}">
    @endif

    <!-- Twitter Cards -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="@yield('title', 'UnityGameTranslator - Community Game Translations')">
    <meta name="twitter:description" content="@yield('description', 'Free automatic AI translation for Unity games. Download community translations or generate your own with local AI. No API costs.')">
    @hasSection('og_image')
    <meta name="twitter:image" content="@yield('og_image')">
    @else
    <meta name="twitter:image" content="{{ asset('images/og-default.png') }}">
    @endif

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    @stack('head')
</head>
<body class="animated-bg text-gray-100 min-h-screen flex flex-col overflow-x-hidden">
    <nav class="bg-gray-800 border-b border-gray-700" x-data="{ mobileMenuOpen: false }">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between h-16">
                <!-- Logo + Desktop Nav -->
                <div class="flex items-center">
                    <a href="{{ route('home') }}" class="text-xl font-bold text-purple-400 flex items-center">
                        <img src="/logo.svg" alt="UGT" class="w-8 h-8 mr-2"><span class="hidden sm:inline">UnityGameTranslator</span><span class="sm:hidden">UGT</span>
                    </a>
                    <!-- Desktop Navigation -->
                    <div class="hidden md:flex ml-10 space-x-4">
                        <a href="{{ route('games.index') }}" class="text-gray-300 hover:text-white px-3 py-2 transition">
                            <i class="fas fa-gamepad mr-1"></i> {{ __('nav.games') }}
                        </a>
                        <a href="{{ route('docs') }}" class="text-gray-300 hover:text-white px-3 py-2 transition">
                            <i class="fas fa-book mr-1"></i> {{ __('nav.docs') }}
                        </a>
                        @auth
                        <a href="{{ route('translations.create') }}" class="text-gray-300 hover:text-white px-3 py-2 transition">
                            <i class="fas fa-upload mr-1"></i> {{ __('nav.upload') }}
                        </a>
                        @else
                        <a href="{{ route('login') }}?action=upload" class="text-gray-300 hover:text-white px-3 py-2 transition">
                            <i class="fas fa-upload mr-1"></i> {{ __('nav.upload') }}
                        </a>
                        @endauth
                    </div>
                </div>

                <!-- Desktop Right Section -->
                <div class="hidden md:flex items-center space-x-3">
                    <!-- Language Switcher -->
                    <div class="relative" x-data="{ open: false }">
                        <button @click="open = !open" @click.away="open = false" class="flex items-center text-gray-300 hover:text-white px-2 py-1 rounded transition">
                            <span class="text-lg">{{ config('locales.supported')[app()->getLocale()]['flag'] ?? '🌐' }}</span>
                            <i class="fas fa-chevron-down text-xs ml-1"></i>
                        </button>
                        <div x-show="open" x-cloak x-transition class="absolute right-0 mt-2 w-48 bg-gray-800 border border-gray-700 rounded-lg shadow-xl z-50 max-h-80 overflow-y-auto">
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
                        <a href="{{ route('login') }}" class="bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded-lg font-medium transition">
                            <i class="fas fa-sign-in-alt mr-1"></i> {{ __('nav.login') }}
                        </a>
                    @else
                        <!-- User Dropdown -->
                        <div class="relative" x-data="{ open: false }">
                            <button @click="open = !open" @click.away="open = false" class="flex items-center space-x-2 text-gray-300 hover:text-white px-2 py-1 rounded transition">
                                <div class="relative">
                                    @if(auth()->user()->avatar)
                                        <img src="{{ auth()->user()->avatar }}" alt="" class="w-8 h-8 rounded-full">
                                    @else
                                        <i class="fas fa-user-circle text-2xl"></i>
                                    @endif
                                    @if(auth()->user()->isAdmin() && $pendingReportsCount > 0)
                                        <span class="absolute -top-1 -right-1 bg-red-600 text-white text-xs font-bold w-5 h-5 flex items-center justify-center rounded-full">{{ $pendingReportsCount }}</span>
                                    @endif
                                </div>
                                <span class="max-w-[120px] truncate">{{ auth()->user()->name }}</span>
                                <i class="fas fa-chevron-down text-xs"></i>
                            </button>
                            <div x-show="open" x-cloak x-transition class="absolute right-0 mt-2 w-56 bg-gray-800 border border-gray-700 rounded-lg shadow-xl z-50 py-1">
                                <!-- User Actions -->
                                <a href="{{ route('translations.mine') }}" class="flex items-center px-4 py-2.5 text-sm text-gray-300 hover:bg-gray-700 hover:text-white transition">
                                    <i class="fas fa-folder w-5 mr-3 text-purple-400"></i> {{ __('nav.my_translations') }}
                                </a>
                                @if(auth()->user()->isAdmin())
                                <a href="{{ route('admin.dashboard') }}" class="flex items-center px-4 py-2.5 text-sm text-yellow-400 hover:bg-gray-700 hover:text-yellow-300 transition">
                                    <i class="fas fa-shield-alt w-5 mr-3"></i> {{ __('nav.admin') }}
                                    @if($pendingReportsCount > 0)
                                        <span class="bg-red-600 text-white text-xs font-bold px-1.5 py-0.5 rounded-full ml-auto">{{ $pendingReportsCount }}</span>
                                    @endif
                                </a>
                                @endif
                                <div class="border-t border-gray-700 my-1"></div>
                                <a href="{{ route('profile.edit') }}" class="flex items-center px-4 py-2.5 text-sm text-gray-300 hover:bg-gray-700 hover:text-white transition">
                                    <i class="fas fa-cog w-5 mr-3 text-gray-500"></i> {{ __('nav.profile') }}
                                </a>
                                <form action="{{ route('logout') }}" method="POST">
                                    @csrf
                                    <button type="submit" class="flex items-center w-full px-4 py-2.5 text-sm text-gray-300 hover:bg-gray-700 hover:text-white transition">
                                        <i class="fas fa-sign-out-alt w-5 mr-3 text-gray-500"></i> {{ __('nav.logout') }}
                                    </button>
                                </form>
                            </div>
                        </div>
                    @endguest
                </div>

                <!-- Mobile Menu Button -->
                <div class="flex items-center md:hidden">
                    <!-- Mobile Language Switcher (always visible) -->
                    <div class="relative mr-2" x-data="{ open: false }">
                        <button @click="open = !open" @click.away="open = false" class="flex items-center text-gray-300 hover:text-white p-2 rounded transition">
                            <span class="text-lg">{{ config('locales.supported')[app()->getLocale()]['flag'] ?? '🌐' }}</span>
                        </button>
                        <div x-show="open" x-cloak x-transition class="absolute right-0 mt-2 w-48 bg-gray-800 border border-gray-700 rounded-lg shadow-xl z-50 max-h-80 overflow-y-auto">
                            @foreach(config('locales.supported', []) as $code => $locale)
                                <a href="{{ route('locale.switch', $code) }}"
                                   class="flex items-center px-4 py-2 text-sm hover:bg-gray-700 transition {{ app()->getLocale() === $code ? 'bg-purple-900 text-purple-200' : 'text-gray-300' }}">
                                    <span class="text-lg mr-2">{{ $locale['flag'] }}</span>
                                    <span>{{ $locale['native'] }}</span>
                                </a>
                            @endforeach
                        </div>
                    </div>
                    <!-- Hamburger Button -->
                    <button @click="mobileMenuOpen = !mobileMenuOpen" class="text-gray-300 hover:text-white p-2 rounded-lg transition">
                        <i class="fas fa-bars text-xl" x-show="!mobileMenuOpen"></i>
                        <i class="fas fa-times text-xl" x-show="mobileMenuOpen" x-cloak></i>
                    </button>
                </div>
            </div>
        </div>

        <!-- Mobile Menu Overlay -->
        <div x-show="mobileMenuOpen" x-cloak x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 translate-y-0" x-transition:leave-end="opacity-0 -translate-y-2" class="md:hidden bg-gray-800 border-b border-gray-700">
            <div class="px-4 py-4 space-y-3">
                <!-- Navigation Links -->
                <a href="{{ route('games.index') }}" class="block text-gray-300 hover:text-white hover:bg-gray-700 px-4 py-3 rounded-lg transition">
                    <i class="fas fa-gamepad mr-3 w-5 text-center"></i> {{ __('nav.games') }}
                </a>
                <a href="{{ route('docs') }}" class="block text-gray-300 hover:text-white hover:bg-gray-700 px-4 py-3 rounded-lg transition">
                    <i class="fas fa-book mr-3 w-5 text-center"></i> {{ __('nav.docs') }}
                </a>
                @auth
                <a href="{{ route('translations.create') }}" class="block text-gray-300 hover:text-white hover:bg-gray-700 px-4 py-3 rounded-lg transition">
                    <i class="fas fa-upload mr-3 w-5 text-center"></i> {{ __('nav.upload') }}
                </a>
                <a href="{{ route('translations.mine') }}" class="block text-gray-300 hover:text-white hover:bg-gray-700 px-4 py-3 rounded-lg transition">
                    <i class="fas fa-folder mr-3 w-5 text-center"></i> {{ __('nav.my_translations') }}
                </a>
                @if(auth()->user()->isAdmin())
                <a href="{{ route('admin.dashboard') }}" class="block text-yellow-400 hover:text-yellow-300 hover:bg-gray-700 px-4 py-3 rounded-lg transition">
                    <i class="fas fa-shield-alt mr-3 w-5 text-center"></i> {{ __('nav.admin') }}
                    @if($pendingReportsCount > 0) <span class="bg-red-600 text-white text-xs font-bold px-1.5 py-0.5 rounded-full ml-2">{{ $pendingReportsCount }}</span>@endif
                </a>
                @endif
                @else
                <a href="{{ route('login') }}?action=upload" class="block text-gray-300 hover:text-white hover:bg-gray-700 px-4 py-3 rounded-lg transition">
                    <i class="fas fa-upload mr-3 w-5 text-center"></i> {{ __('nav.upload') }}
                </a>
                @endauth

                <!-- Divider -->
                <div class="border-t border-gray-700 my-3"></div>

                <!-- Auth Section -->
                @guest
                <p class="text-gray-400 text-sm px-4 mb-2">{{ __('nav.login_with') }}</p>
                <div class="grid grid-cols-3 gap-2 px-4">
                    <a href="{{ route('auth.redirect', 'steam') }}" class="bg-gray-700 hover:bg-gray-600 text-white p-3 rounded-lg flex items-center justify-center transition" title="Steam">
                        <i class="fab fa-steam text-lg"></i>
                    </a>
                    <a href="{{ route('auth.redirect', 'discord') }}" class="bg-indigo-600 hover:bg-indigo-700 text-white p-3 rounded-lg flex items-center justify-center transition" title="Discord">
                        <i class="fab fa-discord text-lg"></i>
                    </a>
                    <a href="{{ route('auth.redirect', 'github') }}" class="bg-gray-700 hover:bg-gray-600 text-white p-3 rounded-lg flex items-center justify-center transition" title="GitHub">
                        <i class="fab fa-github text-lg"></i>
                    </a>
                    <a href="{{ route('auth.redirect', 'google') }}" class="bg-red-600 hover:bg-red-700 text-white p-3 rounded-lg flex items-center justify-center transition" title="Google">
                        <i class="fab fa-google text-lg"></i>
                    </a>
                    <a href="{{ route('auth.redirect', 'twitch') }}" class="bg-purple-600 hover:bg-purple-700 text-white p-3 rounded-lg flex items-center justify-center transition" title="Twitch">
                        <i class="fab fa-twitch text-lg"></i>
                    </a>
                    <a href="{{ route('auth.redirect', 'epicgames') }}" class="bg-black hover:bg-gray-900 text-white p-3 rounded-lg flex items-center justify-center border border-gray-600 transition" title="Epic Games">
                        <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M3.537 0C2.165 0 1.66.506 1.66 1.879V18.44a4.262 4.262 0 00.02.433c.031.3.037.59.316.92.027.033.311.245.311.245.153.075.258.13.43.2l8.335 3.491c.433.199.614.276.928.27h.002c.314.006.495-.071.928-.27l8.335-3.492c.172-.07.277-.124.43-.2 0 0 .284-.211.311-.243.28-.33.285-.621.316-.92a4.261 4.261 0 00.02-.434V1.879c0-1.373-.506-1.88-1.878-1.88zm13.366 3.11h.68c1.138 0 1.688.553 1.688 1.696v1.88h-1.374v-1.8c0-.369-.17-.54-.523-.54h-.235c-.367 0-.537.17-.537.539v5.81c0 .369.17.54.537.54h.262c.353 0 .523-.171.523-.54V8.619h1.373v2.143c0 1.144-.562 1.71-1.7 1.71h-.694c-1.138 0-1.7-.566-1.7-1.71V4.82c0-1.144.562-1.709 1.7-1.709zm-12.186.08h3.114v1.274H6.117v2.603h1.648v1.275H6.117v2.774h1.74v1.275h-3.14zm3.816 0h2.198c1.138 0 1.7.564 1.7 1.708v2.445c0 1.144-.562 1.71-1.7 1.71h-.799v3.338h-1.4zm4.53 0h1.4v9.201h-1.4zm-3.13 1.235v3.392h.575c.354 0 .523-.171.523-.54V4.965c0-.368-.17-.54-.523-.54z"/></svg>
                    </a>
                </div>
                @else
                <div class="flex items-center justify-between px-4 py-2 bg-gray-700 rounded-lg">
                    <a href="{{ route('profile.edit') }}" class="flex items-center space-x-3 hover:text-purple-400 transition">
                        @if(auth()->user()->avatar)
                            <img src="{{ auth()->user()->avatar }}" alt="" class="w-10 h-10 rounded-full">
                        @endif
                        <span class="text-white font-medium">{{ auth()->user()->name }}</span>
                    </a>
                    <form action="{{ route('logout') }}" method="POST">
                        @csrf
                        <button type="submit" class="text-gray-400 hover:text-white p-2 transition" title="{{ __('nav.logout') }}">
                            <i class="fas fa-sign-out-alt text-lg"></i>
                        </button>
                    </form>
                </div>
                @endguest
            </div>
        </div>
    </nav>

    <main class="flex-1 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 w-full">
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

    <footer class="bg-gray-800 border-t border-gray-700 mt-auto">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-8 items-center">
                <!-- Logo & Description -->
                <div class="text-center md:text-left">
                    <a href="{{ route('home') }}" class="text-xl font-bold text-purple-400 inline-flex items-center">
                        <img src="/logo.svg" alt="UGT" class="w-8 h-8 mr-2">UnityGameTranslator
                    </a>
                    <p class="text-gray-500 text-sm mt-2">{{ __('footer.tagline') }}</p>
                </div>

                <!-- CTA & Links -->
                <div class="text-center">
                    <a href="{{ route('docs') }}" class="inline-flex items-center bg-purple-600 hover:bg-purple-700 text-white px-5 py-2.5 rounded-lg font-medium transition mb-4">
                        <i class="fas fa-download mr-2"></i>{{ __('footer.download_mod') }}
                    </a>
                    <div class="flex justify-center space-x-4 mt-4">
                        <a href="https://github.com/djethino/UnityGameTranslator" target="_blank" class="text-gray-400 hover:text-white transition" title="GitHub">
                            <i class="fab fa-github text-xl"></i>
                        </a>
                    </div>
                </div>

                <!-- Legal Links -->
                <div class="text-center md:text-right">
                    <div class="flex flex-wrap justify-center md:justify-end gap-4 text-sm text-gray-500">
                        <a href="{{ route('legal.mentions') }}" class="hover:text-gray-300 transition">{{ __('footer.legal') }}</a>
                        <a href="{{ route('legal.privacy') }}" class="hover:text-gray-300 transition">{{ __('footer.privacy') }}</a>
                        <a href="{{ route('legal.terms') }}" class="hover:text-gray-300 transition">{{ __('footer.terms') }}</a>
                    </div>
                </div>
            </div>

            <!-- Copyright -->
            <div class="border-t border-gray-700 mt-8 pt-6 text-center">
                <p class="text-gray-500 text-sm">© {{ date('Y') }} ASymptOmatik Games. {{ __('footer.rights') }}</p>
            </div>
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
