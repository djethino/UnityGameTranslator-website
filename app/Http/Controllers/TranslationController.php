<?php

namespace App\Http\Controllers;

use App\Models\AnalyticsEvent;
use App\Models\AuditLog;
use App\Models\Game;
use App\Models\MergePreviewToken;
use App\Models\Translation;
use App\Services\TranslationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class TranslationController extends Controller
{
    public function create()
    {
        return view('translations.create');
    }

    public function store(Request $request, TranslationService $service)
    {
        $languages = config('languages');

        $request->validate([
            'game_id' => 'nullable|exists:games,id',
            'game_name' => 'required_without:game_id|string|max:255',
            'source_language' => ['required', 'string', 'in:' . implode(',', $languages)],
            'target_language' => ['required', 'string', 'in:' . implode(',', $languages)],
            'status' => 'nullable|in:in_progress,complete', // Optional - branches inherit from Main
            'notes' => 'nullable|string|max:1000',
            'file' => 'required|file|mimes:json|max:102400', // 100MB max
            'game_source' => 'required_without:game_id|string|in:igdb,rawg',
            'game_external_id' => 'required_without:game_id|integer',
            'game_image_url' => 'nullable|url|max:500',
        ]);

        // Find or create game based on API data or existing game_id
        $game = $this->findOrCreateGame($request);

        // Parse and validate content (includes normalization)
        $content = file_get_contents($request->file('file')->getRealPath());
        try {
            $parsed = $service->parseAndValidate($content);
        } catch (\InvalidArgumentException $e) {
            $message = $e->getMessage();
            // Check if it's a JSON-encoded error with details
            $decoded = json_decode($message, true);
            if (is_array($decoded) && isset($decoded['details'])) {
                return back()->withErrors(['file' => $decoded['error'] . ' ' . implode(', ', array_slice($decoded['details'], 0, 3))]);
            }

            return back()->withErrors(['file' => $message]);
        }

        $fileUuid = $parsed['uuid'];
        $userId = auth()->id();

        // Check for existing translation with same UUID (UPDATE case)
        $existingTranslation = $service->findUserTranslation($fileUuid, $userId);

        // Determine ownership and visibility
        $ownership = $service->determineOwnership($fileUuid, $userId);
        $originalTranslation = $existingTranslation ? null : $ownership['original'];
        $visibility = $existingTranslation ? $existingTranslation->visibility : $ownership['visibility'];
        $parentId = $existingTranslation ? $existingTranslation->parent_id : $ownership['parent_id'];

        // Resolve languages
        try {
            $languages = $service->resolveLanguages(
                $request->source_language,
                $request->target_language,
                $existingTranslation,
                $originalTranslation
            );
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['file' => $e->getMessage()]);
        }

        // Calculate type automatically from HVASM stats
        $tagCounts = $parsed['tag_counts'];
        $type = $this->calculateTypeFromStats($tagCounts);

        // Determine status: branches inherit from Main or use 'in_progress'
        // Only Main owners can set/change status
        $isBranch = $visibility === 'branch' || ($existingTranslation && $existingTranslation->visibility === 'branch');
        if ($isBranch) {
            // Branches: inherit status from Main or keep existing
            if ($existingTranslation) {
                $status = $existingTranslation->status;
            } else {
                // New branch: inherit from Main or default to in_progress
                $main = $originalTranslation ?? Translation::where('file_uuid', $fileUuid)
                    ->where('visibility', 'public')
                    ->first();
                $status = $main ? $main->status : 'in_progress';
            }
        } else {
            // Main owner: can set status (default to in_progress if not provided)
            $status = $request->status ?? ($existingTranslation ? $existingTranslation->status : 'in_progress');
        }

        // Store file
        $fileName = $service->storeFile($parsed['normalized_content'], $fileUuid);

        if ($existingTranslation) {
            // UPDATE: Delete old file and update record
            $service->deleteFile($existingTranslation->file_path);

            $existingTranslation->update([
                'game_id' => $game->id,
                'line_count' => $parsed['line_count'],
                'human_count' => $parsed['tag_counts']['human_count'],
                'validated_count' => $parsed['tag_counts']['validated_count'],
                'ai_count' => $parsed['tag_counts']['ai_count'],
                'capture_count' => $parsed['tag_counts']['capture_count'],
                'status' => $status,
                'type' => $type,
                'notes' => $request->notes,
                'file_path' => $fileName,
                'file_hash' => $parsed['file_hash'],
            ]);

            AuditLog::logTranslationUpload($userId, $existingTranslation->id, [
                'game_id' => $game->id,
                'game_name' => $game->name,
                'source_language' => $languages['source'],
                'target_language' => $languages['target'],
                'line_count' => $parsed['line_count'],
                'type' => $type,
                'is_update' => true,
            ], $request);

            return redirect()->route('games.show', $game)
                ->with('success', 'Translation updated successfully!');
        }

        // NEW or BRANCH: Create new translation
        $translation = Translation::create([
            'game_id' => $game->id,
            'user_id' => $userId,
            'parent_id' => $parentId,
            'visibility' => $visibility,
            'source_language' => $languages['source'],
            'target_language' => $languages['target'],
            'line_count' => $parsed['line_count'],
            'human_count' => $parsed['tag_counts']['human_count'],
            'validated_count' => $parsed['tag_counts']['validated_count'],
            'ai_count' => $parsed['tag_counts']['ai_count'],
            'capture_count' => $parsed['tag_counts']['capture_count'],
            'status' => $status,
            'type' => $type,
            'notes' => $request->notes,
            'file_path' => $fileName,
            'file_uuid' => $fileUuid,
            'file_hash' => $parsed['file_hash'],
        ]);

        AuditLog::logTranslationUpload($userId, $translation->id, [
            'game_id' => $game->id,
            'game_name' => $game->name,
            'source_language' => $languages['source'],
            'target_language' => $languages['target'],
            'line_count' => $parsed['line_count'],
            'type' => $type,
            'is_fork' => $parentId !== null,
        ], $request);

        return redirect()->route('games.show', $game)
            ->with('success', 'Translation uploaded successfully!');
    }

    public function download(Translation $translation)
    {
        $translation->incrementDownloads();

        // Track download for analytics
        try {
            $request = request();
            $userAgent = $request->userAgent() ?? '';
            $ip = $request->ip() ?? '0.0.0.0';

            AnalyticsEvent::create([
                'route' => 'translations.download',
                'game_id' => $translation->game_id,
                'country' => null, // Not tracking country for downloads
                'referrer_domain' => AnalyticsEvent::extractReferrerDomain($request->header('Referer')),
                'device' => AnalyticsEvent::detectDevice($userAgent),
                'browser' => AnalyticsEvent::detectBrowser($userAgent),
                'visitor_hash' => AnalyticsEvent::generateVisitorHash($ip, $userAgent, now()->toDateString()),
                'created_at' => now(),
            ]);
        } catch (\Exception $e) {
            // Silently fail - don't break downloads if analytics fails
            report($e);
        }

        return Storage::disk('public')->download(
            $translation->file_path,
            'translations.json'
        );
    }

    /**
     * Check if a UUID exists and return translation info for auto-fill
     *
     * Returns:
     * - type: 'update' (user has a translation with this UUID) or 'fork' (new branch)
     * - translation_id: user's own translation ID (for merge preview)
     * - main_translation_id: main owner's translation ID (for comparison)
     */
    public function checkUuid(Request $request)
    {
        $uuid = $request->get('uuid');

        if (!$uuid) {
            return response()->json(['exists' => false]);
        }

        // Find the main translation with this UUID (first uploaded)
        $mainTranslation = Translation::with(['game', 'user'])
            ->where('file_uuid', $uuid)
            ->orderBy('created_at', 'asc')
            ->first();

        if (!$mainTranslation) {
            return response()->json(['exists' => false]);
        }

        $userId = auth()->id();

        // Check if user has ANY translation with this UUID (main or branch)
        $userTranslation = Translation::where('file_uuid', $uuid)
            ->where('user_id', $userId)
            ->first();

        // type = 'update' if user has a translation, 'fork' if new contribution
        $type = $userTranslation ? 'update' : 'fork';

        return response()->json([
            'exists' => true,
            'type' => $type,
            'original_id' => $mainTranslation->id,
            'translation_id' => $userTranslation?->id, // User's own translation (for merge)
            'main_translation_id' => $mainTranslation->id, // Main for comparison
            'is_main_owner' => $userTranslation && $userTranslation->id === $mainTranslation->id,
            'game' => [
                'id' => $mainTranslation->game->id,
                'name' => $mainTranslation->game->name,
                'image_url' => $mainTranslation->game->image_url,
                'igdb_id' => $mainTranslation->game->igdb_id,
                'rawg_id' => $mainTranslation->game->rawg_id,
            ],
            'source_language' => $mainTranslation->source_language,
            'target_language' => $mainTranslation->target_language,
            'uploader' => $mainTranslation->user->name,
        ]);
    }

    public function myTranslations()
    {
        $translations = auth()->user()->translations()
            ->with(['game', 'forks'])
            ->orderBy('created_at', 'desc')
            ->get();

        return view('translations.mine', compact('translations'));
    }

    public function edit(Translation $translation)
    {
        $user = auth()->user();
        $isAdmin = $user->isAdmin();

        // Main owners or admins can edit
        if (!$isAdmin && ($translation->user_id !== $user->id || !$translation->isMain())) {
            abort(403);
        }

        $translation->load(['game', 'user']);

        // Detect if accessed via admin route (for back button navigation)
        $fromAdmin = request()->routeIs('admin.*');

        return view('translations.edit', compact('translation', 'fromAdmin'));
    }

    public function update(Request $request, Translation $translation)
    {
        $user = auth()->user();
        $isAdmin = $user->isAdmin();

        // Main owners or admins can edit
        if (!$isAdmin && ($translation->user_id !== $user->id || !$translation->isMain())) {
            abort(403);
        }

        $request->validate([
            'status' => 'required|in:in_progress,complete',
            'notes' => 'nullable|string|max:1000',
        ]);

        $translation->update([
            'status' => $request->status,
            'notes' => $request->notes,
        ]);

        // Redirect based on access route, not user role
        if (request()->routeIs('admin.*')) {
            return redirect()->route('admin.translations.show', $translation)
                ->with('success', __('my_translations.updated'));
        }

        return redirect()->route('translations.mine')
            ->with('success', __('my_translations.updated'));
    }

    public function destroy(Translation $translation)
    {
        $user = auth()->user();

        // Allow owner or admin
        if ($translation->user_id !== $user->id && !$user->isAdmin()) {
            abort(403);
        }

        // Delete file
        Storage::disk('public')->delete($translation->file_path);

        // Delete translation (forks will have parent_id set to null via onDelete)
        $translation->delete();

        return redirect()->route('translations.mine')
            ->with('success', 'Translation deleted successfully!');
    }

    /**
     * Show dashboard for a translation (Main or Branch view).
     * Main: sees branches stats, lines to merge
     * Branch: sees Main info, comparison, convert to fork option
     */
    public function dashboard(Translation $translation, TranslationService $service)
    {
        $user = auth()->user();

        // Verify user owns this translation
        if ($translation->user_id !== $user->id) {
            abort(403);
        }

        $translation->load(['game', 'user']);

        $isMain = $translation->visibility === 'public';

        if ($isMain) {
            // Main view: show branches and merge stats
            $branches = Translation::where('file_uuid', $translation->file_uuid)
                ->where('visibility', 'branch')
                ->with('user:id,name')
                ->orderBy('updated_at', 'desc')
                ->get();

            // Load Main content for comparison
            $mainContent = $this->getTranslationContent($translation);

            // Calculate diff stats for each branch
            $branchStats = [];
            foreach ($branches as $branch) {
                $branchContent = $this->getTranslationContent($branch);
                $stats = $this->calculateDiffStats($mainContent, $branchContent);
                $branchStats[$branch->id] = $stats;
            }

            // Total lines to merge (union of all branch differences)
            $totalLinesToMerge = 0;
            foreach ($branchStats as $stats) {
                $totalLinesToMerge += $stats['different'] + $stats['branch_only'];
            }

            return view('translations.dashboard', compact(
                'translation',
                'isMain',
                'branches',
                'branchStats',
                'totalLinesToMerge'
            ));
        } else {
            // Branch view: show Main info and comparison
            $mainTranslation = Translation::where('file_uuid', $translation->file_uuid)
                ->where('visibility', 'public')
                ->with(['user:id,name', 'game'])
                ->first();

            $diffStats = null;
            if ($mainTranslation) {
                $mainContent = $this->getTranslationContent($mainTranslation);
                $branchContent = $this->getTranslationContent($translation);
                $diffStats = $this->calculateDiffStats($mainContent, $branchContent);
            }

            return view('translations.dashboard', compact(
                'translation',
                'isMain',
                'mainTranslation',
                'diffStats'
            ));
        }
    }

    /**
     * Convert a branch to a fork (new UUID, becomes independent Main).
     * User must download the new file and replace their local copy.
     */
    public function convertToFork(Translation $translation, TranslationService $service)
    {
        $user = auth()->user();

        // Verify user owns this translation
        if ($translation->user_id !== $user->id) {
            abort(403);
        }

        // Must be a branch to convert
        if ($translation->visibility !== 'branch') {
            return back()->withErrors(['error' => __('dashboard.not_a_branch')]);
        }

        // Generate new UUID
        $newUuid = \Illuminate\Support\Str::uuid()->toString();

        // Load and update file content
        $path = $translation->getSafeFilePath();
        if (!$path || !file_exists($path)) {
            return back()->withErrors(['error' => __('dashboard.file_not_found')]);
        }

        $rawContent = file_get_contents($path);
        $rawContent = $service->normalizeContent($rawContent);
        $content = json_decode($rawContent, true);

        if (!is_array($content)) {
            return back()->withErrors(['error' => __('dashboard.invalid_file')]);
        }

        // Update UUID in content
        $oldUuid = $content['_uuid'] ?? null;
        $content['_uuid'] = $newUuid;

        // Save file with new UUID
        $jsonFlags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        file_put_contents($path, json_encode($content, $jsonFlags));

        // Update database record
        $translation->update([
            'file_uuid' => $newUuid,
            'visibility' => 'public',
            // Keep parent_id for reference/traceability
        ]);

        // Recalculate hash
        $translation->file_hash = $translation->computeHash();
        $translation->save();

        // Return the file for download
        return Storage::disk('public')->download(
            $translation->file_path,
            'translations.json',
            ['Content-Type' => 'application/json']
        );
    }

    /**
     * Calculate diff stats between main and branch content.
     */
    private function calculateDiffStats(?array $mainContent, ?array $branchContent): array
    {
        if (!$mainContent || !$branchContent) {
            return ['same' => 0, 'different' => 0, 'main_only' => 0, 'branch_only' => 0];
        }

        // Filter out metadata keys
        $mainKeys = array_filter(array_keys($mainContent), fn($k) => !str_starts_with($k, '_'));
        $branchKeys = array_filter(array_keys($branchContent), fn($k) => !str_starts_with($k, '_'));

        $allKeys = array_unique(array_merge($mainKeys, $branchKeys));

        $same = 0;
        $different = 0;
        $mainOnly = 0;
        $branchOnly = 0;

        foreach ($allKeys as $key) {
            $inMain = in_array($key, $mainKeys);
            $inBranch = in_array($key, $branchKeys);

            if ($inMain && $inBranch) {
                $mainValue = $this->extractValue($mainContent[$key]);
                $branchValue = $this->extractValue($branchContent[$key]);

                if ($mainValue === $branchValue) {
                    $same++;
                } else {
                    $different++;
                }
            } elseif ($inMain) {
                $mainOnly++;
            } else {
                $branchOnly++;
            }
        }

        return [
            'same' => $same,
            'different' => $different,
            'main_only' => $mainOnly,
            'branch_only' => $branchOnly,
        ];
    }

    /**
     * Extract value from entry (supports both old string format and new object format).
     */
    private function extractValue($entry): string
    {
        if ($entry === null) {
            return '';
        }
        if (is_array($entry)) {
            return $entry['v'] ?? '';
        }
        return (string) $entry;
    }

    /**
     * Show merge preview for comparing local file with online version.
     * User must own the translation.
     *
     * Supports two access modes:
     * 1. Web upload flow: local content passed via sessionStorage (JS)
     * 2. Mod flow: local content passed via ?token=xxx (from API init)
     */
    public function mergePreview(Request $request, Translation $translation)
    {
        $tokenContent = null;
        $token = $request->query('token');

        // Mode 1: Token-based auth (from mod)
        // Token provides authentication - no web session required
        if ($token) {
            $mergeToken = MergePreviewToken::findValid($token);

            if (!$mergeToken) {
                abort(403, 'Invalid or expired token. Please try again from the mod.');
            }

            if ((int) $mergeToken->translation_id !== (int) $translation->id) {
                abort(403, 'Token does not match this translation.');
            }

            // Verify the token's user owns this translation
            if ((int) $mergeToken->user_id !== (int) $translation->user_id) {
                abort(403, 'You can only preview your own translations.');
            }

            $tokenContent = $mergeToken->local_content;

            // Create web session for the user (so POST save will work)
            Auth::loginUsingId($mergeToken->user_id);

            // Delete token after use (one-time)
            $mergeToken->delete();
        }
        // Mode 2: Web session auth (from website)
        else {
            $user = auth()->user();

            if (!$user) {
                return redirect()->route('login')->with('error', 'Please log in to access merge preview.');
            }

            if ((int) $translation->user_id !== (int) $user->id) {
                abort(403, 'You can only merge your own translations.');
            }
        }

        // Load game and user relationships
        $translation->load(['game', 'user']);

        // Get online content
        $onlineContent = $this->getTranslationContent($translation);
        if ($onlineContent === null) {
            abort(404, 'Translation file not found.');
        }

        // Check if this is a branch (to show Main comparison option)
        $isMainOwner = $translation->visibility === 'public';
        $mainTranslation = null;

        if (!$isMainOwner) {
            // Find the Main translation for comparison
            $mainTranslation = Translation::where('file_uuid', $translation->file_uuid)
                ->where('visibility', 'public')
                ->with('user:id,name')
                ->first();
        }

        $mainContent = null;
        if ($mainTranslation) {
            $mainContent = $this->getTranslationContent($mainTranslation);
        }

        return view('translations.merge-preview', compact(
            'translation',
            'onlineContent',
            'isMainOwner',
            'mainTranslation',
            'mainContent',
            'tokenContent'
        ));
    }

    /**
     * Get translation content from file, excluding metadata keys.
     */
    private function getTranslationContent(Translation $translation): ?array
    {
        $path = $translation->getSafeFilePath();
        if (!$path || !file_exists($path)) {
            return null;
        }

        $rawContent = file_get_contents($path);
        $content = json_decode($rawContent, true);
        if (!is_array($content)) {
            return null;
        }

        return $content;
    }

    /**
     * Apply merge preview selections to the user's translation.
     * Same rules as MergeController::apply for tag handling.
     */
    public function applyMergePreview(Request $request, Translation $translation, TranslationService $service)
    {
        $user = auth()->user();

        // Verify user owns this translation
        if ((int) $translation->user_id !== (int) $user->id) {
            abort(403, 'You can only modify your own translations.');
        }

        // Validate selections
        $request->validate([
            'selections' => 'required|array',
            'selections.*.key' => 'required|string',
            'selections.*.value' => 'present|string',
            'selections.*.tag' => 'required|in:H,A,V,M,S',
            'selections.*.source' => 'required|string', // 'local', 'online', or 'manual'
        ]);

        // Load current file content
        $path = $translation->getSafeFilePath();
        if (!$path || !file_exists($path)) {
            return back()->withErrors(['error' => __('merge_preview.error_file_not_found')]);
        }

        $rawContent = file_get_contents($path);
        $rawContent = $service->normalizeContent($rawContent);
        $content = json_decode($rawContent, true);
        if (!is_array($content)) {
            return back()->withErrors(['error' => __('merge_preview.error_invalid_json')]);
        }

        // Apply selections
        $modifiedCount = 0;
        foreach ($request->selections as $sel) {
            $key = $service->normalizeContent($sel['key']);
            $value = $service->normalizeContent($sel['value']);
            $tag = $sel['tag'];
            $source = $sel['source'];

            // Tag rules (same as MergeController):
            // - M (Mod UI) and S (Skipped) are preserved as-is
            // - Manual edit → becomes H
            // - Tag A selected → becomes V (human validated this AI translation)
            // - Tag H and V stay the same
            if ($tag !== 'M' && $tag !== 'S') {
                if ($source === 'manual') {
                    $tag = 'H';
                } elseif ($tag === 'A') {
                    $tag = 'V';
                }
            }

            $content[$key] = ['v' => $value, 't' => $tag];
            $modifiedCount++;
        }

        // Save the file
        $jsonFlags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        file_put_contents($path, json_encode($content, $jsonFlags));

        // Recalculate counters and hash
        $translation->file_hash = $translation->computeHash();
        $tagCounts = Translation::extractTagCounts($content);
        $translation->human_count = $tagCounts['human_count'];
        $translation->validated_count = $tagCounts['validated_count'];
        $translation->ai_count = $tagCounts['ai_count'];
        $translation->capture_count = $tagCounts['capture_count'];
        $translation->line_count = count(array_filter(
            array_keys($content),
            fn($k) => !str_starts_with($k, '_')
        ));
        $translation->save();

        return redirect()
            ->route('translations.mine')
            ->with('success', __('merge_preview.save_success', ['count' => $modifiedCount]));
    }

    /**
     * Calculate the legacy 'type' field from HVASM tag counts.
     * This is for backwards compatibility - the type is now derived from stats.
     *
     * @param array $tagCounts ['human_count' => int, 'validated_count' => int, 'ai_count' => int]
     * @return string 'human', 'ai_corrected', or 'ai'
     */
    private function calculateTypeFromStats(array $tagCounts): string
    {
        $human = $tagCounts['human_count'] ?? 0;
        $validated = $tagCounts['validated_count'] ?? 0;
        $ai = $tagCounts['ai_count'] ?? 0;
        $total = $human + $validated + $ai;

        if ($total === 0) {
            return 'ai'; // Default for empty/capture-only files
        }

        // If more than 50% is human-translated, it's a human translation
        if ($human > $total * 0.5) {
            return 'human';
        }

        // If there are validated entries, it's been human-reviewed
        if ($validated > 0 || $human > 0) {
            return 'ai_corrected';
        }

        // Otherwise it's pure AI
        return 'ai';
    }

    /**
     * Find or create a game based on existing game_id or external API data
     */
    private function findOrCreateGame(Request $request): Game
    {
        // If we have a direct game_id (from UUID auto-detection), use it
        if ($request->filled('game_id')) {
            return Game::findOrFail($request->input('game_id'));
        }

        // Otherwise, we must have external API data
        $source = $request->input('game_source');
        $externalId = $request->input('game_external_id');
        $imageUrl = $request->input('game_image_url');
        $name = $request->input('game_name');

        $idField = $source === 'igdb' ? 'igdb_id' : 'rawg_id';

        // Try to find existing game by external ID
        $game = Game::where($idField, $externalId)->first();

        if ($game) {
            // Update image if we have a new one
            if ($imageUrl && !$game->image_url) {
                $game->update(['image_url' => $imageUrl]);
            }
            return $game;
        }

        // Create new game with external ID
        return Game::create([
            'name' => $name,
            $idField => $externalId,
            'image_url' => $imageUrl,
        ]);
    }
}
