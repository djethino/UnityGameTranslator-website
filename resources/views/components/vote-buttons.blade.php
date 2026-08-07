@props(['translation', 'size' => 'lg'])

{{--
    The one signal that says someone was GLAD to find a translation. Downloads only say they
    tried it.

    Three copies of this markup lived in the game page, differing by two size classes; the rule
    "not on your own work" would have had to be written into each of them, and one would have
    drifted. The ids and the .vote-btn class are what the page's script drives — they must not
    change shape here.

    An author sees the count and no arrows: the server refuses a self-vote (Translation::
    canBeVotedBy), and offering a button that answers 403 is worse than offering none.
    A signed-out visitor keeps the arrows as links to the login page — the vote is worth the
    detour, and a dead grey arrow says nothing about what to do.
--}}
@php
    $count = $translation->vote_count;
    $countTone = $count > 0 ? 'text-green-400' : ($count < 0 ? 'text-red-400' : 'text-gray-400');
    $isOwn = auth()->check() && auth()->id() === $translation->user_id;
    $userVote = auth()->check() && !$isOwn ? $translation->userVote() : null;

    $loginUrl = route('login') . '?redirect=' . urlencode(url()->current()) . '&action=vote';

    $wrapper = $size === 'sm'
        ? 'flex items-center gap-1 text-sm'
        : 'flex items-center gap-1 bg-gray-700 rounded-lg px-3 py-2';
    $icon = $size === 'sm' ? '' : ' text-lg';
    $countClass = $size === 'sm' ? '' : 'text-lg font-bold min-w-[2.5rem] text-center ';
    $button = $size === 'sm' ? 'vote-btn transition ' : 'vote-btn p-1 rounded hover:bg-gray-600 transition ';
    $link = $size === 'sm' ? 'transition ' : 'p-1 transition ';
@endphp

<div class="{{ $wrapper }}" id="vote-container-{{ $translation->id }}">
    @auth
        @if($isOwn)
            <span class="text-gray-600{{ $icon }}" title="{{ __('translation.cannot_vote_own') }}">
                <i class="fas fa-arrow-up"></i>
            </span>
        @else
            <button type="button"
                data-vote-id="{{ $translation->id }}" data-vote-value="1"
                class="{{ $button }}{{ $userVote && $userVote->value === 1 ? 'text-green-400' : 'text-gray-400' }} hover:text-green-400"
                id="upvote-{{ $translation->id }}"
                title="{{ __('translation.upvote') }}">
                <i class="fas fa-arrow-up{{ $icon }}"></i>
            </button>
        @endif
    @else
        <a href="{{ $loginUrl }}" class="{{ $link }}text-gray-500 hover:text-green-400"
            title="{{ __('translation.login_to_vote') }}">
            <i class="fas fa-arrow-up{{ $icon }}"></i>
        </a>
    @endauth

    <span class="{{ $countClass }}{{ $countTone }}" id="vote-count-{{ $translation->id }}">
        {{ $count >= 0 ? '+' : '' }}{{ $count }}
    </span>

    @auth
        @if($isOwn)
            <span class="text-gray-600{{ $icon }}" title="{{ __('translation.cannot_vote_own') }}">
                <i class="fas fa-arrow-down"></i>
            </span>
        @else
            <button type="button"
                data-vote-id="{{ $translation->id }}" data-vote-value="-1"
                class="{{ $button }}{{ $userVote && $userVote->value === -1 ? 'text-red-400' : 'text-gray-400' }} hover:text-red-400"
                id="downvote-{{ $translation->id }}"
                title="{{ __('translation.downvote') }}">
                <i class="fas fa-arrow-down{{ $icon }}"></i>
            </button>
        @endif
    @else
        <a href="{{ $loginUrl }}" class="{{ $link }}text-gray-500 hover:text-red-400"
            title="{{ __('translation.login_to_vote') }}">
            <i class="fas fa-arrow-down{{ $icon }}"></i>
        </a>
    @endauth
</div>
