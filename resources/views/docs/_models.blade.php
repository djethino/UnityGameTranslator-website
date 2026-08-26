{{-- The local models, built from the shared catalogue.

     ⚠ NOTHING FACTUAL IS TRANSLATED HERE. Every name, figure, source and date comes from
     catalogs/models.json; the translated strings are labels that do not change when the catalogue
     does. The paragraph this replaced carried the model's name, its memory use and its language
     count inside nineteen translated sentences — so correcting a figure meant nineteen edits and a
     translator for each, and it had already drifted into recommending a `:latest` tag the
     catalogue forbids.

     ⚠ The measurement note is not decoration. One machine, one card, one pair of languages: print
     the figures without it and a set of observations reads as a verdict. The order leads with the
     score — see ModelCatalog::installable — which makes the note load-bearing, not a footnote. --}}

@php
    $reference = \App\Services\ModelCatalog::reference();
    $models = \App\Services\ModelCatalog::installable();
    $context = \App\Services\ModelCatalog::measurementContext();
    $smallest = collect($models)->min('min_vram_gb');
@endphp

@if ($reference)
    <div class="callout callout-tip">
        <p class="text-sm text-gray-300">
            <i class="fas fa-lightbulb text-blue-400 mr-2"></i>
            <strong>{{ __('docs.ai_tip_title') }}</strong>
        </p>

        <p class="mt-2">
            <code class="text-purple-300 text-base">{{ $reference['pull'] }}</code>
        </p>

        {{-- Labelled facts rather than a sentence: a model with no published language count simply
             shows one fewer line, where a sentence would have needed a variant to translate. --}}
        <dl class="mt-3 grid grid-cols-1 sm:grid-cols-3 gap-x-6 gap-y-2 text-sm">
            @if (isset($reference['measured']['vram_gb']) || isset($reference['min_vram_gb']))
                <div>
                    <dt class="text-gray-500">{{ __('docs.models.video_memory') }}</dt>
                    <dd class="text-gray-300">
                        @isset($reference['measured']['vram_gb'])
                            {{ __('docs.models.gigabytes', ['gb' => $reference['measured']['vram_gb']]) }}
                        @endisset
                        @isset($reference['min_vram_gb'])
                            <span class="text-gray-500">
                                ({{ __('docs.models.minimum', ['gb' => $reference['min_vram_gb']]) }})
                            </span>
                        @endisset
                    </dd>
                </div>
            @endif

            @isset($reference['download_gb'])
                <div>
                    <dt class="text-gray-500">{{ __('docs.models.download') }}</dt>
                    <dd class="text-gray-300">{{ __('docs.models.gigabytes', ['gb' => $reference['download_gb']]) }}</dd>
                </div>
            @endisset

            @if ($claimed = \App\Services\ModelCatalog::claimedLanguages($reference))
                <div>
                    <dt class="text-gray-500">{{ __('docs.models.languages_claimed') }}</dt>
                    <dd class="text-gray-300">
                        @if ($source = \App\Services\ModelCatalog::languageSource($reference))
                            <a href="{{ $source }}" target="_blank" rel="noopener nofollow"
                               class="text-purple-300 hover:text-purple-200 underline">{{ $claimed }}</a>
                        @else
                            {{ $claimed }}
                        @endif
                        <span class="text-gray-500">{{ __('docs.models.publisher_claim') }}</span>
                    </dd>
                </div>
            @endif
        </dl>

        {{-- ⚠ A tighter card is NOT a reason to send somebody to a paid service, and the wording
             this replaced did exactly that. Several models in the list below run on 4 GB; the
             honest first answer is to try one, and the tester in the Manager settles it on the
             reader's own machine, in the reader's own language, against the very instructions the
             mod sends. An external API stays available to whoever wants one — it is their call,
             and all we owe them is a word about what it can cost. --}}
        {{-- ⚠ INTERNAL since the Manager has a section of its own. It used to point straight at the
             GitHub release, which sent somebody deciding between models out of the page and onto a
             download button, past everything that explains what the tester does and what its
             figures are worth. The download lives in that section, one paragraph in. --}}
        <p class="text-sm text-gray-400 mt-3">
            {{ __('docs.models.try_smaller') }}
            <a href="#install-manager"
               class="text-purple-300 hover:text-purple-200 underline">{{ __('docs.models.get_manager') }}</a>
        </p>

        <p class="text-sm text-gray-400 mt-2">{{ __('docs.models.external_apis') }}</p>
    </div>
@endif

@if (count($models) > 1)
    {{-- Native <details>: no JavaScript, works before Alpine loads and with it blocked, and it is
         what a keyboard and a screen reader already know how to operate. --}}
    <details class="mt-4 bg-gray-800/50 border border-gray-700 rounded-lg">
        <summary class="cursor-pointer select-none px-4 py-3 text-sm text-gray-300 hover:text-white">
            <i class="fas fa-chevron-right mr-2 text-purple-400 text-xs"></i>
            {{ __('docs.models.expand', ['count' => count($models), 'gb' => $smallest]) }}
        </summary>

        <div class="px-4 pb-4">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-400 border-b border-gray-700">
                            <th class="py-2 pr-3 font-medium">{{ __('docs.models.model') }}</th>
                            <th class="py-2 px-3 font-medium">{{ __('docs.models.video_memory') }}</th>
                            <th class="py-2 px-3 font-medium">{{ __('docs.models.download') }}</th>
                            <th class="py-2 px-3 font-medium">{{ __('docs.models.instructions') }}</th>
                            <th class="py-2 pl-3 font-medium">{{ __('docs.models.languages_claimed') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($models as $model)
                            <tr class="border-b border-gray-700/50 last:border-0">
                                <td class="py-2 pr-3">
                                    <code class="text-purple-300">{{ $model['pull'] }}</code>
                                </td>
                                <td class="py-2 px-3 text-gray-300">
                                    @isset($model['min_vram_gb'])
                                        {{ __('docs.models.minimum', ['gb' => $model['min_vram_gb']]) }}
                                    @else
                                        <span class="text-gray-600">—</span>
                                    @endisset
                                </td>
                                <td class="py-2 px-3 text-gray-300">
                                    @isset($model['download_gb'])
                                        {{ __('docs.models.gigabytes', ['gb' => $model['download_gb']]) }}
                                    @else
                                        <span class="text-gray-600">—</span>
                                    @endisset
                                </td>
                                <td class="py-2 px-3 text-gray-300">
                                    @if (isset($model['measured']['suite'], $model['measured']['suite_of']))
                                        {{ $model['measured']['suite'] }}/{{ $model['measured']['suite_of'] }}
                                    @else
                                        <span class="text-gray-600">—</span>
                                    @endif
                                </td>
                                <td class="py-2 pl-3 text-gray-300">
                                    {{ \App\Services\ModelCatalog::claimedLanguages($model) ?? '—' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($context['card'] && $context['language'])
                <p class="text-xs text-gray-500 mt-3">
                    {{ __('docs.models.measured_note', [
                        'card' => $context['card'],
                        'language' => $context['language'],
                        'date' => $context['updated'] ?? '—',
                    ]) }}
                </p>
            @endif
        </div>
    </details>
@endif
