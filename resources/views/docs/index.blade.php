@extends('layouts.app')

@section('title', __('docs.title') . ' - UnityGameTranslator')

@section('description', __('docs.meta_description'))

@push('styles')
<style>
    /* Sidebar navigation */
    .docs-sidebar {
        position: sticky;
        top: 2rem;
        max-height: calc(100vh - 4rem);
        overflow-y: auto;
    }
    .docs-sidebar::-webkit-scrollbar {
        width: 4px;
    }
    .docs-sidebar::-webkit-scrollbar-thumb {
        background: #4b5563;
        border-radius: 2px;
    }
    .docs-nav-item {
        transition: all 0.2s;
        border-left: 2px solid transparent;
    }
    .docs-nav-item:hover {
        border-left-color: #9333ea;
        background: rgba(147, 51, 234, 0.1);
    }
    .docs-nav-item.active {
        border-left-color: #9333ea;
        background: rgba(147, 51, 234, 0.2);
        color: #c084fc;
    }
    /* Group heading in the sidebar. Deliberately quiet: it has to separate without competing
       with the items it introduces, and the active item stays the loudest thing in the menu. */
    .docs-nav-group {
        margin: 1.25rem 0 0.25rem;
        padding: 0 1rem;
        font-size: 0.6875rem;
        font-weight: 600;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        color: #6b7280;
    }
    .docs-nav-group:first-child {
        margin-top: 0;
    }
    /* Sub-entries of a section that carries many subjects. Quieter than a section — smaller,
       indented, no left border of their own — so the menu still reads as a list of sections with
       detail underneath, not as a flat list of twenty things. */
    .docs-nav-sub {
        display: block;
        padding: 0.3rem 1rem 0.3rem 2.5rem;
        font-size: 0.8125rem;
        color: #9ca3af;
        transition: color 0.2s, background 0.2s;
    }
    .docs-nav-sub:hover {
        color: #c084fc;
        background: rgba(147, 51, 234, 0.08);
    }
    /* Lit at the same time as the section it hangs under, and quieter than it: reading "Mod
       defaults" is reading "The Manager" too, so both are marked, and the section has to stay the
       one that carries the purple bar. Same colour, weaker ground — the nesting is what is being
       shown, not two separate places. */
    .docs-nav-sub.active {
        color: #c084fc;
        background: rgba(147, 51, 234, 0.12);
    }
    /* The chevron: a real button beside the link, not on top of it. Narrow on purpose — it must
       not steal clicks meant for the section itself, which is the common gesture. */
    .docs-nav-toggle {
        flex: 0 0 auto;
        padding: 0 0.75rem;
        color: #6b7280;
        transition: color 0.2s, transform 0.2s;
    }
    .docs-nav-toggle:hover { color: #c084fc; }
    .docs-nav-toggle[aria-expanded="false"] .fa-chevron-down { transform: rotate(-90deg); }
    .docs-nav-toggle .fa-chevron-down { transition: transform 0.2s; }
    /* Collapsed by class rather than by `hidden` or an inline style: the class is written by the
       Blade, so the menu is already folded in the very first paint — no flash of a fifty-entry
       list collapsing once the script runs.

       ⚠ It also means the fold only exists where this stylesheet does. Without CSS the entries are
       all visible, which is the right failure: a table of contents that loses half its lines is
       worse than one that shows too many. */
    .docs-nav-subs.is-collapsed { display: none; }
    /* Quick Start step cards, which are links. They looked like plain boxes for a long time, so
       the hover has to say "this goes somewhere" without turning three cards into three buttons. */
    .docs-step {
        transition: background 0.2s, transform 0.2s;
    }
    .docs-step:hover {
        background: #4b5563;
        transform: translateY(-2px);
    }
    /* Image styles */
    .doc-img {
        border: 1px solid #374151;
        border-radius: 0.5rem;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.3);
        cursor: zoom-in;
        transition: transform 0.2s, box-shadow 0.2s;
    }
    .doc-img:hover {
        transform: scale(1.02);
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.4);
    }
    .doc-img-mod {
        /* Never wider than the column it lives in (side-by-side grids) */
        max-width: min(500px, 100%);
        height: auto;
        margin: 0 auto;
        display: block;
    }
    .doc-img-web {
        max-width: 100%;
    }
    /* Tall portrait screenshots: capped height so they never dwarf the text
       next to them — the real reading happens through the click-to-zoom. */
    .doc-img-tall {
        max-height: 440px;
        width: auto;
        max-width: 100%;
    }
    /* Callout boxes */
    .callout {
        border-left: 4px solid;
        padding: 1rem;
        border-radius: 0 0.5rem 0.5rem 0;
        margin: 1rem 0;
    }
    .callout-tip {
        background: rgba(59, 130, 246, 0.1);
        border-color: #3b82f6;
    }
    .callout-warning {
        background: rgba(234, 179, 8, 0.1);
        border-color: #eab308;
    }
    /* Not a tip and not a warning: how the thing works. Purple, like the rest of what the
       product says about itself. */
    .callout-info {
        background: rgba(168, 85, 247, 0.1);
        border-color: #a855f7;
    }
    .callout-danger {
        background: rgba(239, 68, 68, 0.1);
        border-color: #ef4444;
    }
    /* Smooth scroll */
    html {
        scroll-behavior: smooth;
    }
    /* Mobile sidebar */
    @media (max-width: 1023px) {
        .docs-sidebar {
            position: fixed;
            top: 0;
            left: -100%;
            width: 280px;
            height: 100vh;
            max-height: 100vh;
            background: #1f2937;
            z-index: 100;
            padding: 1rem;
            transition: left 0.3s;
        }
        .docs-sidebar.open {
            left: 0;
        }
        .docs-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 99;
        }
        .docs-overlay.open {
            display: block;
        }
    }
</style>
@endpush

@section('content')
<div class="flex gap-8">
    <!-- Mobile menu button -->
    <button id="docs-menu-btn" class="lg:hidden fixed bottom-4 right-4 z-50 bg-purple-600 hover:bg-purple-700 text-white p-4 rounded-full shadow-lg">
        <i class="fas fa-bars text-xl"></i>
    </button>

    <!-- Mobile overlay -->
    <div id="docs-overlay" class="docs-overlay"></div>

    <!-- Sidebar -->
    {{-- data-nav-scroll: this box scrolls on its own, so the scroll-spy keeps the marked entry
         inside it (resources/js/section-spy.js). Without it the menu still highlights correctly
         and the reader still cannot see the highlight — the list is taller than the screen. --}}
    <aside id="docs-sidebar" data-nav-scroll class="docs-sidebar w-64 flex-shrink-0 hidden lg:block self-start">
        {{-- Grouped, and the groups are the point.

             Twelve items in a flat list is already past what anyone scans: you stop reading the
             menu and start using Ctrl-F, which works but tells you nothing about what else exists.
             The groups answer a question the item labels cannot — "is this for me right now?" —
             and they hold the order the page follows: get it running, use it, understand it, look
             things up, fix it.

             ⚠ The scroll-spy keys on `[data-nav-anchor]` and `section[id]` for the places, and on
             `.docs-nav-item` / `.docs-nav-sub` for the links (resources/js/app.js). Keep those
             classes on every entry or the highlight silently stops following the reader.

             ⚠ The two install paths sit in different groups on purpose, and the order was decided
             rather than inherited: the Manager takes the slot in Start because it is the way in for
             anyone arriving today, and `install-manual` — the by-hand procedure, formerly
             `installation` — sits in Reference because it is a long procedure for a minority. It is
             NOT deprecated and must never read as such: it is the answer when somebody wants to see
             exactly what goes where, and when the Manager refuses a game they know is fine. The two
             link to each other in both directions. See analyse/docs-structure.md. --}}
        @php
            /* 🔴 The menu is written as a table, not as forty hand-copied <a> tags.

               The defect it fixes: the page carries about fifty subjects and the menu listed
               twelve. Everything else was reachable only by scrolling the whole page or guessing
               that it existed — the model bench in particular, which somebody may come for on its
               own. So every section that has sub-parts now lists them.

               ⚠ Sub-entries name themselves with the HEADING'S OWN key, never a second string
               written for the menu. A menu that paraphrases its own page is a menu that goes stale
               in twenty languages at once, silently.

               ⚠ Order follows the page, top to bottom, always. The menu is a map of the page; a map
               that reorders the territory is worse than no map.

               🔴 **A line here is a DESTINATION, not a heading.** "The mod" and "The website" under
               `what-is` were listed once and taken out: two cards side by side, three lines each,
               that you read in one glance on the way past — jumping to the second lands you where
               the first is already on screen. Anchoring every `<h3>` lists the page's LAYOUT
               instead of its subjects. The test is mechanical and worth running after a batch:
               two anchors whose rectangles overlap vertically are on the same line, and at most
               one of them belongs here.

               ⚠ Adding a sub-part to the page means adding `data-nav-anchor` to its heading and one
               line here. The anchor without the line gives an entry nobody can reach from the menu;
               the line without the anchor gives a menu entry that never lights up. */
            $nav = [
                'docs.nav.group.start' => [
                    // One sub-part only: "The mod" and "The website" are two cards on one line,
                    // not two places to go. Reasoning at the grid itself.
                    ['what-is', 'fa-circle-info', 'docs.nav.what_is', [
                        'whatis-flow' => 'docs.whatis.flow_title',
                    ]],
                    ['quick-start', 'fa-rocket', 'docs.nav.quick_start', []],
                    ['install-manager', 'fa-screwdriver-wrench', 'docs.nav.install_manager', [
                        'manager-get'        => 'docs.manager.get_title',
                        'manager-games'      => 'docs.manager.games_title',
                        'manager-refused'    => 'docs.manager.refused_title',
                        'manager-defaults'   => 'docs.manager.defaults_title',
                        'manager-oneclick'   => 'docs.manager.oneclick_title',
                        'manager-ai'         => 'docs.manager.ai_title',
                        // Deliberately its own entry: somebody may come to the page for this alone.
                        'manager-model-test' => 'docs.manager.ai_test_title',
                        'manager-card'       => 'docs.manager.card_title',
                        'manager-updates'    => 'docs.manager.updates_title',
                        'manager-byhand'     => 'docs.manager.byhand_title',
                        'manager-settings'   => 'docs.manager.settings_title',
                    ]],
                    ['first-launch', 'fa-play', 'docs.nav.first_launch', [
                        'wizard-steps'       => 'docs.wizard_steps_title',
                        'first-launch-after' => 'docs.first_launch_after_title',
                    ]],
                ],
                'docs.nav.group.use' => [
                    ['editing', 'fa-pen-to-square', 'docs.nav.editing', [
                        'editing-text-editor' => 'docs.editing.text_editor_title',
                        'editing-live-edit'   => 'docs.editing.live_edit_title',
                        'editing-web-edit'    => 'docs.editing.web_edit_title',
                        'editing-toolbox'     => 'docs.editing.toolbox_title',
                    ]],
                    ['collaboration', 'fa-users', 'docs.nav.collaboration', [
                        'collaboration-model'  => 'docs.collaboration.model_title',
                        'collaboration-upload' => 'docs.collaboration.upload_title',
                        'collaboration-merge'  => 'docs.collaboration.merge_title',
                    ]],
                    ['sync', 'fa-sync', 'docs.nav.sync', [
                        'sync-online-mode'  => 'docs.sync.online_mode_title',
                        'sync-device-flow'  => 'docs.sync.device_flow_title',
                        'sync-multi-device' => 'docs.sync.multi_device_title',
                    ]],
                ],
                'docs.nav.group.understand' => [
                    ['quality-system', 'fa-star', 'docs.nav.quality_system', []],
                    ['algorithms', 'fa-calculator', 'docs.nav.algorithms', [
                        'algorithms-completeness' => 'docs.algorithms.completeness_title',
                        'algorithms-stage'        => 'docs.algorithms.stage_title',
                        'algorithms-rate'         => 'docs.algorithms.rate_title',
                        'algorithms-coverage'     => 'docs.algorithms.coverage_title',
                        'algorithms-dormancy'     => 'docs.algorithms.dormancy_title',
                        'algorithms-order'        => 'docs.algorithms.order_title',
                    ]],
                ],
                'docs.nav.group.reference' => [
                    ['configuration', 'fa-cog', 'docs.nav.configuration', [
                        'config-gui'  => 'docs.config.gui_title',
                        'config-file' => 'docs.config.file_title',
                    ]],
                    ['install-manual', 'fa-download', 'docs.nav.install_manual', [
                        'install-loader' => 'docs.install_modloader',
                        'install-plugin' => 'docs.download_ugt',
                        'enable-ai'      => 'docs.enable_ai',
                    ]],
                    ['external-resources', 'fa-folder-open', 'docs.nav.external_resources', [
                        'external-resources-where'  => 'docs.external_resources.where_title',
                        'external-resources-fonts'  => 'docs.external_resources.fonts_title',
                        'external-resources-images' => 'docs.external_resources.images_title',
                    ]],
                ],
                'docs.nav.group.problems' => [
                    ['troubleshooting', 'fa-question-circle', 'docs.nav.troubleshooting', [
                        'mod-not-loading'     => 'docs.mod_not_loading',
                        'ai-not-translating'  => 'docs.ai_not_translating',
                        'overlay-not-showing' => 'docs.overlay_not_showing',
                        'sync-not-working'    => 'docs.sync_not_working',
                    ]],
                ],
            ];
        @endphp
        <nav class="space-y-1">
            @foreach ($nav as $groupKey => $entries)
                <p class="docs-nav-group">{{ __($groupKey) }}</p>
                @foreach ($entries as [$id, $icon, $label, $subs])
                    @if (empty($subs))
                        <a href="#{{ $id }}" class="docs-nav-item block px-4 py-2 text-sm text-gray-300 rounded-r">
                            <i class="fas {{ $icon }} mr-2 w-4"></i>{{ __($label) }}
                        </a>
                    @else
                        {{-- 🔴 The section link stays a real <a class="docs-nav-item">, and the
                             chevron is a separate <button>. A <details>/<summary> would have been
                             shorter and is wrong here for two reasons: a <summary> carries no href,
                             so the scroll-spy — which filters on `href` starting with `#`
                             (resources/js/section-spy.js) — would drop the entry and that section
                             alone would never light up; and clicking a label inside a summary both
                             navigates AND collapses, so going to a section would hide its own
                             sub-entries.

                             🔴 **Folded by default, and the section you are reading opens itself.**
                             Fifty entries permanently unfolded is 2 100 px of menu: you scroll the
                             table of contents to find where you are, which is the problem it exists
                             to solve. Folded, the menu is thirteen lines you take in at once, and
                             the detail appears exactly where you are — the two things a reader
                             wants are never in competition. Opening another one by hand is one
                             click, and it stays open until you leave the section you are in
                             (resources/js/app.js). --}}
                        <div class="docs-nav-collapsible" data-nav-collapsible>
                            <div class="flex items-stretch">
                                <a href="#{{ $id }}" class="docs-nav-item flex-1 px-4 py-2 text-sm text-gray-300 rounded-r">
                                    <i class="fas {{ $icon }} mr-2 w-4"></i>{{ __($label) }}
                                </a>
                                <button type="button" class="docs-nav-toggle" aria-expanded="false"
                                        aria-controls="docs-nav-subs-{{ $id }}"
                                        aria-label="{{ __($label) }}">
                                    <i class="fas fa-chevron-down text-xs"></i>
                                </button>
                            </div>
                            <div id="docs-nav-subs-{{ $id }}" class="docs-nav-subs is-collapsed">
                                @foreach ($subs as $subId => $subLabel)
                                    <a href="#{{ $subId }}" class="docs-nav-sub">{{ __($subLabel) }}</a>
                                @endforeach
                            </div>
                        </div>
                    @endif
                @endforeach
            @endforeach
        </nav>

        {{-- Quick Links.

             ⚠ The Manager comes FIRST, and it is the coloured one: it is the way in this page now
             recommends, and a sidebar that offered the mod first contradicted the page beside it.
             Installing by hand still gets its own button — it is a supported route, not a footnote.

             ⚠ GitHub is gone from here. It answered a question this page does not raise — somebody
             reading the documentation wants the software, not its source — and it took the place
             that now names the second download. The repository is still linked from the footer,
             where the things about the project live. --}}
        <div class="mt-8 pt-4 border-t border-gray-700 space-y-2">
            <a href="https://github.com/djethino/unitygametranslator-manager/releases/latest" target="_blank" rel="noopener"
               class="flex items-center gap-2 px-4 py-2 bg-purple-600 hover:bg-purple-700 rounded text-sm text-white">
                <i class="fas fa-download"></i>
                {{ __('docs.manager.get_download') }}
            </a>
            <a href="https://github.com/djethino/UnityGameTranslator/releases/latest" target="_blank" rel="noopener"
               class="flex items-center gap-2 px-4 py-2 bg-gray-700 hover:bg-gray-600 rounded text-sm text-gray-300">
                <i class="fas fa-download"></i>
                {{ __('docs.download_mod') }}
            </a>
        </div>
    </aside>

    {{-- data-section-history turns on the reading trail (resources/js/section-history.js):
         cross-links inside this page become steps you can walk back. It stays invisible until a
         link is actually followed, and it keeps nothing once the page is left. --}}
    <main class="flex-1 min-w-0 max-w-4xl" data-section-history>
        <h1 class="text-3xl font-bold mb-2">
            <i class="fas fa-book mr-3 text-purple-400"></i>{{ __('docs.title') }}
        </h1>
        <p class="text-gray-400 mb-8">{{ __('docs.subtitle') }}</p>

        <!-- What is it -->
        <section id="what-is" class="mb-12 scroll-mt-8">
            <h2 class="text-2xl font-bold mb-6 flex items-center">
                <i class="fas fa-circle-info mr-3 text-purple-400"></i>{{ __('docs.whatis.title') }}
            </h2>

            <div class="bg-gray-800 rounded-lg p-6 border border-gray-700">
                <p class="text-gray-300 mb-6">{{ __('docs.whatis.pitch') }}</p>
                <figure class="mb-6 w-full flex flex-col items-center justify-center">
                    <img src="{{ asset('images/screenshots/WebBrowse.webp') }}"
                         alt="{{ __('docs.shot.browse_alt') }}"
                         class="doc-img doc-img-web block mx-auto"
                         width="1400" height="841"
                         loading="lazy"
                         data-zoomable>
                    <figcaption class="text-sm text-gray-400 mt-2 text-center">{{ __('docs.shot.browse_caption') }}</figcaption>
                </figure>

                {{-- ⚠ These two carry NO `data-nav-anchor`, and it is not an oversight.

                     They are two cards side by side in a two-column grid, three lines each. A
                     sub-entry in the table of contents is somewhere you want to GO — and nobody
                     goes to "The website": you read the pair in one glance on the way past, and
                     jumping to the second would land you where the first is already on screen.

                     🔴 The rule: what earns a line in the menu is a DESTINATION, not a heading.
                     Anchoring every `<h3>` is how you end up listing the page's layout instead of
                     its subjects. --}}
                <div class="grid md:grid-cols-2 gap-4 mb-6">
                    <div class="bg-gray-700 rounded-lg p-4">
                        <h3 class="font-semibold text-white mb-2">
                            <i class="fas fa-puzzle-piece text-purple-400 mr-2"></i>{{ __('docs.whatis.mod_title') }}
                        </h3>
                        <p class="text-sm text-gray-300">{{ __('docs.whatis.mod_desc') }}</p>
                    </div>
                    <div class="bg-gray-700 rounded-lg p-4">
                        <h3 class="font-semibold text-white mb-2">
                            <i class="fas fa-globe text-purple-400 mr-2"></i>{{ __('docs.whatis.site_title') }}
                        </h3>
                        <p class="text-sm text-gray-300">{{ __('docs.whatis.site_desc') }}</p>
                    </div>
                </div>

                <h3 id="whatis-flow" data-nav-anchor class="scroll-mt-8 font-semibold mb-3 text-lg">{{ __('docs.whatis.flow_title') }}</h3>
                <ol class="space-y-3 mb-6">
                    <li class="flex items-start">
                        <span class="inline-flex flex-shrink-0 items-center justify-center w-8 h-8 rounded-full bg-purple-600 text-white text-sm mr-3">1</span>
                        <span class="text-gray-300 pt-1">{{ __('docs.whatis.flow_step1') }}</span>
                    </li>
                    <li class="flex items-start">
                        <span class="inline-flex flex-shrink-0 items-center justify-center w-8 h-8 rounded-full bg-purple-600 text-white text-sm mr-3">2</span>
                        <span class="text-gray-300 pt-1">{{ __('docs.whatis.flow_step2') }}</span>
                    </li>
                    <li class="flex items-start">
                        <span class="inline-flex flex-shrink-0 items-center justify-center w-8 h-8 rounded-full bg-purple-600 text-white text-sm mr-3">3</span>
                        <span class="text-gray-300 pt-1">{{ __('docs.whatis.flow_step3') }}</span>
                    </li>
                    <li class="flex items-start">
                        <span class="inline-flex flex-shrink-0 items-center justify-center w-8 h-8 rounded-full bg-purple-600 text-white text-sm mr-3">4</span>
                        <span class="text-gray-300 pt-1">{{ __('docs.whatis.flow_step4') }}</span>
                    </li>
                    <li class="flex items-start">
                        <span class="inline-flex flex-shrink-0 items-center justify-center w-8 h-8 rounded-full bg-purple-600 text-white text-sm mr-3">5</span>
                        <span class="text-gray-300 pt-1">{{ __('docs.whatis.flow_step5') }}</span>
                    </li>
                </ol>

                <div class="callout callout-tip mb-4">
                    <p class="text-sm text-gray-300">
                        <i class="fas fa-shield-halved text-blue-400 mr-2"></i>
                        <strong>{{ __('docs.whatis.privacy_title') }}</strong><br>
                        {{ __('docs.whatis.privacy_desc') }}
                    </p>
                </div>

                <div class="bg-gray-900 rounded-lg p-4 border border-gray-700">
                    <p class="text-sm text-gray-300">
                        <i class="fas fa-code text-purple-400 mr-2"></i>
                        <strong>{{ __('docs.whatis.dev_title') }}</strong><br>
                        {{ __('docs.whatis.dev_desc') }}
                        <a href="https://github.com/djethino/UnityGameTranslator/discussions" target="_blank"
                           class="text-purple-400 hover:text-purple-300 underline">{{ __('docs.whatis.dev_cta') }}</a>
                    </p>
                </div>
            </div>
        
            </section>

        <!-- Quick Start -->
        <section id="quick-start" class="mb-12 scroll-mt-8">
            <h2 class="text-2xl font-bold mb-6 flex items-center">
                <i class="fas fa-rocket mr-3 text-purple-400"></i>{{ __('docs.quick_start.title') }}
            </h2>

            <div class="bg-gray-800 rounded-lg p-6 border border-gray-700">
                <p class="text-gray-300 mb-6">{{ __('docs.quick_start.intro') }}</p>
                <figure class="mb-6 w-full flex flex-col items-center justify-center">
                    <img src="{{ asset('images/screenshots/WebGamePage.webp') }}"
                         alt="{{ __('docs.shot.game_alt') }}"
                         class="doc-img doc-img-web block mx-auto"
                         width="1390" height="840"
                         loading="lazy"
                         data-zoomable>
                    <figcaption class="text-sm text-gray-400 mt-2 text-center">{{ __('docs.shot.game_caption') }}</figcaption>
                </figure>

                {{-- 🔴 A FORK, not a tutorial (analyse/docs-structure.md, decision 3 of 2026-08-11).

                     Two ways in, weighed the same on screen: two cards of equal size, side by side.
                     A single figure of one of them would decide for the reader — they would take
                     the one they were shown. What separates them is written on them, not implied
                     by their placement.

                     ⚠ The three numbered steps that used to be here still exist and still say what
                     to do BY HAND — they moved into the by-hand card below, where they belong now
                     that they are one of two answers rather than the answer. --}}
                <div class="grid md:grid-cols-2 gap-4 mb-6">
                    <a href="#install-manager" class="docs-step bg-gray-700 rounded-lg p-5 block">
                        <div class="flex items-center gap-2 mb-2">
                            <i class="fas fa-screwdriver-wrench text-purple-400"></i>
                            <span class="font-semibold text-white">{{ __('docs.quick_start.way_manager') }}</span>
                        </div>
                        <p class="text-sm text-gray-300">{{ __('docs.quick_start.way_manager_desc') }}</p>
                        <div class="text-xs text-purple-400 mt-3">
                            {{ __('docs.nav.install_manager') }} <i class="fas fa-arrow-right ml-1"></i>
                        </div>
                    </a>
                    <a href="#install-manual" class="docs-step bg-gray-700 rounded-lg p-5 block">
                        <div class="flex items-center gap-2 mb-2">
                            <i class="fas fa-download text-purple-400"></i>
                            <span class="font-semibold text-white">{{ __('docs.quick_start.way_manual') }}</span>
                        </div>
                        <p class="text-sm text-gray-300">{{ __('docs.quick_start.way_manual_desc') }}</p>
                        <div class="text-xs text-purple-400 mt-3">
                            {{ __('docs.nav.install_manual') }} <i class="fas fa-arrow-right ml-1"></i>
                        </div>
                    </a>
                </div>

                {{-- What both ways end at. Said once, under the fork, because it is the same file in
                     the same folder either way — and somebody choosing between two routes needs to
                     know they arrive at the same place. --}}
                <p class="text-sm text-gray-400 mb-6">
                    {{ __('docs.quick_start.both_ways') }}
                    <a href="#first-launch" class="text-purple-300 hover:text-purple-200 underline underline-offset-2">{{ __('docs.nav.first_launch') }} <i class="fas fa-arrow-right text-xs"></i></a>
                </p>

                {{-- How the text is FOUND, said before anyone installs anything.

                     This was the most frequently misunderstood thing about the mod: people
                     expected a one-click extraction of the whole game, the way a datamining
                     tool works, and only found out otherwise by asking. The site did say it —
                     once, in "First launch", a section addressed to someone who has already
                     installed. Whoever is still deciding never reached it. --}}
                <div class="callout callout-info mb-6">
                    <p class="text-sm text-gray-300">
                        <i class="fas fa-eye text-purple-400 mr-2"></i>
                        <strong>{{ __('docs.quick_start.discovery_title') }}</strong><br>
                        {{ __('docs.quick_start.discovery') }}
                    </p>
                </div>

                {{-- Says the mod works on community translations alone, and now offers the way to
                     one. It used to make the claim and stop there, on a site whose whole catalogue
                     of translations is two clicks away — the reader had to believe us and then go
                     looking. --}}
                <div class="callout callout-tip">
                    <p class="text-sm text-gray-300">
                        <i class="fas fa-lightbulb text-blue-400 mr-2"></i>
                        <strong>{{ __('docs.quick_start.tip_title') }}</strong><br>
                        {{ __('docs.quick_start.tip_content') }}
                        <a href="{{ route('games.index') }}"
                           class="text-purple-300 hover:text-purple-200 underline whitespace-nowrap">{{ __('games.browse') }} <i class="fas fa-arrow-right text-xs"></i></a>
                    </p>
                </div>
            </div>
        
            </section>

        {{-- The Manager.

             🔴 A SECTION of this page, not a page of its own — decided 2026-08-11 and not reopened
             (analyse/docs-structure.md). The menu was grouped for it at the time.

             Shape: one scannable inventory, then depth on the four things that carry the product —
             Mod defaults, the one click, the AI, and looking after a game. The short blocks stay
             short on purpose; somebody reading this has not installed anything yet.

             ⚠ Every UI label is quoted in English and stays that way in all 20 languages: the
             Manager is not translated, so a Korean reader has to find the same word on screen. The
             sentence AROUND it explains — never the label itself. See
             analyse/i18n-terminology-rules.md §6. --}}
        <section id="install-manager" class="mb-12 scroll-mt-8">
            <h2 class="text-2xl font-bold mb-6 flex items-center">
                <i class="fas fa-screwdriver-wrench mr-3 text-purple-400"></i>{{ __('docs.manager.title') }}
            </h2>

            {{-- 1 — What it does.

                 A plain list, not a card grid: the grid on this page means "choices you pick
                 between" (local AI / cloud / translation API), and these twelve do not exclude one
                 another. No image either — this is the block somebody skims in ten seconds, and the
                 first figure of the section belongs to the first real gesture. --}}
            <div class="bg-gray-800 rounded-lg p-6 border border-gray-700 mb-4">
                <p class="text-gray-300 mb-4">{{ __('docs.manager.intro') }}</p>

                <ul class="text-gray-300 text-sm space-y-2 list-disc list-inside marker:text-purple-400">
                    <li>{{ __('docs.manager.does_find') }}</li>
                    <li>{{ __('docs.manager.does_read') }}</li>
                    <li>{{ __('docs.manager.does_install') }}</li>
                    <li>{{ __('docs.manager.does_answer_once') }}</li>
                    <li>{{ __('docs.manager.does_oneclick') }}</li>
                    <li>{{ __('docs.manager.does_ai') }}</li>
                    <li>{{ __('docs.manager.does_test') }}</li>
                    <li>{{ __('docs.manager.does_translations') }}</li>
                    <li>{{ __('docs.manager.does_standing') }}</li>
                    <li>{{ __('docs.manager.does_backup') }}</li>
                    <li>{{ __('docs.manager.does_repair') }}</li>
                    <li>{{ __('docs.manager.does_other_account') }}</li>
                    <li>{{ __('docs.manager.does_remove') }}</li>
                </ul>

                {{-- The guard against the one confusion this whole section can create. Somebody who
                     reads "AI server", "model" and "target language" on a desktop tool will look
                     for the translation itself in it. --}}
                <p class="text-sm text-gray-400 mt-4 border-t border-gray-700 pt-4">
                    <i class="fas fa-circle-info text-purple-400 mr-2"></i>{{ __('docs.manager.not_a_translator') }}
                </p>
            </div>

            {{-- 3 — Getting it. (2, the fork between the two ways, lives in Quick start.) --}}
            <div id="manager-get" data-nav-anchor class="bg-gray-800 rounded-lg p-6 border border-gray-700 mb-4 scroll-mt-8">
                <h3 class="text-lg font-semibold mb-4">
                    <span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-purple-600 text-white text-sm mr-2">1</span>
                    {{ __('docs.manager.get_title') }}
                </h3>

                <p class="text-gray-300 mb-4">{{ __('docs.manager.get_portable') }}</p>

                <div class="text-center mb-4">
                    <a href="https://github.com/djethino/unitygametranslator-manager/releases/latest" target="_blank" rel="noopener"
                       class="inline-flex items-center gap-2 bg-purple-600 hover:bg-purple-700 text-white px-6 py-3 rounded-lg text-lg">
                        <i class="fas fa-download"></i>
                        {{ __('docs.manager.get_download') }}
                    </a>
                </div>

                <p class="text-gray-300 text-sm mb-4">{{ __('docs.manager.get_install') }}</p>

                <figure class="mb-6 w-full flex flex-col items-center justify-center">
                    <img src="{{ asset('images/screenshots/ManagerFirstRun.webp') }}"
                         alt="{{ __('docs.manager.shot_firstrun_alt') }}"
                         class="doc-img doc-img-web block mx-auto"
                         width="810" height="68"
                         loading="lazy"
                         data-zoomable>
                    <figcaption class="text-sm text-gray-400 mt-2 text-center">{{ __('docs.manager.shot_firstrun_caption') }}</figcaption>
                </figure>
            </div>

            {{-- 4 — Your games. Establishing shot first: three paragraphs about a window nobody has
                 seen is three paragraphs wasted. --}}
            <div id="manager-games" data-nav-anchor class="bg-gray-800 rounded-lg p-6 border border-gray-700 mb-4 scroll-mt-8">
                <h3 class="text-lg font-semibold mb-4">
                    <span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-purple-600 text-white text-sm mr-2">2</span>
                    {{ __('docs.manager.games_title') }}
                </h3>

                <p class="text-gray-300 mb-4">{{ __('docs.manager.games_found') }}</p>

                <figure class="mb-6 w-full flex flex-col items-center justify-center">
                    <img src="{{ asset('images/screenshots/ManagerHome.webp') }}"
                         alt="{{ __('docs.manager.shot_home_alt') }}"
                         class="doc-img doc-img-web block mx-auto"
                         width="1282" height="738"
                         loading="lazy"
                         data-zoomable>
                    <figcaption class="text-sm text-gray-400 mt-2 text-center">{{ __('docs.manager.shot_home_caption') }}</figcaption>
                </figure>

                <p class="text-gray-300 mb-4">{{ __('docs.manager.games_play_in') }}</p>
                <p class="text-gray-300 mb-6">{{ __('docs.manager.games_tags') }}</p>

                {{-- My folders: a second window, so a second figure — and small enough to sit beside
                     the text that explains it rather than under it. --}}
                <div class="grid md:grid-cols-2 gap-6 mb-6 items-center">
                    <p class="text-gray-300">{{ __('docs.manager.games_folders') }}</p>
                    <figure class="text-center">
                        <img src="{{ asset('images/screenshots/ManagerFolders.webp') }}"
                             alt="{{ __('docs.manager.shot_folders_alt') }}"
                             class="doc-img doc-img-web mx-auto"
                             width="716" height="589"
                             loading="lazy"
                             data-zoomable>
                        <figcaption class="text-sm text-gray-400 mt-2 text-center">{{ __('docs.manager.shot_folders_caption') }}</figcaption>
                    </figure>
                </div>

                {{-- What it reads out of a game, and the proof of it. This strip is the evidence for
                     the paragraph above it: Unity version, Mono or IL2CPP, the loader that fits, and
                     the sentence saying what would happen. --}}
                <p class="text-gray-300 mb-4">
                    {{ __('docs.manager.games_reads') }}
                    <a href="#install-manual" class="text-purple-300 hover:text-purple-200 underline underline-offset-2">{{ __('docs.manager.games_reads_link') }}</a>
                </p>

                <figure class="mb-6 w-full flex flex-col items-center justify-center">
                    <img src="{{ asset('images/screenshots/ManagerDetection.webp') }}"
                         alt="{{ __('docs.manager.shot_detection_alt') }}"
                         class="doc-img doc-img-web block mx-auto"
                         width="814" height="160"
                         loading="lazy"
                         data-zoomable>
                    <figcaption class="text-sm text-gray-400 mt-2 text-center">{{ __('docs.manager.shot_detection_caption') }}</figcaption>
                </figure>
            </div>

            {{-- Refusals. Their own card, and RED throughout — because that is what the product
                 does: its refusal card is red whatever the reason, including the ones you can
                 overrule. What separates the anti-cheat is the words and the icon, not the colour,
                 exactly as the Manager separates it by removing the button rather than recolouring
                 it. See analyse/docs-structure.md and the draft §3.1. --}}
            <div id="manager-refused" data-nav-anchor class="bg-gray-800 rounded-lg p-6 border border-gray-700 mb-4 scroll-mt-8">
                <h3 class="text-lg font-semibold mb-4">
                    <i class="fas fa-circle-exclamation text-red-400 mr-2"></i>{{ __('docs.manager.refused_title') }}
                </h3>

                <p class="text-gray-300 mb-4">{{ __('docs.manager.refused_intro') }}</p>

                <div class="overflow-x-auto mb-4">
                    <table class="w-full text-sm">
                        <tbody class="text-gray-300">
                            <tr class="border-t border-gray-700">
                                <td class="px-4 py-2 font-medium whitespace-nowrap">{{ __('docs.manager.refused_store') }}</td>
                                <td class="px-4 py-2">{{ __('docs.manager.refused_store_why') }}</td>
                            </tr>
                            <tr class="border-t border-gray-700">
                                <td class="px-4 py-2 font-medium whitespace-nowrap">{{ __('docs.manager.refused_stripped') }}</td>
                                <td class="px-4 py-2">{{ __('docs.manager.refused_stripped_why') }}</td>
                            </tr>
                            <tr class="border-t border-gray-700">
                                <td class="px-4 py-2 font-medium whitespace-nowrap">{{ __('docs.manager.refused_unreadable') }}</td>
                                <td class="px-4 py-2">{{ __('docs.manager.refused_unreadable_why') }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                {{-- The most important sentence of the block: it turns "your game is refused" into
                     "you can try anyway". True — ModdabilityProbe.CanBeOverridden. --}}
                <p class="text-gray-300 text-sm mb-4">{{ __('docs.manager.refused_overrule') }}</p>

                <figure class="mb-6 w-full flex flex-col items-center justify-center">
                    <img src="{{ asset('images/screenshots/ManagerGameRefused.webp') }}"
                         alt="{{ __('docs.manager.shot_refused_alt') }}"
                         class="doc-img doc-img-web block mx-auto"
                         width="1206" height="242"
                         loading="lazy"
                         data-zoomable>
                    <figcaption class="text-sm text-gray-400 mt-2 text-center">{{ __('docs.manager.shot_refused_caption') }}</figcaption>
                </figure>

                {{-- callout-danger, and this is its first use in the page. Reserved for the one
                     refusal that cannot be overruled and whose cost is paid by the reader. --}}
                <div class="callout callout-danger">
                    <p class="text-sm text-gray-300">
                        <i class="fas fa-ban text-red-400 mr-2"></i>
                        <strong>{{ __('docs.manager.refused_anticheat_title') }}</strong><br>
                        {{ __('docs.manager.refused_anticheat') }}
                    </p>
                </div>
            </div>

            {{-- 5 — Mod defaults. --}}
            <div id="manager-defaults" data-nav-anchor class="bg-gray-800 rounded-lg p-6 border border-gray-700 mb-4 scroll-mt-8">
                <h3 class="text-lg font-semibold mb-4">
                    <span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-purple-600 text-white text-sm mr-2">3</span>
                    {{ __('docs.manager.defaults_title') }}
                </h3>

                {{-- Portrait beside the text, doc-img-tall: the window is 842x1356 and its point
                     here is the shape of the whole thing — which cards there are, in what order.
                     The fine reading happens on click, which is what that class is for. --}}
                <div class="grid md:grid-cols-2 gap-6 mb-6 items-center">
                    <div>
                        <p class="text-gray-300 mb-3">{{ __('docs.manager.defaults_intro') }}</p>
                        <ul class="text-gray-300 text-sm space-y-2 list-disc list-inside marker:text-purple-400">
                            <li>{{ __('docs.manager.defaults_language') }}</li>
                            <li>{{ __('docs.manager.defaults_backend') }}</li>
                            <li>{{ __('docs.manager.defaults_ai') }}</li>
                            <li>{{ __('docs.manager.defaults_hotkey') }}</li>
                            <li>{{ __('docs.manager.defaults_online') }}</li>
                        </ul>
                    </div>
                    <figure class="text-center">
                        <img src="{{ asset('images/screenshots/ManagerModDefaults.webp') }}"
                             alt="{{ __('docs.manager.shot_defaults_alt') }}"
                             class="doc-img doc-img-tall mx-auto"
                             width="842" height="1356"
                             loading="lazy"
                             data-zoomable>
                        <figcaption class="text-sm text-gray-400 mt-2 text-center">{{ __('docs.manager.shot_defaults_caption') }}</figcaption>
                    </figure>
                </div>

                {{-- 🔴 The one thing that must land, or nothing else in this section works: Mod
                     defaults is the CONDITION of the one click. The message on screen is
                     deliberately four words ("Mod defaults comes first."); this is the only place
                     that can say why. --}}
                <div class="callout callout-info mb-6">
                    <p class="text-sm text-gray-300">
                        <i class="fas fa-key text-purple-400 mr-2"></i>
                        <strong>{{ __('docs.manager.defaults_first_title') }}</strong><br>
                        {{ __('docs.manager.defaults_first') }}
                    </p>
                </div>

                <p class="text-gray-300 mb-4">
                    {{ __('docs.manager.defaults_ways') }}
                    <a href="#first-launch" class="text-purple-300 hover:text-purple-200 underline underline-offset-2">{{ __('docs.manager.defaults_ways_link') }}</a>
                </p>

                <figure class="mb-6 w-full flex flex-col items-center justify-center">
                    <img src="{{ asset('images/screenshots/ManagerSetupWays.webp') }}"
                         alt="{{ __('docs.manager.shot_ways_alt') }}"
                         class="doc-img doc-img-web block mx-auto"
                         width="788" height="170"
                         loading="lazy"
                         data-zoomable>
                    <figcaption class="text-sm text-gray-400 mt-2 text-center">{{ __('docs.manager.shot_ways_caption') }}</figcaption>
                </figure>

                {{-- Inheritance made visible — the mechanism the whole tool rests on, and one no
                     sentence replaces: every field says where its value came from. --}}
                <p class="text-gray-300 mb-4">
                    {{ __('docs.manager.defaults_inherited') }}
                    <a href="#configuration" class="text-purple-300 hover:text-purple-200 underline underline-offset-2">{{ __('docs.nav.configuration') }} <i class="fas fa-arrow-right text-xs"></i></a>
                </p>

                <figure class="mb-6 w-full flex flex-col items-center justify-center">
                    <img src="{{ asset('images/screenshots/ManagerInherited.webp') }}"
                         alt="{{ __('docs.manager.shot_inherited_alt') }}"
                         class="doc-img doc-img-web block mx-auto"
                         width="758" height="262"
                         loading="lazy"
                         data-zoomable>
                    <figcaption class="text-sm text-gray-400 mt-2 text-center">{{ __('docs.manager.shot_inherited_caption') }}</figcaption>
                </figure>

                {{-- The hotkey is the only setting that does NOT travel, and that surprises people.
                     Two facts, one sentence — see .claude/rules/name-things-in-ui.md, corollary 2. --}}
                <div class="callout callout-warning">
                    <p class="text-sm text-gray-300">
                        <i class="fas fa-keyboard text-yellow-400 mr-2"></i>
                        <strong>{{ __('docs.manager.defaults_key_title') }}</strong><br>
                        {{ __('docs.manager.defaults_key') }}
                    </p>
                </div>
            </div>

            {{-- 6 — One click. --}}
            <div id="manager-oneclick" data-nav-anchor class="bg-gray-800 rounded-lg p-6 border border-gray-700 mb-4 scroll-mt-8">
                <h3 class="text-lg font-semibold mb-4">
                    <span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-purple-600 text-white text-sm mr-2">4</span>
                    {{ __('docs.manager.oneclick_title') }}
                </h3>

                <p class="text-gray-300 mb-4">{{ __('docs.manager.oneclick_intro') }}</p>

                {{-- Numbered like the by-hand steps, and that echo is the message: four things by
                     hand, per game — or one button. --}}
                <ol class="text-gray-300 text-sm space-y-2 list-decimal list-inside marker:text-purple-400 mb-4">
                    <li>{{ __('docs.manager.oneclick_step_loader') }}</li>
                    <li>{{ __('docs.manager.oneclick_step_mod') }}</li>
                    <li>{{ __('docs.manager.oneclick_step_settings') }}</li>
                    <li>{{ __('docs.manager.oneclick_step_translation') }}</li>
                </ol>

                <p class="text-gray-300 text-sm mb-4">{{ __('docs.manager.oneclick_nothing_else') }}</p>

                <figure class="mb-6 w-full flex flex-col items-center justify-center">
                    <img src="{{ asset('images/screenshots/ManagerOneClick.webp') }}"
                         alt="{{ __('docs.manager.shot_oneclick_alt') }}"
                         class="doc-img doc-img-web block mx-auto"
                         width="839" height="561"
                         loading="lazy"
                         data-zoomable>
                    <figcaption class="text-sm text-gray-400 mt-2 text-center">{{ __('docs.manager.shot_oneclick_caption') }}</figcaption>
                </figure>

                {{-- 🔴 The part that exists nowhere else. A greyed button with no reason is the most
                     frustrating thing an installer can show; the Manager always says why, and the
                     documentation is where the way out gets room. Six reasons, from WhyNotReady. --}}
                <h4 class="font-semibold text-white mb-3">{{ __('docs.manager.oneclick_off_title') }}</h4>
                <p class="text-gray-300 text-sm mb-4">{{ __('docs.manager.oneclick_off_intro') }}</p>

                <div class="overflow-x-auto mb-4">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-700">
                            <tr>
                                <th class="px-4 py-2 text-left">{{ __('docs.manager.oneclick_off_says') }}</th>
                                <th class="px-4 py-2 text-left">{{ __('docs.manager.oneclick_off_means') }}</th>
                            </tr>
                        </thead>
                        <tbody class="text-gray-300">
                            <tr class="border-t border-gray-700">
                                <td class="px-4 py-2"><em>This game is fully set up.</em></td>
                                <td class="px-4 py-2">{{ __('docs.manager.oneclick_off_done') }}</td>
                            </tr>
                            <tr class="border-t border-gray-700">
                                <td class="px-4 py-2"><em>Mod defaults comes first.</em></td>
                                <td class="px-4 py-2">{{ __('docs.manager.oneclick_off_defaults') }}</td>
                            </tr>
                            <tr class="border-t border-gray-700">
                                <td class="px-4 py-2"><em>Nothing to translate this game with yet.</em></td>
                                <td class="px-4 py-2">{{ __('docs.manager.oneclick_off_notranslator') }}</td>
                            </tr>
                            <tr class="border-t border-gray-700">
                                <td class="px-4 py-2"><em>The game is running.</em></td>
                                <td class="px-4 py-2">{{ __('docs.manager.oneclick_off_running') }}</td>
                            </tr>
                            <tr class="border-t border-gray-700">
                                <td class="px-4 py-2"><em>No loader in the catalog fits this game.</em></td>
                                <td class="px-4 py-2">{{ __('docs.manager.oneclick_off_noloader') }}</td>
                            </tr>
                            <tr class="border-t border-gray-700">
                                <td class="px-4 py-2">{{ __('docs.manager.oneclick_off_prereq_says') }}</td>
                                <td class="px-4 py-2">{{ __('docs.manager.oneclick_off_prereq') }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="callout callout-info">
                    <p class="text-sm text-gray-300">
                        <i class="fas fa-user-shield text-purple-400 mr-2"></i>
                        <strong>{{ __('docs.manager.oneclick_other_account_title') }}</strong><br>
                        {{ __('docs.manager.oneclick_other_account') }}
                    </p>
                </div>
            </div>

            {{-- 7 — Translating with an AI.

                 ⚠ Renamed from "Choosing an AI model": that title announced a catalogue, while
                 three of the four things here work with any OpenAI-compatible server. It genuinely
                 made a reader who knows the product ask whether the whole block was Ollama-only. --}}
            <div id="manager-ai" data-nav-anchor class="bg-gray-800 rounded-lg p-6 border border-gray-700 mb-4 scroll-mt-8">
                <h3 class="text-lg font-semibold mb-4">
                    <span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-purple-600 text-white text-sm mr-2">5</span>
                    {{ __('docs.manager.ai_title') }}
                </h3>

                <p class="text-gray-300 mb-4">
                    {{ __('docs.manager.ai_intro') }}
                    <a href="#install-manual" class="text-purple-300 hover:text-purple-200 underline underline-offset-2">{{ __('docs.manager.ai_intro_link') }}</a>
                </p>

                <p class="text-gray-300 mb-4">{{ __('docs.manager.ai_install_server') }}</p>

                {{-- The hardware caveat. Said here because this is where somebody decides — and
                     WITHOUT the smallest-model figure, which docs/_models.blade.php computes from
                     the catalogue. Two figures for one fact would go stale in opposite directions.
                     AMD and Intel are not repeated either: docs.wizard_step4_desc says it. --}}
                <div class="callout callout-warning mb-6">
                    <p class="text-sm text-gray-300">
                        <i class="fas fa-microchip text-yellow-400 mr-2"></i>
                        <strong>{{ __('docs.manager.ai_hardware_title') }}</strong><br>
                        {{ __('docs.manager.ai_hardware') }}
                    </p>
                </div>

                {{-- 🔴 Two lists of models on one screen, and they do not have the same reach. The
                     picker holds YOUR server's models, whatever server it is; the table holds the
                     ones we measured, and only Ollama can be asked to fetch one. Naming them
                     separately is the whole point — "the list" would mean either. --}}
                <p class="text-gray-300 mb-4">{{ __('docs.manager.ai_any_server') }}</p>
                <p class="text-gray-300 mb-4">{{ __('docs.manager.ai_our_models') }}</p>

                <figure class="mb-6 w-full flex flex-col items-center justify-center">
                    <img src="{{ asset('images/screenshots/ManagerModels.webp') }}"
                         alt="{{ __('docs.manager.shot_models_alt') }}"
                         class="doc-img doc-img-web block mx-auto"
                         width="762" height="308"
                         loading="lazy"
                         data-zoomable>
                    <figcaption class="text-sm text-gray-400 mt-2 text-center">{{ __('docs.manager.shot_models_caption') }}</figcaption>
                </figure>

                <p class="text-gray-300 text-sm">{{ __('docs.manager.ai_ollama_names') }}</p>
            </div>

            {{-- 🔴 THE MODEL BENCH GETS ITS OWN BLOCK, and its own anchor.

                 It was a sub-heading inside "Translating with an AI", where somebody scanning the
                 page never met it — and it is the one feature here that stands on its own: it
                 answers "is this model any good for translating a game", which is a question
                 somebody has even when they installed everything by hand and manage nothing with
                 the Manager. That makes it a reason to download the Manager in itself, and a
                 sub-heading cannot carry that.

                 ⚠ Addressable: `#manager-model-test` is linked from the models block of
                 `Installing by hand` and belongs in the side menu. --}}
            <div id="manager-model-test" data-nav-anchor class="bg-gray-800 rounded-lg p-6 border border-gray-700 mb-4 scroll-mt-8">
                <h3 class="text-lg font-semibold mb-4">
                    <span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-purple-600 text-white text-sm mr-2">6</span>
                    {{ __('docs.manager.ai_test_title') }}
                </h3>

                {{-- Why it is worth reading even for somebody who came here for nothing else. --}}
                <div class="callout callout-tip mb-4">
                    <p class="text-sm text-gray-300">
                        <i class="fas fa-flask text-blue-400 mr-2"></i>
                        <strong>{{ __('docs.manager.test_standalone_title') }}</strong><br>
                        {{ __('docs.manager.test_standalone') }}
                    </p>
                </div>

                <p class="text-gray-300 mb-4">{{ __('docs.manager.ai_test') }}</p>

                <figure class="mb-6 w-full flex flex-col items-center justify-center">
                    <img src="{{ asset('images/screenshots/ManagerModelTest.webp') }}"
                         alt="{{ __('docs.manager.shot_test_alt') }}"
                         class="doc-img doc-img-web block mx-auto"
                         width="766" height="320"
                         loading="lazy"
                         data-zoomable>
                    <figcaption class="text-sm text-gray-400 mt-2 text-center">{{ __('docs.manager.shot_test_caption') }}</figcaption>
                </figure>

                {{-- The Breton sentence. It is the only line stopping a reader from treating the
                     score as a verdict, and it is true — see ModelTestSuite's own docblock. --}}
                <p class="text-gray-300 mb-4">{{ __('docs.manager.ai_read_answers') }}</p>

                <div class="callout callout-warning">
                    <p class="text-sm text-gray-300">
                        <i class="fas fa-scale-unbalanced text-yellow-400 mr-2"></i>
                        <strong>{{ __('docs.manager.ai_selfmark_title') }}</strong><br>
                        {{ __('docs.manager.ai_selfmark') }}
                    </p>
                </div>
            </div>

            {{-- 8 — Looking after a game. --}}
            <div id="manager-card" data-nav-anchor class="bg-gray-800 rounded-lg p-6 border border-gray-700 mb-4 scroll-mt-8">
                <h3 class="text-lg font-semibold mb-4">
                    <span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-purple-600 text-white text-sm mr-2">7</span>
                    {{ __('docs.manager.card_title') }}
                </h3>

                <p class="text-gray-300 mb-4">{{ __('docs.manager.card_two_sides') }}</p>

                <figure class="mb-6 w-full flex flex-col items-center justify-center">
                    <img src="{{ asset('images/screenshots/ManagerGameCard.webp') }}"
                         alt="{{ __('docs.manager.shot_card_alt') }}"
                         class="doc-img doc-img-web block mx-auto"
                         width="1282" height="925"
                         loading="lazy"
                         data-zoomable>
                    <figcaption class="text-sm text-gray-400 mt-2 text-center">{{ __('docs.manager.shot_card_caption') }}</figcaption>
                </figure>

                {{-- Chips and the H/V/A bar belong to quality-system and collaboration. This block
                     shows that the Manager displays them and sends the reader there for the meaning
                     — repeating it would create a second explanation to keep in step. --}}
                <p class="text-gray-300 mb-4">
                    {{ __('docs.manager.card_chips') }}
                    <a href="#quality-system" class="text-purple-300 hover:text-purple-200 underline underline-offset-2">{{ __('docs.nav.quality_system') }}</a> ·
                    <a href="#collaboration" class="text-purple-300 hover:text-purple-200 underline underline-offset-2">{{ __('docs.nav.collaboration') }} <i class="fas fa-arrow-right text-xs"></i></a>
                </p>

                <p class="text-gray-300 mb-4">{{ __('docs.manager.card_actions') }}</p>

                {{-- 🔴 A capability my own inventory had missed, and the screenshot carried it: the
                     community list is not limited to the language you play in. Somebody bilingual
                     has a preferred language, not an only one. --}}
                <p class="text-gray-300 mb-4">{{ __('docs.manager.card_other_language') }}</p>

                <p class="text-gray-300">{{ __('docs.manager.card_setup_tab') }}</p>
            </div>

            {{-- 9, 10, 11 — updates, removing, and the bridge back for hand installs. Short blocks:
                 they answer questions somebody has later, not now. --}}
            <div id="manager-updates" data-nav-anchor class="bg-gray-800 rounded-lg p-6 border border-gray-700 mb-4 scroll-mt-8">
                <h3 class="text-lg font-semibold mb-4">
                    <span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-purple-600 text-white text-sm mr-2">8</span>
                    {{ __('docs.manager.updates_title') }}
                </h3>

                <p class="text-gray-300 mb-4">{{ __('docs.manager.updates_intro') }}</p>

                <ul class="text-gray-300 text-sm space-y-2 list-disc list-inside marker:text-purple-400 mb-6">
                    <li>{{ __('docs.manager.updates_mod') }}</li>
                    <li>{{ __('docs.manager.updates_loader') }}</li>
                    <li>{{ __('docs.manager.updates_tool') }}</li>
                </ul>

                <figure class="mb-6 w-full flex flex-col items-center justify-center">
                    <img src="{{ asset('images/screenshots/ManagerLoaderNotOurs.webp') }}"
                         alt="{{ __('docs.manager.shot_loader_alt') }}"
                         class="doc-img doc-img-web block mx-auto"
                         width="878" height="80"
                         loading="lazy"
                         data-zoomable>
                    <figcaption class="text-sm text-gray-400 mt-2 text-center">{{ __('docs.manager.shot_loader_caption') }}</figcaption>
                </figure>

                <h4 class="font-semibold text-white mb-3">{{ __('docs.manager.remove_title') }}</h4>
                <p class="text-gray-300 mb-4">{{ __('docs.manager.remove_intro') }}</p>
                <p class="text-gray-300 text-sm">{{ __('docs.manager.remove_options') }}</p>
            </div>

            {{-- 🔴 The block that speaks to every current user of the mod: they all installed by
                 hand. Linked from `install-manual` too, or this public never finds it. --}}
            <div id="manager-byhand" data-nav-anchor class="bg-gray-800 rounded-lg p-6 border border-gray-700 mb-4 scroll-mt-8">
                <h3 class="text-lg font-semibold mb-4">
                    <i class="fas fa-hand text-purple-400 mr-2"></i>{{ __('docs.manager.byhand_title') }}
                </h3>

                <p class="text-gray-300 mb-4">{{ __('docs.manager.byhand_intro') }}</p>

                <ul class="text-gray-300 text-sm space-y-2 list-disc list-inside marker:text-purple-400 mb-4">
                    <li>{{ __('docs.manager.byhand_misplaced') }}</li>
                    <li>{{ __('docs.manager.byhand_copies') }}</li>
                </ul>

                <p class="text-gray-300">{{ __('docs.manager.byhand_loader') }}</p>
            </div>

            {{-- 12 — The Manager's own settings. Last, and short: it is about the program, not about
                 the games, and nobody arrives here first. --}}
            <div id="manager-settings" data-nav-anchor class="bg-gray-800 rounded-lg p-6 border border-gray-700 scroll-mt-8">
                <h3 class="text-lg font-semibold mb-4">
                    <i class="fas fa-cog text-purple-400 mr-2"></i>{{ __('docs.manager.settings_title') }}
                </h3>

                <p class="text-gray-300 mb-3">{{ __('docs.manager.settings_intro') }}</p>
                <p class="text-gray-300 text-sm">
                    {{ __('docs.manager.settings_account') }}
                    <a href="#sync" class="text-purple-300 hover:text-purple-200 underline underline-offset-2">{{ __('docs.nav.sync') }} <i class="fas fa-arrow-right text-xs"></i></a>
                </p>
            </div>
        </section>


        <!-- First Launch -->
        <section id="first-launch" class="mb-12 scroll-mt-8">
            <h2 class="text-2xl font-bold mb-6 flex items-center">
                <i class="fas fa-play mr-3 text-purple-400"></i>{{ __('docs.first_launch') }}
            </h2>

            <div class="bg-gray-800 rounded-lg p-6 border border-gray-700">
                <p class="text-gray-300 mb-6">{{ __('docs.first_launch_intro') }}</p>

                <!-- Wizard Screenshot -->
                <figure class="mb-6 w-full flex flex-col items-center justify-center">
                    <img src="{{ asset('images/screenshots/ModWizard1.webp') }}"
                         alt="{{ __('docs.wizard_screenshot_alt') }}"
                         class="doc-img doc-img-mod block mx-auto"
                         width="519" height="396"
                         loading="lazy"
                         data-zoomable>
                    <figcaption class="text-sm text-gray-400 mt-2 text-center">{{ __('docs.wizard_caption') }}</figcaption>
                </figure>

                <h3 id="wizard-steps" data-nav-anchor class="scroll-mt-8 font-semibold mb-3 text-lg">{{ __('docs.wizard_steps_title') }}</h3>
                <ol class="text-gray-300 space-y-3 list-decimal list-inside">
                    <li><strong>{{ __('docs.wizard_step1_title') }}</strong> - {{ __('docs.wizard_step1_desc') }}</li>
                    <li><strong>{{ __('docs.wizard_step2_title') }}</strong> - {{ __('docs.wizard_step2_desc') }}</li>
                    <li><strong>{{ __('docs.wizard_step3_title') }}</strong> - {{ __('docs.wizard_step3_desc') }}</li>
                    <li><strong>{{ __('docs.wizard_step4_title') }}</strong> - {{ __('docs.wizard_step4_desc') }}</li>
                </ol>

                <h3 id="first-launch-after" data-nav-anchor class="scroll-mt-8 font-semibold mb-3 mt-6 text-lg">{{ __('docs.first_launch_after_title') }}</h3>
                <ul class="text-gray-300 space-y-2">
                    <li class="flex items-start">
                        <i class="fas fa-gamepad text-purple-400 mr-2 mt-1"></i>
                        <span>{{ __('docs.first_launch_after1') }}</span>
                    </li>
                    <li class="flex items-start">
                        <i class="fas fa-keyboard text-purple-400 mr-2 mt-1"></i>
                        <span>{{ __('docs.first_launch_after2') }}</span>
                    </li>
                    <li class="flex items-start">
                        <i class="fas fa-bell text-purple-400 mr-2 mt-1"></i>
                        <span>{{ __('docs.first_launch_after3') }}</span>
                    </li>
                </ul>

                <div class="callout callout-tip mt-4">
                    <p class="text-sm text-gray-300">
                        <i class="fas fa-keyboard text-blue-400 mr-2"></i>
                        <strong>{{ __('docs.hotkey_tip_title') }}</strong><br>
                        {{ __('docs.hotkey_tip_content') }}
                    </p>
                </div>

                {{-- The return leg. The Manager section says one of its three ways is "leave it to
                     the wizard" and points here; without this, the reader who arrived at the wizard
                     never learns it could have been answered for them, across every game at once.
                     A cross-link that only goes one way leaves half the readers outside. --}}
                <div class="callout callout-info mt-4">
                    <p class="text-sm text-gray-300">
                        <i class="fas fa-screwdriver-wrench text-purple-400 mr-2"></i>
                        <strong>{{ __('docs.first_launch.skip_wizard_title') }}</strong><br>
                        {{ __('docs.first_launch.skip_wizard') }}
                        <a href="#install-manager" class="text-purple-300 hover:text-purple-200 underline underline-offset-2">{{ __('docs.nav.install_manager') }} <i class="fas fa-arrow-right text-xs"></i></a>
                    </p>
                </div>
            </div>
        </section>

        <!-- Editing (in-game text editor + browser live edit) -->
        <section id="editing" class="mb-12 scroll-mt-8">
            <h2 class="text-2xl font-bold mb-6 flex items-center">
                <i class="fas fa-pen-to-square mr-3 text-purple-400"></i>{{ __('docs.editing.title') }}
            </h2>
            <!-- Intro + the mod's Tools tab: entry point of both in-game editors -->
            <div class="bg-gray-800 rounded-lg p-6 border border-gray-700 mb-4">
                <p class="text-gray-300 mb-4">{{ __('docs.editing.intro') }}</p>
                <figure class="text-center">
                    <img src="{{ asset('images/screenshots/ModToolsPanel.webp') }}"
                         alt="{{ __('docs.editing.mod_tools_alt') }}"
                         class="doc-img doc-img-tall mx-auto"
                         width="751" height="1132"
                         loading="lazy"
                         data-zoomable>
                    <figcaption class="text-sm text-gray-400 mt-2 text-center">{{ __('docs.editing.mod_tools_caption') }}</figcaption>
                </figure>
            </div>

            <!-- In-game Text Editor -->
            <div class="bg-gray-800 rounded-lg p-6 border border-gray-700 mb-4">
                <h3 id="editing-text-editor" data-nav-anchor class="scroll-mt-8 font-semibold mb-4 text-lg">
                    <i class="fas fa-i-cursor text-blue-400 mr-2"></i>{{ __('docs.editing.text_editor_title') }}
                </h3>
                <p class="text-gray-300 mb-4">{{ __('docs.editing.text_editor_intro') }}</p>
                <div class="grid md:grid-cols-2 gap-6 mb-4 items-center">
                    <ol class="text-gray-300 space-y-3 list-decimal list-inside">
                        <li>{{ __('docs.editing.text_editor_step1') }}</li>
                        <li>{{ __('docs.editing.text_editor_step2') }}</li>
                        <li>{{ __('docs.editing.text_editor_step3') }}</li>
                    </ol>
                    <figure class="text-center">
                        <img src="{{ asset('images/screenshots/ModTextEditor.webp') }}"
                             alt="{{ __('docs.editing.text_editor_alt') }}"
                             class="doc-img doc-img-tall mx-auto"
                             width="661" height="1049"
                             loading="lazy"
                             data-zoomable>
                        <figcaption class="text-sm text-gray-400 mt-2 text-center">{{ __('docs.editing.text_editor_caption') }}</figcaption>
                    </figure>
                </div>
                <div class="callout callout-tip mb-4">
                    <p class="text-sm text-gray-300">
                        <i class="fas fa-hashtag text-blue-400 mr-2"></i>
                        <strong>{{ __('docs.editing.placeholders_title') }}</strong><br>
                        {{ __('docs.editing.placeholders_desc') }}
                    </p>
                </div>

                <div class="callout callout-warning">
                    <p class="text-sm text-gray-300">
                        <i class="fas fa-keyboard text-yellow-400 mr-2"></i>
                        <strong>{{ __('docs.editing.text_editor_limits_title') }}</strong><br>
                        {{ __('docs.editing.text_editor_limits_desc') }}
                    </p>
                </div>
            </div>

            <!-- Browser Live Edit -->
            <div class="bg-gray-800 rounded-lg p-6 border border-gray-700 mb-4">
                <h3 id="editing-live-edit" data-nav-anchor class="scroll-mt-8 font-semibold mb-4 text-lg">
                    <i class="fas fa-globe text-green-400 mr-2"></i>{{ __('docs.editing.live_edit_title') }}
                </h3>
                <p class="text-gray-300 mb-4">{{ __('docs.editing.live_edit_intro') }}</p>

                <!-- The three promises, scannable -->
                <div class="grid sm:grid-cols-3 gap-3 mb-6">
                    <div class="bg-gray-900 rounded-lg p-3 text-center text-sm text-gray-300">
                        <i class="fas fa-user-slash text-green-400 mr-2"></i>{{ __('docs.editing.live_pill1') }}
                    </div>
                    <div class="bg-gray-900 rounded-lg p-3 text-center text-sm text-gray-300">
                        <i class="fas fa-eye-slash text-green-400 mr-2"></i>{{ __('docs.editing.live_pill2') }}
                    </div>
                    <div class="bg-gray-900 rounded-lg p-3 text-center text-sm text-gray-300">
                        <i class="fas fa-rotate text-green-400 mr-2"></i>{{ __('docs.editing.live_pill3') }}
                    </div>
                </div>

                <ol class="text-gray-300 space-y-2 list-decimal list-inside mb-4">
                    <li>{{ __('docs.editing.live_edit_step1') }}</li>
                    <li>{{ __('docs.editing.live_edit_step2') }}</li>
                    <li>{{ __('docs.editing.live_edit_step3') }}</li>
                </ol>

                <div class="grid md:grid-cols-3 gap-6 mb-4 items-center">
                    <figure class="text-center">
                        <img src="{{ asset('images/screenshots/ModBrowserSessionActive.webp') }}"
                             alt="{{ __('docs.editing.session_active_alt') }}"
                             class="doc-img doc-img-tall mx-auto"
                             width="821" height="1078"
                             loading="lazy"
                             data-zoomable>
                        <figcaption class="text-sm text-gray-400 mt-2 text-center">{{ __('docs.editing.session_active_caption') }}</figcaption>
                    </figure>
                    <figure class="md:col-span-2">
                        <img src="{{ asset('images/screenshots/WebLiveEdit.webp') }}"
                             alt="{{ __('docs.editing.live_edit_alt') }}"
                             class="doc-img doc-img-web"
                             width="1228" height="1122"
                             loading="lazy"
                             data-zoomable>
                        <figcaption class="text-sm text-gray-400 mt-2 text-center">{{ __('docs.editing.live_edit_caption') }}</figcaption>
                    </figure>
                </div>

                <div class="callout callout-tip mb-4">
                    <p class="text-sm text-gray-300">
                        <i class="fas fa-wand-magic-sparkles text-purple-400 mr-2"></i>
                        <strong>{{ __('docs.editing.retranslate_title') }}</strong><br>
                        {{ __('docs.editing.retranslate_desc') }}
                    </p>
                </div>

                <div class="callout callout-tip">
                    <p class="text-sm text-gray-300">
                        <i class="fas fa-hourglass-half text-blue-400 mr-2"></i>
                        <strong>{{ __('docs.editing.lifecycle_title') }}</strong><br>
                        {{ __('docs.editing.lifecycle_desc') }}
                    </p>
                </div>
            </div>

            <!-- Editing a published translation on the website -->
            <div class="bg-gray-800 rounded-lg p-6 border border-gray-700 mb-4">
                <h3 id="editing-web-edit" data-nav-anchor class="scroll-mt-8 font-semibold mb-4 text-lg">
                    <i class="fas fa-cloud text-blue-400 mr-2"></i>{{ __('docs.editing.web_edit_title') }}
                </h3>
                <p class="text-gray-300 mb-4">{{ __('docs.editing.web_edit_intro') }}</p>
                <ol class="text-gray-300 space-y-2 list-decimal list-inside">
                    <li>{{ __('docs.editing.web_edit_step1') }}</li>
                    <li>{{ __('docs.editing.web_edit_step2') }}</li>
                    <li>{{ __('docs.editing.web_edit_step3') }}</li>
                </ol>
            </div>

            <!-- Editor toolbox (shared by all web editors) -->
            <div class="bg-gray-800 rounded-lg p-6 border border-gray-700">
                <h3 id="editing-toolbox" data-nav-anchor class="scroll-mt-8 font-semibold mb-4 text-lg">
                    <i class="fas fa-toolbox text-orange-400 mr-2"></i>{{ __('docs.editing.toolbox_title') }}
                </h3>
                <p class="text-gray-300 mb-4">{{ __('docs.editing.toolbox_intro') }}</p>
                <ul class="text-gray-300 space-y-2">
                    <li><i class="fas fa-search text-purple-400 mr-2"></i>{{ __('docs.editing.toolbox_search') }}</li>
                    <li><i class="fas fa-right-left text-purple-400 mr-2"></i>{{ __('docs.editing.toolbox_replace') }}</li>
                    <li><i class="fas fa-keyboard text-purple-400 mr-2"></i>{{ __('docs.editing.toolbox_keyboard') }}</li>
                    <li><i class="fas fa-arrow-pointer text-purple-400 mr-2"></i>{{ __('docs.editing.toolbox_validate') }}</li>
                    <li><i class="fas fa-chart-simple text-purple-400 mr-2"></i>{{ __('docs.editing.toolbox_quality') }}</li>
                    <li><i class="fas fa-shield-halved text-purple-400 mr-2"></i>{{ __('docs.editing.toolbox_placeholders') }}</li>
                </ul>
            </div>
        </section>

        <!-- Collaboration -->
        <section id="collaboration" class="mb-12 scroll-mt-8">
            <h2 class="text-2xl font-bold mb-6 flex items-center">
                <i class="fas fa-users mr-3 text-purple-400"></i>{{ __('docs.collaboration.title') }}
            </h2>

            <div class="bg-gray-800 rounded-lg p-6 border border-gray-700 mb-4">
                <h3 id="collaboration-model" data-nav-anchor class="scroll-mt-8 font-semibold mb-4 text-lg">{{ __('docs.collaboration.model_title') }}</h3>
                <p class="text-gray-300 mb-6">{{ __('docs.collaboration.model_intro') }}</p>

                <!-- Main/Branch/Fork -->
                <div class="grid md:grid-cols-3 gap-4 mb-6">
                    <div class="bg-gray-700 rounded-lg p-4 border-l-4 border-purple-500">
                        <h4 class="font-semibold text-white mb-2">
                            <i class="fas fa-crown text-purple-400 mr-2"></i>Main
                        </h4>
                        <p class="text-sm text-gray-300">{{ __('docs.collaboration.main_desc') }}</p>
                    </div>
                    <div class="bg-gray-700 rounded-lg p-4 border-l-4 border-blue-500">
                        <h4 class="font-semibold text-white mb-2">
                            <i class="fas fa-code-branch text-blue-400 mr-2"></i>Branch
                        </h4>
                        <p class="text-sm text-gray-300">{{ __('docs.collaboration.branch_desc') }}</p>
                    </div>
                    <div class="bg-gray-700 rounded-lg p-4 border-l-4 border-green-500">
                        <h4 class="font-semibold text-white mb-2">
                            <i class="fas fa-code-fork text-green-400 mr-2"></i>Fork
                        </h4>
                        <p class="text-sm text-gray-300">{{ __('docs.collaboration.fork_desc') }}</p>
                    </div>
                </div>

                <!-- Workflow -->
                <h4 class="font-semibold mb-3">{{ __('docs.collaboration.workflow_title') }}</h4>
                <ol class="text-gray-300 space-y-2 list-decimal list-inside">
                    <li>{{ __('docs.collaboration.workflow1') }}</li>
                    <li>{{ __('docs.collaboration.workflow2') }}</li>
                    <li>{{ __('docs.collaboration.workflow3') }}</li>
                    <li>{{ __('docs.collaboration.workflow4') }}</li>
                <figure class="mb-6 w-full flex flex-col items-center justify-center">
                    <img src="{{ asset('images/screenshots/WebMerge.webp') }}"
                         alt="{{ __('docs.shot.merge_alt') }}"
                         class="doc-img doc-img-web block mx-auto"
                         width="1389" height="841"
                         loading="lazy"
                         data-zoomable>
                    <figcaption class="text-sm text-gray-400 mt-2 text-center">{{ __('docs.shot.merge_caption') }}</figcaption>
                </figure>
                </ol>
                <h4 class="font-semibold mb-3 mt-6">{{ __('docs.card.title') }}</h4>
                <p class="text-gray-300 mb-4">{{ __('docs.card.intro') }}</p>

                <figure class="mb-6 w-full flex flex-col items-center justify-center">
                    <img src="{{ asset('images/screenshots/WebTranslationCard.webp') }}"
                         alt="{{ __('docs.card.alt') }}"
                         class="doc-img doc-img-web block mx-auto"
                         width="1232" height="202"
                         loading="lazy"
                         data-zoomable>
                    <figcaption class="text-sm text-gray-400 mt-2 text-center">{{ __('docs.card.caption') }}</figcaption>
                </figure>

                <ul class="space-y-2 text-gray-300 mb-2">
                    <li class="flex items-start gap-3">
                        <i class="fas fa-pen text-purple-400 mt-1 w-4 text-center"></i>
                        <span><strong>{{ __('my_translations.edit_translations') }}</strong> — {{ __('docs.card.pen') }}</span>
                    </li>
                    <li class="flex items-start gap-3">
                        <i class="fas fa-code-merge text-green-400 mt-1 w-4 text-center"></i>
                        <span><strong>{{ __('my_translations.merge_branches', ['count' => 2]) }}</strong> — {{ __('docs.card.merge') }}</span>
                    </li>
                    <li class="flex items-start gap-3">
                        <i class="fas fa-download text-blue-400 mt-1 w-4 text-center"></i>
                        <span><strong>{{ __('translation.download') }}</strong> — {{ __('docs.card.download') }}</span>
                    </li>
                    <li class="flex items-start gap-3">
                        <i class="fas fa-circle-info text-gray-400 mt-1 w-4 text-center"></i>
                        <span><strong>{{ __('dashboard.title') }}</strong> — {{ __('docs.card.settings') }}</span>
                    </li>
                    <li class="flex items-start gap-3">
                        <i class="fas fa-trash text-red-400 mt-1 w-4 text-center"></i>
                        <span><strong>{{ __('translation.delete') }}</strong> — {{ __('docs.card.delete') }}</span>
                    </li>
                </ul>
            </div>

            <!-- Upload -->
            <div class="bg-gray-800 rounded-lg p-6 border border-gray-700 mb-4">
                <h3 id="collaboration-upload" data-nav-anchor class="scroll-mt-8 font-semibold mb-4 text-lg">
                    <i class="fas fa-upload text-green-400 mr-2"></i>{{ __('docs.collaboration.upload_title') }}
                </h3>
                <p class="text-gray-300 mb-4">{{ __('docs.collaboration.upload_intro') }}</p>
                <figure class="mb-6 w-full flex flex-col items-center justify-center">
                    <img src="{{ asset('images/screenshots/WebUpload.webp') }}"
                         alt="{{ __('docs.shot.upload_alt') }}"
                         class="doc-img doc-img-web block mx-auto"
                         width="1400" height="841"
                         loading="lazy"
                         data-zoomable>
                    <figcaption class="text-sm text-gray-400 mt-2 text-center">{{ __('docs.shot.upload_caption') }}</figcaption>
                </figure>

                <div class="grid md:grid-cols-2 gap-4">
                    <div>
                        <h4 class="font-medium text-white mb-2">{{ __('docs.collaboration.from_mod') }}</h4>
                        <ol class="text-sm text-gray-300 space-y-1 list-decimal list-inside">
                            <li>{{ __('docs.collaboration.mod_upload1') }}</li>
                            <li>{{ __('docs.collaboration.mod_upload2') }}</li>
                            <li>{{ __('docs.collaboration.mod_upload3') }}</li>
                        </ol>
                    </div>
                    <div>
                        <h4 class="font-medium text-white mb-2">{{ __('docs.collaboration.from_website') }}</h4>
                        <ol class="text-sm text-gray-300 space-y-1 list-decimal list-inside">
                            <li>{{ __('docs.collaboration.web_upload1') }}</li>
                            <li>{{ __('docs.collaboration.web_upload2') }}</li>
                            <li>{{ __('docs.collaboration.web_upload3') }}</li>
                        </ol>
                    </div>
                </div>
            </div>

            <!-- Merge -->
            <div class="bg-gray-800 rounded-lg p-6 border border-gray-700">
                <h3 id="collaboration-merge" data-nav-anchor class="scroll-mt-8 font-semibold mb-4 text-lg">
                    <i class="fas fa-code-merge text-purple-400 mr-2"></i>{{ __('docs.collaboration.merge_title') }}
                </h3>
                <p class="text-gray-300 mb-4">{{ __('docs.collaboration.merge_intro') }}</p>

                <!-- Merge Screenshot -->
                <figure class="mb-6 w-full flex flex-col items-center justify-center">
                    <img src="{{ asset('images/screenshots/WebHumanEditAndValidation.webp') }}"
                         alt="{{ __('docs.merge_screenshot_alt') }}"
                         class="doc-img doc-img-web"
                         width="1421" height="1276"
                         loading="lazy"
                         data-zoomable>
                    <figcaption class="text-sm text-gray-400 mt-2 text-center">{{ __('docs.merge_caption') }}</figcaption>
                </figure>

                <p class="text-gray-300">{{ __('docs.collaboration.merge_rules') }}</p>
            </div>
        
            
            </section>

        <!-- Sync -->
        <section id="sync" class="mb-12 scroll-mt-8">
            <h2 class="text-2xl font-bold mb-6 flex items-center">
                <i class="fas fa-sync mr-3 text-purple-400"></i>{{ __('docs.sync.title') }}
            </h2>

            <div class="bg-gray-800 rounded-lg p-6 border border-gray-700 mb-4">
                <h3 id="sync-online-mode" data-nav-anchor class="scroll-mt-8 font-semibold mb-4 text-lg">{{ __('docs.sync.online_mode_title') }}</h3>
                <p class="text-gray-300 mb-4">{{ __('docs.sync.online_mode_desc') }}</p>

                <ul class="text-gray-300 space-y-2">
                    <li><i class="fas fa-check text-green-400 mr-2"></i>{{ __('docs.sync.feature1') }}</li>
                    <li><i class="fas fa-check text-green-400 mr-2"></i>{{ __('docs.sync.feature2') }}</li>
                    <li><i class="fas fa-check text-green-400 mr-2"></i>{{ __('docs.sync.feature3') }}</li>
                    <li><i class="fas fa-check text-green-400 mr-2"></i>{{ __('docs.sync.feature4') }}</li>
                </ul>
            </div>

            <!-- Device Flow (Account Linking) -->
            <div class="bg-gray-800 rounded-lg p-6 border border-gray-700 mb-4">
                <h3 id="sync-device-flow" data-nav-anchor class="scroll-mt-8 font-semibold mb-4 text-lg">
                    <i class="fas fa-link text-blue-400 mr-2"></i>{{ __('docs.sync.device_flow_title') }}
                </h3>
                <p class="text-gray-300 mb-4">{{ __('docs.sync.device_flow_desc') }}</p>

                <!-- Screenshots side by side -->
                <div class="grid md:grid-cols-2 gap-4 mb-6">
                    <figure class="text-center">
                        <img src="{{ asset('images/screenshots/ModConnect.webp') }}"
                             alt="{{ __('docs.sync.mod_connect_alt') }}"
                             class="doc-img doc-img-mod mx-auto"
                             width="761" height="732"
                             loading="lazy"
                             data-zoomable>
                        <figcaption class="text-sm text-gray-400 mt-2 text-center">{{ __('docs.sync.mod_connect_caption') }}</figcaption>
                    </figure>
                    <figure class="text-center">
                        <img src="{{ asset('images/screenshots/WebConnect.webp') }}"
                             alt="{{ __('docs.sync.web_connect_alt') }}"
                             class="doc-img doc-img-web"
                             width="617" height="583"
                             loading="lazy"
                             data-zoomable>
                        <figcaption class="text-sm text-gray-400 mt-2 text-center">{{ __('docs.sync.web_connect_caption') }}</figcaption>
                    </figure>
                </div>

                <ol class="text-gray-300 space-y-2 list-decimal list-inside">
                    <li>{{ __('docs.sync.device_step1') }}</li>
                    <li>{{ __('docs.sync.device_step2') }} <code class="bg-gray-700 px-2 py-0.5 rounded">ABC-123</code>)</li>
                    <li>{{ __('docs.sync.device_step3') }} <a href="{{ route('link') }}" class="text-purple-400 hover:underline">{{ url('/link') }}</a></li>
                    <li>{{ __('docs.sync.device_step4') }}</li>
                </ol>

                <div class="callout callout-tip mt-4">
                    <p class="text-sm text-gray-300">
                        <i class="fas fa-shield-alt text-blue-400 mr-2"></i>
                        <strong>{{ __('docs.sync.security_title') }}</strong><br>
                        {{ __('docs.sync.security_desc') }}
                    </p>
                </div>
            </div>

            <!-- Multi-device -->
            <div class="bg-gray-800 rounded-lg p-6 border border-gray-700">
                <h3 id="sync-multi-device" data-nav-anchor class="scroll-mt-8 font-semibold mb-4 text-lg">
                    <i class="fas fa-laptop mr-2 text-purple-400"></i>{{ __('docs.sync.multi_device_title') }}
                </h3>
                <p class="text-gray-300 mb-4">{{ __('docs.sync.multi_device_desc') }}</p>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-700">
                            <tr>
                                <th class="px-4 py-2 text-left">{{ __('docs.sync.situation') }}</th>
                                <th class="px-4 py-2 text-left">{{ __('docs.sync.action') }}</th>
                            </tr>
                        </thead>
                        <tbody class="text-gray-300">
                            <tr class="border-t border-gray-700">
                                <td class="px-4 py-2">{{ __('docs.sync.local_only') }}</td>
                                <td class="px-4 py-2"><span class="text-green-400">{{ __('docs.sync.upload_prompt') }}</span></td>
                            </tr>
                            <tr class="border-t border-gray-700">
                                <td class="px-4 py-2">{{ __('docs.sync.server_only') }}</td>
                                <td class="px-4 py-2"><span class="text-blue-400">{{ __('docs.sync.download_prompt') }}</span></td>
                            </tr>
                            <tr class="border-t border-gray-700">
                                <td class="px-4 py-2">{{ __('docs.sync.both_changed') }}</td>
                                <td class="px-4 py-2"><span class="text-purple-400">{{ __('docs.sync.merge_prompt') }}</span></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <!-- Quality System -->
        <section id="quality-system" class="mb-12 scroll-mt-8">
            <h2 class="text-2xl font-bold mb-6 flex items-center">
                <i class="fas fa-star mr-3 text-purple-400"></i>{{ __('docs.quality_system.title') }}
            </h2>

            <div class="bg-gray-800 rounded-lg p-6 border border-gray-700">
                <p class="text-gray-300 mb-2">{{ __('docs.quality_system.intro') }}</p>
                <figure class="mb-6 w-full flex flex-col items-center justify-center">
                    <img src="{{ asset('images/screenshots/WebMyTranslations.webp') }}"
                         alt="{{ __('docs.shot.mine_alt') }}"
                         class="doc-img doc-img-web block mx-auto"
                         width="1400" height="841"
                         loading="lazy"
                         data-zoomable>
                    <figcaption class="text-sm text-gray-400 mt-2 text-center">{{ __('docs.shot.mine_caption') }}</figcaption>
                </figure>
                {{-- The tags and the formulas that read them were two sections apart with nothing
                     connecting them, though neither means much without the other. --}}
                <p class="text-sm mb-6">
                    <a href="#algorithms" class="text-purple-400 hover:text-purple-300 underline underline-offset-2">
                        <i class="fas fa-arrow-turn-down mr-1 text-xs"></i>{{ __('docs.nav.algorithms') }}
                    </a>
                </p>

                <!-- HVAS Badges -->
                <div class="grid md:grid-cols-4 gap-4 mb-6">
                    <div class="bg-gray-700 rounded-lg p-4 text-center border-t-4 border-green-500">
                        <span class="inline-block px-3 py-1 rounded text-lg font-bold bg-green-600 text-white mb-2">H</span>
                        <div class="font-semibold text-white">{{ __('docs.quality_system.human') }}</div>
                        <div class="text-sm text-gray-400">{{ __('docs.quality_system.human_desc') }}</div>
                    </div>
                    <div class="bg-gray-700 rounded-lg p-4 text-center border-t-4 border-blue-500">
                        <span class="inline-block px-3 py-1 rounded text-lg font-bold bg-blue-600 text-white mb-2">V</span>
                        <div class="font-semibold text-white">{{ __('docs.quality_system.validated') }}</div>
                        <div class="text-sm text-gray-400">{{ __('docs.quality_system.validated_desc') }}</div>
                    </div>
                    <div class="bg-gray-700 rounded-lg p-4 text-center border-t-4 border-orange-500">
                        <span class="inline-block px-3 py-1 rounded text-lg font-bold bg-orange-600 text-white mb-2">A</span>
                        <div class="font-semibold text-white">{{ __('docs.quality_system.ai') }}</div>
                        <div class="text-sm text-gray-400">{{ __('docs.quality_system.ai_desc') }}</div>
                    </div>
                    {{-- Purple, like its segment in the composition bar: a line kept as it is
                         has been dealt with, and must not be mistaken for one still waiting. --}}
                    <div class="bg-gray-700 rounded-lg p-4 text-center border-t-4 border-purple-500">
                        <span class="inline-block px-3 py-1 rounded text-lg font-bold bg-purple-600 text-white mb-2">S</span>
                        <div class="font-semibold text-white">{{ __('docs.quality_system.skip') }}</div>
                        <div class="text-sm text-gray-400">{{ __('docs.quality_system.skip_desc') }}</div>
                        <div class="text-xs text-purple-300 mt-2">{{ __('docs.quality_system.skip_note') }}</div>
                    </div>
                </div>

                <!-- Capture Mode -->
                <div class="mt-6 p-4 bg-gray-900 rounded-lg">
                    <h4 class="font-semibold mb-2 text-white">
                        <i class="fas fa-camera mr-2 text-purple-400"></i>{{ __('docs.quality_system.capture_mode') }}
                    </h4>
                    <p class="text-sm text-gray-300">{{ __('docs.quality_system.capture_desc') }}</p>
                </div>
            </div>
        
            </section>

        <!-- How the numbers are computed. Published in full on purpose: every figure shown on
             this site comes from a formula, and anyone whose work is being measured is entitled
             to read the measure. -->
        <section id="algorithms" class="mb-12 scroll-mt-8">
            <h2 class="text-2xl font-bold mb-6 flex items-center">
                <i class="fas fa-calculator mr-3 text-purple-400"></i>{{ __('docs.algorithms.title') }}
            </h2>

            <div class="bg-gray-800 rounded-lg p-6 border border-gray-700">
                <p class="text-gray-300 mb-2">{{ __('docs.algorithms.intro') }}</p>
                <p class="text-sm mb-6">
                    <a href="#quality-system" class="text-purple-400 hover:text-purple-300 underline underline-offset-2">
                        <i class="fas fa-arrow-turn-up mr-1 text-xs"></i>{{ __('docs.nav.quality_system') }}
                    </a>
                </p>

                {{-- One card per algorithm, in reading order: is the text written, then has it
                     been read, then how far it reaches, then how they decide the order. The dots
                     on the thresholds carry the colours of the badges they describe. --}}
                <div class="bg-gray-900 rounded-lg p-4 border-t-4 border-amber-500 mb-4">
                    <h3 id="algorithms-completeness" data-nav-anchor class="scroll-mt-8 font-semibold mb-2 text-white">
                        <i class="fas fa-hourglass-half mr-2 text-purple-400"></i>{{ __('docs.algorithms.completeness_title') }}
                    </h3>
                    <p class="text-sm text-gray-300 mb-3">{{ __('docs.algorithms.completeness_desc') }}</p>
                    <pre class="bg-gray-950 rounded p-3 text-sm text-gray-300 mb-3 overflow-x-auto">(H + V + S + A) / (H + V + S + A + captured)</pre>
                    <p class="text-sm text-gray-400">
                        <i class="fas fa-circle-info text-blue-400 mr-2"></i>{{ __('docs.algorithms.completeness_floor') }}
                    </p>
                </div>

                <div class="bg-gray-900 rounded-lg p-4 border-t-4 border-blue-500 mb-4">
                    <h3 id="algorithms-stage" data-nav-anchor class="scroll-mt-8 font-semibold mb-2 text-white">
                        <i class="fas fa-list-check mr-2 text-purple-400"></i>{{ __('docs.algorithms.stage_title') }}
                    </h3>
                    <p class="text-sm text-gray-300 mb-3">{{ __('docs.algorithms.stage_desc') }}</p>
                    <pre class="bg-gray-950 rounded p-3 text-sm text-gray-300 mb-3 overflow-x-auto">(H + V + S) / (H + V + S + A)</pre>
                    <ul class="text-sm text-gray-400 space-y-1">
                        <li><span class="inline-block w-2 h-2 rounded-full bg-green-500 mr-2"></span>100 % — {{ __('progress.stage.reviewed') }}</li>
                        <li><span class="inline-block w-2 h-2 rounded-full bg-blue-500 mr-2"></span>40 % … 99 % — {{ __('progress.stage.advanced') }}</li>
                        <li><span class="inline-block w-2 h-2 rounded-full bg-gray-400 mr-2"></span>1 % … 39 % — {{ __('progress.stage.started') }}</li>
                        <li><span class="inline-block w-2 h-2 rounded-full bg-gray-600 mr-2"></span>0 % — {{ __('progress.stage.machine') }}</li>
                    </ul>
                </div>

                <div class="bg-gray-900 rounded-lg p-4 border-t-4 border-green-500 mb-4">
                    <h3 id="algorithms-rate" data-nav-anchor class="scroll-mt-8 font-semibold mb-2 text-white">
                        <i class="fas fa-user-check mr-2 text-purple-400"></i>{{ __('docs.algorithms.rate_title') }}
                    </h3>
                    <p class="text-sm text-gray-300 mb-3">{{ __('docs.algorithms.rate_desc') }}</p>
                    <pre class="bg-gray-950 rounded p-3 text-sm text-gray-300 mb-3 overflow-x-auto">(H + S + c × V) / (H + V + S + A)
c = 0.8 → 1.0</pre>
                    <p class="text-sm text-gray-400">
                        <i class="fas fa-circle-info text-blue-400 mr-2"></i>{{ __('docs.algorithms.rate_limit') }}
                    </p>
                </div>

                <div class="bg-gray-900 rounded-lg p-4 border-t-4 border-cyan-500 mb-4">
                    <h3 id="algorithms-coverage" data-nav-anchor class="scroll-mt-8 font-semibold mb-2 text-white">
                        <i class="fas fa-map-location-dot mr-2 text-purple-400"></i>{{ __('docs.algorithms.coverage_title') }}
                    </h3>
                    <p class="text-sm text-gray-300 mb-3">{{ __('docs.algorithms.coverage_desc') }}</p>
                    <pre class="bg-gray-950 rounded p-3 text-sm text-gray-300 mb-3 overflow-x-auto">(H + V + S + A) / max(H + V + S + A)</pre>
                    <p class="text-sm text-gray-400">
                        <i class="fas fa-circle-info text-blue-400 mr-2"></i>{{ __('docs.algorithms.coverage_caveat') }}
                    </p>
                </div>

                <div class="bg-gray-900 rounded-lg p-4 border-t-4 border-orange-500 mb-4">
                    <h3 id="algorithms-dormancy" data-nav-anchor class="scroll-mt-8 font-semibold mb-2 text-white">
                        <i class="fas fa-hourglass-end mr-2 text-purple-400"></i>{{ __('docs.algorithms.dormancy_title') }}
                    </h3>
                    <p class="text-sm text-gray-300 mb-3">{{ __('docs.algorithms.dormancy_desc') }}</p>
                    <pre class="bg-gray-950 rounded p-3 text-sm text-gray-300 mb-3 overflow-x-auto">21 j + 159 j × (H + V + S) / (H + V + S + A + captured)</pre>
                    <p class="text-sm text-gray-400">
                        <i class="fas fa-circle-info text-blue-400 mr-2"></i>{{ __('docs.algorithms.dormancy_note') }}
                    </p>
                </div>

                <div class="bg-gray-900 rounded-lg p-4 border-t-4 border-purple-500 mb-4">
                    <h3 id="algorithms-order" data-nav-anchor class="scroll-mt-8 font-semibold mb-2 text-white">
                        <i class="fas fa-arrow-down-wide-short mr-2 text-purple-400"></i>{{ __('docs.algorithms.order_title') }}
                    </h3>
                    <p class="text-sm text-gray-300 mb-3">{{ __('docs.algorithms.order_desc') }}</p>
                    <pre class="bg-gray-950 rounded p-3 text-sm text-gray-300 mb-3 overflow-x-auto">completeness × coverage × (0.5 + 0.5 × rate)</pre>
                    <p class="text-sm text-gray-400">
                        <i class="fas fa-circle-info text-blue-400 mr-2"></i>{{ __('docs.algorithms.order_base') }}
                    </p>
                </div>

                <div class="callout callout-tip">
                    <p class="text-sm text-gray-300">
                        <i class="fas fa-eye text-blue-400 mr-2"></i>
                        <strong>{{ __('docs.algorithms.visibility_title') }}</strong><br>
                        {{ __('docs.algorithms.visibility_desc') }}
                    </p>
                </div>
            </div>
        </section>

        <!-- Configuration -->
        <section id="configuration" class="mb-12 scroll-mt-8">
            <h2 class="text-2xl font-bold mb-6 flex items-center">
                <i class="fas fa-cog mr-3 text-purple-400"></i>{{ __('docs.configuration') }}
            </h2>

            {{-- The panel first, the file second.

                 The home page sends people here from "connect your key", and what they landed on
                 was a JSON block — an answer for someone who already knows what they are doing,
                 offered to someone who has just decided to try. Nothing in the mod requires
                 editing that file: the same three choices live in the Translation tab, and this
                 one screen covers three of the four ways a translation can come about — collect
                 by hand, a local AI, or an online service. --}}
            <div class="bg-gray-800 rounded-lg p-6 border border-gray-700 mb-4">
                <h3 id="config-gui" data-nav-anchor class="scroll-mt-8 text-lg font-semibold text-white mb-2">
                    <i class="fas fa-sliders mr-2 text-purple-400"></i>{{ __('docs.config.gui_title') }}
                </h3>
                <p class="text-gray-300 mb-4">{{ __('docs.config.gui_intro') }}</p>

                <div class="grid md:grid-cols-2 gap-6">
                    <figure class="text-center">
                        <img src="{{ asset('images/screenshots/ModOptionsTranslationAi.webp') }}"
                             alt="{{ __('docs.config.gui_ai_alt') }}"
                             class="doc-img doc-img-tall mx-auto"
                             width="579" height="916"
                             loading="lazy"
                             data-zoomable>
                        <figcaption class="text-sm text-gray-400 mt-2 text-center">{{ __('docs.config.gui_ai_caption') }}</figcaption>
                    </figure>
                    <figure class="text-center">
                        <img src="{{ asset('images/screenshots/ModOptionsTranslationTools.webp') }}"
                             alt="{{ __('docs.config.gui_tools_alt') }}"
                             class="doc-img doc-img-tall mx-auto"
                             width="575" height="673"
                             loading="lazy"
                             data-zoomable>
                        <figcaption class="text-sm text-gray-400 mt-2 text-center">{{ __('docs.config.gui_tools_caption') }}</figcaption>
                    </figure>
                </div>
            </div>

            <div class="bg-gray-800 rounded-lg p-6 border border-gray-700">
                <h3 id="config-file" data-nav-anchor class="scroll-mt-8 text-lg font-semibold text-white mb-2">
                    <i class="fas fa-file-code mr-2 text-purple-400"></i>{{ __('docs.config.file_title') }}
                </h3>
                <p class="text-gray-300 mb-3">{{ __('docs.config_location') }}</p>

                {{-- "In the mod folder" was the whole answer, and it is not one: under
                     MelonLoader the folder holding your data is not the folder the mod was
                     installed into. Shared component, so the three sections that name this
                     folder can never drift apart again. --}}
                <x-docs.mod-folder :subfolders="['config.json']" class="mb-4" />
                <p class="text-sm text-gray-400 mb-4">
                    {{ __('docs.config.folder_hint') }}
                    <a href="#install-manual" class="text-purple-400 hover:text-purple-300 underline underline-offset-2">{{ __('docs.nav.install_manual') }}</a>.
                </p>

                {{-- Written from TokenProtection's own threat model, and deliberately not one
                     word further: the encryption binds the secrets to the machine, so a copy
                     taken elsewhere is unreadable — but any process running as the same user can
                     rebuild the key. Calling that "your keys are safe" is how someone ends up
                     pasting the file into a support thread. --}}
                <div class="callout callout-warning mb-6">
                    <p class="text-sm text-gray-300">
                        <i class="fas fa-triangle-exclamation text-yellow-400 mr-2"></i>
                        <strong>{{ __('docs.config.never_share_title') }}</strong><br>
                        {{ __('docs.config.never_share') }}
                    </p>
                </div>

                <pre class="bg-gray-900 rounded p-4 overflow-x-auto text-sm mb-6"><code class="text-gray-300">{
  "translation_backend": "llm",
  "ai_url": "http://127.0.0.1:11434",
  "ai_model": "{{ \App\Services\ModelCatalog::reference()['pull'] ?? '' }}",
  "ai_api_key": null,
  "enable_ai": true,
  "source_language": "auto",
  "target_language": "auto",
  "game_context": "",
  "settings_hotkey": "F10",
  "online_mode": true,
  "sync": {
    "update_check_frequency": "auto",
    "auto_download": false,
    "notify_updates": true
  }
}</code></pre>

                <p class="text-gray-300 mb-4">{{ __('docs.config.table_intro') }}</p>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-700">
                            <tr>
                                <th class="px-4 py-2 text-left">{{ __('docs.option') }}</th>
                                <th class="px-4 py-2 text-left">{{ __('docs.default') }}</th>
                                <th class="px-4 py-2 text-left">{{ __('docs.description') }}</th>
                            </tr>
                        </thead>
                        <tbody class="text-gray-300">
                            <tr class="border-t border-gray-700">
                                <td class="px-4 py-2"><code class="text-purple-300">target_language</code></td>
                                <td class="px-4 py-2"><code>"auto"</code></td>
                                <td class="px-4 py-2">{{ __('docs.config_target_lang') }}</td>
                            </tr>
                            <tr class="border-t border-gray-700">
                                <td class="px-4 py-2"><code class="text-purple-300">enable_ai</code></td>
                                <td class="px-4 py-2"><code>false</code></td>
                                <td class="px-4 py-2">{{ __('docs.config_enable_ai') }}</td>
                            </tr>
                            <tr class="border-t border-gray-700">
                                <td class="px-4 py-2"><code class="text-purple-300">ai_url</code></td>
                                <td class="px-4 py-2"><code>"http://localhost:11434"</code></td>
                                <td class="px-4 py-2">{{ __('docs.config_ai_url') }}</td>
                            </tr>
                            <tr class="border-t border-gray-700">
                                <td class="px-4 py-2"><code class="text-purple-300">ai_model</code></td>
                                <td class="px-4 py-2"><code>""</code></td>
                                <td class="px-4 py-2">{{ __('docs.config_ai_model') }}</td>
                            </tr>
                            <tr class="border-t border-gray-700">
                                <td class="px-4 py-2"><code class="text-purple-300">ai_api_key</code></td>
                                <td class="px-4 py-2"><code>null</code></td>
                                <td class="px-4 py-2">{{ __('docs.config_ai_api_key') }}</td>
                            </tr>
                            <tr class="border-t border-gray-700">
                                <td class="px-4 py-2"><code class="text-purple-300">settings_hotkey</code></td>
                                <td class="px-4 py-2"><code>"F10"</code></td>
                                <td class="px-4 py-2">{{ __('docs.config_hotkey') }}</td>
                            </tr>
                            <tr class="border-t border-gray-700">
                                <td class="px-4 py-2"><code class="text-purple-300">online_mode</code></td>
                                <td class="px-4 py-2"><code>true</code></td>
                                <td class="px-4 py-2">{{ __('docs.config_online_mode') }}</td>
                            </tr>
                            <tr class="border-t border-gray-700">
                                <td class="px-4 py-2"><code class="text-purple-300">sync.auto_download</code></td>
                                <td class="px-4 py-2"><code>false</code></td>
                                <td class="px-4 py-2">{{ __('docs.config_auto_download') }}</td>
                            </tr>

                            {{-- Everything below is reachable only from the file. Taken from a
                                 config.json of a game actually being worked on, then checked
                                 against ModConfig: the table had drifted so far that it still
                                 documented sync.check_update_on_start, which no longer exists —
                                 it is read once to migrate old files and then dropped. --}}
                            @php
                                $advanced = [
                                    ['group' => 'docs.config.group_sync'],
                                    ['sync.update_check_frequency', '"auto"', 'docs.config.update_frequency'],
                                    ['sync.notify_prereleases', 'false', 'docs.config.notify_prereleases'],
                                    ['sync.ignored_uuids', '[]', 'docs.config.ignored_uuids'],

                                    ['group' => 'docs.config.group_perf'],
                                    ['max_text_detection_latency_seconds', '1.0', 'docs.config.detection_latency'],
                                    ['timeout_ms', '30000', 'docs.config.timeout'],
                                    ['rate_limit_retry_delay', '3.0', 'docs.config.rate_limit'],
                                    ['max_font_atlas_size', '0', 'docs.config.atlas_size'],

                                    ['group' => 'docs.config.group_network'],
                                    ['api_base_url', 'null', 'docs.config.api_base_url'],
                                    ['api_token_server', 'null', 'docs.config.token_server'],
                                    ['proxy_mode', '"default"', 'docs.config.proxy_mode'],
                                    ['proxy_url', 'null', 'docs.config.proxy_url'],

                                    ['group' => 'docs.config.group_risky'],
                                    ['translate_localization_fallback', 'false', 'docs.config.localization_fallback'],
                                    ['strict_source_language', 'false', 'docs.config.strict_source'],
                                    ['translate_mod_ui', 'null', 'docs.config.translate_mod_ui'],
                                ];
                            @endphp
                            @foreach($advanced as $row)
                                @if(isset($row['group']))
                                    <tr class="border-t border-gray-700 bg-gray-750">
                                        <td colspan="3" class="px-4 py-2 text-purple-300 font-semibold">{{ __($row['group']) }}</td>
                                    </tr>
                                @else
                                    <tr class="border-t border-gray-700">
                                        <td class="px-4 py-2"><code class="text-purple-300">{{ $row[0] }}</code></td>
                                        <td class="px-4 py-2"><code>{{ $row[1] }}</code></td>
                                        <td class="px-4 py-2">{{ __($row[2]) }}</td>
                                    </tr>
                                @endif
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        {{-- Installing by hand — formerly `installation`, in the Start group.

             🔴 **`installation` is a PUBLIC address**: it is indexed, and it has been pasted into
             issues for months. The invisible span below keeps every one of those links landing on
             the by-hand procedure they were pointing at. It costs one line and it never expires —
             do not remove it on the grounds that nothing in this repository uses it, because that
             is precisely the point: what uses it is out there.

             ⚠ Renamed rather than kept: `#installation` beside `#install-manager` would be two
             addresses for the same subject where only one says which. --}}
        <span id="installation" class="block scroll-mt-8" aria-hidden="true"></span>
        <section id="install-manual" class="mb-12 scroll-mt-8">
            <h2 class="text-2xl font-bold mb-6 flex items-center">
                <i class="fas fa-download mr-3 text-purple-400"></i>{{ __('docs.install_manual.title') }}
            </h2>

            {{-- Why anyone would take this route, said before the first step rather than left to be
                 inferred from its position in Reference. A long procedure filed under Reference
                 reads as deprecated unless it says otherwise — and it is not: it is the answer when
                 somebody wants to see exactly what goes where, and when the Manager refuses a game
                 they know is fine. --}}
            <div class="callout callout-info mb-4">
                <p class="text-sm text-gray-300">
                    <i class="fas fa-hand text-purple-400 mr-2"></i>
                    <strong>{{ __('docs.install_manual.when_title') }}</strong><br>
                    {{ __('docs.install_manual.when') }}
                    <a href="#install-manager" class="text-purple-300 hover:text-purple-200 underline underline-offset-2">{{ __('docs.nav.install_manager') }} <i class="fas fa-arrow-right text-xs"></i></a>
                </p>
            </div>

            <!-- Step 1: Mod Loader -->
            <div class="bg-gray-800 rounded-lg p-6 border border-gray-700 mb-4">
                {{-- Addressable on its own: the Quick Start steps link straight here rather than
                     dropping the reader at the top of a 160-line section to hunt for step one. --}}
                <h3 data-nav-anchor id="install-loader" class="text-lg font-semibold mb-4 scroll-mt-8">
                    <span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-purple-600 text-white text-sm mr-2">1</span>
                    {{ __('docs.install_modloader') }}
                </h3>

                <div class="overflow-x-auto mb-4">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-700">
                            <tr>
                                <th class="px-4 py-2 text-left">{{ __('docs.modloader') }}</th>
                                <th class="px-4 py-2 text-left">{{ __('docs.unity_type') }}</th>
                                <th class="px-4 py-2 text-left">{{ __('docs.download') }}</th>
                            </tr>
                        </thead>
                        <tbody class="text-gray-300">
                            <tr class="border-t border-gray-700">
                                <td class="px-4 py-2 font-medium">BepInEx 5</td>
                                <td class="px-4 py-2"><span class="bg-green-900 text-green-300 px-2 py-0.5 rounded text-xs">Mono</span></td>
                                <td class="px-4 py-2"><a href="https://github.com/BepInEx/BepInEx/releases" target="_blank" class="text-purple-400 hover:underline">GitHub <i class="fas fa-external-link-alt text-xs ml-1"></i></a></td>
                            </tr>
                            <tr class="border-t border-gray-700">
                                <td class="px-4 py-2 font-medium">BepInEx 6</td>
                                <td class="px-4 py-2">
                                    <span class="bg-green-900 text-green-300 px-2 py-0.5 rounded text-xs mr-1">Mono</span>
                                    <span class="bg-blue-900 text-blue-300 px-2 py-0.5 rounded text-xs">IL2CPP</span>
                                </td>
                                <td class="px-4 py-2"><a href="https://builds.bepinex.dev/projects/bepinex_be" target="_blank" class="text-purple-400 hover:underline">Bleeding Edge <i class="fas fa-external-link-alt text-xs ml-1"></i></a></td>
                            </tr>
                            <tr class="border-t border-gray-700">
                                <td class="px-4 py-2 font-medium">MelonLoader</td>
                                <td class="px-4 py-2">
                                    <span class="bg-green-900 text-green-300 px-2 py-0.5 rounded text-xs mr-1">Mono</span>
                                    <span class="bg-blue-900 text-blue-300 px-2 py-0.5 rounded text-xs">IL2CPP</span>
                                </td>
                                <td class="px-4 py-2"><a href="https://github.com/LavaGang/MelonLoader/releases" target="_blank" class="text-purple-400 hover:underline">GitHub <i class="fas fa-external-link-alt text-xs ml-1"></i></a></td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="bg-gray-900 rounded p-4">
                    <p class="text-sm text-gray-300">
                        <strong class="text-white">{{ __('docs.how_to_know') }}</strong><br>
                        <code class="bg-gray-700 px-2 py-0.5 rounded text-blue-300">GameAssembly.dll</code> {{ __('docs.in_game_folder') }} → <strong class="text-blue-400">IL2CPP</strong><br>
                        <code class="bg-gray-700 px-2 py-0.5 rounded text-green-300">&lt;Game&gt;_Data/Managed/*.dll</code> → <strong class="text-green-400">Mono</strong>
                    </p>
                </div>
            </div>

            <!-- Step 2: Download UGT -->
            <div class="bg-gray-800 rounded-lg p-6 border border-gray-700 mb-4">
                <h3 data-nav-anchor id="install-plugin" class="text-lg font-semibold mb-4 scroll-mt-8">
                    <span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-purple-600 text-white text-sm mr-2">2</span>
                    {{ __('docs.download_ugt') }}
                </h3>

                <p class="text-gray-300 mb-4">{{ __('docs.download_ugt_intro') }}</p>

                <div class="text-center mb-4">
                    <a href="https://github.com/djethino/UnityGameTranslator/releases/latest" target="_blank"
                       class="inline-flex items-center gap-2 bg-purple-600 hover:bg-purple-700 text-white px-6 py-3 rounded-lg text-lg">
                        <i class="fas fa-download"></i>
                        {{ __('docs.download_latest') }}
                    </a>
                </div>

                <div class="space-y-3 text-gray-300">
                    <div>
                        <p class="font-semibold text-purple-300 mb-1">BepInEx</p>
                        <p class="text-sm mb-1">{{ __('docs.install_bepinex_desc') }}</p>
                        <code class="bg-gray-700 px-2 py-1 rounded text-sm block">&lt;Game&gt;/BepInEx/plugins/UnityGameTranslator/</code>
                    </div>
                    <div>
                        <p class="font-semibold text-purple-300 mb-1">MelonLoader</p>
                        <p class="text-sm mb-1">{{ __('docs.install_melon_dll_desc') }}</p>
                        <code class="bg-gray-700 px-2 py-1 rounded text-sm block">&lt;Game&gt;/Mods/</code>
                        <p class="text-sm mt-2 mb-1">{{ __('docs.install_melon_data_desc') }}</p>
                        <code class="bg-gray-700 px-2 py-1 rounded text-sm block">&lt;Game&gt;/UserData/UnityGameTranslator/</code>
                    </div>
                    <div class="bg-yellow-900/30 border border-yellow-700/50 rounded p-3 text-sm">
                        <p><i class="fas fa-exclamation-triangle text-yellow-400 mr-2"></i>{{ __('docs.install_melon_warning') }}</p>
                    </div>
                </div>
            </div>

            <!-- Step 3: AI Translation (Optional) -->
            <div class="bg-gray-800 rounded-lg p-6 border border-gray-700">
                <h3 id="enable-ai" data-nav-anchor class="scroll-mt-8 text-lg font-semibold mb-4">
                    <span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-purple-600 text-white text-sm mr-2">3</span>
                    {{ __('docs.enable_ai') }}
                    <span class="ml-2 bg-gray-600 text-gray-300 px-2 py-0.5 rounded text-xs">{{ __('docs.optional') }}</span>
                </h3>

                <p class="text-gray-300 mb-4">{{ __('docs.enable_ai_desc') }}</p>

                <div class="grid md:grid-cols-3 gap-4 mb-4">
                    <div class="bg-gray-700/50 rounded-lg p-4">
                        <h4 class="font-semibold text-green-400 mb-2"><i class="fas fa-desktop mr-2"></i>{{ __('docs.ai_local_title') }}</h4>
                        <p class="text-gray-300 text-sm mb-3">{{ __('docs.ai_local_desc') }}</p>
                        <div class="flex gap-3 text-sm">
                            <a href="https://ollama.ai" target="_blank" class="text-purple-400 hover:underline">ollama.ai</a>
                            <a href="https://lmstudio.ai" target="_blank" class="text-purple-400 hover:underline">lmstudio.ai</a>
                        </div>
                    </div>
                    <div class="bg-gray-700/50 rounded-lg p-4">
                        <h4 class="font-semibold text-blue-400 mb-2"><i class="fas fa-cloud mr-2"></i>{{ __('docs.ai_cloud_title') }}</h4>
                        <p class="text-gray-300 text-sm mb-3">{{ __('docs.ai_cloud_desc') }}</p>
                        <div class="flex gap-3 text-sm">
                            <a href="https://groq.com" target="_blank" class="text-purple-400 hover:underline">groq.com</a>
                            <a href="https://openrouter.ai" target="_blank" class="text-purple-400 hover:underline">openrouter.ai</a>
                        </div>
                    </div>
                    <div class="bg-gray-700/50 rounded-lg p-4">
                        <h4 class="font-semibold text-orange-400 mb-2"><i class="fas fa-language mr-2"></i>{{ __('docs.ai_translation_api_title') }}</h4>
                        <p class="text-gray-300 text-sm mb-3">{{ __('docs.ai_translation_api_desc') }}</p>
                        <div class="flex gap-3 text-sm">
                            <a href="https://cloud.google.com/translate" target="_blank" class="text-purple-400 hover:underline">Google Translate</a>
                            <a href="https://www.deepl.com/pro-api" target="_blank" class="text-purple-400 hover:underline">DeepL</a>
                        </div>
                    </div>
                </div>

                <p class="text-gray-300 text-sm mb-4">{{ __('docs.ai_setup_steps') }}</p>

                {{-- URL format guide --}}
                <div class="bg-gray-700/50 rounded-lg p-4 mb-4">
                    <h4 class="font-semibold text-white mb-2"><i class="fas fa-link mr-2 text-purple-400"></i>{{ __('docs.ai_url_title') }}</h4>
                    <p class="text-gray-300 text-sm mb-3">{{ __('docs.ai_url_desc') }}</p>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-gray-400 border-b border-gray-600">
                                    <th class="text-left py-2 px-3">{{ __('docs.ai_url_example_provider') }}</th>
                                    <th class="text-left py-2 px-3">{{ __('docs.ai_url_example_url') }}</th>
                                </tr>
                            </thead>
                            <tbody class="text-gray-300">
                                <tr class="border-b border-gray-700"><td class="py-2 px-3">Ollama</td><td class="py-2 px-3"><code class="text-purple-300">{{ __('docs.ai_url_example_ollama') }}</code></td></tr>
                                <tr class="border-b border-gray-700"><td class="py-2 px-3">LM Studio</td><td class="py-2 px-3"><code class="text-purple-300">{{ __('docs.ai_url_example_lmstudio') }}</code></td></tr>
                                <tr class="border-b border-gray-700"><td class="py-2 px-3">Groq</td><td class="py-2 px-3"><code class="text-purple-300">{{ __('docs.ai_url_example_groq') }}</code></td></tr>
                                <tr class="border-b border-gray-700"><td class="py-2 px-3">OpenAI</td><td class="py-2 px-3"><code class="text-purple-300">{{ __('docs.ai_url_example_openai') }}</code></td></tr>
                                <tr><td class="py-2 px-3">Google Gemini</td><td class="py-2 px-3"><code class="text-purple-300">{{ __('docs.ai_url_example_gemini') }}</code></td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                @include('docs._models')
            </div>
        </section>

        <!-- External Resources -->
        <section id="external-resources" class="mb-12 scroll-mt-8">
            <h2 class="text-2xl font-bold mb-6 flex items-center">
                <i class="fas fa-folder-open mr-3 text-purple-400"></i>{{ __('docs.external_resources.title') }}
            </h2>

            <div class="bg-gray-800 rounded-lg p-6 border border-gray-700 mb-4">
                <p class="text-gray-300 mb-4">{{ __('docs.external_resources.intro') }}</p>

                <div class="bg-blue-900/30 border border-blue-700/50 rounded p-3 text-sm mb-4">
                    <p><i class="fas fa-info-circle text-blue-400 mr-2"></i>{{ __('docs.external_resources.optional_note') }}</p>
                </div>

                <h3 id="external-resources-where" data-nav-anchor class="scroll-mt-8 font-semibold text-purple-300 mb-2">{{ __('docs.external_resources.where_title') }}</h3>
                <p class="text-gray-300 mb-3">{{ __('docs.external_resources.where_desc') }}</p>

                <x-docs.mod-folder :subfolders="['fonts/', 'images/']" class="mb-4" />

                <h3 id="external-resources-fonts" data-nav-anchor class="scroll-mt-8 font-semibold text-purple-300 mb-2">{{ __('docs.external_resources.fonts_title') }}</h3>
                <p class="text-gray-300 mb-2">{{ __('docs.external_resources.fonts_desc') }}</p>
                <ul class="list-disc list-inside text-gray-300 text-sm mb-4 space-y-1">
                    <li>{{ __('docs.external_resources.fonts_formats') }}</li>
                    <li>{{ __('docs.external_resources.fonts_naming') }}</li>
                    <li>{{ __('docs.external_resources.fonts_usage') }}</li>
                </ul>

                <h3 id="external-resources-images" data-nav-anchor class="scroll-mt-8 font-semibold text-purple-300 mb-2">{{ __('docs.external_resources.images_title') }}</h3>
                <p class="text-gray-300 mb-2">{{ __('docs.external_resources.images_desc') }}</p>
                <ul class="list-disc list-inside text-gray-300 text-sm mb-4 space-y-1">
                    <li>{{ __('docs.external_resources.images_formats') }}</li>
                    <li>{{ __('docs.external_resources.images_workflow') }}</li>
                </ul>

                <div class="bg-yellow-900/30 border border-yellow-700/50 rounded p-3 text-sm">
                    <p><i class="fas fa-exclamation-triangle text-yellow-400 mr-2"></i>{{ __('docs.external_resources.disclaimer') }}</p>
                </div>
            </div>
        </section>

        <!-- Troubleshooting -->
        <section id="troubleshooting" class="mb-12 scroll-mt-8">
            <h2 class="text-2xl font-bold mb-6 flex items-center">
                <i class="fas fa-question-circle mr-3 text-purple-400"></i>{{ __('docs.troubleshooting') }}
            </h2>

            <div class="bg-gray-800 rounded-lg p-6 border border-gray-700">
                <div class="space-y-6">
                    <div>
                        <h3 id="mod-not-loading" data-nav-anchor class="scroll-mt-8 font-semibold text-yellow-400 mb-2">
                            <i class="fas fa-exclamation-triangle mr-2"></i>{{ __('docs.mod_not_loading') }}
                        </h3>
                        <p class="text-gray-300 text-sm mb-2">{{ __('docs.mod_not_loading_desc') }}</p>
                        <ul class="text-sm text-gray-400 list-disc list-inside">
                            <li>{{ __('docs.mod_not_loading_tip1') }}</li>
                            <li>{{ __('docs.mod_not_loading_tip2') }}</li>
                            <li>{{ __('docs.mod_not_loading_tip3') }}</li>
                        </ul>
                    </div>

                    <div>
                        <h3 id="ai-not-translating" data-nav-anchor class="scroll-mt-8 font-semibold text-yellow-400 mb-2">
                            <i class="fas fa-exclamation-triangle mr-2"></i>{{ __('docs.ai_not_translating') }}
                        </h3>
                        <p class="text-gray-300 text-sm mb-2">{{ __('docs.ai_not_translating_desc') }}</p>
                        <ul class="text-sm text-gray-400 list-disc list-inside">
                            <li>{{ __('docs.ai_tip1') }}</li>
                            <li>{{ __('docs.ai_tip2') }}</li>
                            <li>{{ __('docs.ai_tip3') }}</li>
                        </ul>
                        {{-- Troubleshooting sends people looking for a setting; saying where it
                             lives beats making them find the section by hand. --}}
                        <p class="text-sm mt-2">
                            <a href="#configuration" class="text-purple-400 hover:text-purple-300 underline underline-offset-2">
                                <i class="fas fa-arrow-turn-down mr-1 text-xs"></i>{{ __('docs.nav.configuration') }}
                            </a>
                        </p>
                    </div>

                    <div>
                        <h3 id="overlay-not-showing" data-nav-anchor class="scroll-mt-8 font-semibold text-yellow-400 mb-2">
                            <i class="fas fa-exclamation-triangle mr-2"></i>{{ __('docs.overlay_not_showing') }}
                        </h3>
                        <p class="text-gray-300 text-sm">{{ __('docs.overlay_not_showing_desc') }}</p>
                        <p class="text-sm mt-2">
                            <a href="#configuration" class="text-purple-400 hover:text-purple-300 underline underline-offset-2">
                                <i class="fas fa-arrow-turn-down mr-1 text-xs"></i>{{ __('docs.nav.configuration') }}
                            </a>
                        </p>
                    </div>

                    <div>
                        <h3 id="sync-not-working" data-nav-anchor class="scroll-mt-8 font-semibold text-yellow-400 mb-2">
                            <i class="fas fa-exclamation-triangle mr-2"></i>{{ __('docs.sync_not_working') }}
                        </h3>
                        <p class="text-gray-300 text-sm">{{ __('docs.sync_not_working_desc') }}</p>
                    </div>
                </div>

                <div class="mt-8 pt-6 border-t border-gray-700">
                    <p class="text-gray-400">
                        {{ __('docs.need_help') }}
                        <a href="https://github.com/djethino/UnityGameTranslator/issues" target="_blank" class="text-purple-400 hover:underline">
                            {{ __('docs.open_issue') }} <i class="fas fa-external-link-alt text-xs ml-1"></i>
                        </a>
                    </p>
                </div>
            </div>
        </section>

    </main>
</div>

@push('scripts')
<script nonce="{{ $cspNonce ?? '' }}">
    // Mobile sidebar toggle
    const menuBtn = document.getElementById('docs-menu-btn');
    const sidebar = document.getElementById('docs-sidebar');
    const overlay = document.getElementById('docs-overlay');

    menuBtn?.addEventListener('click', () => {
        sidebar.classList.toggle('open');
        overlay.classList.toggle('open');
    });

    overlay?.addEventListener('click', () => {
        sidebar.classList.remove('open');
        overlay.classList.remove('open');
    });

    // Active section tracking
    const sections = document.querySelectorAll('section[id]');
    const navItems = document.querySelectorAll('.docs-nav-item');

    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                navItems.forEach(item => item.classList.remove('active'));
                const activeItem = document.querySelector(`.docs-nav-item[href="#${entry.target.id}"]`);
                activeItem?.classList.add('active');
            }
        });
    }, { rootMargin: '-20% 0px -80% 0px' });

    sections.forEach(section => observer.observe(section));
</script>
@endpush
@endsection
