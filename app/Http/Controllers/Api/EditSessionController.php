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
            // The mod advertises its OWN AI backend so the browser can offer
            // per-line retranslation — no AI credential ever reaches the site
            'ai_available' => 'sometimes|boolean',
            'ai_model' => 'nullable|string|max:100',
        ]);

        try {
            $session = EditSessionToken::createSession(
                $request->input('content'),
                $request->input('game_name'),
                $request->input('source_language'),
                $request->input('target_language'),
                $request->boolean('ai_available'),
                $request->input('ai_model')
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

        $request->validate([
            'content' => 'required|array',
            'ai_available' => 'sometimes|boolean',
            'ai_model' => 'nullable|string|max:100',
        ]);

        try {
            $contentHash = $session->writeContent($request->input('content'));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => 'Content exceeds the size limit.'], 413);
        }
        // The player can toggle AI mid-session: pushes refresh the flag
        if ($request->has('ai_available')) {
            $session->update([
                'ai_available' => $request->boolean('ai_available'),
                'ai_model' => $request->input('ai_model'),
            ]);
        }
        $session->touchGameSeen();

        return response()->json([
            'content_hash' => $contentHash,
            'browser_seen_seconds_ago' => $session->browserSeenSecondsAgo(),
            'browser_left' => $session->browser_left_at !== null,
        ]);
    }

    /**
     * What is going on in a session, without moving the file.
     *
     * ⚠ Exists for a client that cannot hold an SSE stream open. The mod can — it is inside a
     * running game and already keeps one — but a desktop tool sitting behind a corporate proxy
     * often cannot, and those are exactly the setups where a long-lived stream fails silently and
     * looks like "the editor does nothing".
     *
     * ⚠ The alternative was polling the content endpoint, which streams the WHOLE translation
     * file: tens of megabytes on a large game, every few seconds, to learn whether one line
     * changed. This answers that question in a few dozen bytes, and the file is fetched only once
     * the hash says it is worth fetching.
     *
     * Same credential and same throttle as every other mod-side route: the 64-character mod key,
     * which is the only thing that authorises it.
     *
     * GET /api/v1/edit-session/{modKey}/state
     */
    public function state(string $modKey): JsonResponse
    {
        $session = EditSessionToken::findByModKey($modKey);
        if (!$session) {
            return response()->json(['error' => 'Edit session expired or not found.'], 404);
        }

        // ⚠ Deliberately NOT touchGameSeen: asking what happened is not being present, and a tool
        // polling in the background would otherwise hold a session alive for ever on behalf of a
        // window nobody has looked at since yesterday. Keepalive is the route that says "still
        // here", and it is a separate decision made by a caller that means it.
        return response()->json([
            'content_hash' => $session->content_hash,
            'expires_at' => $session->expires_at->toIso8601String(),
            // Whether anyone is at the other end. A session whose page was closed is finished for
            // practical purposes, and the caller can stop following it instead of waiting.
            'browser_seen_seconds_ago' => $session->browserSeenSecondsAgo(),
            'browser_left' => $session->browser_left_at !== null,
            // Edits saved in the browser that this side has not fetched yet.
            'pending_changes' => $session->pending_changes,
        ]);
    }

    /**
     * The answer to a per-line retranslation the browser asked for.
     *
     * ⚠ This exists so the mod does NOT have to write the line to be able to
     * show it. A retranslation is a proposal: it reaches the page as a pending
     * edit, subject to the same Save button as anything the user typed, and
     * cancelled the same way. Before this route the mod had to store the value
     * for it to travel at all — the file being the only thing that moved —
     * which meant the browser's Retranslate silently bypassed Save.
     *
     * ⚠ Deliberately NOT part of the content push: that one carries the WHOLE
     * file (tens of megabytes on a large game) and short-circuits when the
     * hash has not changed — which is precisely the case here, since nothing
     * was written.
     *
     * Kept in the cache, not in a column: it is worth nothing five minutes
     * later, and a session that ends takes it with it.
     *
     * POST /api/v1/edit-session/{modKey}/retranslation
     */
    public function retranslation(Request $request, string $modKey): JsonResponse
    {
        $session = EditSessionToken::findByModKey($modKey);
        if (!$session) {
            return response()->json(['error' => 'Edit session expired or not found.'], 404);
        }

        $request->validate([
            'id' => 'required|string|max:64',
            'key' => 'required|string|max:10000',
            // Absent when the backend gave nothing back — the outcome says which
            'value' => 'nullable|string|max:20000',
            'outcome' => 'required|string|in:replaced,unchanged,failed',
        ]);

        $session->pushRetranslation(
            $request->input('id'),
            $request->input('key'),
            $request->input('value'),
            $request->input('outcome'),
        );
        $session->touchGameSeen();

        return response()->json(['received' => true]);
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

        $session->touchGameSeen();

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

        // Counts as game presence: only the mod holds the key, and it only
        // fetches when it is running and applying a save in-game.
        //
        // This is also the ONLY proof that browser edits reached the player's
        // machine, so it is where the pending counter is cleared — and what
        // makes it safe to collect an abandoned session early.
        $session->touchGameSeen();
        $session->clearPendingChanges();

        return response()->stream(function () use ($path) {
            readfile($path);
        }, 200, [
            'Content-Type' => 'application/json; charset=utf-8',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
