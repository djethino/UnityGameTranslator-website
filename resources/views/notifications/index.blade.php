@extends('layouts.app')

@section('title', __('notif.page_title') . ' - UnityGameTranslator')

@section('content')
<div class="max-w-3xl mx-auto">
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold text-white">
            <i class="fas fa-bell mr-2 text-purple-400"></i>{{ __('notif.page_title') }}
        </h1>
        @if($notifications->total() > 0)
        <form method="POST" action="{{ route('notifications.read-all') }}">
            @csrf
            <button type="submit" class="text-sm text-gray-400 hover:text-white transition">
                <i class="fas fa-check-double mr-1"></i>{{ __('notif.mark_all_read') }}
            </button>
        </form>
        @endif
    </div>

    @if($notifications->isEmpty())
        <div class="bg-gray-800 rounded-lg p-8 border border-gray-700 text-center text-gray-400">
            <i class="fas fa-bell-slash text-3xl mb-3 block"></i>
            {{ __('notif.empty') }}
        </div>
    @else
        <div class="space-y-3">
            @foreach($notifications as $notification)
                @php
                    $data = $notification->data;
                    $type = $data['type'] ?? '';
                    $isUnread = is_null($notification->read_at);
                @endphp
                <div class="bg-gray-800 rounded-lg p-4 border {{ $isUnread ? 'border-purple-500/60' : 'border-gray-700' }} flex items-start gap-3">
                    <div class="mt-1">
                        @if($type === 'branch_submitted')
                            <i class="fas fa-code-branch text-blue-400"></i>
                        @elseif($type === 'branch_merged')
                            <i class="fas fa-code-merge text-green-400"></i>
                        @elseif($type === 'announcement')
                            <i class="fas fa-bullhorn text-purple-400"></i>
                        @else
                            <i class="fas fa-bell text-gray-400"></i>
                        @endif
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-gray-200 text-sm">
                            @if($type === 'branch_submitted')
                                {{ __('notif.branch_submitted', [
                                    'count' => $data['count'] ?? 1,
                                    'game' => $data['game_name'] ?? '?',
                                    'lang' => $data['target_language'] ?? '?',
                                    'user' => $data['last_contributor'] ?? '?',
                                ]) }}
                            @elseif($type === 'branch_merged')
                                {{ __('notif.branch_merged', [
                                    'count' => $data['merged_count'] ?? 1,
                                    'game' => $data['game_name'] ?? '?',
                                    'lang' => $data['target_language'] ?? '?',
                                    'owner' => $data['owner_username'] ?? '?',
                                ]) }}
                            @elseif($type === 'announcement')
                                <span class="font-semibold">{{ $data['title'] ?? '' }}</span><br>
                                <span class="text-gray-300">{{ $data['body'] ?? '' }}</span>
                            @else
                                {{ $data['message'] ?? '' }}
                            @endif
                        </p>
                        <div class="flex items-center gap-4 mt-2 text-xs">
                            <span class="text-gray-500">{{ $notification->created_at->diffForHumans() }}</span>
                            @if($type === 'branch_submitted' && !empty($data['uuid']))
                                <a href="{{ route('translations.merge', $data['uuid']) }}" class="text-purple-400 hover:text-purple-300">
                                    {{ __('notif.review_now') }} →
                                </a>
                            @elseif($type === 'branch_merged' && !empty($data['game_slug']))
                                <a href="{{ route('games.show', $data['game_slug']) }}" class="text-purple-400 hover:text-purple-300">
                                    {{ __('notif.see_translation') }} →
                                </a>
                            @elseif($type === 'announcement' && !empty($data['link']))
                                <a href="{{ $data['link'] }}" class="text-purple-400 hover:text-purple-300" rel="noopener">
                                    {{ __('notif.learn_more') }} →
                                </a>
                            @endif
                        </div>
                    </div>
                    @if($isUnread)
                    <form method="POST" action="{{ route('notifications.read', $notification->id) }}">
                        @csrf
                        <button type="submit" class="text-gray-500 hover:text-white transition" title="{{ __('notif.mark_read') }}">
                            <i class="fas fa-check"></i>
                        </button>
                    </form>
                    @endif
                </div>
            @endforeach
        </div>

        <div class="mt-6">
            {{ $notifications->links() }}
        </div>
    @endif
</div>
@endsection
