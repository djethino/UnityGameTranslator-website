<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MergePreviewToken;
use App\Models\Translation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MergePreviewController extends Controller
{
    /**
     * Initialize a merge preview session from the mod.
     *
     * POST /api/v1/merge-preview/init
     * Body: { "translation_id": 123, "local_content": {...} }
     *
     * Returns a token that the mod can use to open the merge preview page in browser.
     */
    public function init(Request $request): JsonResponse
    {
        $request->validate([
            'translation_id' => 'required|integer|exists:translations,id',
            'local_content' => 'required|array',
        ]);

        $user = $request->user();
        $translation = Translation::findOrFail($request->translation_id);

        // Verify the user owns this translation
        if ($translation->user_id !== $user->id) {
            return response()->json([
                'error' => 'You can only merge preview your own translations.',
            ], 403);
        }

        // Create the token with local content
        $token = MergePreviewToken::createForUser(
            $user->id,
            $translation->id,
            $request->local_content
        );

        return response()->json([
            'token' => $token->token,
            'url' => route('translations.merge-preview', [
                'translation' => $translation->id,
                'token' => $token->token,
            ]),
            'expires_at' => $token->expires_at->toIso8601String(),
        ]);
    }
}
