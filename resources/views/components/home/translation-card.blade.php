@props([
    'translation',
    // The date this card's SECTION is ordered by. A "recently finished" list ordered on the last
    // content change, showing the publication date, told a card it had been finished seven months
    // ago while sitting at the top of the list.
    'date' => null,
])

{{--
    One translation, as the front page shows it.

    Shared by both lists — finished and under way — because they show the same thing about the
    same object and differ only in which set they draw from. Two copies would have drifted the
    day one of them gained a badge.
--}}
<a href="{{ route('games.show', $translation->game) }}"
   class="bg-gray-800 rounded-lg p-4 border border-gray-700 hover:border-purple-500 transition block">
    <div class="flex items-start space-x-3">
        @if($translation->game->image_url)
            <img src="{{ $translation->game->image_url }}" alt="{{ $translation->game->name }}"
                 class="w-12 h-16 object-cover rounded">
        @else
            <div class="w-12 h-16 bg-gray-700 rounded flex items-center justify-center">
                <i class="fas fa-gamepad text-gray-400"></i>
            </div>
        @endif
        <div class="flex-1 min-w-0">
            <h3 class="font-semibold text-white truncate">{{ $translation->game->name }}</h3>
            <div class="text-sm text-gray-400 flex items-center gap-1">
                <span>@langflag($translation->source_language)</span>
                <i class="fas fa-arrow-right text-xs text-gray-600"></i>
                <span>@langflag($translation->target_language)</span>
            </div>
            <div class="text-xs text-gray-400 mt-1">
                {{-- Never updated_at, whichever date is passed: a vote or a download moves it, so
                     it says neither when the work was published nor when it was last touched. --}}
                <x-user-mention :user="$translation->user" /> · {{ ($date ?? $translation->created_at)->diffForHumans() }}
            </div>
            {{-- The same badges as the game pages: what catches the eye about a translation must
                 not depend on which page you meet it. --}}
            <div class="flex flex-wrap gap-1 mt-2">
                <x-translation-badges :translation="$translation" />
            </div>
            <div class="mt-2">
                <x-progress-bar :translation="$translation" />
                <x-quality-legend :translation="$translation" compact>
                    <span class="text-gray-600">•</span>
                    <span>{{ trans_choice('my_translations.lines_count', $translation->line_count, ['count' => number_format($translation->line_count)]) }}</span>
                </x-quality-legend>
            </div>
        </div>
    </div>
</a>
