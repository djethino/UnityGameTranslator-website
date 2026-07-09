<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EditSessionToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Anonymous live-edit sessions (mod → browser).
 *
 * Both endpoints are UNAUTHENTICATED by design: the whole point of the
 * feature is editing a local file without an account. Guards instead of
 * auth: tight throttle on init, a hard content size cap, and unguessable
 * 64-char credentials (browser token + mod key).
 */
class EditSessionController extends Controller
{
    /**
     * Initialize a live edit session from the mod.
     *
     * POST /api/v1/edit-session/init
     * Body: { "content": {...}, "game_name": "...", "source_language": "..", "target_language": ".." }
     *
     * Returns the browser URL (one-time token) and the mod key used for the
     * content download and the SSE stream.
     */
    public function init(Request $request): JsonResponse
    {
        $request->validate([
            'content' => 'required|array',
            'game_name' => 'nullable|string|max:255',
            'source_language' => 'nullable|string|max:16',
            'target_language' => 'nullable|string|max:16',
        ]);

        try {
            $session = EditSessionToken::createSession(
                $request->input('content'),
                $request->input('game_name'),
                $request->input('source_language'),
                $request->input('target_language')
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => 'Content exceeds the size limit.'], 413);
        } catch (\OverflowException $e) {
            return response()->json(['error' => 'Too many active edit sessions, please try again later.'], 503);
        }

        return response()->json([
            'mod_key' => $session->mod_key,
            'url' => route('edit-session.open', ['token' => $session->token]),
            'expires_at' => $session->expires_at->toIso8601String(),
        ]);
    }

    /**
     * Download the current session content (mod side, after a browser save).
     *
     * GET /api/v1/edit-session/{modKey}/content
     */
    public function content(string $modKey)
    {
        $session = EditSessionToken::findByModKey($modKey);
        $path = $session?->getContentFilePath();

        if (!$path) {
            return response()->json(['error' => 'Edit session expired or not found.'], 404);
        }

        return response()->stream(function () use ($path) {
            readfile($path);
        }, 200, [
            'Content-Type' => 'application/json; charset=utf-8',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
