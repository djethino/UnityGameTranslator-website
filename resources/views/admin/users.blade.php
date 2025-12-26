@extends('layouts.app')

@section('title', 'Users - Admin')

@section('content')
<div class="flex justify-between items-center mb-6">
    <h1 class="text-3xl font-bold"><i class="fas fa-users mr-2"></i> Users</h1>
    <a href="{{ route('admin.dashboard') }}" class="text-gray-400 hover:text-white">
        <i class="fas fa-arrow-left mr-1"></i> Back to Dashboard
    </a>
</div>

<!-- Filters -->
<form action="{{ route('admin.users') }}" method="GET" class="bg-gray-800 rounded-lg p-4 mb-6 flex flex-wrap gap-4 items-end">
    <div class="flex-1 min-w-[200px]">
        <label class="block text-sm text-gray-400 mb-1">Search</label>
        <input type="text" name="search" value="{{ request('search') }}" placeholder="Name or email..."
            class="w-full bg-gray-700 border border-gray-600 rounded px-3 py-2 text-white">
    </div>
    <div>
        <label class="block text-sm text-gray-400 mb-1">Status</label>
        <select name="status" class="bg-gray-700 border border-gray-600 rounded px-3 py-2 text-white">
            <option value="">All</option>
            <option value="active" {{ request('status') == 'active' ? 'selected' : '' }}>Active</option>
            <option value="banned" {{ request('status') == 'banned' ? 'selected' : '' }}>Banned</option>
        </select>
    </div>
    <button type="submit" class="bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded">
        <i class="fas fa-search"></i> Search
    </button>
</form>

<!-- Users List -->
<div class="bg-gray-800 rounded-lg border border-gray-700 overflow-hidden">
    <table class="w-full">
        <thead class="bg-gray-700">
            <tr>
                <th class="px-4 py-3 text-left">User</th>
                <th class="px-4 py-3 text-left">Provider</th>
                <th class="px-4 py-3 text-center">Translations</th>
                <th class="px-4 py-3 text-left">Status</th>
                <th class="px-4 py-3 text-left">Joined</th>
                <th class="px-4 py-3 text-center">Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse($users as $user)
                <tr class="border-t border-gray-700 {{ $user->isBanned() ? 'bg-red-900/20' : '' }}">
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-3">
                            @if($user->avatar)
                                <img src="{{ $user->avatar }}" alt="" class="w-8 h-8 rounded-full">
                            @else
                                <div class="w-8 h-8 rounded-full bg-gray-600 flex items-center justify-center">
                                    <i class="fas fa-user text-gray-400"></i>
                                </div>
                            @endif
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
                    </td>
                    <td class="px-4 py-3">
                        <i class="fab fa-{{ $user->provider }} mr-1"></i>
                        {{ ucfirst($user->provider) }}
                    </td>
                    <td class="px-4 py-3 text-center">
                        {{ $user->translations_count }}
                    </td>
                    <td class="px-4 py-3">
                        @if($user->isBanned())
                            <span class="text-red-400">
                                <i class="fas fa-ban mr-1"></i> Banned
                            </span>
                            @if($user->ban_reason)
                                <div class="text-xs text-gray-400 mt-1">{{ Str::limit($user->ban_reason, 30) }}</div>
                            @endif
                        @else
                            <span class="text-green-400">
                                <i class="fas fa-check mr-1"></i> Active
                            </span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-sm text-gray-400">
                        {{ $user->created_at->format('M d, Y') }}
                    </td>
                    <td class="px-4 py-3 text-center">
                        @if(!$user->isAdmin())
                            @if($user->isBanned())
                                <form action="{{ route('admin.users.unban', $user) }}" method="POST" class="inline">
                                    @csrf
                                    <button type="submit" class="bg-green-600 hover:bg-green-700 text-white px-3 py-1 rounded text-sm">
                                        <i class="fas fa-unlock mr-1"></i> Unban
                                    </button>
                                </form>
                            @else
                                <button type="button" onclick="openBanModal({{ $user->id }}, '{{ $user->name }}')"
                                    class="bg-red-600 hover:bg-red-700 text-white px-3 py-1 rounded text-sm">
                                    <i class="fas fa-ban mr-1"></i> Ban
                                </button>
                            @endif
                        @else
                            <span class="text-gray-500 text-sm">-</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="px-4 py-8 text-center text-gray-400">
                        No users found.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-6">
    {{ $users->withQueryString()->links() }}
</div>

<!-- Ban Modal -->
<div id="banModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
    <div class="bg-gray-800 rounded-lg p-6 max-w-md w-full mx-4 border border-gray-700">
        <h3 class="text-xl font-semibold mb-4"><i class="fas fa-ban text-red-400 mr-2"></i> Ban User</h3>
        <p class="text-gray-300 mb-4">Ban <strong id="banUserName"></strong>?</p>
        <form id="banForm" method="POST">
            @csrf
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-300 mb-2">Reason (optional)</label>
                <textarea name="reason" rows="3" class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-3 text-white" placeholder="Why is this user being banned?"></textarea>
            </div>
            <div class="flex gap-3">
                <button type="button" onclick="closeBanModal()" class="flex-1 bg-gray-600 hover:bg-gray-500 text-white py-2 rounded-lg">Cancel</button>
                <button type="submit" class="flex-1 bg-red-600 hover:bg-red-700 text-white py-2 rounded-lg">Ban User</button>
            </div>
        </form>
    </div>
</div>

<script nonce="{{ $cspNonce }}">
function openBanModal(userId, userName) {
    document.getElementById("banForm").action = "/admin/users/" + userId + "/ban";
    document.getElementById("banUserName").textContent = userName;
    document.getElementById("banModal").classList.remove("hidden");
    document.getElementById("banModal").classList.add("flex");
}
function closeBanModal() {
    document.getElementById("banModal").classList.add("hidden");
    document.getElementById("banModal").classList.remove("flex");
}
document.getElementById("banModal").onclick = function(e) { if(e.target===this) closeBanModal(); };
</script>
@endsection
