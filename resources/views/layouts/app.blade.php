<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ config('locales.supported.' . app()->getLocale() . '.rtl', false) ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', __('seo.default_title'))</title>
    <meta name="description" content="@yield('description', __('seo.default_description'))">
    <meta name="keywords" content="Unity game translation, automatic game translation, AI game localization, free game translation, Unity mod, OpenAI compatible, local AI translation">
    <link rel="canonical" href="{{ url()->current() }}">

    <!-- Favicon -->
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16.png">
    <link rel="apple-touch-icon" sizes="128x128" href="/icon-128.png">

    <!-- Preconnect to external CDNs -->
    <link rel="preconnect" href="https://steamcdn-a.akamaihd.net" crossorigin>
    <link rel="dns-prefetch" href="https://steamcdn-a.akamaihd.net">

    <!-- Hreflang for multilingual SEO -->
    @php
        $currentPath = trim(request()->path(), '/');
        $supportedLocales = array_keys(config('locales.supported', []));
        // Remove existing locale prefix if present
        $pathWithoutLocale = $currentPath;
        foreach ($supportedLocales as $loc) {
            if ($currentPath === $loc || str_starts_with($currentPath, $loc . '/')) {
                $pathWithoutLocale = substr($currentPath, strlen($loc) + 1) ?: '';
                break;
            }
        }
    @endphp
    @foreach(config('locales.supported', []) as $code => $locale)
    <link rel="alternate" hreflang="{{ $code }}" href="{{ url('/' . $code . ($pathWithoutLocale ? '/' . $pathWithoutLocale : '')) }}">
    @endforeach
    <link rel="alternate" hreflang="x-default" href="{{ url('/' . $pathWithoutLocale) }}">

    <!-- Open Graph -->
    <meta property="og:type" content="@yield('og_type', 'website')">
    <meta property="og:title" content="@yield('title', __('seo.default_title'))">
    <meta property="og:description" content="@yield('description', __('seo.default_description'))">
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
    <meta name="twitter:title" content="@yield('title', __('seo.default_title'))">
    <meta name="twitter:description" content="@yield('description', __('seo.default_description'))">
    @hasSection('og_image')
    <meta name="twitter:image" content="@yield('og_image')">
    @else
    <meta name="twitter:image" content="{{ asset('images/og-default.png') }}">
    @endif

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    @stack('styles')
    @stack('head')
</head>
{{-- data-locales: the background borrows other languages to glitch a word with. Read from the
     config rather than written out, so adding a locale needs nothing here. --}}
<body class="animated-bg text-gray-100 min-h-screen flex flex-col overflow-x-hidden"
      data-locales="{{ implode(',', array_keys(config('locales.supported'))) }}">
    <nav class="bg-gray-800 border-b border-gray-700" x-data="{ mobileMenuOpen: false }">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between h-16">
                <!-- Logo + Desktop Nav -->
                <div class="flex items-center">
                    <a href="{{ route('home') }}" class="text-xl font-bold text-purple-400 flex items-center">
                        <img src="/logo.svg" alt="" class="w-8 h-8 mr-2"><span class="hidden sm:inline">UnityGameTranslator</span><span class="sm:hidden">UGT</span>
                    </a>
                    {{-- Desktop Navigation

                         🔴 **lg, not md.** The bar needs about 1030px of content — logo 240, links
                         375, right side 420 — and md turns it on at 768. Between the two it did not
                         wrap: it OVERFLOWED, pushing the avatar off screen and giving the whole page
                         a horizontal scrollbar. Measured at 784px wide: 960px of content in a 769px
                         box. The hidden name and label above buy back enough for lg to hold. --}}
                    <div class="hidden lg:flex ml-10 space-x-4">
                        <a href="{{ route('games.index') }}" class="text-gray-300 hover:text-white px-3 py-2 transition">
                            <i class="fas fa-gamepad mr-1"></i> {{ __('nav.games') }}
                        </a>
                        <a href="{{ route('docs') }}" class="text-gray-300 hover:text-white px-3 py-2 transition">
                            <i class="fas fa-book mr-1"></i> {{ __('nav.docs') }}
                        </a>
                        {{-- The only main-nav entry that leaves the site. Saying so beforehand
                             costs one glyph and spares the surprise of landing on GitHub.

                             ⚠ **Raised, like a footnote mark.** On the baseline it read as a SECOND
                             icon — and this entry was then the only one with something on both
                             sides, which looks like a mistake rather than a warning. Superscript is
                             the shape a reader already knows for "there is a note about this word",
                             so it annotates instead of competing. --}}
                        <a href="https://github.com/djethino/UnityGameTranslator/discussions?discussions_q=" target="_blank" rel="noopener" class="text-gray-300 hover:text-white px-3 py-2 transition">
                            <i class="fas fa-comments mr-1"></i> {{ __('nav.community') }}<sup class="ml-0.5"><i class="fas fa-arrow-up-right-from-square text-[0.55em] opacity-60"></i></sup>
                        </a>
                        {{-- ⚠ Publishing is deliberately NOT here any more. It was the least-used
                             entry and the widest, and the row now carries two language switchers:
                             the bar wrapped onto two lines, which costs every visitor a worse
                             header so that one action can be one click closer. It is reached from
                             the account menu, from a game's page and from the mod itself. --}}
                    </div>
                </div>

                <!-- Desktop Right Section -->
                <div class="hidden lg:flex items-center space-x-3">
                    <!-- Language Switcher -->
                    <div class="relative" x-data="{ open: false }">
                        <button @click="open = !open" @click.away="open = false" class="flex items-center text-gray-300 hover:text-white px-2 py-1 rounded transition">
                            {{-- Our own drawing, not flag-icons: same catalogue as the games. --}}
                            <x-flag :flag="config('locales.supported')[app()->getLocale()]['flag'] ?? null" />
                            <i class="fas fa-chevron-down text-xs ml-1"></i>
                        </button>
                        <div x-show="open" x-cloak x-transition class="absolute right-0 mt-2 w-48 bg-gray-800 border border-gray-700 rounded-lg shadow-xl z-50 max-h-80 overflow-y-auto">
                            @foreach(config('locales.supported', []) as $code => $locale)
                                <a href="{{ route('locale.switch', $code) }}"
                                   class="flex items-center px-4 py-2 text-sm hover:bg-gray-700 transition {{ app()->getLocale() === $code ? 'bg-purple-900 text-purple-200' : 'text-gray-300' }}">
                                    <x-flag :flag="$locale['flag']" class="mr-2" />
                                    <span>{{ $locale['native'] }}</span>
                                </a>
                            @endforeach
                        </div>
                    </div>

                    <x-game-language-switcher />

                    @guest
                        {{-- ⚠ The word goes when the room does, the icon stays. Between the width
                             this bar appears at and the width everything fits in, something has to
                             give: a label the icon already carries is the cheapest thing to drop.
                             title= keeps it readable for anyone who hovers or uses a reader. --}}
                        <a href="{{ route('login') }}" title="{{ __('nav.login') }}"
                           class="bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded-lg font-medium transition">
                            <i class="fas fa-sign-in-alt xl:mr-1"></i><span class="hidden xl:inline">{{ __('nav.login') }}</span>
                        </a>
                    @else
                        <!-- Notification bell -->
                        <a href="{{ route('notifications.index') }}"
                           x-data="notifBell"
                           data-count-url="{{ route('notifications.count') }}"
                           data-initial-count="{{ auth()->user()->unreadNotifications()->count() }}"
                           class="relative text-gray-300 hover:text-white px-2 py-1 transition"
                           title="{{ __('notif.bell_title') }}">
                            <i class="fas fa-bell text-lg"></i>
                            <span x-show="hasUnread" x-cloak x-text="badge"
                                  class="absolute -top-1 -right-1 bg-purple-600 text-white text-xs font-bold min-w-[1.25rem] h-5 px-1 flex items-center justify-center rounded-full"></span>
                        </a>

                        <!-- User Dropdown -->
                        <div class="relative" x-data="{ open: false }">
                            <button @click="open = !open" @click.away="open = false" class="flex items-center space-x-2 text-gray-300 hover:text-white px-2 py-1 rounded transition">
                                <div class="relative">
                                    <x-avatar :user="auth()->user()" :size="32" />
                                    @if(auth()->user()->isAdmin() && $pendingReportsCount > 0)
                                        <span class="absolute -top-1 -right-1 bg-red-600 text-white text-xs font-bold w-5 h-5 flex items-center justify-center rounded-full">{{ $pendingReportsCount }}</span>
                                    @endif
                                </div>
                                {{-- ⚠ Same rule as the sign-in button: the name goes when the room
                                     does, the avatar stays. The avatar IS the identity here, and
                                     the menu behind it opens either way. --}}
                                <span class="hidden xl:inline max-w-[120px] truncate">{{ auth()->user()->name }}</span>
                                <i class="fas fa-chevron-down text-xs"></i>
                            </button>
                            <div x-show="open" x-cloak x-transition class="absolute right-0 mt-2 w-56 bg-gray-800 border border-gray-700 rounded-lg shadow-xl z-50 py-1">
                                <!-- User Actions -->
                                {{-- ⚠ Publishing moved HERE when it left the top bar. Taking an
                                     action out of a menu without putting it back somewhere is not
                                     tidying, it is removing it. --}}
                                <a href="{{ route('translations.create') }}" class="flex items-center px-4 py-2.5 text-sm text-gray-300 hover:bg-gray-700 hover:text-white transition">
                                    <i class="fas fa-upload w-5 mr-3 text-purple-400"></i> {{ __('nav.upload') }}
                                </a>
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
                                    <i class="fas fa-cog w-5 mr-3 text-gray-400"></i> {{ __('nav.profile') }}
                                </a>
                                <form action="{{ route('logout') }}" method="POST">
                                    @csrf
                                    <button type="submit" class="flex items-center w-full px-4 py-2.5 text-sm text-gray-300 hover:bg-gray-700 hover:text-white transition">
                                        <i class="fas fa-sign-out-alt w-5 mr-3 text-gray-400"></i> {{ __('nav.logout') }}
                                    </button>
                                </form>
                            </div>
                        </div>
                    @endguest
                </div>

                {{-- Mobile Menu Button

                     ⚠ **The two language switchers stay OUT of the menu, side by side.** The
                     interface one always has been — it is how somebody who cannot read the page
                     fixes that, and burying it behind a menu whose own label they cannot read
                     would be a trap. The game one had simply been left in the desktop row, so
                     below the breakpoint there was no way at all to say which language you play
                     in. They are two questions, they are answered in the same place at every
                     width.

                     ⚠ **Same order as the desktop row: site first, then game.** A pair that swaps
                     places when the window narrows makes the reader check which is which every
                     time, and the two are told apart by a small controller icon — exactly the kind
                     of difference an unstable order destroys. --}}
                <div class="flex items-center lg:hidden">
                    <!-- Mobile Language Switcher (always visible) -->
                    <div class="relative mr-1" x-data="{ open: false }">
                        <button @click="open = !open" @click.away="open = false" class="flex items-center text-gray-300 hover:text-white p-2 rounded transition">
                            {{-- Our own drawing, not flag-icons: same catalogue as the games. --}}
                            <x-flag :flag="config('locales.supported')[app()->getLocale()]['flag'] ?? null" />
                        </button>
                        <div x-show="open" x-cloak x-transition class="absolute right-0 mt-2 w-48 bg-gray-800 border border-gray-700 rounded-lg shadow-xl z-50 max-h-80 overflow-y-auto">
                            @foreach(config('locales.supported', []) as $code => $locale)
                                <a href="{{ route('locale.switch', $code) }}"
                                   class="flex items-center px-4 py-2 text-sm hover:bg-gray-700 transition {{ app()->getLocale() === $code ? 'bg-purple-900 text-purple-200' : 'text-gray-300' }}">
                                    <x-flag :flag="$locale['flag']" class="mr-2" />
                                    <span>{{ $locale['native'] }}</span>
                                </a>
                            @endforeach
                        </div>
                    </div>
                    <x-game-language-switcher class="mr-2" />
                    <!-- Hamburger Button -->
                    <button @click="mobileMenuOpen = !mobileMenuOpen" class="text-gray-300 hover:text-white p-2 rounded-lg transition" aria-label="Menu">
                        <i class="fas fa-bars text-xl" x-show="!mobileMenuOpen"></i>
                        <i class="fas fa-times text-xl" x-show="mobileMenuOpen" x-cloak></i>
                    </button>
                </div>
            </div>
        </div>

        <!-- Mobile Menu Overlay -->
        <div x-show="mobileMenuOpen" x-cloak x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 translate-y-0" x-transition:leave-end="opacity-0 -translate-y-2" class="lg:hidden bg-gray-800 border-b border-gray-700">
            <div class="px-4 py-4 space-y-3">
                <!-- Navigation Links -->
                <a href="{{ route('games.index') }}" class="block text-gray-300 hover:text-white hover:bg-gray-700 px-4 py-3 rounded-lg transition">
                    <i class="fas fa-gamepad mr-3 w-5 text-center"></i> {{ __('nav.games') }}
                </a>
                <a href="{{ route('docs') }}" class="block text-gray-300 hover:text-white hover:bg-gray-700 px-4 py-3 rounded-lg transition">
                    <i class="fas fa-book mr-3 w-5 text-center"></i> {{ __('nav.docs') }}
                </a>
                <a href="https://github.com/djethino/UnityGameTranslator/discussions?discussions_q=" target="_blank" rel="noopener" class="block text-gray-300 hover:text-white hover:bg-gray-700 px-4 py-3 rounded-lg transition">
                    <i class="fas fa-comments mr-3 w-5 text-center"></i> {{ __('nav.community') }}<sup class="ml-0.5"><i class="fas fa-arrow-up-right-from-square text-[0.55em] opacity-60"></i></sup>
                </a>
                @auth
                <a href="{{ route('translations.create') }}" class="block text-gray-300 hover:text-white hover:bg-gray-700 px-4 py-3 rounded-lg transition">
                    <i class="fas fa-upload mr-3 w-5 text-center"></i> {{ __('nav.upload') }}
                </a>
                <a href="{{ route('translations.mine') }}" class="block text-gray-300 hover:text-white hover:bg-gray-700 px-4 py-3 rounded-lg transition">
                    <i class="fas fa-folder mr-3 w-5 text-center"></i> {{ __('nav.my_translations') }}
                </a>
                <a href="{{ route('notifications.index') }}" class="block text-gray-300 hover:text-white hover:bg-gray-700 px-4 py-3 rounded-lg transition">
                    <i class="fas fa-bell mr-3 w-5 text-center"></i> {{ __('notif.bell_title') }}
                    @php $__unreadNotifs = auth()->user()->unreadNotifications()->count(); @endphp
                    @if($__unreadNotifs > 0) <span class="bg-purple-600 text-white text-xs font-bold px-1.5 py-0.5 rounded-full ml-2">{{ $__unreadNotifs }}</span>@endif
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
                {{-- 🔴 **One door to signing in, not five sixths of one.**

                     This offered five providers and nothing else, so somebody with a local account
                     — a username and a password, no platform — could not sign in from the menu at
                     all, and nothing on this screen even hinted that /login existed. A menu LEADS
                     to an action; it does not perform a part of it.

                     ⚠ Comes back to the page it was opened from, so the menu is not a detour. --}}
                <a href="{{ route('login') }}?redirect={{ urlencode(url()->current()) }}"
                   class="block bg-purple-600 hover:bg-purple-700 text-white px-4 py-3 rounded-lg font-medium text-center transition">
                    <i class="fas fa-sign-in-alt mr-2"></i> {{ __('nav.login') }}
                </a>
                @else
                <div class="flex items-center justify-between px-4 py-2 bg-gray-700 rounded-lg">
                    <a href="{{ route('profile.edit') }}" class="flex items-center space-x-3 hover:text-purple-400 transition">
                        {{-- The component, so an account with no provider avatar still has one
                             here rather than a nameplate with a gap where a face should be. --}}
                        <x-avatar :user="auth()->user()" :size="40" />
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

    @php $siteBanner = \App\Models\Announcement::currentBanner(); @endphp
    @if($siteBanner)
    <div x-data="announceBanner" data-banner-id="{{ $siteBanner->id }}" x-show="visible" x-cloak
         class="bg-purple-900/80 border-b border-purple-700">
        {{-- items-start, not items-center: the message can now run to several lines, and centring
             floated the megaphone and the close button somewhere in the middle of the paragraph —
             a cross halfway down a banner does not read as "close this banner".

             ⚠ leading-5 on both, which is what text-sm already gives the paragraph. It makes each
             icon's box exactly one line tall, so the glyph sits on the FIRST line rather than at
             the top of a box whose height nobody chose. --}}
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-2.5 flex items-start gap-3 text-sm">
            <i class="fas fa-bullhorn text-purple-300 leading-5"></i>
            <p class="flex-1 text-purple-100 min-w-0">
                {{-- Title on its own line, message under it — the shape the notifications page
                     already gave this same announcement. It used to read "Title — first line",
                     which only held while the message was one line: once the author's own breaks
                     are honoured, joining the title to the first of them makes the title look like
                     the opening of a sentence it does not belong to.

                     whitespace-pre-line rather than nl2br + {!! !!}: the message comes from a
                     textarea, and CSS gets the breaks back without handing raw HTML to a field.
                     Long lines still wrap normally — pre-line keeps the breaks it is given without
                     forbidding the ones the width imposes. --}}
                <span class="block font-semibold">{{ $siteBanner->title }}</span>
                <span class="block text-purple-200 whitespace-pre-line">{{ $siteBanner->body }}</span>
                @if($siteBanner->link)
                    {{-- Its own line, ranged right: inline after the message it landed wherever the
                         body happened to stop, so on a long announcement it sat mid-sentence and
                         read as part of the prose rather than as the way out of it.

                         ⚠ No top margin. The block already puts it on a line of its own; a margin
                         on top of that spends the height of a fourth line on a banner that sits
                         above every page of the site.

                         ⚠ The block is the SPAN, never the anchor. An <a> made block-level would
                         stretch the full width and turn the empty space left of the words into a
                         click that navigates — a link you hit without aiming at it. --}}
                    <span class="block text-right">
                        <a href="{{ $siteBanner->link }}" class="underline text-white hover:text-purple-200" rel="noopener">{{ __('notif.learn_more') }}</a>
                    </span>
                @endif
            </p>
            <button @click="dismiss" class="text-purple-300 hover:text-white transition leading-5" aria-label="{{ __('notif.dismiss') }}">
                <i class="fas fa-times"></i>
            </button>
        </div>
    </div>
    @endif

    @auth
        @if(is_null(auth()->user()->username_prompt_seen_at))
        <!-- One-shot username prompt: OAuth usernames sometimes expose real
             names; offer a rename once, then it lives in the profile settings -->
        <div class="fixed inset-0 z-[90] flex items-center justify-center p-4" style="background: rgba(0,0,0,0.65);">
            <div class="bg-gray-800 border border-gray-600 rounded-xl shadow-2xl max-w-md w-full p-6">
                <h2 class="text-lg font-bold text-white mb-2">
                    <i class="fas fa-user-pen text-purple-400 mr-2"></i>{{ __('profile.prompt_title') }}
                </h2>
                <p class="text-gray-300 text-sm mb-2">{{ __('profile.prompt_body', ['name' => auth()->user()->name]) }}</p>
                <p class="text-gray-400 text-xs mb-5">{{ __('profile.prompt_privacy') }}</p>
                <form method="POST" action="{{ route('profile.username-prompt-seen') }}" class="flex gap-3 justify-end">
                    @csrf
                    <button type="submit" name="action" value="keep"
                            class="px-4 py-2 text-sm text-gray-300 hover:text-white bg-gray-700 hover:bg-gray-600 rounded-lg transition">
                        {{ __('profile.prompt_keep') }}
                    </button>
                    <button type="submit" name="action" value="change"
                            class="px-4 py-2 text-sm text-white bg-purple-600 hover:bg-purple-700 rounded-lg transition">
                        {{ __('profile.prompt_change') }}
                    </button>
                </form>
            </div>
        </div>
        @endif
    @endauth

    {{-- Reading pages are capped at a comfortable measure; WORKSPACES are not. A merge screen
         puts the main translation beside every contribution selected, so its width is dictated by
         how many people are being arbitrated between — three branches and the fourth column was
         cut in mid-word, the last two off screen entirely.

         A page opts out with @section('container', 'w-full px-4 sm:px-6 lg:px-8'), which keeps
         the decision in the page that knows why it needs the room. --}}
    <main class="flex-1 @yield('container', 'max-w-7xl mx-auto px-4 sm:px-6 lg:px-8') py-8 w-full">
        @if(session('success'))
            <div class="bg-green-900 border border-green-700 text-green-100 px-4 py-3 rounded mb-6">
                {{ session('success') }}
            </div>
        @endif
        {{-- Neither a success nor a failure: something was deliberately NOT
             done, and the user has to know which part. --}}
        @if(session('warning'))
            <div class="bg-amber-900 border border-amber-700 text-amber-100 px-4 py-3 rounded mb-6">
                <i class="fas fa-triangle-exclamation mr-2"></i>{{ session('warning') }}
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
                        <img src="/logo.svg" alt="" class="w-8 h-8 mr-2">UnityGameTranslator
                    </a>
                    <p class="text-gray-400 text-sm mt-2">{{ __('footer.tagline') }}</p>
                </div>

                <!-- CTA & Links -->
                <div class="text-center">
                    {{-- Two buttons, and the Manager first — the same order as the sidebar of the
                         documentation and as the fork in Quick start. Both point INTO the docs
                         rather than straight at a release: whoever lands here has not read
                         anything yet, and a download with no page around it is how somebody ends
                         up with a zip and no idea what to unzip it into. --}}
                    <div class="flex flex-wrap justify-center gap-2 mb-4">
                        <a href="{{ route('docs') }}#install-manager" class="inline-flex items-center bg-purple-600 hover:bg-purple-700 text-white px-5 py-2.5 rounded-lg font-medium transition">
                            <i class="fas fa-screwdriver-wrench mr-2"></i>{{ __('footer.download_manager') }}
                        </a>
                        <a href="{{ route('docs') }}" class="inline-flex items-center bg-gray-700 hover:bg-gray-600 text-white px-5 py-2.5 rounded-lg font-medium transition">
                            <i class="fas fa-download mr-2"></i>{{ __('footer.download_mod') }}
                        </a>
                    </div>
                    {{-- ⚠ Three products now, and the footer names all three or it names none.
                         The Manager has its own repository and its own issues: a bug in the desktop
                         tool filed against the mod goes to the wrong tracker and gets moved by hand.
                         `flex-wrap` because three "report a bug" links plus the rest no longer fit
                         one line on a narrow screen. --}}
                    <div class="flex flex-wrap justify-center items-center gap-x-4 gap-y-2 mt-4 text-sm">
                        <a href="https://github.com/djethino/UnityGameTranslator" target="_blank" rel="noopener" class="text-gray-400 hover:text-white transition" title="GitHub">
                            <i class="fab fa-github text-xl"></i>
                        </a>
                        <span class="text-gray-600">|</span>
                        <a href="https://github.com/djethino/UnityGameTranslator/issues" target="_blank" rel="noopener" class="text-gray-400 hover:text-purple-400 transition">
                            <i class="fas fa-bug mr-1"></i>{{ __('footer.report_mod_bug') }}
                        </a>
                        <a href="https://github.com/djethino/unitygametranslator-manager/issues" target="_blank" rel="noopener" class="text-gray-400 hover:text-purple-400 transition">
                            <i class="fas fa-bug mr-1"></i>{{ __('footer.report_manager_bug') }}
                        </a>
                        <a href="https://github.com/djethino/UnityGameTranslator-website/issues" target="_blank" rel="noopener" class="text-gray-400 hover:text-purple-400 transition">
                            <i class="fas fa-bug mr-1"></i>{{ __('footer.report_site_bug') }}
                        </a>
                        <span class="text-gray-600">|</span>
                        <a href="https://github.com/djethino/UnityGameTranslator/discussions?discussions_q=" target="_blank" rel="noopener" class="text-gray-400 hover:text-purple-400 transition">
                            <i class="fas fa-comments mr-1"></i>{{ __('footer.community') }}
                        </a>
                    </div>
                </div>

                <!-- Legal Links -->
                <div class="text-center md:text-right">
                    <div class="flex flex-wrap justify-center md:justify-end gap-4 text-sm text-gray-400">
                        <a href="{{ route('legal.mentions') }}" class="hover:text-gray-300 transition">{{ __('footer.legal') }}</a>
                        <a href="{{ route('legal.privacy') }}" class="hover:text-gray-300 transition">{{ __('footer.privacy') }}</a>
                        <a href="{{ route('legal.terms') }}" class="hover:text-gray-300 transition">{{ __('footer.terms') }}</a>
                    </div>
                </div>
            </div>

            <!-- Copyright -->
            <div class="border-t border-gray-700 mt-8 pt-6 text-center">
                <p class="text-gray-400 text-sm">© {{ date('Y') }} <a href="https://asymptomatikgames.com" class="text-gray-400 hover:text-purple-400 transition-colors">ASymptOmatik Games</a>. {{ __('footer.rights') }}</p>
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
