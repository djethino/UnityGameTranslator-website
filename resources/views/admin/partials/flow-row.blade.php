{{-- One line of the Flows list (admin/flows.blade.php): an event, or the head of a run of them
     (`opens` = how many it holds), or one event inside an opened run (`nested`).
     `$translations` and `$link` come from the screen. --}}
@use('App\Support\TranslationFlows')
@php
    $m = $event->metadata ?? [];
    $style = TranslationFlows::LABELS[$event->action];
    $gameId = $m['game_id'] ?? $m['to']['id'] ?? null;
    $gameName = $m['game'] ?? $m['game_name'] ?? $m['to']['name'] ?? null;
@endphp
<tr class="align-top {{ $nested ? 'bg-gray-900/40 text-gray-400' : 'border-t border-gray-700' }}"
    @if($nested) x-show="open" x-cloak @endif>
    <td class="py-2 px-4 whitespace-nowrap text-gray-400 {{ $nested ? 'pl-8' : '' }}">{{ $when }}</td>
    <td class="py-2 px-4 whitespace-nowrap">
        <i class="fas {{ $style['icon'] }} {{ $style['class'] }} mr-1"></i> {{ $style['label'] }}
    </td>
    <td class="py-2 px-4 whitespace-nowrap">
        @if($event->entity_id)
            @if($translations->has($event->entity_id))
                <a href="{{ route('admin.translations.show', $event->entity_id) }}" class="text-purple-400 hover:text-purple-300">#{{ $event->entity_id }}</a>
            @else
                <span class="text-gray-500" title="No longer exists">#{{ $event->entity_id }}</span>
            @endif
            <a href="{{ $link(['translation' => $event->entity_id]) }}" class="text-gray-600 hover:text-gray-300 ml-1" title="Only this translation">
                <i class="fas fa-filter text-xs"></i>
            </a>
        @else
            <span class="text-gray-600">—</span>
        @endif
    </td>
    <td class="py-2 px-4">
        @if($gameId)
            <a href="{{ $link(['game' => $gameId]) }}" class="hover:text-purple-400">{{ $gameName ?? '#' . $gameId }}</a>
        @else
            <span class="{{ $gameName ? '' : 'text-gray-600' }}">{{ $gameName ?? '—' }}</span>
        @endif
    </td>
    <td class="py-2 px-4 whitespace-nowrap">
        @if(!empty($m['target_language']))
            <a href="{{ $link(['language' => $m['target_language']]) }}" class="hover:text-purple-400">{{ $m['target_language'] }}</a>
        @else
            <span class="text-gray-600">—</span>
        @endif
    </td>
    <td class="py-2 px-4 whitespace-nowrap">
        <x-admin.user-link :user="$event->user" />
        @if($event->user_id)
            <a href="{{ $link(['user' => $event->user_id]) }}" class="text-gray-600 hover:text-gray-300 ml-1" title="Only this account">
                <i class="fas fa-filter text-xs"></i>
            </a>
        @endif
    </td>
    <td class="py-2 px-4 whitespace-nowrap text-gray-400">
        {{ TranslationFlows::viaOf($event) ?? '—' }}
    </td>
    <td class="py-2 px-4">
        {{ $what }}
        @if($opens)
            <button type="button" @click="open = !open" class="text-purple-400 hover:text-purple-300 text-xs ml-2 whitespace-nowrap">
                <i class="fas mr-1" :class="open ? 'fa-chevron-up' : 'fa-chevron-down'"></i>
                <span x-text="open ? 'Hide' : 'Show {{ $opens }}'">Show {{ $opens }}</span>
            </button>
        @endif
    </td>
</tr>
