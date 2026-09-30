@extends('layouts.app')

@section('title', $user->name . ' - Users - Admin')

@section('content')
<div class="flex justify-between items-center mb-6">
    <h1 class="text-3xl font-bold"><i class="fas fa-user mr-2"></i> {{ $user->name }}</h1>
    <a href="{{ route('admin.users') }}" class="text-gray-400 hover:text-white">
        <i class="fas fa-arrow-left mr-1"></i> Back to Users
    </a>
</div>

{{-- Who this is, in the words and order of the Users list the admin came from: the same facts must
     not read differently one click apart. Ban / Unban stay on that list — one way in per act. --}}
<div class="bg-gray-800 rounded-lg p-5 border border-gray-700 mb-6 flex flex-wrap items-center gap-x-8 gap-y-3">
    <div class="flex items-center gap-3">
        <x-avatar :user="$user" :size="48" />
        <div>
            <div class="font-medium">
                {{ $user->name }}
                @if($user->isAdmin())
                    <span class="text-xs bg-yellow-600 px-1.5 py-0.5 rounded ml-1">Admin</span>
                @endif
            </div>
            <div class="text-sm text-gray-400">{{ $user->email }}</div>
        </div>
    </div>
    <div class="text-sm">
        <div class="text-gray-500">Provider</div>
        <div><i class="fab fa-{{ $user->provider }} mr-1"></i>{{ ucfirst($user->provider) }}</div>
    </div>
    <div class="text-sm">
        <div class="text-gray-500">Status</div>
        {{-- Erased before banned, as on the list: deleting an account bans it too. --}}
        @if($user->isDeletedAccount())
            <div class="text-gray-400"><i class="fas fa-user-slash mr-1"></i> Deleted {{ $user->account_deleted_at->format('M d, Y') }}</div>
        @elseif($user->isBanned())
            <div class="text-red-400" title="{{ $user->ban_reason }}"><i class="fas fa-ban mr-1"></i> Banned</div>
        @else
            <div class="text-green-400"><i class="fas fa-check mr-1"></i> Active</div>
        @endif
    </div>
    <div class="text-sm">
        <div class="text-gray-500">Last mod activity</div>
        @if($user->last_mod_activity)
            @php $activity = \Illuminate\Support\Carbon::parse($user->last_mod_activity); @endphp
            <div class="text-gray-300" title="{{ $activity->format('M d, Y H:i') }}">{{ $activity->diffForHumans() }}</div>
        @else
            <div class="text-gray-600">Never</div>
        @endif
    </div>
    <div class="text-sm">
        <div class="text-gray-500">Joined</div>
        <div class="text-gray-300">{{ $user->created_at->format('M d, Y') }}</div>
    </div>
</div>

@if($translations->isEmpty())
    <div class="text-center py-12 text-gray-400">
        <i class="fas fa-folder-open text-6xl mb-4"></i>
        <p class="text-xl">No translation.</p>
    </div>
@else
    {{-- How many of each role, then the order — the author's own sort control, same words. --}}
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
        <p class="text-sm text-gray-400">
            {{ $translations->count() }} translation(s)
            @foreach($roles as $role => $count)
                @if($count > 0) &middot; {{ $count }} {{ $role }} @endif
            @endforeach
        </p>
        @if($translations->count() > 1)
            <form action="{{ route('admin.users.show', $user) }}" method="GET"
                class="flex items-center gap-2" data-auto-submit>
                <label for="sort" class="text-sm text-gray-400">{{ __('games.sort_by') }}</label>
                <select name="sort" id="sort"
                    class="bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white text-sm">
                    @foreach(\App\Support\OwnerTranslations::SORTS as $option)
                        <option value="{{ $option }}" {{ $sort === $option ? 'selected' : '' }}>{{ __('my_translations.sort.' . $option) }}</option>
                    @endforeach
                </select>
                <button type="submit" data-hide-when-auto
                    class="bg-purple-600 hover:bg-purple-700 text-white px-3 py-2 rounded text-sm">
                    <i class="fas fa-sort"></i>
                </button>
            </form>
        @endif
    </div>

    <div class="space-y-4">
        @foreach($translations as $translation)
            <div id="translation-{{ $translation->id }}"
                 class="bg-gray-800 rounded-lg p-5 border border-gray-700 flex justify-between items-center gap-4 scroll-mt-24">
                <div>
                    @include('translations.partials.owner-card', [
                        'titleUrl' => route('admin.translations.show', $translation),
                        'hideMain' => false,
                    ])

                    {{-- What "My translations" tells this author in its banners, said per card here:
                         the admin looks at one account and needs to see which of its translations
                         is in which situation, not a message addressed to somebody else. --}}
                    @php
                        $situations = array_filter([
                            ($translation->visibility === 'public' && $translation->isCaptureOnly())
                                ? ['fa-hourglass-half', 'text-amber-300', 'Published, nothing translated'] : null,
                            $translation->isOrphanBranch()
                                ? ['fa-unlink', 'text-red-300', 'Branch with no Main'] : null,
                            $translation->mainIgnoresContributions()
                                ? ['fa-inbox', 'text-amber-300', 'Its Main is not taking the new work'] : null,
                            $translation->mainIsDelisted()
                                ? ['fa-eye-slash', 'text-amber-300', 'Its Main is hidden from players'] : null,
                            ($translation->isBranch() && $translation->mainIsDormant())
                                ? ['fa-moon', 'text-purple-300', 'Its Main has stopped moving'] : null,
                        ]);
                        $waiting = $branchCounts[$translation->file_uuid] ?? 0;
                    @endphp
                    @if($situations || ($translation->isMain() && $waiting > 0))
                        <div class="mt-2 ml-16 flex flex-wrap gap-3 text-xs">
                            @if($translation->isMain() && $waiting > 0)
                                <span class="text-green-300"><i class="fas fa-code-merge mr-1"></i>{{ $waiting }} branch(es) waiting for review</span>
                            @endif
                            @foreach($situations as [$icon, $colour, $text])
                                <span class="{{ $colour }}"><i class="fas {{ $icon }} mr-1"></i>{{ $text }}</span>
                            @endforeach
                        </div>
                    @endif
                </div>

                {{-- The admin translation screens' own actions, the same three as on the Translations
                     list — never a second way in. Delete comes back to this page. --}}
                <div class="flex gap-2 flex-shrink-0">
                    <a href="{{ route('admin.translations.show', $translation) }}" class="bg-gray-700 hover:bg-gray-600 text-white px-3 py-2 rounded" title="{{ __('admin.view_json') }}">
                        <i class="fas fa-eye"></i>
                    </a>
                    <a href="{{ route('admin.translations.edit', $translation) }}" class="bg-orange-600 hover:bg-orange-700 text-white px-3 py-2 rounded" title="{{ __('common.edit') }}">
                        <i class="fas fa-edit"></i>
                    </a>
                    <form action="{{ route('admin.translations.destroy', $translation) }}" method="POST" class="inline delete-form" data-confirm="{{ __('admin.delete_confirm') }}">
                        @csrf
                        @method('DELETE')
                        <input type="hidden" name="return" value="user">
                        <button type="submit" class="bg-red-900 hover:bg-red-800 text-white px-3 py-2 rounded" title="{{ __('common.delete') }}">
                            <i class="fas fa-trash"></i>
                        </button>
                    </form>
                </div>
            </div>
        @endforeach
    </div>
@endif
@endsection
