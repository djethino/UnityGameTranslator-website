<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EditSessionToken;
use App\Services\SsePublisher;
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
     * Mod → session push: the local file changed in-game (AI translations,
     * in-game edits) while the session is open. Replaces the session file;
     * the browser picks it up through its state poll. The response carries
     * browser presence so the mod can conclude the page was closed.
     *
     * POST /api/v1/edit-session/{modKey}/update
     * Body: { "content": {...} }
     */
    public function update(Request $request, string $modKey): JsonResponse
    {
        $session = EditSessionToken::findByModKey($modKey);
        if (!$session || !$session->getContentFilePath()) {
            return response()->json(['error' => 'Edit session expired or not found.'], 404);
        }

        $request->validate(['content' => 'required|array']);

        try {
            $contentHash = $session->writeContent($request->input('content'));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => 'Content exceeds the size limit.'], 413);
        }
        $session->touchExpiry();

        return response()->json([
            'content_hash' => $contentHash,
            'browser_seen_seconds_ago' => $session->browserSeenSecondsAgo(),
            'browser_left' => $session->browser_left_at !== null,
        ]);
    }

    /**
     * Keep the session alive while the game runs. A session must only end
     * when the browser page is explicitly closed or the game stops — never
     * on a timer: a player can keep the editor open for hours between
     * edits. The mod pings this every ~10 minutes for the whole play
     * session; the sliding TTL is only a backstop for orphaned sessions
     * (game AND browser both gone without cleanup).
     *
     * POST /api/v1/edit-session/{modKey}/keepalive
     */
    public function keepalive(string $modKey): JsonResponse
    {
        $session = EditSessionToken::findByModKey($modKey);
        if (!$session) {
            return response()->json(['error' => 'Edit session expired or not found.'], 404);
        }

        $session->touchExpiry();

        return response()->json([
            'expires_at' => $session->expires_at->toIso8601String(),
            'browser_left' => $session->browser_left_at !== null,
        ]);
    }

    /**
     * Mod-side session end: the mod stops the session (user clicked Stop,
     * the browser page was closed past the grace period, or the game
     * is shutting down).
     *
     * DELETE /api/v1/edit-session/{modKey}
     */
    public function destroy(string $modKey): JsonResponse
    {
        $session = EditSessionToken::findByModKey($modKey);
        if ($session) {
            SsePublisher::editSessionEnded($session->mod_key);
            $session->deleteWithFile();
        }

        // Idempotent: an already-gone session is a success for the caller
        return response()->json(['ended' => true]);
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
