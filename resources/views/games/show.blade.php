@extends('layouts.app')

@section('title', $game->name . ' Translation - Download ' . $game->name . ' in ' . implode(', ', $targetLanguages->take(3)->toArray()) . (count($targetLanguages) > 3 ? '...' : ''))

@section('description')Free {{ $game->name }} translation download. AI automatic translation available in {{ implode(', ', $targetLanguages->toArray()) }}. {{ count($translationGroups) }} community translations - play {{ $game->name }} in your language!@endsection

@section('og_type', 'article')

@section('og_image', $game->image_url ?? '')

@push('head')
<script type="application/ld+json">
{
    "@@context": "https://schema.org",
    "@@type": "VideoGame",
    "name": "{{ $game->name }}",
    "image": "{{ $game->image_url ?? '' }}",
    "description": "Community translations for {{ $game->name }}",
    "url": "{{ route('games.show', $game) }}",
    "offers": {
        "@@type": "Offer",
        "price": "0",
        "priceCurrency": "USD",
        "availability": "https://schema.org/InStock"
    }
}
</script>
@endpush

@section('content')
<div class="mb-6">
    <a href="{{ route('games.index') }}" class="text-purple-400 hover:text-purple-300">
        <i class="fas fa-arrow-left mr-2"></i> {{ __('games.back') }}
    </a>
</div>

<div class="flex flex-col sm:flex-row sm:justify-between sm:items-start gap-4 mb-8">
    <div class="flex items-center gap-4 sm:gap-6">
        @if($game->image_url)
            <img src="{{ $game->image_url }}" alt="{{ $game->name }}" class="w-20 h-28 sm:w-24 sm:h-32 object-cover rounded-lg shadow-lg flex-shrink-0">
        @else
            <div class="w-20 h-28 sm:w-24 sm:h-32 bg-gray-700 rounded-lg flex items-center justify-center flex-shrink-0">
                <i class="fas fa-gamepad text-2xl sm:text-3xl text-gray-500"></i>
            </div>
        @endif
        <div class="min-w-0">
            <h1 class="text-2xl sm:text-3xl font-bold break-words">{{ $game->name }}</h1>
            <p class="text-gray-400 mt-1 text-sm sm:text-base">{{ trans_choice('home.translations_count', count($translationGroups), ['count' => count($translationGroups)]) }}</p>
        </div>
    </div>
    @auth
        <a href="{{ route('translations.create') }}?game={{ $game->id }}" class="bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded-lg transition text-center sm:text-left flex-shrink-0">
            <i class="fas fa-upload mr-2"></i> {{ __('games.upload_translation') }}
        </a>
    @else
        <a href="{{ route('login') }}?redirect={{ urlencode(url()->current()) }}&action=upload" class="bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded-lg transition text-center sm:text-left flex-shrink-0">
            <i class="fas fa-upload mr-2"></i> {{ __('games.upload_translation') }}
        </a>
    @endauth
</div>

<!-- Filters -->
<form action="{{ route('games.show', $game) }}" method="GET" class="bg-gray-800 rounded-lg p-4 mb-8 flex flex-wrap gap-4 items-end">
    <div>
        <label class="block text-sm text-gray-400 mb-1">{{ __('games.target_language') }}</label>
        <select name="target" class="bg-gray-700 border border-gray-600 rounded px-3 py-2 text-white">
            <option value="">{{ __('games.all') }}</option>
            @foreach($targetLanguages as $lang)
                <option value="{{ $lang }}" {{ request('target') == $lang ? 'selected' : '' }}>{{ $lang }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-sm text-gray-400 mb-1">{{ __('games.source_language') }}</label>
        <select name="source" class="bg-gray-700 border border-gray-600 rounded px-3 py-2 text-white">
            <option value="">{{ __('games.all') }}</option>
            @foreach($sourceLanguages as $lang)
                <option value="{{ $lang }}" {{ request('source') == $lang ? 'selected' : '' }}>{{ $lang }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-sm text-gray-400 mb-1">{{ __('games.type') }}</label>
        <select name="type" class="bg-gray-700 border border-gray-600 rounded px-3 py-2 text-white">
            <option value="">{{ __('games.all') }}</option>
            <option value="ai" {{ request('type') == 'ai' ? 'selected' : '' }}>{{ __('translation.type.ai_short') }}</option>
            <option value="human" {{ request('type') == 'human' ? 'selected' : '' }}>{{ __('translation.type.human_short') }}</option>
            <option value="ai_corrected" {{ request('type') == 'ai_corrected' ? 'selected' : '' }}>{{ __('translation.type.ai_corrected_short') }}</option>
        </select>
    </div>
    <div>
        <label class="block text-sm text-gray-400 mb-1">{{ __('games.sort_by') }}</label>
        <select name="sort" class="bg-gray-700 border border-gray-600 rounded px-3 py-2 text-white">
            <option value="votes" {{ request('sort', 'votes') == 'votes' ? 'selected' : '' }}>{{ __('games.sort.votes') }}</option>
            <option value="date" {{ request('sort') == 'date' ? 'selected' : '' }}>{{ __('games.sort.date') }}</option>
            <option value="lines" {{ request('sort') == 'lines' ? 'selected' : '' }}>{{ __('games.sort.lines') }}</option>
            <option value="downloads" {{ request('sort') == 'downloads' ? 'selected' : '' }}>{{ __('games.sort.downloads') }}</option>
        </select>
    </div>
    <button type="submit" class="bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded">
        <i class="fas fa-filter"></i> {{ __('games.filter') }}
    </button>
</form>

@if(empty($translationGroups))
    <div class="text-center py-12 text-gray-400">
        <p class="text-xl">{{ __('games.no_translations') }}</p>
        @auth
            <a href="{{ route('translations.create') }}?game={{ $game->id }}" class="inline-block mt-4 bg-purple-600 hover:bg-purple-700 text-white px-6 py-3 rounded-lg">
                <i class="fas fa-upload mr-2"></i> {{ __('games.be_first') }}
            </a>
        @else
            <a href="{{ route('login') }}?redirect={{ urlencode(url()->current()) }}&action=upload" class="inline-block mt-4 bg-purple-600 hover:bg-purple-700 text-white px-6 py-3 rounded-lg">
                <i class="fas fa-upload mr-2"></i> {{ __('games.be_first') }}
            </a>
        @endauth
    </div>
@else
    <div class="space-y-6">
        @foreach($translationGroups as $index => $group)
            @php
                $translation = $group['primary'];
                $versions = $group['versions'];
                $forks = $group['forks'];
                $hasVersionHistory = $versions->count() > 1;
                $hasForks = $forks->count() > 0;
            @endphp

            @if($translation)
            <div class="bg-gray-800 rounded-lg border border-gray-700 overflow-hidden" x-data="{ showVersions: false, showForks: false }">
                <!-- Main Translation Card -->
                <div class="p-6">
                    <div class="flex justify-between items-start gap-4">
                        <!-- Left: Info -->
                        <div class="flex-1 min-w-0">
                            <!-- Badges row -->
                            <div class="flex items-center gap-2 mb-3 flex-wrap">
                                <span class="bg-blue-900 text-blue-200 px-3 py-1 rounded text-sm font-medium inline-flex items-center gap-1">
                                    <span>@langflag($translation->source_language)</span>
                                    <span>{{ $translation->source_language }}</span>
                                    <span class="mx-1">→</span>
                                    <span>@langflag($translation->target_language)</span>
                                    <span>{{ $translation->target_language }}</span>
                                </span>

                                @if($translation->type === 'ai')
                                    <span class="bg-blue-800 text-blue-200 px-2 py-1 rounded text-xs" title="{{ __('translation.type.ai') }}">
                                        <i class="fas fa-robot"></i> {{ __('translation.type.ai_short') }}
                                    </span>
                                @elseif($translation->type === 'ai_corrected')
                                    <span class="bg-purple-800 text-purple-200 px-2 py-1 rounded text-xs" title="{{ __('translation.type.ai_corrected') }}">
                                        <i class="fas fa-user-edit"></i> {{ __('translation.type.ai_corrected_short') }}
                                    </span>
                                @elseif($translation->type === 'human')
                                    <span class="bg-green-800 text-green-200 px-2 py-1 rounded text-xs" title="{{ __('translation.type.human') }}">
                                        <i class="fas fa-user"></i> {{ __('translation.type.human_short') }}
                                    </span>
                                @endif

                                @if($translation->isComplete())
                                    <span class="bg-green-900 text-green-200 px-2 py-1 rounded text-xs">
                                        <i class="fas fa-check"></i> {{ __('translation.complete') }}
                                    </span>
                                @else
                                    <span class="bg-yellow-900 text-yellow-200 px-2 py-1 rounded text-xs">
                                        <i class="fas fa-clock"></i> {{ __('translation.in_progress') }}
                                    </span>
                                @endif

                                @if($hasVersionHistory)
                                    <span class="bg-gray-700 text-gray-300 px-2 py-1 rounded text-xs">
                                        <i class="fas fa-layer-group"></i> v{{ $versions->count() }}
                                    </span>
                                @endif
                            </div>

                            <!-- Meta info -->
                            <div class="text-gray-400 text-sm flex items-center gap-4 flex-wrap mb-2">
                                <span class="flex items-center gap-1">
                                    @if($translation->user->avatar)
                                        <img src="{{ $translation->user->avatar }}" class="w-5 h-5 rounded-full">
                                    @endif
                                    <span class="font-medium text-gray-300">{{ $translation->user->name }}</span>
                                </span>
                                <span><i class="fas fa-calendar mr-1"></i> {{ $translation->updated_at->format('M d, Y') }}</span>
                                <span><i class="fas fa-file-alt mr-1"></i> {{ number_format($translation->line_count) }} {{ __('translation.lines', ['count' => '']) }}</span>
                                <span><i class="fas fa-download mr-1"></i> {{ $group['total_downloads'] }}</span>
                            </div>

                            <!-- Notes -->
                            @if($translation->notes)
                                <div class="mt-3 text-sm text-gray-400 bg-gray-750 rounded p-3 border-l-2 border-purple-500">
                                    <i class="fas fa-quote-left text-purple-500 mr-2"></i>{{ $translation->notes }}
                                </div>
                            @endif
                        </div>

                        <!-- Right: Actions -->
                        <div class="flex flex-col items-end gap-3">
                            <!-- Vote buttons -->
                            <div class="flex items-center gap-1 bg-gray-700 rounded-lg px-3 py-2" id="vote-container-{{ $translation->id }}">
                                @auth
                                    @php $userVote = $translation->userVote(); @endphp
                                    <button type="button"
                                        onclick="vote({{ $translation->id }}, 1)"
                                        class="vote-btn p-1 rounded hover:bg-gray-600 transition {{ $userVote && $userVote->value === 1 ? 'text-green-400' : 'text-gray-400' }}"
                                        id="upvote-{{ $translation->id }}"
                                        title="{{ __('translation.upvote') }}">
                                        <i class="fas fa-arrow-up text-lg"></i>
                                    </button>
                                @else
                                    <a href="{{ route('login') }}?redirect={{ urlencode(url()->current()) }}&action=vote" class="p-1 text-gray-500 hover:text-green-400 transition" title="{{ __('translation.login_to_vote') }}"><i class="fas fa-arrow-up text-lg"></i></a>
                                @endauth
                                <span class="text-lg font-bold min-w-[2.5rem] text-center {{ $translation->vote_count > 0 ? 'text-green-400' : ($translation->vote_count < 0 ? 'text-red-400' : 'text-gray-400') }}" id="vote-count-{{ $translation->id }}">
                                    {{ $translation->vote_count >= 0 ? '+' : '' }}{{ $translation->vote_count }}
                                </span>
                                @auth
                                    <button type="button"
                                        onclick="vote({{ $translation->id }}, -1)"
                                        class="vote-btn p-1 rounded hover:bg-gray-600 transition {{ $userVote && $userVote->value === -1 ? 'text-red-400' : 'text-gray-400' }}"
                                        id="downvote-{{ $translation->id }}"
                                        title="{{ __('translation.downvote') }}">
                                        <i class="fas fa-arrow-down text-lg"></i>
                                    </button>
                                @else
                                    <a href="{{ route('login') }}?redirect={{ urlencode(url()->current()) }}&action=vote" class="p-1 text-gray-500 hover:text-red-400 transition" title="{{ __('translation.login_to_vote') }}"><i class="fas fa-arrow-down text-lg"></i></a>
                                @endauth
                            </div>

                            <!-- Action buttons -->
                            <div class="flex gap-2">
                                @auth
                                    @if(auth()->user()->isAdmin())
                                        <a href="{{ route('admin.translations.edit', $translation) }}" class="bg-yellow-600 hover:bg-yellow-700 text-white px-3 py-2 rounded text-sm" title="{{ __('translation.edit') }}">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                    @endif
                                    <button type="button" onclick="openReportModal({{ $translation->id }})" class="bg-gray-700 hover:bg-gray-600 text-white px-3 py-2 rounded text-sm" title="{{ __('translation.report') }}">
                                        <i class="fas fa-flag"></i>
                                    </button>
                                @else
                                    <a href="{{ route('login') }}?redirect={{ urlencode(url()->current()) }}&action=report" class="bg-gray-700 hover:bg-gray-600 text-white px-3 py-2 rounded text-sm" title="{{ __('translation.report') }}">
                                        <i class="fas fa-flag"></i>
                                    </a>
                                @endauth
                                <a href="{{ route('translations.download', $translation) }}" class="bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded font-medium">
                                    <i class="fas fa-download mr-1"></i> {{ __('translation.download') }}
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Expandable Sections Toggle -->
                @if($hasVersionHistory || $hasForks)
                <div class="border-t border-gray-700 px-6 py-3 bg-gray-750 flex gap-4">
                    @if($hasVersionHistory)
                        <button @click="showVersions = !showVersions" class="flex items-center gap-2 text-sm text-gray-400 hover:text-white transition">
                            <i class="fas fa-history"></i>
                            <span>{{ $versions->count() - 1 }} older version{{ $versions->count() - 1 > 1 ? 's' : '' }}</span>
                            <i class="fas fa-chevron-down text-xs transition-transform" :class="showVersions && 'rotate-180'"></i>
                        </button>
                    @endif
                    @if($hasForks)
                        <button @click="showForks = !showForks" class="flex items-center gap-2 text-sm text-gray-400 hover:text-white transition">
                            <i class="fas fa-code-branch"></i>
                            <span>{{ $forks->count() }} community fork{{ $forks->count() > 1 ? 's' : '' }}</span>
                            <i class="fas fa-chevron-down text-xs transition-transform" :class="showForks && 'rotate-180'"></i>
                        </button>
                    @endif
                </div>
                @endif

                <!-- Version History (Expandable) -->
                @if($hasVersionHistory)
                <div x-show="showVersions" x-collapse class="border-t border-gray-700">
                    <div class="px-6 py-4 bg-gray-850">
                        <h4 class="text-sm font-medium text-gray-400 mb-3">
                            <i class="fas fa-history mr-2"></i> Version History
                        </h4>
                        <div class="space-y-2">
                            @foreach($versions->skip(1) as $vIndex => $version)
                                <div class="flex items-center justify-between p-3 bg-gray-800 rounded-lg border border-gray-700">
                                    <div class="flex items-center gap-4 text-sm">
                                        <span class="bg-gray-700 text-gray-400 px-2 py-1 rounded text-xs">v{{ $versions->count() - $vIndex - 1 }}</span>
                                        <span class="text-gray-400">{{ $version->created_at->format('M d, Y') }}</span>
                                        <span class="text-gray-500">{{ number_format($version->line_count) }} lines</span>
                                        @if($version->type)
                                            <span class="text-gray-500">
                                                @if($version->type === 'ai')
                                                    <i class="fas fa-robot"></i>
                                                @elseif($version->type === 'human')
                                                    <i class="fas fa-user"></i>
                                                @else
                                                    <i class="fas fa-user-edit"></i>
                                                @endif
                                            </span>
                                        @endif
                                        @if($version->notes)
                                            <span class="text-gray-500 truncate max-w-xs" title="{{ $version->notes }}">{{ Str::limit($version->notes, 50) }}</span>
                                        @endif
                                    </div>
                                    <div class="flex items-center gap-3">
                                        <span class="text-sm {{ $version->vote_count > 0 ? 'text-green-400' : ($version->vote_count < 0 ? 'text-red-400' : 'text-gray-500') }}">
                                            {{ $version->vote_count >= 0 ? '+' : '' }}{{ $version->vote_count }}
                                        </span>
                                        <a href="{{ route('translations.download', $version) }}" class="text-purple-400 hover:text-purple-300">
                                            <i class="fas fa-download"></i>
                                        </a>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
                @endif

                <!-- Community Forks (Expandable) -->
                @if($hasForks)
                <div x-show="showForks" x-collapse class="border-t border-gray-700">
                    <div class="px-6 py-4 bg-gray-850">
                        <h4 class="text-sm font-medium text-gray-400 mb-3">
                            <i class="fas fa-code-branch mr-2"></i> Community Forks
                        </h4>
                        <div class="space-y-2">
                            @foreach($forks as $fork)
                                <div class="flex items-center justify-between p-3 bg-gray-800 rounded-lg border border-gray-700">
                                    <div class="flex items-center gap-4 text-sm">
                                        <span class="flex items-center gap-1">
                                            @if($fork->user->avatar)
                                                <img src="{{ $fork->user->avatar }}" class="w-5 h-5 rounded-full">
                                            @endif
                                            <span class="text-gray-300">{{ $fork->user->name }}</span>
                                        </span>
                                        <span class="text-gray-500">{{ $fork->created_at->format('M d, Y') }}</span>
                                        <span class="text-gray-500">{{ number_format($fork->line_count) }} lines</span>
                                        @if($fork->type)
                                            @if($fork->type === 'ai')
                                                <span class="bg-blue-800 text-blue-200 px-1.5 py-0.5 rounded text-xs"><i class="fas fa-robot"></i></span>
                                            @elseif($fork->type === 'human')
                                                <span class="bg-green-800 text-green-200 px-1.5 py-0.5 rounded text-xs"><i class="fas fa-user"></i></span>
                                            @else
                                                <span class="bg-purple-800 text-purple-200 px-1.5 py-0.5 rounded text-xs"><i class="fas fa-user-edit"></i></span>
                                            @endif
                                        @endif
                                        @if($fork->notes)
                                            <span class="text-gray-500 truncate max-w-xs" title="{{ $fork->notes }}">{{ Str::limit($fork->notes, 50) }}</span>
                                        @endif
                                    </div>
                                    <div class="flex items-center gap-3">
                                        <!-- Fork vote -->
                                        <div class="flex items-center gap-1 text-sm" id="vote-container-{{ $fork->id }}">
                                            @auth
                                                @php $forkUserVote = $fork->userVote(); @endphp
                                                <button type="button" onclick="vote({{ $fork->id }}, 1)"
                                                    class="vote-btn hover:text-green-400 transition {{ $forkUserVote && $forkUserVote->value === 1 ? 'text-green-400' : 'text-gray-500' }}"
                                                    id="upvote-{{ $fork->id }}">
                                                    <i class="fas fa-arrow-up"></i>
                                                </button>
                                            @else
                                                <a href="{{ route('login') }}?redirect={{ urlencode(url()->current()) }}&action=vote" class="text-gray-500 hover:text-green-400 transition">
                                                    <i class="fas fa-arrow-up"></i>
                                                </a>
                                            @endauth
                                            <span class="{{ $fork->vote_count > 0 ? 'text-green-400' : ($fork->vote_count < 0 ? 'text-red-400' : 'text-gray-500') }}" id="vote-count-{{ $fork->id }}">
                                                {{ $fork->vote_count >= 0 ? '+' : '' }}{{ $fork->vote_count }}
                                            </span>
                                            @auth
                                                <button type="button" onclick="vote({{ $fork->id }}, -1)"
                                                    class="vote-btn hover:text-red-400 transition {{ $forkUserVote && $forkUserVote->value === -1 ? 'text-red-400' : 'text-gray-500' }}"
                                                    id="downvote-{{ $fork->id }}">
                                                    <i class="fas fa-arrow-down"></i>
                                                </button>
                                            @else
                                                <a href="{{ route('login') }}?redirect={{ urlencode(url()->current()) }}&action=vote" class="text-gray-500 hover:text-red-400 transition">
                                                    <i class="fas fa-arrow-down"></i>
                                                </a>
                                            @endauth
                                        </div>
                                        @auth
                                            @if(auth()->user()->isAdmin())
                                                <a href="{{ route('admin.translations.edit', $fork) }}" class="text-yellow-400 hover:text-yellow-300">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                            @endif
                                            <button type="button" onclick="openReportModal({{ $fork->id }})" class="text-gray-500 hover:text-gray-300">
                                                <i class="fas fa-flag"></i>
                                            </button>
                                        @else
                                            <a href="{{ route('login') }}?redirect={{ urlencode(url()->current()) }}&action=report" class="text-gray-500 hover:text-gray-300">
                                                <i class="fas fa-flag"></i>
                                            </a>
                                        @endauth
                                        <a href="{{ route('translations.download', $fork) }}" class="text-purple-400 hover:text-purple-300">
                                            <i class="fas fa-download"></i>
                                        </a>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
                @endif
            </div>
            @endif
        @endforeach
    </div>
@endif

@auth
<!-- Report Modal -->
<div id="reportModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
    <div class="bg-gray-800 rounded-lg p-6 max-w-md w-full mx-4 border border-gray-700">
        <h3 class="text-xl font-semibold mb-4"><i class="fas fa-flag mr-2"></i> {{ __('report.title') }}</h3>
        <form id="reportForm" method="POST">
            @csrf
            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-300 mb-2">{{ __('report.reason') }}</label>
                <textarea name="reason" rows="4" required class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-3 text-white" placeholder="{{ __('report.placeholder') }}"></textarea>
            </div>
            <div class="flex gap-3">
                <button type="button" onclick="closeReportModal()" class="flex-1 bg-gray-600 hover:bg-gray-500 text-white py-2 rounded-lg">{{ __('report.cancel') }}</button>
                <button type="submit" class="flex-1 bg-red-600 hover:bg-red-700 text-white py-2 rounded-lg">{{ __('report.submit') }}</button>
            </div>
        </form>
    </div>
</div>
<script nonce="{{ $cspNonce }}">
function openReportModal(id) {
    document.getElementById("reportForm").action = "/report/" + id;
    document.getElementById("reportModal").classList.remove("hidden");
    document.getElementById("reportModal").classList.add("flex");
}
function closeReportModal() {
    document.getElementById("reportModal").classList.add("hidden");
    document.getElementById("reportModal").classList.remove("flex");
}
document.getElementById("reportModal").onclick = function(e) { if(e.target===this) closeReportModal(); };

async function vote(translationId, value) {
    try {
        const response = await fetch(`/vote/${translationId}`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                'Accept': 'application/json'
            },
            body: JSON.stringify({ value: value })
        });

        if (!response.ok) throw new Error('Vote failed');

        const data = await response.json();

        // Update vote count display
        const countEl = document.getElementById(`vote-count-${translationId}`);
        const count = data.vote_count;
        countEl.textContent = (count >= 0 ? '+' : '') + count;
        countEl.className = countEl.className.replace(/text-(green|red|gray)-\d+/g, '');
        countEl.classList.add(count > 0 ? 'text-green-400' : (count < 0 ? 'text-red-400' : 'text-gray-400'));

        // Update button states
        const upvoteBtn = document.getElementById(`upvote-${translationId}`);
        const downvoteBtn = document.getElementById(`downvote-${translationId}`);

        if (upvoteBtn) {
            upvoteBtn.className = upvoteBtn.className.replace(/text-(green|gray)-\d+/g, '');
            upvoteBtn.classList.add(data.user_vote === 1 ? 'text-green-400' : 'text-gray-400');
        }
        if (downvoteBtn) {
            downvoteBtn.className = downvoteBtn.className.replace(/text-(red|gray)-\d+/g, '');
            downvoteBtn.classList.add(data.user_vote === -1 ? 'text-red-400' : 'text-gray-400');
        }
    } catch (error) {
        console.error('Vote error:', error);
    }
}
</script>
@endauth

<style>
    .bg-gray-750 { background-color: rgb(42, 48, 60); }
    .bg-gray-850 { background-color: rgb(30, 34, 43); }
</style>
@endsection
