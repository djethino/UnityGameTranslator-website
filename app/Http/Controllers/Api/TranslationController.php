<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AnalyticsEvent;
use App\Models\AuditLog;
use App\Models\Game;
use App\Models\Translation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class TranslationController extends Controller
{
    /**
     * Search translations by game (steam_id, game name, or game slug) and language.
     *
     * GET /api/v1/translations?steam_id=367520&lang=fr
     * GET /api/v1/translations?game=hollow-knight&lang=fr
     * GET /api/v1/translations?q=Hollow&lang=fr
     */
    public function search(Request $request): JsonResponse
    {
        $query = Translation::with(['game:id,name,slug,steam_id,image_url', 'user:id,name'])
            ->where('status', 'complete'); // Only show complete translations

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

        // Filter by target language
        if ($request->filled('lang')) {
            $query->where('target_language', $request->lang);
        }

        // Filter by source language
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
                    'type' => $t->type,
                    'vote_count' => $t->vote_count,
                    'download_count' => $t->download_count,
                    'file_hash' => $t->file_hash,
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
        }

        return response()
            ->json($response)
            ->header('ETag', $etag)
            ->header('Cache-Control', 'private, must-revalidate');
    }

    /**
     * Download a translation file.
     * Returns gzipped JSON with ETag for caching.
     *
     * GET /api/v1/translations/{id}/download
     */
    public function download(Translation $translation, Request $request): mixed
    {
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
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'steam_id' => 'required_without:game_name|string',
            'game_name' => 'required_without:steam_id|string|max:255',
            'source_language' => 'required|string|max:10',
            'target_language' => 'required|string|max:10',
            'type' => 'required|in:ai,human,ai_corrected',
            'status' => 'required|in:in_progress,complete',
            'content' => 'required|string|min:2',
            'notes' => 'nullable|string|max:1000',
        ]);

        // Parse and validate JSON content
        $json = json_decode($request->content, true, 2);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return response()->json([
                'error' => 'Invalid JSON content: ' . json_last_error_msg(),
            ], 422);
        }

        if (!is_array($json)) {
            return response()->json([
                'error' => 'Content must be a JSON object',
            ], 422);
        }

        // UUID is required
        if (!isset($json['_uuid']) || !is_string($json['_uuid'])) {
            return response()->json([
                'error' => 'Missing _uuid in translation file',
            ], 422);
        }

        $fileUuid = $json['_uuid'];

        // Count lines (exclude metadata keys)
        $lineCount = count(array_filter(array_keys($json), fn($k) => !str_starts_with($k, '_')));

        // Find or create game
        $game = $this->findOrCreateGame($request);

        if (!$game) {
            return response()->json([
                'error' => 'Could not find or create game',
            ], 422);
        }

        // Check for existing translation with same UUID (update vs fork)
        $parentId = null;
        $existingTranslation = Translation::where('file_uuid', $fileUuid)
            ->orderBy('created_at', 'asc')
            ->first();

        if ($existingTranslation) {
            if ((int) $existingTranslation->user_id !== (int) $request->user()->id) {
                // Different user = fork
                $parentId = $existingTranslation->id;
            }
            // Same user = update (will create new version)
        }

        // Store file
        $fileName = 'translations/' . uniqid() . '_' . $fileUuid . '.json';
        Storage::disk('public')->put($fileName, $request->content);

        // Compute hash
        $fileHash = hash('sha256', $request->content);

        // Create translation
        $translation = Translation::create([
            'game_id' => $game->id,
            'user_id' => $request->user()->id,
            'parent_id' => $parentId,
            'source_language' => $request->source_language,
            'target_language' => $request->target_language,
            'line_count' => $lineCount,
            'status' => $request->status,
            'type' => $request->type,
            'notes' => $request->notes,
            'file_path' => $fileName,
            'file_uuid' => $fileUuid,
            'file_hash' => $fileHash,
        ]);

        // Log translation upload
        AuditLog::logTranslationUpload($request->user()->id, $translation->id, [
            'game_id' => $game->id,
            'game_name' => $game->name,
            'source_language' => $request->source_language,
            'target_language' => $request->target_language,
            'line_count' => $lineCount,
            'type' => $request->type,
            'is_fork' => $parentId !== null,
        ], $request);

        return response()->json([
            'success' => true,
            'translation' => [
                'id' => $translation->id,
                'file_hash' => $translation->file_hash,
                'line_count' => $translation->line_count,
                'is_fork' => $parentId !== null,
                'web_url' => url("/games/{$game->slug}"),
            ],
        ], 201);
    }

    /**
     * Find or create a game from API request
     */
    private function findOrCreateGame(Request $request): ?Game
    {
        // Try by Steam ID first
        if ($request->filled('steam_id')) {
            $game = Game::where('steam_id', $request->steam_id)->first();

            if ($game) {
                return $game;
            }

            // Create with Steam ID
            if ($request->filled('game_name')) {
                return Game::create([
                    'name' => $request->game_name,
                    'steam_id' => $request->steam_id,
                ]);
            }
        }

        // Try by name
        if ($request->filled('game_name')) {
            $game = Game::where('name', $request->game_name)->first();

            if ($game) {
                // Update steam_id if we have it now
                if ($request->filled('steam_id') && !$game->steam_id) {
                    $game->update(['steam_id' => $request->steam_id]);
                }
                return $game;
            }

            // Create new game
            return Game::create([
                'name' => $request->game_name,
                'steam_id' => $request->steam_id,
            ]);
        }

        return null;
    }
}
