<?php

namespace App\Http\Controllers;

use App\Models\EditSessionToken;
use App\Services\SsePublisher;
use App\Services\TranslationService;
use Illuminate\Http\Request;

/**
 * Browser side of the anonymous live-edit session (see EditSessionToken).
 *
 * No auth() anywhere: the browser is authenticated by the one-time token,
 * then by its (guest) web session. Save is AJAX so the page stays open for
 * repeated edit → save → check-in-game cycles.
 */
class EditSessionController extends Controller
{
    private const SESSION_KEY = 'edit_session_token';

    /**
     * Entry point from the mod: consume the one-time token, bind the
     * session, and redirect to a token-less URL (the token must not linger
     * in browser history, logs or Referer headers).
     *
     * GET /edit-session/{token}
     */
    public function open(string $token)
    {
        $session = EditSessionToken::findValidByToken($token);

        if (!$session) {
            abort(403, 'Invalid or expired edit session link. Please try again from the mod.');
        }

        $session->markConsumed();
        // Fresh session id at the trust boundary (anti session fixation),
        // same spirit as the login-based merge-preview flow
        session()->regenerate();
        session([self::SESSION_KEY => $session->token]);

        return redirect()->route('edit-session.show', [], 303);
    }

    /**
     * The edit page (session-bound, token-less URL).
     *
     * GET /edit-session
     */
    public function show()
    {
        $session = $this->currentSession();

        if (!$session) {
            return view('edit-session.expired');
        }

        if ($session->touchBrowserSeen()) {
            SsePublisher::editSessionBrowserJoined($session->mod_key);
        }

        return view('edit-session.show', ['editSession' => $session]);
    }

    /**
     * Lightweight state poll: current content hash + presence heartbeat.
     * The page calls this every ~10s; a hash change means the mod pushed an
     * update and the page should refetch the data.
     *
     * GET /edit-session-state
     */
    public function state()
    {
        $session = $this->currentSession();

        if (!$session) {
            return response()->json(['error' => __('edit_session.error_expired')], 410);
        }

        if ($session->touchBrowserSeen()) {
            SsePublisher::editSessionBrowserJoined($session->mod_key);
        }

        return response()->json(['content_hash' => $session->content_hash]);
    }

    /**
     * pagehide beacon: the browser is leaving (close, navigation or refresh).
     * Marks the session as away and tells the mod, which applies its grace
     * period before ending the session. CSRF-exempt (sendBeacon cannot send
     * a token) — see bootstrap/app.php for why that is safe.
     *
     * POST /edit-session-leave
     */
    public function leave()
    {
        $session = $this->currentSession();

        if ($session) {
            $session->markBrowserLeft();
            SsePublisher::editSessionBrowserLeft($session->mod_key);
        }

        return response()->noContent();
    }

    /**
     * Stream the session content as JSON (page JS; files can be tens of MB).
     *
     * GET /edit-session/data
     */
    public function data()
    {
        $session = $this->currentSession();
        $path = $session?->getContentFilePath();

        if (!$path) {
            abort(410, 'Edit session expired. Please restart it from the mod.');
        }

        return response()->stream(function () use ($path) {
            echo '{"content":';
            readfile($path);
            echo '}';
        }, 200, [
            'Content-Type' => 'application/json; charset=utf-8',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /**
     * Apply edits to the session file and signal the mod over SSE.
     * Same selections wire format and tag rules as applyMergePreview.
     *
     * POST /edit-session/save (AJAX)
     */
    public function save(Request $request, TranslationService $service)
    {
        $session = $this->currentSession();
        $path = $session?->getContentFilePath();

        if (!$path) {
            return response()->json(['error' => __('edit_session.error_expired')], 410);
        }

        $request->validate([
            'selections' => 'required|array|max:5000',
            'selections.*.key' => 'required|string',
            'selections.*.value' => 'present|string',
            'selections.*.tag' => 'required|in:H,A,V,M,S',
            'selections.*.source' => 'required|string', // 'manual' or 'local' (tag change)
        ]);

        $rawContent = file_get_contents($path);
        $rawContent = $service->normalizeContent($rawContent);
        $content = json_decode($rawContent, true);
        if (!is_array($content)) {
            return response()->json(['error' => __('merge_preview.error_invalid_json')], 422);
        }

        $modifiedCount = 0;
        foreach ($request->selections as $sel) {
            $key = $service->normalizeContent($sel['key']);

            // Metadata keys (_uuid, _game, _source, ...) must never be written
            // through selections: the mod reloads this file verbatim and a
            // forged {v,t} object there would corrupt its lineage/sync state.
            // The page filters them out on load — enforce it server-side too.
            if (str_starts_with($key, '_')) {
                continue;
            }

            $value = $service->normalizeContent($sel['value']);
            $tag = $sel['tag'];

            // Tag rules (same as applyMergePreview):
            // M (Mod UI) and S (Skipped) preserved; manual edit → H
            if ($tag !== 'M' && $tag !== 'S' && $sel['source'] === 'manual') {
                $tag = 'H';
            }

            $content[$key] = ['v' => $value, 't' => $tag];
            $modifiedCount++;
        }

        try {
            $contentHash = $session->writeContent($content);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => 'Content exceeds the size limit.'], 413);
        }
        $session->touchExpiry();

        $lineCount = count(array_filter(
            array_keys($content),
            fn($k) => !str_starts_with($k, '_')
        ));

        SsePublisher::editSessionSaved($session->mod_key, [
            'content_hash' => $contentHash,
            'line_count' => $lineCount,
            'saved_at' => now()->toIso8601String(),
        ]);

        return response()->json([
            'saved' => $modifiedCount,
            'line_count' => $lineCount,
            'content_hash' => $contentHash,
        ]);
    }

    /**
     * End the session: tell the mod, delete the row and the content file.
     *
     * POST /edit-session/end
     */
    public function end()
    {
        $session = $this->currentSession();

        if ($session) {
            SsePublisher::editSessionEnded($session->mod_key);
            $session->deleteWithFile();
        }

        session()->forget(self::SESSION_KEY);

        return redirect()->route('home')->with('success', __('edit_session.ended'));
    }

    private function currentSession(): ?EditSessionToken
    {
        $token = session(self::SESSION_KEY);

        return $token ? EditSessionToken::findForSession($token) : null;
    }
}
