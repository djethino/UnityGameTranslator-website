<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AnalyticsEvent;
use App\Models\AuditLog;
use App\Models\Game;
use App\Models\Translation;
use App\Services\GameSearchService;
use App\Services\TranslationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class TranslationController extends Controller
{
    /**
     * Search translations by game (steam_id, game name, or game slug) and language.
     *
     * GET /api/v1/translations?steam_id=367520&lang=French
     * GET /api/v1/translations?game=hollow-knight&lang=French
     * GET /api/v1/translations?q=Hollow&lang=French
     */
    public function search(Request $request): JsonResponse
    {
        $query = Translation::with(['game:id,name,slug,steam_id,image_url', 'user:id,name'])
            ->where('visibility', 'public'); // Only public translations (Main/Fork)

        // Filter by Steam ID (exact match)
        if ($request->filled('steam_id')) {
            $query->whereHas('game', function ($q) use ($request) {
                $q->where('steam_id', $request->steam_id);
            });
        }
        // Filter by game slug or ID
        elseif ($request->filled('game')) {
            $gameIdentifier = $request->game;
            $query->whereHas('game', function ($q) use ($gameIdentifier) {
                $q->where('slug', $gameIdentifier)
                    ->orWhere('id', is_numeric($gameIdentifier) ? $gameIdentifier : 0);
            });
        }
        // Search by game name
        elseif ($request->filled('q')) {
            $search = str_replace(['%', '_'], ['\\%', '\\_'], $request->q);
            $query->whereHas('game', function ($q) use ($search) {
                $q->where('name', 'like', '%' . $search . '%');
            });
        }

        // Filter by target language (full name, e.g., "French")
        if ($request->filled('lang')) {
            $query->where('target_language', $request->lang);
        }

        // Filter by source language (full name, e.g., "English")
        if ($request->filled('source_lang')) {
            $query->where('source_language', $request->source_lang);
        }

        // Filter by translation type
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        // Order by votes (best first), then by downloads
        $translations = $query
            ->orderBy('vote_count', 'desc')
            ->orderBy('download_count', 'desc')
            ->limit(50)
            ->get();

        return response()->json([
            'count' => $translations->count(),
            'translations' => $translations->map(function ($t) {
                return [
                    'id' => $t->id,
                    'game' => [
                        'id' => $t->game->id,
                        'name' => $t->game->name,
                        'slug' => $t->game->slug,
                        'steam_id' => $t->game->steam_id,
                        'image_url' => $t->game->image_url,
                    ],
                    'uploader' => $t->user->name,
                    'source_language' => $t->source_language,
                    'target_language' => $t->target_language,
                    'line_count' => $t->line_count,
                    'status' => $t->status,
                    'type' => $t->type,
                    'notes' => $t->notes,
                    'vote_count' => $t->vote_count,
                    'download_count' => $t->download_count,
                    'file_hash' => $t->file_hash,
                    'file_uuid' => $t->file_uuid,
                    'updated_at' => $t->updated_at->toIso8601String(),
                ];
            }),
        ]);
    }

    /**
     * Check if a translation has been updated.
     * Supports ETag/If-None-Match for efficient polling.
     *
     * GET /api/v1/translations/{id}/check
     */
    public function check(Translation $translation, Request $request): JsonResponse
    {
        // Compute hash if not already stored
        if (!$translation->file_hash) {
            $translation->updateHash();
        }

        $etag = '"' . $translation->file_hash . '"';

        // Check If-None-Match header for 304 response
        $ifNoneMatch = $request->header('If-None-Match');
        if ($ifNoneMatch && $ifNoneMatch === $etag) {
            return response()->json(null, 304);
        }

        $response = [
            'id' => $translation->id,
            'file_hash' => $translation->file_hash,
            'line_count' => $translation->line_count,
            'vote_count' => $translation->vote_count,
            'updated_at' => $translation->updated_at->toIso8601String(),
        ];

        // If client sent their current hash, indicate if update is available
        if ($request->filled('hash')) {
            $response['has_update'] = $translation->file_hash !== $request->hash;

            // Debug info: show computed hash and JSON preview
            if ($request->boolean('debug')) {
                $computedHash = $translation->computeHash();
                $response['debug'] = [
                    'stored_hash' => $translation->file_hash,
                    'computed_hash' => $computedHash,
                    'client_hash' => $request->hash,
                    'stored_matches_computed' => $translation->file_hash === $computedHash,
                ];

                // Show JSON preview
                $safePath = $translation->getSafeFilePath();
                if ($safePath && file_exists($safePath)) {
                    $content = file_get_contents($safePath);
                    $data = json_decode($content, true);
                    if (is_array($data)) {
                        $hashData = [];
                        foreach ($data as $key => $value) {
                            if ($key === '_uuid' || !str_starts_with($key, '_')) {
                                $hashData[$key] = $value;
                            }
                        }
                        ksort($hashData);
                        $normalized = json_encode($hashData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        $response['debug']['json_preview'] = substr($normalized, 0, 300);
                        $response['debug']['json_length'] = strlen($normalized);
                        $response['debug']['entry_count'] = count($hashData);
                    }
                }
            }
        }

        return response()
            ->json($response)
            ->header('ETag', $etag)
            ->header('Cache-Control', 'private, must-revalidate');
    }

    /**
     * Check if a UUID exists on the server.
     * Used by the mod to determine if upload is NEW, UPDATE, or FORK.
     *
     * GET /api/v1/translations/check-uuid?uuid={uuid}
     *
     * Returns:
     * - exists: false, role: 'none' → NEW (would become new Main)
     * - exists: true, role: 'main' → UPDATE (user is Main owner)
     * - exists: true, role: 'branch' → UPDATE (user is Branch contributor)
     * - exists: true, role: 'none' → Would become BRANCH (Main exists, different user)
     */
    public function checkUuid(Request $request): JsonResponse
    {
        $request->validate([
            'uuid' => 'required|string|max:36',
        ]);

        $uuid = $request->uuid;
        $userId = $request->user()->id;

        // Check if current user owns a translation with this UUID
        $ownTranslation = Translation::where('file_uuid', $uuid)
            ->where('user_id', $userId)
            ->first();

        if ($ownTranslation) {
            // User has a translation with this UUID
            $role = $ownTranslation->visibility === 'public' ? 'main' : 'branch';
            $branchesCount = $role === 'main'
                ? Translation::where('file_uuid', $uuid)->branches()->count()
                : null;

            return response()->json([
                'exists' => true,
                'role' => $role,
                'translation' => [
                    'id' => $ownTranslation->id,
                    'source_language' => $ownTranslation->source_language,
                    'target_language' => $ownTranslation->target_language,
                    'type' => $ownTranslation->type,
                    'notes' => $ownTranslation->notes,
                    'line_count' => $ownTranslation->line_count,
                    'file_hash' => $ownTranslation->file_hash,
                    'updated_at' => $ownTranslation->updated_at->toIso8601String(),
                ],
                'branches_count' => $branchesCount,
            ]);
        }

        // Check if a Main exists with this UUID (user would become branch)
        $mainTranslation = Translation::where('file_uuid', $uuid)
            ->where('visibility', 'public')
            ->with('user:id,name')
            ->orderBy('created_at', 'asc')
            ->first();

        if ($mainTranslation) {
            // Main exists, user would become a branch if they upload
            return response()->json([
                'exists' => true,
                'role' => 'none', // User has no translation yet
                'main' => [
                    'id' => $mainTranslation->id,
                    'uploader' => $mainTranslation->user->name,
                    'source_language' => $mainTranslation->source_language,
                    'target_language' => $mainTranslation->target_language,
                    'line_count' => $mainTranslation->line_count,
                    'updated_at' => $mainTranslation->updated_at->toIso8601String(),
                ],
            ]);
        }

        // NEW case: UUID doesn't exist → would become new Main
        return response()->json([
            'exists' => false,
            'role' => 'none',
        ]);
    }

    /**
     * Download a translation file.
     * Returns gzipped JSON with ETag for caching.
     *
     * GET /api/v1/translations/{id}/download
     */
    public function download(Translation $translation, Request $request): mixed
    {
        // Visibility check: branches are private to their Main owner
        if ($translation->visibility === 'branch') {
            $main = $translation->getMain();
            $user = $request->user();

            // Must be authenticated AND be the Main owner
            if (!$user || !$main || $main->user_id !== $user->id) {
                return response()->json([
                    'error' => 'Forbidden',
                    'message' => 'Branch translations are only visible to the Main owner',
                ], 403);
            }
        }

        // Compute hash if not stored
        if (!$translation->file_hash) {
            $translation->updateHash();
        }

        $etag = '"' . $translation->file_hash . '"';

        // Check If-None-Match header for 304 response
        $ifNoneMatch = $request->header('If-None-Match');
        if ($ifNoneMatch && $ifNoneMatch === $etag) {
            return response('', 304)->header('ETag', $etag);
        }

        // Increment download counter
        $translation->incrementDownloads();

        // Track download for analytics
        try {
            $userAgent = $request->userAgent() ?? 'UnityGameTranslator';
            $ip = $request->ip() ?? '0.0.0.0';

            AnalyticsEvent::create([
                'route' => 'api.translations.download',
                'game_id' => $translation->game_id,
                'country' => null,
                'referrer_domain' => 'mod', // Mark as mod download
                'device' => 'mod',
                'browser' => AnalyticsEvent::detectBrowser($userAgent),
                'visitor_hash' => AnalyticsEvent::generateVisitorHash($ip, $userAgent, now()->toDateString()),
                'created_at' => now(),
            ]);
        } catch (\Exception $e) {
            report($e);
        }

        // Get validated file path (prevents path traversal)
        $filePath = $translation->getSafeFilePath();

        if (!$filePath || !file_exists($filePath)) {
            return response()->json(['error' => 'File not found'], 404);
        }

        $content = file_get_contents($filePath);

        // Check if client accepts gzip
        $acceptEncoding = $request->header('Accept-Encoding', '');
        if (str_contains($acceptEncoding, 'gzip')) {
            $gzipped = gzencode($content, 9);
            return response($gzipped)
                ->header('Content-Type', 'application/json')
                ->header('Content-Encoding', 'gzip')
                ->header('ETag', $etag)
                ->header('Cache-Control', 'private, max-age=3600');
        }

        return response($content)
            ->header('Content-Type', 'application/json')
            ->header('ETag', $etag)
            ->header('Cache-Control', 'private, max-age=3600');
    }

    /**
     * Upload a translation file (requires authentication).
     *
     * POST /api/v1/translations
     */
    public function store(Request $request, TranslationService $service): JsonResponse
    {
        $languages = config('languages');

        $request->validate([
            'steam_id' => 'nullable|required_without:game_name|string',
            'game_name' => 'nullable|required_without:steam_id|string|max:255',
            'source_language' => ['required', 'string', 'in:' . implode(',', $languages)],
            'target_language' => ['required', 'string', 'in:' . implode(',', $languages)],
            'type' => 'required|in:ai,human,ai_corrected',
            'status' => 'required|in:in_progress,complete',
            'content' => 'required|string|min:2',
            'notes' => 'nullable|string|max:1000',
        ]);

        // Parse and validate content (includes normalization)
        try {
            $parsed = $service->parseAndValidate($request->content);
        } catch (\InvalidArgumentException $e) {
            $message = $e->getMessage();
            // Check if it's a JSON-encoded error with details
            $decoded = json_decode($message, true);
            if (is_array($decoded)) {
                return response()->json($decoded, 422);
            }

            return response()->json(['error' => $message], 422);
        }

        $fileUuid = $parsed['uuid'];
        $userId = $request->user()->id;

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
            return response()->json(['error' => $e->getMessage()], 422);
        }

        // Find or create game
        $game = $this->findOrCreateGame($request);
        if (!$game) {
            return response()->json(['error' => 'Could not find or create game'], 422);
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
                'status' => $request->status,
                'type' => $request->type,
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
                'type' => $request->type,
                'is_update' => true,
            ], $request);

            return response()->json([
                'success' => true,
                'translation' => [
                    'id' => $existingTranslation->id,
                    'file_hash' => $existingTranslation->file_hash,
                    'line_count' => $existingTranslation->line_count,
                    'role' => $service->getRole($existingTranslation->visibility),
                    'web_url' => url("/games/{$game->slug}"),
                ],
            ], 200);
        }

        // NEW or BRANCH: Create new translation
        $translation = Translation::create([
            'game_id' => $game->id,
            'user_id' => $userId,
            'parent_id' => $parentId,
            'source_language' => $languages['source'],
            'target_language' => $languages['target'],
            'line_count' => $parsed['line_count'],
            'human_count' => $parsed['tag_counts']['human_count'],
            'validated_count' => $parsed['tag_counts']['validated_count'],
            'ai_count' => $parsed['tag_counts']['ai_count'],
            'capture_count' => $parsed['tag_counts']['capture_count'],
            'status' => $request->status,
            'type' => $request->type,
            'visibility' => $visibility,
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
            'type' => $request->type,
            'is_fork' => $parentId !== null,
        ], $request);

        return response()->json([
            'success' => true,
            'translation' => [
                'id' => $translation->id,
                'file_hash' => $translation->file_hash,
                'line_count' => $translation->line_count,
                'role' => $service->getRole($visibility),
                'web_url' => url("/games/{$game->slug}"),
            ],
        ], 201);
    }

    /**
     * List branches for a Main translation (owner only).
     *
     * GET /api/v1/translations/{uuid}/branches
     */
    public function branches(Request $request, string $uuid): JsonResponse
    {
        $userId = $request->user()->id;

        // Verify user is the Main owner of this UUID
        $main = Translation::where('file_uuid', $uuid)
            ->where('visibility', 'public')
            ->where('user_id', $userId)
            ->first();

        if (!$main) {
            return response()->json([
                'error' => 'Forbidden',
                'message' => 'You are not the Main owner of this translation',
            ], 403);
        }

        $branches = Translation::where('file_uuid', $uuid)
            ->where('visibility', 'branch')
            ->with('user:id,name')
            ->orderBy('updated_at', 'desc')
            ->get()
            ->map(fn($b) => [
                'id' => $b->id,
                'user' => ['id' => $b->user->id, 'name' => $b->user->name],
                'line_count' => $b->line_count,
                'human_count' => $b->human_count,
                'validated_count' => $b->validated_count,
                'ai_count' => $b->ai_count,
                'updated_at' => $b->updated_at->toIso8601String(),
            ]);

        return response()->json([
            'main_id' => $main->id,
            'branches_count' => $branches->count(),
            'branches' => $branches,
        ]);
    }

    /**
     * Find or create a game from API request.
     * Uses Steam → IGDB → RAWG to get game details.
     */
    private function findOrCreateGame(Request $request): ?Game
    {
        $steamId = $request->filled('steam_id') ? $request->steam_id : null;
        $gameName = $request->filled('game_name') ? $request->game_name : null;

        // Try by Steam ID first
        if ($steamId) {
            $game = Game::where('steam_id', $steamId)->first();
            if ($game) {
                return $game;
            }
        }

        // Try by name (case-insensitive)
        if ($gameName) {
            $game = Game::whereRaw('LOWER(name) = ?', [strtolower($gameName)])->first();
            if ($game) {
                // Update steam_id if we have it now
                if ($steamId && !$game->steam_id) {
                    $game->update(['steam_id' => $steamId]);
                }
                return $game;
            }
        }

        // Game not found - try to get details from external APIs
        if (!$gameName) {
            return null;
        }

        $gameSearchService = app(GameSearchService::class);
        $externalGame = $gameSearchService->findGame($steamId, $gameName);

        if ($externalGame) {
            // Create game with external data
            return Game::create([
                'name' => $externalGame['name'] ?? $gameName,
                'steam_id' => $externalGame['steam_id'] ?? $steamId,
                'image_url' => $externalGame['image_url'] ?? null,
            ]);
        }

        // Fallback: Create basic game entry without external data
        return Game::create([
            'name' => $gameName,
            'steam_id' => $steamId,
        ]);
    }
}
