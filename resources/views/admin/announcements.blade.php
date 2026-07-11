@extends('layouts.app')

@section('title', 'Announcements - Admin')

@section('content')
<div class="max-w-4xl mx-auto">
    <h1 class="text-2xl font-bold text-white mb-6">
        <i class="fas fa-bullhorn mr-2 text-purple-400"></i>Announcements
    </h1>

    @if(session('success'))
        <div class="bg-green-900/50 border border-green-500 text-green-200 rounded-lg p-4 mb-6">
            {{ session('success') }}
        </div>
    @endif

    <!-- New announcement -->
    <div class="bg-gray-800 rounded-lg p-6 border border-gray-700 mb-8">
        <h2 class="text-lg font-semibold text-white mb-4">Publish an announcement</h2>
        <p class="text-sm text-gray-400 mb-4">
            Sent as an in-app notification to every user (and visible to the mod).
            Check "banner" to also pin it at the top of the site for everyone, visitors included.
        </p>

        <form method="POST" action="{{ route('admin.announcements.store') }}" class="space-y-4">
            @csrf
            <div>
                <label class="block text-sm text-gray-300 mb-1" for="ann-title">Title</label>
                <input id="ann-title" name="title" type="text" required maxlength="150" value="{{ old('title') }}"
                       class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-purple-500">
                @error('title')<p class="text-red-400 text-sm mt-1">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="block text-sm text-gray-300 mb-1" for="ann-body">Message</label>
                <textarea id="ann-body" name="body" required maxlength="2000" rows="3"
                          class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-purple-500">{{ old('body') }}</textarea>
                @error('body')<p class="text-red-400 text-sm mt-1">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="block text-sm text-gray-300 mb-1" for="ann-link">Link (optional)</label>
                <input id="ann-link" name="link" type="url" maxlength="500" value="{{ old('link') }}" placeholder="https://..."
                       class="w-full bg-gray-700 border border-gray-600 rounded-lg px-3 py-2 text-white focus:outline-none focus:border-purple-500">
                @error('link')<p class="text-red-400 text-sm mt-1">{{ $message }}</p>@enderror
            </div>
            <label class="flex items-center gap-2 text-sm text-gray-300">
                <input type="checkbox" name="show_banner" value="1" @checked(old('show_banner'))
                       class="rounded bg-gray-700 border-gray-600 text-purple-600 focus:ring-purple-500">
                Also show as a site-wide banner (until expired)
            </label>
            <button type="submit" class="bg-purple-600 hover:bg-purple-700 text-white font-semibold px-6 py-2 rounded-lg transition">
                <i class="fas fa-paper-plane mr-1"></i> Publish
            </button>
        </form>
    </div>

    <!-- History -->
    <div class="bg-gray-800 rounded-lg border border-gray-700 overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-700 text-left text-gray-300">
                <tr>
                    <th class="px-4 py-3">Title</th>
                    <th class="px-4 py-3">Banner</th>
                    <th class="px-4 py-3">Published</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="text-gray-300">
                @forelse($announcements as $announcement)
                <tr class="border-t border-gray-700">
                    <td class="px-4 py-3">
                        <span class="font-medium text-white">{{ $announcement->title }}</span>
                        <p class="text-gray-400 text-xs mt-1 line-clamp-2">{{ $announcement->body }}</p>
                    </td>
                    <td class="px-4 py-3">{!! $announcement->show_banner ? '<i class="fas fa-check text-green-400"></i>' : '—' !!}</td>
                    <td class="px-4 py-3 whitespace-nowrap">{{ $announcement->published_at->format('Y-m-d H:i') }}</td>
                    <td class="px-4 py-3">
                        @if($announcement->isActive())
                            <span class="text-green-400">Active</span>
                        @else
                            <span class="text-gray-500">Expired</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-right">
                        @if($announcement->isActive())
                        <form method="POST" action="{{ route('admin.announcements.expire', $announcement) }}"
                              onsubmit="return confirm('Expire this announcement? The banner disappears; already-sent notifications remain.');">
                            @csrf
                            <button type="submit" class="text-red-400 hover:text-red-300 text-xs">Expire</button>
                        </form>
                        @endif
                    </td>
                </tr>
                @empty
                <tr><td colspan="5" class="px-4 py-6 text-center text-gray-500">No announcements yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $announcements->links() }}</div>
</div>
@endsection
