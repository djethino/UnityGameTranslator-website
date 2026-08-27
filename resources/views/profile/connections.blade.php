@extends('layouts.app')

@section('title', __('connections.page_title') . ' - UnityGameTranslator')

{{-- What holds an access to this account, and how to cut it.

     ⚠ Its own page rather than a card in /profile: that column is `max-w-xl` (576 px), and a
     contributor can legitimately hold dozens of lines. The shell is the notifications page's —
     same width, same header row, same ghost action on the right.

     ⚠ Two subjects, not one: programs hold a token, browsers hold a session, and they are cut by
     two different acts. They share a page because they answer the same question — who can act as
     me — and because one creates the other: a browser session left open is what lets somebody link
     a game. --}}

@section('content')
<div class="max-w-3xl mx-auto">
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-2xl font-bold text-white">
            <i class="fas fa-link mr-2 text-purple-400"></i>{{ __('connections.page_title') }}
        </h1>
        {{-- Shown from the first line, and a ghost: whoever is panicking should not have to scroll
             past fifty rows to find the remedy, and a ghost is not something a hand clicks by
             reflex on the way past. --}}
        @if($total > 0)
        <form method="POST" action="{{ route('profile.connections.destroy-many') }}"
              data-confirm="{{ __('connections.revoke_all_confirm') }}">
            @csrf
            @method('DELETE')
            <input type="hidden" name="scope" value="all">
            <button type="submit" class="text-sm text-gray-400 hover:text-white transition">
                <i class="fas fa-power-off mr-1"></i>{{ __('connections.revoke_all') }}
            </button>
        </form>
        @endif
    </div>

    {{-- The contract, before the anxiety. It moves the question from "do I recognise this?" to "do
         I still need it?", which is the one somebody can actually answer. --}}
    <p class="text-gray-400 text-sm mb-2">{{ __('connections.intro') }}</p>
    {{-- 🔴 The six-month rule was applied and stated NOWHERE, so the date on each line arrived out
         of the blue — and a date nobody can account for reads as a bug, which is exactly how it was
         reported. Said once, here, because it is one rule for the whole page and not a property of
         any line. --}}
    <p class="text-gray-500 text-xs mb-1">{{ __('connections.idle_rule') }}</p>
    <p class="text-gray-500 text-xs mb-6">{{ __('connections.scope_note') }}</p>

    {{-- ⚠ No success box here. The layout already renders session('success') for every page, so
         adding one showed "Name saved." twice — once at the top of the window and once inside the
         page. Reading the neighbour would have caught it; the screenshot did. --}}
    @if($errors->any())
        <div class="bg-red-900 border border-red-700 text-red-100 px-4 py-3 rounded mb-6">
            <ul class="list-disc list-inside">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Browsers, kept compact and high on the page so both remedies sit in the first screenful.

         🔴 A count and nothing else. No address, no place, no browser name, no date: this page is
         readable by whoever is already inside the account, so anything locating its owner turns it
         into a surveillance tool pointed at them. A count is enough to decide. --}}
    <div class="bg-gray-800 rounded-lg p-4 border border-gray-700 mb-6">
        <div class="flex items-center justify-between gap-4 flex-wrap">
            <p class="text-sm text-gray-300">
                <i class="fas fa-window-restore mr-2 text-purple-400"></i>
                @if($otherBrowsers > 0)
                    {{ trans_choice('connections.browsers_some', $otherBrowsers, ['count' => $otherBrowsers]) }}
                @else
                    {{ __('connections.browsers_none') }}
                @endif
            </p>
            @if($otherBrowsers > 0)
            <form method="POST" action="{{ route('profile.browsers.destroy') }}"
                  data-confirm="{{ __('connections.browsers_sign_out_confirm') }}">
                @csrf
                @method('DELETE')
                <button type="submit" class="bg-gray-700 hover:bg-gray-600 text-white px-4 py-2 rounded-lg transition text-sm">
                    {{ __('connections.browsers_sign_out') }}
                </button>
            </form>
            @endif
        </div>
        <p class="text-xs text-gray-500 mt-2">{{ __('connections.browsers_note') }}</p>
    </div>

    @if($total === 0)
        {{-- The common case, not an anomaly: an account is optional and online mode is off by
             default, so most people never link anything. It explains what an access is for. --}}
        <div class="bg-gray-800 rounded-lg p-8 border border-gray-700 text-center text-gray-400">
            <i class="fas fa-link-slash text-3xl mb-3 block"></i>
            <p class="text-gray-300 mb-2">{{ __('connections.empty_title') }}</p>
            <p class="text-sm">{{ __('connections.empty_text') }}</p>
        </div>
    @else
        <div class="space-y-4">
            @foreach($groups as $tokens)
                @php
                    // The group's name, and the line that stands for it in the forms below.
                    //
                    // ⚠ The forms post an ID, not the name. A machine can group without anybody
                    // having typed anything, so two groups can share the same absent name — cutting
                    // by name would then cut a machine nobody was looking at.
                    $anyToken = $tokens->first();
                    $label = $anyToken->device_label;
                    $knowsItsMachine = $anyToken->device_slot !== null;
                @endphp
                <div class="bg-gray-800 rounded-lg p-4 border border-gray-700">
                    <div class="flex items-center justify-between gap-3 mb-3">
                        <h2 class="font-semibold text-gray-200">
                            @if($label === null)
                                {{-- A machine that says who it is but has no name yet is NOT the
                                     old "we know nothing" heap: its games are really together, and
                                     naming it once names all of them. Two different states, two
                                     different words. --}}
                                <i class="fas {{ $knowsItsMachine ? 'fa-desktop mr-2 text-gray-400' : 'fa-question-circle mr-2 text-gray-500' }}"></i>
                                {{ $knowsItsMachine ? __('connections.group_this_machine') : __('connections.group_unnamed') }}
                            @else
                                <i class="fas fa-desktop mr-2 text-purple-400"></i>{{ $label }}
                            @endif
                            <span class="text-gray-500 font-normal">({{ $tokens->count() }})</span>
                        </h2>
                        <form method="POST" action="{{ route('profile.connections.destroy-many') }}"
                              data-confirm="{{ trans_choice('connections.revoke_device_confirm', $tokens->count(), ['count' => $tokens->count()]) }}">
                            @csrf
                            @method('DELETE')
                            <input type="hidden" name="scope" value="device">
                            <input type="hidden" name="token" value="{{ $anyToken->id }}">
                            <button type="submit" class="text-xs text-gray-400 hover:text-white transition whitespace-nowrap">
                                {{ __('connections.revoke_device') }}
                            </button>
                        </form>
                    </div>

                    @if($label === null && !$knowsItsMachine)
                        {{-- Said, not hidden. Grouping these by anything would be a guess, and a
                             guess presented as a fact is what makes somebody cut the wrong line. --}}
                        <p class="text-xs text-gray-500 mb-3">{{ __('connections.group_unnamed_hint') }}</p>
                    @elseif($label === null)
                        <p class="text-xs text-gray-500 mb-3">{{ __('connections.group_this_machine_hint') }}</p>
                    @endif

                    {{-- 🔴 The name belongs to the MACHINE, so it is set once, here, and not on each
                         line. It used to sit inside every row — right while the typed name was the
                         only thing grouping anything, and wrong the moment a machine could say so
                         itself: naming a PC would have meant typing the same words into fifteen
                         games in a row. One act, one door.

                         ⚠ Native <details>: the Alpine build here is @alpinejs/csp, whose parser
                         only resolves x-data to a registered component. --}}
                    <details class="mb-3">
                        <summary class="text-xs text-gray-500 hover:text-gray-300 cursor-pointer">
                            {{ __('connections.rename') }}
                        </summary>
                        <form method="POST" action="{{ route('profile.connections.rename', $anyToken->id) }}" class="mt-2 flex gap-2">
                            @csrf
                            @method('PATCH')
                            <input type="text" name="device_label" maxlength="60"
                                   value="{{ $label }}"
                                   placeholder="{{ __('connections.rename_placeholder') }}"
                                   class="flex-1 bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white text-sm focus:outline-none focus:border-purple-500">
                            <button type="submit" class="bg-gray-700 hover:bg-gray-600 text-white px-3 py-2 rounded-lg transition text-sm">
                                {{ __('common.save') }}
                            </button>
                        </form>
                        <p class="text-xs text-gray-500 mt-1">{{ __('connections.rename_hint') }}</p>
                    </details>

                    <ul class="divide-y divide-gray-700/60 rounded-lg border border-gray-700 bg-gray-900/30">
                        @foreach($tokens as $token)
                            @php
                                $loader = \App\Support\ClientAgent::loaderLabel($token->client_variant);
                                $game = $token->gameName();
                                $client = match ($token->client_kind) {
                                    'mod' => __('connections.client_mod'),
                                    'manager' => __('connections.client_manager'),
                                    'other' => __('connections.client_other'),
                                    default => __('connections.client_unknown'),
                                };
                            @endphp
                            <li class="px-3 py-3">
                                <div class="flex items-start justify-between gap-3 flex-wrap">
                                    <div class="min-w-0">
                                        <p class="text-sm text-gray-200">
                                            <i class="fas {{ $token->client_kind === 'manager' ? 'fa-toolbox' : ($token->client_kind === 'mod' ? 'fa-gamepad' : 'fa-circle-question') }} mr-2 text-gray-500"></i>
                                            {{-- 🔴 A mod access with no game used to fall back to
                                                 the word "Mod" — which the icon beside it already
                                                 said, and which left "no game recorded" looking
                                                 exactly like "unknown game". Reported from
                                                 production: every line read "Mod".

                                                 The Manager holds no game by design (one access per
                                                 installation, not per game), so it is only the mod
                                                 that has something missing here. --}}
                                            @if($game)
                                                {{ $game }}
                                            @elseif($token->client_kind === 'mod')
                                                <span class="text-gray-400">{{ __('connections.game_not_recorded') }}</span>
                                            @else
                                                {{ $client }}
                                            @endif
                                            @if($token->published_at_least_once)
                                                <span class="ml-2 text-xs text-amber-300">
                                                    <i class="fas fa-upload mr-1"></i>{{ __('connections.published_badge') }}
                                                </span>
                                            @endif
                                        </p>
                                        <p class="text-xs text-gray-500 mt-1">
                                            @if($game)
                                                {{ $client }}@if($loader) · {{ $loader }}@endif ·
                                            @elseif($loader)
                                                {{ $loader }} ·
                                            @endif
                                            {{ __('connections.linked_on', ['date' => $token->created_at->translatedFormat('j F Y')]) }} ·
                                            @switch($token->lastExchangeBucket())
                                                @case('today') {{ __('connections.exchange_today') }} @break
                                                @case('week') {{ __('connections.exchange_week') }} @break
                                                @case('month') {{ __('connections.exchange_month') }} @break
                                                @case('idle') {{ trans_choice('connections.exchange_idle', $token->idleMonths(), ['count' => $token->idleMonths()]) }} @break
                                                @default {{ __('connections.exchange_never') }}
                                            @endswitch
                                            @if($token->public_code)
                                                · <span class="font-mono text-gray-400">#{{ $token->public_code }}</span>
                                            @endif
                                            {{-- ⚠ One deadline, the nearer of the two, or the line
                                                 becomes unreadable. And it is shown at all because
                                                 an access that vanishes one morning without anybody
                                                 having been told is a surprise, not a cleanup.

                                                 🔴 **Only on a line that has gone quiet**, and that
                                                 was a real defect. The idle deadline SLIDES: it is
                                                 six months from the last exchange, so on an access
                                                 that speaks every day it moves every day. Printing
                                                 a fixed date beside "exchange today" said something
                                                 false about tomorrow — and on the day the rule
                                                 shipped, the grace floor put EVERY line on the same
                                                 date, which read as a broken screen.

                                                 An expiry is the opposite: fixed, decided at issue,
                                                 and nothing the program does moves it. So it is
                                                 shown whatever the line is doing. --}}
                                            @php
                                                $idleCut = \App\Console\Commands\PurgeIdleTokens::deadlineFor($token);
                                                $expiryIsNearer = $token->expires_at && $token->expires_at->lessThan($idleCut);
                                                $goneQuiet = in_array($token->lastExchangeBucket(), ['idle', 'never'], true);
                                            @endphp
                                            @if($expiryIsNearer)
                                                · {{ __('connections.expires_on', ['date' => $token->expires_at->translatedFormat('j F Y')]) }}
                                            @elseif($goneQuiet)
                                                · {{ __('connections.cut_on', ['date' => $idleCut->translatedFormat('j F Y')]) }}
                                            @endif
                                        </p>
                                    </div>

                                    <form method="POST" action="{{ route('profile.connections.destroy', $token->id) }}"
                                          data-confirm="{{ __('connections.revoke_confirm') }}">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="bg-red-900 hover:bg-red-800 text-red-200 px-3 py-1.5 rounded-lg transition text-xs whitespace-nowrap">
                                            {{ __('connections.revoke') }}
                                        </button>
                                    </form>
                                </div>

                                {{-- Where this ONE line is filed, which is not the same act as
                                     naming the pile it sits in — that lives on the group header.

                                     🔴 A group is not a machine. We arrange by machine because it
                                     is what can be known without asking, and it is a starting
                                     point: somebody may just as well file by language, by kind of
                                     game, or by whatever means something to them.

                                     ⚠ Native <details> and a native <select>: the Alpine build here
                                     is @alpinejs/csp, whose parser only resolves x-data to a
                                     registered component.

                                     ⚠ The text field wins over the list when it is filled — one
                                     control, and the obvious reading of typing into it. Leaving
                                     both empty puts the line back under its machine. --}}
                                <details class="mt-2">
                                    <summary class="text-xs text-gray-500 hover:text-gray-300 cursor-pointer">
                                        {{ __('connections.move') }}
                                    </summary>
                                    <form method="POST" action="{{ route('profile.connections.move', $token->id) }}" class="mt-2 space-y-2">
                                        @csrf
                                        @method('PATCH')
                                        {{-- Every group on screen, named or not — see the controller.
                                             Offering only the named ones left this control unable
                                             to reach the boxes right above it.

                                             🔴 **Always rendered, and the line's OWN group is in it,
                                             selected.** It appeared and vanished depending on
                                             whether anywhere else existed, which made the layout
                                             read as arbitrary and left two lines of one group with
                                             a bare text field while a third had a list. Carrying
                                             the current group earns the control its place even
                                             when nothing else is reachable: it says where this line
                                             is filed. A disabled one was the other option, and this
                                             program does not grey controls out.

                                             ⚠ Leaving a group is a SEPARATE option, offered only
                                             when there is a group to leave. On a line nobody has
                                             filed it would name an act that cannot happen. --}}
                                        {{-- 🔴 **The empty option carries three different truths, and
                                             getting that wrong made the control LIE.** A line in the
                                             unplaced heap had no entry of its own — the heap is not
                                             a destination — so the browser selected the first option
                                             and the box announced a machine that line was never on.

                                             ⚠ Choosing it only ever clears the hand-filed name, and
                                             never what a machine said. Clearing that would be undone
                                             by the machine's next call, bouncing the line back — a
                                             destination that undoes itself. Which is also why the
                                             heap can be a STATE here and never a choice. --}}
                                        <select name="into"
                                                class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white text-sm focus:outline-none focus:border-purple-500">
                                            @if($token->device_label !== null)
                                                <option value="">{{ __('connections.move_out') }}</option>
                                            @elseif($token->device_slot === null)
                                                <option value="" selected>{{ __('connections.group_unnamed') }}</option>
                                            @endif
                                            @foreach($destinations as $destination)
                                                <option value="{{ $destination['id'] }}"
                                                        @selected($destination['key'] === $token->groupKey())>
                                                    {{ $destination['label'] ?? __('connections.group_this_machine') }}
                                                    ({{ $destination['count'] }})
                                                </option>
                                            @endforeach
                                        </select>
                                        <div class="flex gap-2">
                                            <input type="text" name="new_group" maxlength="60"
                                                   placeholder="{{ __('connections.move_new_placeholder') }}"
                                                   class="flex-1 bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white text-sm focus:outline-none focus:border-purple-500">
                                            <button type="submit" class="bg-gray-700 hover:bg-gray-600 text-white px-3 py-2 rounded-lg transition text-sm">
                                                {{ __('common.save') }}
                                            </button>
                                        </div>
                                    </form>
                                    <p class="text-xs text-gray-500 mt-1">{{ __('connections.move_hint') }}</p>
                                </details>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </div>

        {{-- The public code, written in ONE direction only.

             🔴 A reader supplies the converse on their own — "I found my code, so it is me, so all
             is well" — and the converse is false: the realistic theft is a copied config.json, and
             the copy shows the victim exactly the code they expect. --}}
        <p class="text-xs text-gray-500 mt-6">
            <i class="fas fa-hashtag mr-1"></i>{{ __('connections.code_note') }}
        </p>

        <details class="mt-4 bg-gray-800 rounded-lg p-4 border border-gray-700">
            <summary class="font-semibold text-gray-200 cursor-pointer">
                <i class="fas fa-circle-question mr-2 text-purple-400"></i>{{ __('connections.unknown_title') }}
            </summary>
            {{-- Benign causes first. Most of what looks alarming here is one access per linked game
                 and a fresh one after every reinstall. --}}
            <p class="text-sm text-gray-400 mt-3">{{ __('connections.unknown_benign') }}</p>
            <p class="text-sm text-gray-400 mt-2">{{ __('connections.unknown_method') }}</p>
            <p class="text-sm text-gray-400 mt-2">{{ __('connections.unknown_action') }}</p>
            {{-- Revoking IS a message addressed to whoever holds the access: their program says it
                 has been signed out. Somebody for whom that is dangerous needs to know before. --}}
            <p class="text-sm text-amber-200/90 mt-2">{{ __('connections.unknown_warning') }}</p>
            <p class="text-sm text-gray-400 mt-2">{{ __('connections.fresh_start') }}</p>
        </details>
    @endif

    {{-- Bounded to what this screen is about. A blanket "we do not record your IP" would be false
         while the sign-in record holds one, and this page is the worst place to be caught out. --}}
    <p class="text-xs text-gray-500 mt-6">
        {{ __('connections.footer_note') }}
        <a href="{{ route('legal.privacy') }}" class="text-purple-400 hover:text-purple-300">{{ __('legal.privacy_title') }}</a>.
    </p>

    <p class="text-center text-gray-500 text-sm mt-8">
        <a href="{{ route('profile.edit') }}" class="text-purple-400 hover:text-purple-300 transition">
            {{-- ⚠ "Settings", because that is the word on the menu entry people arrive through.
                 The page it lands on is headed "Profile Settings"; naming the door they used beats
                 naming the room they end up in. --}}
            <i class="fas fa-arrow-left mr-1"></i> {{ __('connections.back_to_settings') }}
        </a>
    </p>
</div>
@endsection
