<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Game;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GameController extends Controller
{
    /**
     * Escape LIKE wildcards to prevent SQL injection via wildcard abuse
     */
    private function escapeLike(string $value): string
    {
        return str_replace(['%', '_'], ['\\%', '\\_'], $value);
    }

    /**
     * List/search games.
     *
     * GET /api/v1/games
     * GET /api/v1/games?q=hollow
     * GET /api/v1/games?steam_id=367520
     */
    public function index(Request $request): JsonResponse
    {
        $query = Game::withCount('translations')
            ->having('translations_count', '>', 0); // Only games with translations

        // Search by Steam ID (exact match)
        if ($request->filled('steam_id')) {
            $query->where('steam_id', $request->steam_id);
        }
        // Search by name
        elseif ($request->filled('q')) {
            $search = $this->escapeLike($request->q);
            $query->where('name', 'like', '%' . $search . '%');
        }

        // Filter by games that have translations in a specific language
        if ($request->filled('lang')) {
            $query->whereHas('translations', function ($q) use ($request) {
                $q->where('target_language', $request->lang);
            });
        }

        $games = $query
            ->orderBy('translations_count', 'desc')
            ->limit(50)
            ->get();

        return response()->json([
            'count' => $games->count(),
            'games' => $games->map(function ($game) {
                return [
                    'id' => $game->id,
                    'name' => $game->name,
                    'slug' => $game->slug,
                    'steam_id' => $game->steam_id,
                    'image_url' => $game->image_url,
                    'translations_count' => $game->translations_count,
                ];
            }),
        ]);
    }

    /**
     * Get a specific game with its translations.
     *
     * GET /api/v1/games/{game}
     */
    public function show(Game $game, Request $request): JsonResponse
    {
        $translationsQuery = $game->translations()
            ->with('user:id,name')
            ->where('status', 'complete');

        // Filter by target language
        if ($request->filled('lang')) {
            $translationsQuery->where('target_language', $request->lang);
        }

        $translations = $translationsQuery
            ->orderBy('vote_count', 'desc')
            ->orderBy('download_count', 'desc')
            ->get();

        // Get available languages for this game
        $languages = $game->translations()
            ->where('status', 'complete')
            ->distinct()
            ->pluck('target_language')
            ->sort()
            ->values();

        return response()->json([
            'game' => [
                'id' => $game->id,
                'name' => $game->name,
                'slug' => $game->slug,
                'steam_id' => $game->steam_id,
                'image_url' => $game->image_url,
            ],
            'available_languages' => $languages,
            'translations' => $translations->map(function ($t) {
                return [
                    'id' => $t->id,
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
}
