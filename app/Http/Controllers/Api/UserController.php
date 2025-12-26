<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    /**
     * Get the authenticated user's profile.
     *
     * GET /api/v1/me
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'created_at' => $user->created_at->toIso8601String(),
        ]);
    }

    /**
     * Get the authenticated user's translations.
     *
     * GET /api/v1/me/translations
     */
    public function translations(Request $request): JsonResponse
    {
        $user = $request->user();

        $translations = $user->translations()
            ->with('game:id,name,slug,steam_id')
            ->orderBy('updated_at', 'desc')
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
                    ],
                    'source_language' => $t->source_language,
                    'target_language' => $t->target_language,
                    'line_count' => $t->line_count,
                    'type' => $t->type,
                    'status' => $t->status,
                    'vote_count' => $t->vote_count,
                    'download_count' => $t->download_count,
                    'file_hash' => $t->file_hash,
                    'updated_at' => $t->updated_at->toIso8601String(),
                ];
            }),
        ]);
    }
}
