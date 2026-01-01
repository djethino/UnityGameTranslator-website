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
    /* Image styles */
    .doc-img {
        border: 1px solid #374151;
        border-radius: 0.5rem;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.3);
        cursor: pointer;
        transition: transform 0.2s, box-shadow 0.2s;
    }
    .doc-img:hover {
        transform: scale(1.02);
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.4);
    }
    .doc-img-mod {
        max-width: 500px;
        margin: 0 auto;
        display: block;
    }
    .doc-img-web {
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
    .callout-danger {
        background: rgba(239, 68, 68, 0.1);
        border-color: #ef4444;
    }
    /* Lightbox */
    .lightbox {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, 0.9);
        z-index: 9999;
        cursor: pointer;
        padding: 2rem;
    }
    .lightbox.active {
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .lightbox img {
        max-width: 95%;
        max-height: 95%;
        object-fit: contain;
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
    <aside id="docs-sidebar" class="docs-sidebar w-64 flex-shrink-0 hidden lg:block">
        <nav class="space-y-1">
            <a href="#quick-start" class="docs-nav-item block px-4 py-2 text-sm text-gray-300 rounded-r">
                <i class="fas fa-rocket mr-2 w-4"></i>{{ __('docs.nav.quick_start') }}
            </a>
            <a href="#installation" class="docs-nav-item block px-4 py-2 text-sm text-gray-300 rounded-r">
                <i class="fas fa-download mr-2 w-4"></i>{{ __('docs.nav.installation') }}
            </a>
            <a href="#first-launch" class="docs-nav-item block px-4 py-2 text-sm text-gray-300 rounded-r">
                <i class="fas fa-play mr-2 w-4"></i>{{ __('docs.nav.first_launch') }}
            </a>
            <a href="#quality-system" class="docs-nav-item block px-4 py-2 text-sm text-gray-300 rounded-r">
                <i class="fas fa-star mr-2 w-4"></i>{{ __('docs.nav.quality_system') }}
            </a>
            <a href="#collaboration" class="docs-nav-item block px-4 py-2 text-sm text-gray-300 rounded-r">
                <i class="fas fa-users mr-2 w-4"></i>{{ __('docs.nav.collaboration') }}
            </a>
            <a href="#sync" class="docs-nav-item block px-4 py-2 text-sm text-gray-300 rounded-r">
                <i class="fas fa-sync mr-2 w-4"></i>{{ __('docs.nav.sync') }}
            </a>
            <a href="#configuration" class="docs-nav-item block px-4 py-2 text-sm text-gray-300 rounded-r">
                <i class="fas fa-cog mr-2 w-4"></i>{{ __('docs.nav.configuration') }}
            </a>
            <a href="#troubleshooting" class="docs-nav-item block px-4 py-2 text-sm text-gray-300 rounded-r">
                <i class="fas fa-question-circle mr-2 w-4"></i>{{ __('docs.nav.troubleshooting') }}
            </a>
        </nav>

        <!-- Quick Links in sidebar -->
        <div class="mt-8 pt-4 border-t border-gray-700 space-y-2">
            <a href="https://github.com/djethino/UnityGameTranslator/releases/latest" target="_blank"
               class="flex items-center gap-2 px-4 py-2 bg-purple-600 hover:bg-purple-700 rounded text-sm text-white">
                <i class="fas fa-download"></i>
                {{ __('docs.download_mod') }}
            </a>
            <a href="https://github.com/djethino/UnityGameTranslator" target="_blank"
               class="flex items-center gap-2 px-4 py-2 bg-gray-700 hover:bg-gray-600 rounded text-sm text-gray-300">
                <i class="fab fa-github"></i>
                GitHub
            </a>
        </div>
    </aside>

    <!-- Main content -->
    <main class="flex-1 min-w-0 max-w-4xl">
        <h1 class="text-3xl font-bold mb-2">
            <i class="fas fa-book mr-3 text-purple-400"></i>{{ __('docs.title') }}
        </h1>
        <p class="text-gray-400 mb-8">{{ __('docs.subtitle') }}</p>

        <!-- Quick Start -->
        <section id="quick-start" class="mb-12 scroll-mt-8">
            <h2 class="text-2xl font-bold mb-6 flex items-center">
                <i class="fas fa-rocket mr-3 text-purple-400"></i>{{ __('docs.quick_start.title') }}
            </h2>

            <div class="bg-gray-800 rounded-lg p-6 border border-gray-700">
                <p class="text-gray-300 mb-6">{{ __('docs.quick_start.intro') }}</p>

                <div class="grid md:grid-cols-3 gap-4 mb-6">
                    <div class="bg-gray-700 rounded-lg p-4 text-center">
                        <div class="text-3xl font-bold text-purple-400 mb-2">1</div>
                        <div class="text-sm text-gray-300">{{ __('docs.quick_start.step1') }}</div>
                    </div>
                    <div class="bg-gray-700 rounded-lg p-4 text-center">
                        <div class="text-3xl font-bold text-purple-400 mb-2">2</div>
                        <div class="text-sm text-gray-300">{{ __('docs.quick_start.step2') }}</div>
                    </div>
                    <div class="bg-gray-700 rounded-lg p-4 text-center">
                        <div class="text-3xl font-bold text-purple-400 mb-2">3</div>
                        <div class="text-sm text-gray-300">{{ __('docs.quick_start.step3') }}</div>
                    </div>
                </div>

                <div class="callout callout-tip">
                    <p class="text-sm text-gray-300">
                        <i class="fas fa-lightbulb text-blue-400 mr-2"></i>
                        <strong>{{ __('docs.quick_start.tip_title') }}</strong><br>
                        {{ __('docs.quick_start.tip_content') }}
                    </p>
                </div>
            </div>
        </section>

        <!-- Installation -->
        <section id="installation" class="mb-12 scroll-mt-8">
            <h2 class="text-2xl font-bold mb-6 flex items-center">
                <i class="fas fa-download mr-3 text-purple-400"></i>{{ __('docs.installation') }}
            </h2>

            <!-- Step 1: Mod Loader -->
            <div class="bg-gray-800 rounded-lg p-6 border border-gray-700 mb-4">
                <h3 class="text-lg font-semibold mb-4">
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
                <h3 class="text-lg font-semibold mb-4">
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

                <div class="space-y-2 text-gray-300">
                    <p><strong>BepInEx:</strong> <code class="bg-gray-700 px-2 py-1 rounded text-sm">&lt;Game&gt;/BepInEx/plugins/UnityGameTranslator/</code></p>
                    <p><strong>MelonLoader:</strong> <code class="bg-gray-700 px-2 py-1 rounded text-sm">&lt;Game&gt;/Mods/UnityGameTranslator/</code></p>
                </div>
            </div>

            <!-- Step 3: Ollama (Optional) -->
            <div class="bg-gray-800 rounded-lg p-6 border border-gray-700">
                <h3 class="text-lg font-semibold mb-4">
                    <span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-purple-600 text-white text-sm mr-2">3</span>
                    {{ __('docs.enable_ai') }}
                    <span class="ml-2 bg-gray-600 text-gray-300 px-2 py-0.5 rounded text-xs">{{ __('docs.optional') }}</span>
                </h3>

                <p class="text-gray-300 mb-4">{{ __('docs.enable_ai_desc') }}</p>

                <ol class="text-gray-300 space-y-3 list-decimal list-inside mb-4">
                    <li>{{ __('docs.install_ollama') }}: <a href="https://ollama.ai" target="_blank" class="text-purple-400 hover:underline">ollama.ai</a></li>
                    <li>{{ __('docs.download_model') }}: <code class="bg-gray-700 px-2 py-1 rounded text-sm">ollama pull qwen3:8b</code></li>
                    <li>{{ __('docs.enable_in_wizard') }}</li>
                </ol>

                <div class="callout callout-tip">
                    <p class="text-sm text-gray-300">
                        <i class="fas fa-lightbulb text-blue-400 mr-2"></i>
                        <strong>{{ __('docs.ollama_tip_title') }}</strong><br>
                        {{ __('docs.ollama_tip_content') }}
                    </p>
                </div>
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
                <figure class="mb-6 flex flex-col items-center">
                    <img src="{{ asset('images/screenshots/ModWizard1.png') }}"
                         alt="{{ __('docs.wizard_screenshot_alt') }}"
                         class="doc-img doc-img-mod mx-auto"
                         onclick="openLightbox(this.src)">
                    <figcaption class="text-sm text-gray-400 mt-2">{{ __('docs.wizard_caption') }}</figcaption>
                </figure>

                <h3 class="font-semibold mb-3 text-lg">{{ __('docs.wizard_steps_title') }}</h3>
                <ol class="text-gray-300 space-y-3 list-decimal list-inside">
                    <li><strong>{{ __('docs.wizard_step1_title') }}</strong> - {{ __('docs.wizard_step1_desc') }}</li>
                    <li><strong>{{ __('docs.wizard_step2_title') }}</strong> - {{ __('docs.wizard_step2_desc') }}</li>
                    <li><strong>{{ __('docs.wizard_step3_title') }}</strong> - {{ __('docs.wizard_step3_desc') }}</li>
                    <li><strong>{{ __('docs.wizard_step4_title') }}</strong> - {{ __('docs.wizard_step4_desc') }}</li>
                </ol>

                <div class="callout callout-tip mt-4">
                    <p class="text-sm text-gray-300">
                        <i class="fas fa-keyboard text-blue-400 mr-2"></i>
                        <strong>{{ __('docs.hotkey_tip_title') }}</strong><br>
                        {{ __('docs.hotkey_tip_content') }}
                    </p>
                </div>
            </div>
        </section>

        <!-- Quality System -->
        <section id="quality-system" class="mb-12 scroll-mt-8">
            <h2 class="text-2xl font-bold mb-6 flex items-center">
                <i class="fas fa-star mr-3 text-purple-400"></i>{{ __('docs.quality_system.title') }}
            </h2>

            <div class="bg-gray-800 rounded-lg p-6 border border-gray-700">
                <p class="text-gray-300 mb-6">{{ __('docs.quality_system.intro') }}</p>

                <!-- HVAS Badges -->
                <div class="grid md:grid-cols-4 gap-4 mb-6">
                    <div class="bg-gray-700 rounded-lg p-4 text-center border-t-4 border-green-500">
                        <span class="inline-block px-3 py-1 rounded text-lg font-bold bg-green-600 text-white mb-2">H</span>
                        <div class="font-semibold text-white">{{ __('docs.quality_system.human') }}</div>
                        <div class="text-sm text-gray-400">{{ __('docs.quality_system.human_desc') }}</div>
                        <div class="text-xs text-green-400 mt-2">3 {{ __('docs.quality_system.points') }}</div>
                    </div>
                    <div class="bg-gray-700 rounded-lg p-4 text-center border-t-4 border-blue-500">
                        <span class="inline-block px-3 py-1 rounded text-lg font-bold bg-blue-600 text-white mb-2">V</span>
                        <div class="font-semibold text-white">{{ __('docs.quality_system.validated') }}</div>
                        <div class="text-sm text-gray-400">{{ __('docs.quality_system.validated_desc') }}</div>
                        <div class="text-xs text-blue-400 mt-2">2 {{ __('docs.quality_system.points') }}</div>
                    </div>
                    <div class="bg-gray-700 rounded-lg p-4 text-center border-t-4 border-orange-500">
                        <span class="inline-block px-3 py-1 rounded text-lg font-bold bg-orange-600 text-white mb-2">A</span>
                        <div class="font-semibold text-white">{{ __('docs.quality_system.ai') }}</div>
                        <div class="text-sm text-gray-400">{{ __('docs.quality_system.ai_desc') }}</div>
                        <div class="text-xs text-orange-400 mt-2">1 {{ __('docs.quality_system.point') }}</div>
                    </div>
                    <div class="bg-gray-700 rounded-lg p-4 text-center border-t-4 border-gray-500">
                        <span class="inline-block px-3 py-1 rounded text-lg font-bold bg-gray-600 text-white mb-2">S</span>
                        <div class="font-semibold text-white">{{ __('docs.quality_system.skip') }}</div>
                        <div class="text-sm text-gray-400">{{ __('docs.quality_system.skip_desc') }}</div>
                        <div class="text-xs text-gray-400 mt-2">{{ __('docs.quality_system.not_counted') }}</div>
                    </div>
                </div>

                <!-- Quality Score -->
                <h3 class="font-semibold mb-3 text-lg">{{ __('docs.quality_system.score_title') }}</h3>
                <p class="text-gray-300 mb-4">{{ __('docs.quality_system.score_formula') }}</p>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-700">
                            <tr>
                                <th class="px-4 py-2 text-left">{{ __('docs.quality_system.score') }}</th>
                                <th class="px-4 py-2 text-left">{{ __('docs.quality_system.label') }}</th>
                            </tr>
                        </thead>
                        <tbody class="text-gray-300">
                            <tr class="border-t border-gray-700">
                                <td class="px-4 py-2">2.5+</td>
                                <td class="px-4 py-2"><span class="text-green-400">{{ __('docs.quality_system.excellent') }}</span></td>
                            </tr>
                            <tr class="border-t border-gray-700">
                                <td class="px-4 py-2">2.0+</td>
                                <td class="px-4 py-2"><span class="text-blue-400">{{ __('docs.quality_system.good') }}</span></td>
                            </tr>
                            <tr class="border-t border-gray-700">
                                <td class="px-4 py-2">1.5+</td>
                                <td class="px-4 py-2"><span class="text-yellow-400">{{ __('docs.quality_system.fair') }}</span></td>
                            </tr>
                            <tr class="border-t border-gray-700">
                                <td class="px-4 py-2">1.0+</td>
                                <td class="px-4 py-2"><span class="text-orange-400">{{ __('docs.quality_system.basic') }}</span></td>
                            </tr>
                            <tr class="border-t border-gray-700">
                                <td class="px-4 py-2">&lt;1.0</td>
                                <td class="px-4 py-2"><span class="text-red-400">{{ __('docs.quality_system.raw_ai') }}</span></td>
                            </tr>
                        </tbody>
                    </table>
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

        <!-- Collaboration -->
        <section id="collaboration" class="mb-12 scroll-mt-8">
            <h2 class="text-2xl font-bold mb-6 flex items-center">
                <i class="fas fa-users mr-3 text-purple-400"></i>{{ __('docs.collaboration.title') }}
            </h2>

            <div class="bg-gray-800 rounded-lg p-6 border border-gray-700 mb-4">
                <h3 class="font-semibold mb-4 text-lg">{{ __('docs.collaboration.model_title') }}</h3>
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
                </ol>
            </div>

            <!-- Upload -->
            <div class="bg-gray-800 rounded-lg p-6 border border-gray-700 mb-4">
                <h3 class="font-semibold mb-4 text-lg">
                    <i class="fas fa-upload text-green-400 mr-2"></i>{{ __('docs.collaboration.upload_title') }}
                </h3>
                <p class="text-gray-300 mb-4">{{ __('docs.collaboration.upload_intro') }}</p>

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
                <h3 class="font-semibold mb-4 text-lg">
                    <i class="fas fa-code-merge text-purple-400 mr-2"></i>{{ __('docs.collaboration.merge_title') }}
                </h3>
                <p class="text-gray-300 mb-4">{{ __('docs.collaboration.merge_intro') }}</p>

                <!-- Merge Screenshot -->
                <figure class="mb-4">
                    <img src="{{ asset('images/screenshots/WebHumanEditAndValidation.png') }}"
                         alt="{{ __('docs.merge_screenshot_alt') }}"
                         class="doc-img doc-img-web"
                         onclick="openLightbox(this.src)">
                    <figcaption class="text-center text-sm text-gray-400 mt-2">{{ __('docs.merge_caption') }}</figcaption>
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
                <h3 class="font-semibold mb-4 text-lg">{{ __('docs.sync.online_mode_title') }}</h3>
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
                <h3 class="font-semibold mb-4 text-lg">
                    <i class="fas fa-link text-blue-400 mr-2"></i>{{ __('docs.sync.device_flow_title') }}
                </h3>
                <p class="text-gray-300 mb-4">{{ __('docs.sync.device_flow_desc') }}</p>

                <!-- Screenshots side by side -->
                <div class="grid md:grid-cols-2 gap-4 mb-6">
                    <figure class="text-center">
                        <img src="{{ asset('images/screenshots/ModConnect.png') }}"
                             alt="{{ __('docs.sync.mod_connect_alt') }}"
                             class="doc-img doc-img-mod mx-auto"
                             onclick="openLightbox(this.src)">
                        <figcaption class="text-sm text-gray-400 mt-2">{{ __('docs.sync.mod_connect_caption') }}</figcaption>
                    </figure>
                    <figure class="text-center">
                        <img src="{{ asset('images/screenshots/WebConnect.png') }}"
                             alt="{{ __('docs.sync.web_connect_alt') }}"
                             class="doc-img doc-img-web"
                             onclick="openLightbox(this.src)">
                        <figcaption class="text-sm text-gray-400 mt-2">{{ __('docs.sync.web_connect_caption') }}</figcaption>
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
                <h3 class="font-semibold mb-4 text-lg">
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

        <!-- Configuration -->
        <section id="configuration" class="mb-12 scroll-mt-8">
            <h2 class="text-2xl font-bold mb-6 flex items-center">
                <i class="fas fa-cog mr-3 text-purple-400"></i>{{ __('docs.configuration') }}
            </h2>

            <div class="bg-gray-800 rounded-lg p-6 border border-gray-700">
                <p class="text-gray-300 mb-4">{{ __('docs.config_location') }}</p>

                <pre class="bg-gray-900 rounded p-4 overflow-x-auto text-sm mb-6"><code class="text-gray-300">{
  "ollama_url": "http://localhost:11434",
  "model": "qwen3:8b",
  "target_language": "auto",
  "enable_ollama": false,
  "settings_hotkey": "Ctrl+F10",
  "online_mode": true,
  "sync": {
    "check_update_on_start": true,
    "auto_download": false,
    "notify_updates": true
  }
}</code></pre>

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
                                <td class="px-4 py-2"><code class="text-purple-300">enable_ollama</code></td>
                                <td class="px-4 py-2"><code>false</code></td>
                                <td class="px-4 py-2">{{ __('docs.config_enable_ollama') }}</td>
                            </tr>
                            <tr class="border-t border-gray-700">
                                <td class="px-4 py-2"><code class="text-purple-300">model</code></td>
                                <td class="px-4 py-2"><code>"qwen3:8b"</code></td>
                                <td class="px-4 py-2">{{ __('docs.config_model') }}</td>
                            </tr>
                            <tr class="border-t border-gray-700">
                                <td class="px-4 py-2"><code class="text-purple-300">settings_hotkey</code></td>
                                <td class="px-4 py-2"><code>"Ctrl+F10"</code></td>
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
                        </tbody>
                    </table>
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
                        <h3 class="font-semibold text-yellow-400 mb-2">
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
                        <h3 class="font-semibold text-yellow-400 mb-2">
                            <i class="fas fa-exclamation-triangle mr-2"></i>{{ __('docs.ollama_not_translating') }}
                        </h3>
                        <p class="text-gray-300 text-sm mb-2">{{ __('docs.ollama_not_translating_desc') }}</p>
                        <ul class="text-sm text-gray-400 list-disc list-inside">
                            <li>{{ __('docs.ollama_tip1') }}: <code class="bg-gray-700 px-1 rounded">ollama serve</code></li>
                            <li>{{ __('docs.ollama_tip2') }}: <code class="bg-gray-700 px-1 rounded">ollama pull qwen3:8b</code></li>
                            <li>{{ __('docs.ollama_tip3') }}</li>
                        </ul>
                    </div>

                    <div>
                        <h3 class="font-semibold text-yellow-400 mb-2">
                            <i class="fas fa-exclamation-triangle mr-2"></i>{{ __('docs.overlay_not_showing') }}
                        </h3>
                        <p class="text-gray-300 text-sm">{{ __('docs.overlay_not_showing_desc') }}</p>
                    </div>

                    <div>
                        <h3 class="font-semibold text-yellow-400 mb-2">
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

<!-- Lightbox -->
<div id="lightbox" class="lightbox" onclick="closeLightbox()">
    <img id="lightbox-img" src="" alt="">
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

    // Lightbox
    function openLightbox(src) {
        document.getElementById('lightbox-img').src = src;
        document.getElementById('lightbox').classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function closeLightbox() {
        document.getElementById('lightbox').classList.remove('active');
        document.body.style.overflow = '';
    }

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') closeLightbox();
    });
</script>
@endpush
@endsection
