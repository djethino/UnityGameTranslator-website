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
            'destination' => 'sometimes|in:server,local',
        ]);

        $user = $request->user();
        $translation = Translation::findOrFail($request->translation_id);
        $destination = $request->input('destination', MergePreviewToken::DESTINATION_SERVER);

        // Ownership is what publishing requires — not what comparing requires. A comparison
        // whose result only goes back to the mod writes nothing here, so it is allowed against
        // any translation the caller could already download; that is how a branch gets to
        // compare itself with its Main, which ownership made impossible.
        if ($destination === MergePreviewToken::DESTINATION_LOCAL) {
            if (!$translation->isReadableBy($user)) {
                return response()->json([
                    'error' => 'This translation is not available for comparison.',
                ], 403);
            }
        } elseif ((int) $translation->user_id !== (int) $user->id) {
            return response()->json([
                'error' => 'You can only merge preview your own translations.',
            ], 403);
        }

        // Create the token with local content
        $token = MergePreviewToken::createForUser(
            $user->id,
            $translation->id,
            $request->local_content,
            $destination
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

    /**
     * Hand the arbitrated result back to the mod.
     *
     * GET /api/v1/merge-preview/{token}/result
     *
     * Only exists for comparisons that end in the game: a published one is read back through the
     * ordinary download, since it became the online version. Here nothing was published, so the
     * result lives in the token's own file and this is the only way to it.
     *
     * The token is not a credential on its own — it is checked against the authenticated caller,
     * so holding a leaked token is not enough to read someone's file.
     */
    public function result(Request $request, string $token): JsonResponse
    {
        $mergeToken = MergePreviewToken::findForResult($token);

        if (!$mergeToken || (int) $mergeToken->user_id !== (int) $request->user()->id) {
            return response()->json(['error' => 'Merge result not found or expired.'], 404);
        }

        if (!$mergeToken->isLocalDestination()) {
            return response()->json([
                'error' => 'This comparison was published; download the translation instead.',
            ], 409);
        }

        $path = $mergeToken->getContentFilePath();
        if (!$path) {
            return response()->json(['error' => 'Merge result not found or expired.'], 404);
        }

        return response()->json([
            'translation_id' => $mergeToken->translation_id,
            'content' => json_decode(file_get_contents($path), true),
        ]);
    }
}
