@extends('layouts.app')

@section('title', __('upload.title') . ' - UnityGameTranslator')

@section('content')
<div class="max-w-2xl mx-auto">
    <h1 class="text-3xl font-bold mb-8">
        <i class="fas fa-upload mr-2"></i> {{ __('upload.title') }}
    </h1>

    @if($errors->any())
        <div class="bg-red-900 border border-red-700 text-red-100 px-4 py-3 rounded mb-6">
            <ul class="list-disc list-inside">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form action="{{ route('translations.store') }}" method="POST" enctype="multipart/form-data" id="uploadForm" class="bg-gray-800 rounded-lg p-6">
        @csrf

        <!-- Step 1: File Upload (Drag & Drop) -->
        <div class="mb-6">
            <label class="block text-sm font-medium text-gray-300 mb-2">
                <span class="text-lg">1.</span> {{ __('upload.file_json') }}
            </label>
            <div id="dropZone" class="border-2 border-dashed border-gray-600 rounded-lg p-8 text-center hover:border-purple-500 transition cursor-pointer">
                <input type="file" name="file" id="file" accept=".json" required class="hidden">
                <div id="dropZoneContent">
                    <i class="fas fa-cloud-upload-alt text-5xl text-gray-400 mb-4"></i>
                    <p class="text-gray-300 text-lg">{{ __('upload.drag_drop') }}</p>
                    <p class="text-gray-500 mt-2">{{ __('upload.or_click') }}</p>
                    <p class="text-sm text-gray-600 mt-3">{{ __('upload.max_size') }}</p>
                </div>
                <div id="fileInfo" class="hidden">
                    <i class="fas fa-file-code text-5xl text-purple-400 mb-4"></i>
                    <p id="fileName" class="text-purple-400 text-lg font-medium"></p>
                    <p id="lineCount" class="text-gray-400 mt-1"></p>
                </div>
            </div>
        </div>

        <!-- Detection Info (shown after file upload) -->
        <div id="detectionInfo" class="mb-6 hidden">
            <div id="detectionLoading" class="text-center py-4 hidden">
                <i class="fas fa-spinner fa-spin text-2xl text-purple-400"></i>
                <p class="text-gray-400 mt-2">Checking translation file...</p>
            </div>
            <div id="detectionResult" class="hidden"></div>
        </div>

        <!-- Step 2: Game Selection (hidden if auto-detected) -->
        <div id="gameSection" class="mb-6 hidden">
            <label class="block text-sm font-medium text-gray-300 mb-2">
                <span class="text-lg">2.</span> {{ __('upload.game') }}
            </label>
            <div class="relative">
                <input type="text" id="game_search"
                    placeholder="{{ __('upload.search_game') }}"
                    autocomplete="off"
                    class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-3 text-white focus:ring-purple-500 focus:border-purple-500 pl-12">
                <div id="game_image_preview" class="absolute left-3 top-1/2 -translate-y-1/2 w-6 h-6 rounded overflow-hidden hidden">
                    <img id="game_image_thumb" src="" class="w-full h-full object-cover">
                </div>
                <i id="game_search_icon" class="fas fa-search absolute left-4 top-1/2 -translate-y-1/2 text-gray-400"></i>
                <i id="game_loading" class="fas fa-spinner fa-spin absolute right-4 top-1/2 -translate-y-1/2 text-gray-400 hidden"></i>
            </div>
            <input type="hidden" name="game_id" id="game_id" value="">
            <input type="hidden" name="game_name" id="game_name" value="">
            <input type="hidden" name="game_source" id="game_source" value="">
            <input type="hidden" name="game_external_id" id="game_external_id" value="">
            <input type="hidden" name="game_image_url" id="game_image_url" value="">
            <div id="game_suggestions" class="absolute w-full bg-gray-700 border border-gray-600 rounded-lg mt-1 hidden z-10 max-h-80 overflow-y-auto shadow-xl"></div>
            <p id="game_error" class="text-red-400 text-sm mt-1 hidden">{{ __('upload.please_select_game') }}</p>
        </div>

        <!-- Game Display (shown when auto-detected) -->
        <div id="gameDisplay" class="mb-6 hidden">
            <label class="block text-sm font-medium text-gray-300 mb-2">
                <span class="text-lg">2.</span> {{ __('upload.game') }}
            </label>
            <div class="bg-gray-700 rounded-lg p-4 flex items-center gap-4">
                <img id="display_game_image" src="" class="w-16 h-20 object-cover rounded" onerror="this.style.display='none'">
                <div>
                    <p id="display_game_name" class="font-semibold text-lg"></p>
                    <p class="text-sm text-green-400"><i class="fas fa-check mr-1"></i> {{ __('upload.auto_detected') }}</p>
                </div>
            </div>
        </div>

        <!-- Step 3: Languages -->
        <div id="languageSection" class="mb-6 hidden">
            <label class="block text-sm font-medium text-gray-300 mb-2">
                <span class="text-lg">3.</span> {{ __('upload.languages') }}
            </label>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs text-gray-400 mb-1">{{ __('upload.source_language') }}</label>
                    <select name="source_language" id="source_language" required
                        class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-3 text-white focus:ring-purple-500 focus:border-purple-500">
                        <option value="">{{ __('upload.select') }}</option>
                        @foreach(config('languages') as $lang)
                            <option value="{{ $lang }}">{{ $lang }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-gray-400 mb-1">{{ __('upload.target_language') }}</label>
                    <select name="target_language" id="target_language" required
                        class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-3 text-white focus:ring-purple-500 focus:border-purple-500">
                        <option value="">{{ __('upload.select') }}</option>
                        @foreach(config('languages') as $lang)
                            <option value="{{ $lang }}">{{ $lang }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </div>

        <!-- Step 4: Translation Type -->
        <div id="typeSection" class="mb-6 hidden">
            <label class="block text-sm font-medium text-gray-300 mb-2">
                <span class="text-lg">4.</span> {{ __('upload.translation_type') }}
            </label>
            <div class="grid grid-cols-3 gap-3">
                <label class="flex flex-col items-center p-3 bg-gray-700 rounded-lg cursor-pointer border-2 border-transparent hover:border-purple-500 transition type-option">
                    <input type="radio" name="type" value="ai" checked class="hidden">
                    <i class="fas fa-robot text-2xl text-blue-400 mb-2"></i>
                    <span class="text-sm font-medium">{{ __('upload.type_ai') }}</span>
                    <span class="text-xs text-gray-400">{{ __('upload.type_ai_desc') }}</span>
                </label>
                <label class="flex flex-col items-center p-3 bg-gray-700 rounded-lg cursor-pointer border-2 border-transparent hover:border-purple-500 transition type-option">
                    <input type="radio" name="type" value="ai_corrected" class="hidden">
                    <i class="fas fa-user-edit text-2xl text-purple-400 mb-2"></i>
                    <span class="text-sm font-medium">{{ __('upload.type_ai_human') }}</span>
                    <span class="text-xs text-gray-400">{{ __('upload.type_ai_human_desc') }}</span>
                </label>
                <label class="flex flex-col items-center p-3 bg-gray-700 rounded-lg cursor-pointer border-2 border-transparent hover:border-purple-500 transition type-option">
                    <input type="radio" name="type" value="human" class="hidden">
                    <i class="fas fa-user text-2xl text-green-400 mb-2"></i>
                    <span class="text-sm font-medium">{{ __('upload.type_human') }}</span>
                    <span class="text-xs text-gray-400">{{ __('upload.type_human_desc') }}</span>
                </label>
            </div>
        </div>

        <!-- Step 5: Status -->
        <div id="statusSection" class="mb-6 hidden">
            <label class="block text-sm font-medium text-gray-300 mb-2">
                <span class="text-lg">5.</span> {{ __('upload.status') }}
            </label>
            <div class="flex gap-4">
                <label class="flex items-center cursor-pointer">
                    <input type="radio" name="status" value="in_progress" checked class="mr-2 text-purple-600">
                    <span><i class="fas fa-clock text-yellow-400 mr-1"></i> {{ __('translation.in_progress') }}</span>
                </label>
                <label class="flex items-center cursor-pointer">
                    <input type="radio" name="status" value="complete" class="mr-2 text-purple-600">
                    <span><i class="fas fa-check text-green-400 mr-1"></i> {{ __('translation.complete') }}</span>
                </label>
            </div>
        </div>

        <!-- Step 6: Notes (optional) -->
        <div id="notesSection" class="mb-6 hidden">
            <label class="block text-sm font-medium text-gray-300 mb-2">
                <span class="text-lg">6.</span> {{ __('upload.notes') }}
            </label>
            <textarea name="notes" id="notes" rows="3" maxlength="1000"
                placeholder="{{ __('upload.notes_desc') }}"
                class="w-full bg-gray-700 border border-gray-600 rounded-lg px-4 py-3 text-white focus:ring-purple-500 focus:border-purple-500"></textarea>
            <p class="text-xs text-gray-500 mt-1">{{ __('upload.max_chars') }}</p>
        </div>

        <!-- Hidden field for parent_id (fork) -->
        <input type="hidden" name="parent_id" id="parent_id" value="">

        <!-- Submit -->
        <button type="submit" id="submitBtn" disabled
            class="w-full bg-gray-600 text-gray-400 font-semibold py-3 rounded-lg transition cursor-not-allowed">
            <i class="fas fa-upload mr-2"></i> {{ __('upload.submit') }}
        </button>
    </form>
</div>

<script nonce="{{ $cspNonce }}">
const dropZone = document.getElementById('dropZone');
const fileInput = document.getElementById('file');
const dropZoneContent = document.getElementById('dropZoneContent');
const fileInfo = document.getElementById('fileInfo');
const fileName = document.getElementById('fileName');
const lineCount = document.getElementById('lineCount');
const detectionInfo = document.getElementById('detectionInfo');
const detectionLoading = document.getElementById('detectionLoading');
const detectionResult = document.getElementById('detectionResult');
const gameSection = document.getElementById('gameSection');
const gameDisplay = document.getElementById('gameDisplay');
const languageSection = document.getElementById('languageSection');
const statusSection = document.getElementById('statusSection');
const submitBtn = document.getElementById('submitBtn');

// Form fields
const gameId = document.getElementById('game_id');
const gameName = document.getElementById('game_name');
const gameSource = document.getElementById('game_source');
const gameExternalId = document.getElementById('game_external_id');
const gameImageUrl = document.getElementById('game_image_url');
const gameSearch = document.getElementById('game_search');
const sourceLang = document.getElementById('source_language');
const targetLang = document.getElementById('target_language');
const parentId = document.getElementById('parent_id');

let fileSelected = false;
let gameSelected = false;
let isAutoDetected = false;

// Drag & Drop
dropZone.addEventListener('click', () => fileInput.click());

dropZone.addEventListener('dragover', (e) => {
    e.preventDefault();
    dropZone.classList.add('border-purple-500', 'bg-gray-750');
});

dropZone.addEventListener('dragleave', () => {
    dropZone.classList.remove('border-purple-500', 'bg-gray-750');
});

dropZone.addEventListener('drop', (e) => {
    e.preventDefault();
    dropZone.classList.remove('border-purple-500', 'bg-gray-750');

    const files = e.dataTransfer.files;
    if (files.length > 0 && files[0].name.endsWith('.json')) {
        fileInput.files = files;
        handleFileSelect(files[0]);
    }
});

fileInput.addEventListener('change', (e) => {
    if (e.target.files.length > 0) {
        handleFileSelect(e.target.files[0]);
    }
});

async function handleFileSelect(file) {
    // Show file info
    dropZoneContent.classList.add('hidden');
    fileInfo.classList.remove('hidden');
    fileName.textContent = file.name;

    // Parse JSON to get line count and UUID
    try {
        const content = await file.text();
        const json = JSON.parse(content);

        if (!json._uuid) {
            showError('This file is missing _uuid. It was not generated by the UnityGameTranslator mod.');
            return;
        }

        const lines = Object.keys(json).filter(k => k !== '_uuid').length;
        lineCount.textContent = `${lines.toLocaleString()} translation lines`;

        fileSelected = true;

        // Check UUID
        detectionInfo.classList.remove('hidden');
        detectionLoading.classList.remove('hidden');
        detectionResult.classList.add('hidden');

        const response = await fetch('/api/translations/check-uuid?uuid=' + encodeURIComponent(json._uuid));
        const data = await response.json();

        detectionLoading.classList.add('hidden');

        if (data.exists) {
            showAutoDetected(data);
        } else {
            showNewTranslation();
        }

    } catch (e) {
        showError('Invalid JSON file: ' + e.message);
    }
}

function showError(message) {
    detectionInfo.classList.remove('hidden');
    detectionLoading.classList.add('hidden');
    detectionResult.classList.remove('hidden');
    detectionResult.innerHTML = `
        <div class="bg-red-900/50 border border-red-700 rounded-lg p-4">
            <i class="fas fa-exclamation-circle text-red-400 mr-2"></i>
            <span class="text-red-200">${message}</span>
        </div>
    `;
}

function showAutoDetected(data) {
    isAutoDetected = true;
    gameSelected = true;

    const typeLabel = data.type === 'update'
        ? '<span class="bg-blue-600 px-2 py-1 rounded text-sm"><i class="fas fa-sync mr-1"></i> Update</span> You are updating your existing translation'
        : '<span class="bg-purple-600 px-2 py-1 rounded text-sm"><i class="fas fa-code-branch mr-1"></i> Fork</span> You are forking ' + data.uploader + "'s translation";

    detectionResult.classList.remove('hidden');
    detectionResult.innerHTML = `
        <div class="bg-green-900/30 border border-green-700 rounded-lg p-4">
            <p class="text-green-300 mb-2"><i class="fas fa-magic mr-2"></i> Translation detected!</p>
            <p class="text-gray-300">${typeLabel}</p>
        </div>
    `;

    // Set game info
    gameId.value = data.game.id;
    gameName.value = data.game.name;
    document.getElementById('display_game_name').textContent = data.game.name;
    if (data.game.image_url) {
        document.getElementById('display_game_image').src = data.game.image_url;
    }

    // Set languages
    sourceLang.value = data.source_language;
    targetLang.value = data.target_language;

    // Set parent_id for forks
    if (data.type === 'fork') {
        parentId.value = data.original_id;
    }

    // Show sections
    gameDisplay.classList.remove('hidden');
    gameSection.classList.add('hidden');
    languageSection.classList.remove('hidden');
    document.getElementById('typeSection').classList.remove('hidden');
    statusSection.classList.remove('hidden');
    document.getElementById('notesSection').classList.remove('hidden');

    updateSubmitButton();
}

function showNewTranslation() {
    isAutoDetected = false;

    detectionResult.classList.remove('hidden');
    detectionResult.innerHTML = `
        <div class="bg-blue-900/30 border border-blue-700 rounded-lg p-4">
            <p class="text-blue-300"><i class="fas fa-plus-circle mr-2"></i> New translation! Please select the game below.</p>
        </div>
    `;

    // Show game search
    gameDisplay.classList.add('hidden');
    gameSection.classList.remove('hidden');
    languageSection.classList.remove('hidden');
    document.getElementById('typeSection').classList.remove('hidden');
    statusSection.classList.remove('hidden');
    document.getElementById('notesSection').classList.remove('hidden');

    updateSubmitButton();
}

// Type selection styling
document.querySelectorAll('.type-option').forEach(option => {
    option.addEventListener('click', () => {
        document.querySelectorAll('.type-option').forEach(o => {
            o.classList.remove('border-purple-500', 'bg-gray-600');
            o.classList.add('border-transparent');
        });
        option.classList.remove('border-transparent');
        option.classList.add('border-purple-500', 'bg-gray-600');
    });
});
// Set initial selection
document.querySelector('.type-option').classList.add('border-purple-500', 'bg-gray-600');

// Game search
let searchTimeout = null;
const gameSuggestions = document.getElementById('game_suggestions');
const gameLoading = document.getElementById('game_loading');
const gameSearchIcon = document.getElementById('game_search_icon');
const gameImagePreview = document.getElementById('game_image_preview');
const gameImageThumb = document.getElementById('game_image_thumb');

gameSearch.addEventListener('input', function() {
    const q = this.value;

    // Clear selection
    gameId.value = '';
    gameName.value = '';
    gameSource.value = '';
    gameExternalId.value = '';
    gameImageUrl.value = '';
    gameImagePreview.classList.add('hidden');
    gameSearchIcon.classList.remove('hidden');
    gameSelected = false;
    updateSubmitButton();

    if (q.length < 2) {
        gameSuggestions.classList.add('hidden');
        return;
    }

    clearTimeout(searchTimeout);
    gameLoading.classList.remove('hidden');

    searchTimeout = setTimeout(async () => {
        try {
            const res = await fetch('/api/games/search-external?q=' + encodeURIComponent(q));
            const games = await res.json();

            gameLoading.classList.add('hidden');

            if (games.length === 0) {
                gameSuggestions.innerHTML = '<div class="px-4 py-3 text-gray-400 text-sm">No games found in IGDB/RAWG database.</div>';
                gameSuggestions.classList.remove('hidden');
                return;
            }

            gameSuggestions.innerHTML = '';
            games.forEach(g => {
                const div = document.createElement('div');
                div.className = 'flex items-center gap-3 px-4 py-2 hover:bg-gray-600 cursor-pointer';

                const imgHtml = g.image_url
                    ? `<img src="${g.image_url}" class="w-10 h-14 object-cover rounded flex-shrink-0" onerror="this.style.display='none'">`
                    : '<div class="w-10 h-14 bg-gray-600 rounded flex-shrink-0 flex items-center justify-center"><i class="fas fa-gamepad text-gray-400"></i></div>';

                let sourceLabel = '';
                if (g.source === 'igdb') sourceLabel = '<span class="text-xs bg-purple-600 px-1.5 py-0.5 rounded ml-2">IGDB</span>';
                else if (g.source === 'rawg') sourceLabel = '<span class="text-xs bg-blue-600 px-1.5 py-0.5 rounded ml-2">RAWG</span>';
                else if (g.local_id) sourceLabel = '<span class="text-xs bg-green-600 px-1.5 py-0.5 rounded ml-2">Local</span>';

                div.innerHTML = imgHtml + `<div class="flex-1 min-w-0"><div class="font-medium truncate">${g.name}${sourceLabel}</div></div>`;

                div.addEventListener('click', () => {
                    gameSearch.value = g.name;
                    gameName.value = g.name;
                    gameSource.value = g.source || '';
                    gameExternalId.value = g.id || '';
                    gameImageUrl.value = g.image_url || '';

                    // For local games, set game_id instead
                    if (g.local_id) {
                        gameId.value = g.local_id;
                    }

                    if (g.image_url) {
                        gameImageThumb.src = g.image_url;
                        gameImagePreview.classList.remove('hidden');
                        gameSearchIcon.classList.add('hidden');
                    }

                    gameSuggestions.classList.add('hidden');
                    gameSelected = true;
                    document.getElementById('game_error').classList.add('hidden');
                    updateSubmitButton();
                });

                gameSuggestions.appendChild(div);
            });
            gameSuggestions.classList.remove('hidden');
        } catch (e) {
            gameLoading.classList.add('hidden');
            console.error('Search error:', e);
        }
    }, 300);
});

document.addEventListener('click', (e) => {
    if (!gameSearch.contains(e.target) && !gameSuggestions.contains(e.target)) {
        gameSuggestions.classList.add('hidden');
    }
});

function updateSubmitButton() {
    const languagesSelected = sourceLang.value && targetLang.value;
    const canSubmit = fileSelected && (gameSelected || isAutoDetected) && languagesSelected;

    if (canSubmit) {
        submitBtn.disabled = false;
        submitBtn.classList.remove('bg-gray-600', 'text-gray-400', 'cursor-not-allowed');
        submitBtn.classList.add('bg-purple-600', 'hover:bg-purple-700', 'text-white');
    } else {
        submitBtn.disabled = true;
        submitBtn.classList.add('bg-gray-600', 'text-gray-400', 'cursor-not-allowed');
        submitBtn.classList.remove('bg-purple-600', 'hover:bg-purple-700', 'text-white');
    }
}

// Listen for language changes
sourceLang.addEventListener('change', updateSubmitButton);
targetLang.addEventListener('change', updateSubmitButton);

// Form validation
document.getElementById('uploadForm').addEventListener('submit', (e) => {
    if (!isAutoDetected && !gameSelected) {
        e.preventDefault();
        document.getElementById('game_error').classList.remove('hidden');
        gameSearch.focus();
    }
});
</script>
@endsection
