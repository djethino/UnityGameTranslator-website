@extends('layouts.app')

@section('title', 'Documentation - How to Translate Unity Games with AI')

@section('description', 'Learn how to use UnityGameTranslator to automatically translate any Unity game. Free local AI translation with Ollama. No internet or API costs required.')

@section('content')
<div class="max-w-4xl mx-auto">
    <h1 class="text-3xl font-bold mb-8"><i class="fas fa-book mr-3"></i>{{ __('docs.title') }}</h1>

    <!-- Quick Links -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8">
        <a href="https://github.com/djethino/UnityGameTranslator" target="_blank" class="bg-gray-800 rounded-lg p-4 border border-gray-700 hover:border-purple-500 transition flex items-center gap-3">
            <i class="fab fa-github text-2xl"></i>
            <div>
                <div class="font-semibold">{{ __('docs.github_repo') }}</div>
                <div class="text-sm text-gray-400">{{ __('docs.github_desc') }}</div>
            </div>
        </a>
        <a href="https://github.com/djethino/UnityGameTranslator/releases/latest" target="_blank" class="bg-purple-600 hover:bg-purple-700 rounded-lg p-4 transition flex items-center gap-3">
            <i class="fas fa-download text-2xl"></i>
            <div>
                <div class="font-semibold">{{ __('docs.download_latest') }}</div>
                <div class="text-sm text-purple-200">{{ __('docs.download_desc') }}</div>
            </div>
        </a>
        <a href="https://ollama.ai" target="_blank" class="bg-gray-800 rounded-lg p-4 border border-gray-700 hover:border-purple-500 transition flex items-center gap-3">
            <i class="fas fa-robot text-2xl"></i>
            <div>
                <div class="font-semibold">{{ __('docs.ollama') }}</div>
                <div class="text-sm text-gray-400">{{ __('docs.ollama_desc') }}</div>
            </div>
        </a>
    </div>

    <!-- What is it -->
    <section class="bg-gray-800 rounded-lg p-6 mb-6 border border-gray-700">
        <h2 class="text-xl font-bold mb-4"><i class="fas fa-info-circle mr-2 text-purple-400"></i>{{ __('docs.what_is') }}</h2>
        <p class="text-gray-300 mb-4">
            {{ __('docs.what_is_desc') }}
        </p>
        <ul class="space-y-2 text-gray-300">
            <li><i class="fas fa-check text-green-400 mr-2"></i>{{ __('docs.feature_runtime') }}</li>
            <li><i class="fas fa-check text-green-400 mr-2"></i>{{ __('docs.feature_local') }}</li>
            <li><i class="fas fa-check text-green-400 mr-2"></i>{{ __('docs.feature_cache') }}</li>
            <li><i class="fas fa-check text-green-400 mr-2"></i>{{ __('docs.feature_share') }}</li>
        </ul>
    </section>

    <!-- Installation -->
    <section class="bg-gray-800 rounded-lg p-6 mb-6 border border-gray-700">
        <h2 class="text-xl font-bold mb-4"><i class="fas fa-download mr-2 text-purple-400"></i>{{ __('docs.installation') }}</h2>

        <h3 class="font-semibold mb-3 text-lg">1. {{ __('docs.install_modloader') }}</h3>
        <div class="overflow-x-auto mb-6">
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
                        <td class="px-4 py-2">BepInEx 5</td>
                        <td class="px-4 py-2">Mono</td>
                        <td class="px-4 py-2"><a href="https://github.com/BepInEx/BepInEx/releases" target="_blank" class="text-purple-400 hover:underline">GitHub</a></td>
                    </tr>
                    <tr class="border-t border-gray-700">
                        <td class="px-4 py-2">BepInEx 6</td>
                        <td class="px-4 py-2">Mono / IL2CPP</td>
                        <td class="px-4 py-2"><a href="https://builds.bepinex.dev/projects/bepinex_be" target="_blank" class="text-purple-400 hover:underline">Bleeding Edge</a></td>
                    </tr>
                    <tr class="border-t border-gray-700">
                        <td class="px-4 py-2">MelonLoader</td>
                        <td class="px-4 py-2">Mono / IL2CPP</td>
                        <td class="px-4 py-2"><a href="https://github.com/LavaGang/MelonLoader/releases" target="_blank" class="text-purple-400 hover:underline">GitHub</a></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="bg-gray-700 rounded p-4 mb-6">
            <p class="text-sm text-gray-300">
                <strong>{{ __('docs.how_to_know') }}</strong><br>
                <code class="bg-gray-800 px-1 rounded">GameAssembly.dll</code> = <strong>IL2CPP</strong><br>
                <code class="bg-gray-800 px-1 rounded">&lt;Game&gt;_Data/Managed/Assembly-CSharp.dll</code> = <strong>Mono</strong>
            </p>
        </div>

        <h3 class="font-semibold mb-3 text-lg">2. {{ __('docs.download_ugt') }}</h3>
        <p class="text-gray-300 mb-4">
            <a href="https://github.com/djethino/UnityGameTranslator/releases/latest" target="_blank" class="text-purple-400 hover:underline">{{ __('docs.download_ugt_desc') }}</a>
        </p>
        <ul class="text-gray-300 mb-4 space-y-1">
            <li><strong>BepInEx:</strong> <code class="bg-gray-700 px-2 py-1 rounded text-sm">&lt;Game&gt;/BepInEx/plugins/UnityGameTranslator/</code></li>
            <li><strong>MelonLoader:</strong> <code class="bg-gray-700 px-2 py-1 rounded text-sm">&lt;Game&gt;/Mods/</code></li>
        </ul>

        <h3 class="font-semibold mb-3 text-lg">3. {{ __('docs.enable_ai') }}</h3>
        <p class="text-gray-300 mb-3">{{ __('docs.enable_ai_desc') }}</p>
        <ol class="text-gray-300 space-y-2 list-decimal list-inside">
            <li>{{ __('docs.install_ollama') }}: <a href="https://ollama.ai" target="_blank" class="text-purple-400 hover:underline">ollama.ai</a></li>
            <li>{{ __('docs.download_model') }} <code class="bg-gray-700 px-2 py-1 rounded text-sm">ollama pull qwen3:8b</code></li>
            <li>{{ __('docs.edit_config') }} <code class="bg-gray-700 px-1 rounded">"enable_ollama": true</code></li>
        </ol>
    </section>

    <!-- Configuration -->
    <section class="bg-gray-800 rounded-lg p-6 mb-6 border border-gray-700">
        <h2 class="text-xl font-bold mb-4"><i class="fas fa-cog mr-2 text-purple-400"></i>{{ __('docs.configuration') }}</h2>
        <p class="text-gray-300 mb-4">{{ __('docs.config_desc') }}</p>

        <pre class="bg-gray-900 rounded p-4 overflow-x-auto text-sm mb-4"><code class="text-gray-300">{
  "ollama_url": "http://localhost:11434",
  "model": "qwen3:8b",
  "target_language": "auto",
  "source_language": "auto",
  "game_context": "",
  "enable_ollama": false
}</code></pre>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-700">
                    <tr>
                        <th class="px-4 py-2 text-left">{{ __('docs.option') }}</th>
                        <th class="px-4 py-2 text-left">{{ __('docs.description') }}</th>
                    </tr>
                </thead>
                <tbody class="text-gray-300">
                    <tr class="border-t border-gray-700">
                        <td class="px-4 py-2"><code>target_language</code></td>
                        <td class="px-4 py-2">Target language (<code>"auto"</code> = system language)</td>
                    </tr>
                    <tr class="border-t border-gray-700">
                        <td class="px-4 py-2"><code>source_language</code></td>
                        <td class="px-4 py-2">Source language (<code>"auto"</code> = AI detect)</td>
                    </tr>
                    <tr class="border-t border-gray-700">
                        <td class="px-4 py-2"><code>game_context</code></td>
                        <td class="px-4 py-2">Game description (e.g., <code>"Medieval fantasy RPG"</code>)</td>
                    </tr>
                    <tr class="border-t border-gray-700">
                        <td class="px-4 py-2"><code>enable_ollama</code></td>
                        <td class="px-4 py-2"><code>true</code> = enable live AI translation</td>
                    </tr>
                    <tr class="border-t border-gray-700">
                        <td class="px-4 py-2"><code>model</code></td>
                        <td class="px-4 py-2">Ollama model (<code>qwen3:8b</code> recommended, ~6-8 GB VRAM)</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>

    <!-- Sharing -->
    <section class="bg-gray-800 rounded-lg p-6 mb-6 border border-gray-700">
        <h2 class="text-xl font-bold mb-4"><i class="fas fa-share-alt mr-2 text-purple-400"></i>{{ __('docs.sharing') }}</h2>

        <p class="text-gray-300 mb-4">
            {{ __('docs.sharing_desc') }}
        </p>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="bg-gray-700 rounded p-4">
                <h3 class="font-semibold mb-2"><i class="fas fa-upload text-green-400 mr-2"></i>{{ __('docs.upload') }}</h3>
                <p class="text-sm text-gray-300 mb-3">{{ __('docs.upload_desc') }}</p>
                @auth
                    <a href="{{ route('translations.create') }}" class="inline-block bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded text-sm">
                        {{ __('nav.upload') }}
                    </a>
                @else
                    <a href="{{ route('login') }}" class="inline-block bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded text-sm">
                        {{ __('docs.login_to_upload') }}
                    </a>
                @endauth
            </div>
            <div class="bg-gray-700 rounded p-4">
                <h3 class="font-semibold mb-2"><i class="fas fa-download text-blue-400 mr-2"></i>{{ __('docs.download') }}</h3>
                <p class="text-sm text-gray-300 mb-3">{{ __('docs.download_browse_desc') }}</p>
                <a href="{{ route('games.index') }}" class="inline-block bg-gray-600 hover:bg-gray-500 text-white px-4 py-2 rounded text-sm">
                    {{ __('docs.browse_games') }}
                </a>
            </div>
        </div>

        <div class="mt-4 p-4 bg-gray-900 rounded">
            <p class="text-sm text-gray-400">
                <i class="fas fa-info-circle mr-1"></i>
                {{ __('docs.uuid_info') }}
            </p>
        </div>
    </section>

    <!-- Troubleshooting -->
    <section class="bg-gray-800 rounded-lg p-6 border border-gray-700">
        <h2 class="text-xl font-bold mb-4"><i class="fas fa-question-circle mr-2 text-purple-400"></i>{{ __('docs.troubleshooting') }}</h2>

        <div class="space-y-4">
            <div>
                <h3 class="font-semibold text-yellow-400">{{ __('docs.mod_not_loading') }}</h3>
                <p class="text-gray-300 text-sm">{{ __('docs.mod_not_loading_desc') }}</p>
            </div>
            <div>
                <h3 class="font-semibold text-yellow-400">{{ __('docs.ollama_not_translating') }}</h3>
                <p class="text-gray-300 text-sm">{{ __('docs.ollama_not_translating_desc') }} (<code class="bg-gray-700 px-1 rounded">ollama serve</code>, <code class="bg-gray-700 px-1 rounded">ollama pull qwen3:8b</code>)</p>
            </div>
            <div>
                <h3 class="font-semibold text-yellow-400">{{ __('docs.queue_stuck') }}</h3>
                <p class="text-gray-300 text-sm">{{ __('docs.queue_stuck_desc') }}</p>
            </div>
        </div>

        <div class="mt-6 pt-4 border-t border-gray-700">
            <p class="text-gray-400 text-sm">
                {{ __('docs.need_help') }} <a href="https://github.com/djethino/UnityGameTranslator/issues" target="_blank" class="text-purple-400 hover:underline">{{ __('docs.open_issue') }}</a>
            </p>
        </div>
    </section>
</div>
@endsection
