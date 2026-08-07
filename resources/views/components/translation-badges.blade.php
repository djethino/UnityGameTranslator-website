@props(['translation', 'gameMax' => null, 'peerCount' => 1])

{{--
    The two things about a translation that catch the eye and are not written anywhere else on
    its card.

    Deliberately only two. A badge works by being rare: the download count, the review stage, the
    completion mark and the dates are all already on the card in plain words, and repeating any
    of them as a coloured chip would spend attention on something the reader can already see.

    "Newest" uses the same seven days as the games list, rather than inventing a second threshold
    for the same idea on a neighbouring page.
--}}
@php
    $isNew = $translation->created_at && $translation->created_at >= now()->subDays(7);

    // The furthest anyone has got with this game. Worth saying precisely because the coverage
    // badge stays silent at 100%: the yardstick is the furthest translation, not the game's real
    // size, so it cannot claim to cover the game — but it CAN say nobody has gone further.
    // Meaningless when it is the only translation, where "furthest" is a race of one.
    $max = $gameMax ?? \App\Models\Translation::maxResolvedLinesForGame($translation->game_id);
    $isFurthest = $peerCount > 1 && $max > 0 && $translation->resolved_lines >= $max;
@endphp

@if($isNew)
    <span class="bg-green-700 text-green-100 px-2 py-1 rounded text-xs font-medium">
        {{ __('games.new') }}
    </span>
@endif

@if($isFurthest)
    <span class="bg-cyan-900 text-cyan-200 px-2 py-1 rounded text-xs font-medium"
        title="{{ __('progress.furthest_hint') }}">
        <i class="fas fa-flag-checkered mr-1"></i>{{ __('progress.furthest') }}
    </span>
@endif
