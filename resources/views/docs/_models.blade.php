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
    // 🔴 The lightest model as MEASURED, not the card size we round it up to. Taken from
    // min_vram_gb this line announced "from 4 GB upwards" while the smallest was holding 1.7 —
    // making the offering look heavier than it is, to exactly the people with the least card.
    $smallest = collect($models)->pluck('measured.vram_gb')->filter()->min()
             ?? collect($models)->min('min_vram_gb');
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
        {{-- ⚠ TWO links, because the sentence names two things. It described the tester and then
             offered only the download, so the one thing the reader had just been told about was
             the one thing they could not reach — they had to go and look for it.

             The tester's label is the heading of its own section, already translated: a second
             link here costs no new string, and it reads as the same words in both places. --}}
        <p class="text-sm text-gray-400 mt-3">
            {{ __('docs.models.try_smaller') }}
            <a href="#manager-model-test"
               class="text-purple-300 hover:text-purple-200 underline">{{ __('docs.manager.ai_test_title') }}</a>
            ·
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

                            {{-- The wait before the FIRST line, paid while a game is starting. It
                                 is the widest spread of anything measured — six seconds to sixteen
                                 — and it does not follow the download size, so no other column
                                 hints at it. --}}
                            <th class="py-2 px-3 font-medium">{{ __('docs.models.load_time') }}</th>

                            {{-- ⚠ Both waits, and neither stands in for the other: this one is paid
                                 on every line while somebody plays, the one before it once at the
                                 start. A model can be quick here and slow to arrive, or the
                                 reverse. --}}
                            <th class="py-2 px-3 font-medium">{{ __('docs.models.time_per_line') }}</th>

                            <th class="py-2 px-3 font-medium">{{ __('docs.models.instructions') }}</th>

                            {{-- A model can follow every instruction and still get there by asking
                                 again: same result, twice the wait and twice the card, on a line
                                 somebody is waiting for. --}}
                            <th class="py-2 px-3 font-medium">{{ __('docs.models.retries') }}</th>
                            <th class="py-2 pl-3 font-medium">{{ __('docs.models.languages_claimed') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($models as $model)
                            <tr class="border-b border-gray-700/50 last:border-0">
                                <td class="py-2 pr-3">
                                    <code class="text-purple-300">{{ $model['pull'] }}</code>

                                    {{-- A mark rather than a column: one model in ten refuses both
                                         a real foreign language and an invented one, and a column
                                         would spend its width saying "no" nine times.

                                         ⚠ The label is not translated, and that is deliberate: it
                                         is the name of the option itself — strict_source_language,
                                         cited as-is in the configuration table below. Somebody
                                         reads it here and goes looking for it there.

                                         The explanation is that same option's own description,
                                         already written in every language. --}}
                                    {{-- The two marks, and only two. Each answers a question a
                                         reader arrives with — "what do you run yourselves" and
                                         "I have a small card" — and the second is the whole reason
                                         they exist: the model it lands on is SIXTH in the order,
                                         because four retries out of twenty is a real cost, and the
                                         order alone would bury the lightest thing on the page.

                                         ⚠ The mark points, the columns qualify. It says LIGHTEST,
                                         not best, and the retry cell two columns along says 4/20 in
                                         amber. Neither is allowed to say the other's part.

                                         ⚠ Informative blue, never green: green reads as approval
                                         and neither claim is one. Amber and red are kept for the
                                         columns, where a cost is stated. --}}
                                    @if ($mark = \App\Services\ModelCatalog::standout($model, $models))
                                        <span class="block w-fit mt-1 text-[10px] text-blue-300
                                                     bg-blue-500/10 border border-blue-500/30
                                                     rounded px-1.5 py-0.5">
                                            {{ __('docs.models.mark_' . $mark) }}
                                        </span>
                                    @endif

                                    {{-- ⚠ On its own line, not beside the name: the model column is
                                         the narrowest of the seven, and a mark next to a tag ran
                                         past its edge. Under it, both are read whole. --}}
                                    @if (($model['measured']['strict_source'] ?? false) === true)
                                        <span class="block w-fit mt-1 text-[10px] text-gray-400
                                                     border border-gray-600 rounded px-1.5 py-0.5"
                                              title="{{ __('docs.config.strict_source') }}">strict source</span>
                                    @endif
                                </td>
                                {{-- 🔴 **What it HELD, then the card we ask for.** This column showed
                                     the second alone, and that figure is rounded up to real card
                                     sizes — so four models reading "at least 4 GB" were holding
                                     1.7, 2.8, 3.1 and 3.1 GB. The rounding hid the one number that
                                     decides how much card is left for the game while it runs.

                                     Same shape as the block above it, and the same two strings. --}}
                                <td class="py-2 px-3 text-gray-300">
                                    @isset($model['measured']['vram_gb'])
                                        {{ __('docs.models.gigabytes', ['gb' => $model['measured']['vram_gb']]) }}
                                    @endisset
                                    @isset($model['min_vram_gb'])
                                        <span class="text-gray-500">
                                            ({{ __('docs.models.minimum', ['gb' => $model['min_vram_gb']]) }})
                                        </span>
                                    @endisset
                                    @if (!isset($model['measured']['vram_gb'], $model['min_vram_gb']))
                                        <span class="text-gray-600">—</span>
                                    @endif
                                </td>
                                <td class="py-2 px-3 text-gray-300">
                                    @isset($model['download_gb'])
                                        {{ __('docs.models.gigabytes', ['gb' => $model['download_gb']]) }}
                                    @else
                                        <span class="text-gray-600">—</span>
                                    @endisset
                                </td>
                                <td class="py-2 px-3 text-gray-300">
                                    @isset($model['measured']['load_s'])
                                        {{ $model['measured']['load_s'] }}s
                                    @else
                                        <span class="text-gray-600">—</span>
                                    @endisset
                                </td>
                                <td class="py-2 px-3 text-gray-300">
                                    @isset($model['measured']['typical_s'])
                                        {{ $model['measured']['typical_s'] }}s
                                    @else
                                        <span class="text-gray-600">—</span>
                                    @endisset
                                </td>
                                {{-- Amber as soon as one instruction went unfollowed. It is not a
                                     matter of taste: the suite is the set of things the mod asks of
                                     a model, so a miss is a shape of text it will get wrong. --}}
                                @php
                                    $measured = $model['measured'] ?? [];
                                    $short = isset($measured['suite'], $measured['suite_of'])
                                          && $measured['suite'] < $measured['suite_of'];
                                    $lost = $measured['refused'] ?? 0;
                                @endphp
                                <td class="py-2 px-3 {{ $short ? 'text-yellow-400' : 'text-gray-300' }}">
                                    @if (isset($measured['suite'], $measured['suite_of']))
                                        {{ $measured['suite'] }}/{{ $measured['suite_of'] }}
                                    @else
                                        <span class="text-gray-600">—</span>
                                    @endif
                                </td>

                                {{-- 🔴 A line the model never got right is shown HERE, in red, and
                                     not left to be inferred from the column before it. It is the
                                     first thing the order sorts on, and text left in its original
                                     language while somebody plays is not the same kind of cost as a
                                     wait. Both figures are out of the same twenty lines, which is
                                     why they share a cell rather than needing an eighth column.

                                     Amber for a retry: not a failure — the line came out right —
                                     but the same line paid for twice, on the card, while playing. --}}
                                <td class="py-2 px-3 {{ $lost > 0 ? 'text-red-400' : (($measured['retried'] ?? 0) > 0 ? 'text-yellow-400' : 'text-gray-300') }}">
                                    @if (isset($measured['retried'], $measured['lines']))
                                        {{ $measured['retried'] }}/{{ $measured['lines'] }}
                                        @if ($lost > 0)
                                            {{-- trans_choice, not __: several of the twenty
                                                 languages decline the noun, and Polish, Russian and
                                                 Arabic need three, three and six forms of it. --}}
                                            <span class="whitespace-nowrap">· {{ trans_choice('docs.models.failed', $lost, ['count' => $lost]) }}</span>
                                        @endif
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
