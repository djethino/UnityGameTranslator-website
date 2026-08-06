<?php

namespace App\Http\Controllers;

use App\Models\EditSessionToken;
use App\Services\SsePublisher;
use App\Services\TranslationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * Browser side of the anonymous live-edit session (see EditSessionToken).
 *
 * No auth() anywhere: the browser is authenticated by the one-time token,
 * then by a DEDICATED cookie. Save is AJAX so the page stays open for
 * repeated edit → save → check-in-game cycles.
 *
 * Why a dedicated cookie rather than the web session: the session cookie
 * lives for SESSION_LIFETIME (120 min), which governs the whole site and has
 * no reason to match how long someone edits. A sleeping machine or a long
 * break past that window killed the page while the edit session itself was
 * still alive server-side, and pending edits — keyed on the session id — were
 * unrecoverable. This cookie carries the same value, is encrypted like every
 * Laravel cookie, and slides on the session's own inactivity window.
 *
 * It is strictly necessary to a service the user explicitly requested (they
 * clicked "Edit in browser" in the mod), so it needs no consent banner — but
 * it MUST stay listed in the privacy page, which names every cookie.
 */
class EditSessionController extends Controller
{
    /**
     * Holds the session token. Flags (secure, domain, same_site) are inherited
     * from config/session.php so this cookie can never be laxer than the site's
     * own session cookie; host-only when SESSION_DOMAIN is null, which keeps it
     * away from the SSE subdomain.
     */
    private const COOKIE_NAME = 'ugt_edit_session';

    /**
     * How many live sessions one browser may hold at once. Someone running
     * several games edits several of them; the ceiling only exists because a
     * cookie is capped at 4 KB, and 12 × 64-char tokens stay far below it.
     */
    private const MAX_REMEMBERED = 12;

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
        $this->rememberSession($session);

        // The id travels in the URL so the tab keeps pointing at ITS session
        // across refreshes, whatever else the browser opens meanwhile
        return redirect()->route('edit-session.show', ['s' => $session->id], 303);
    }

    /**
     * The edit page (session-bound, token-less URL).
     *
     * GET /edit-session
     */
    public function show(Request $request)
    {
        $session = $this->currentSession();

        if (!$session && !$request->filled('s')) {
            // Bare URL (bookmark, old tab) while several sessions are held:
            // showing "expired" would be a lie. Pick the latest and pin the
            // tab to it — choosing which page to display writes nothing.
            $latest = $this->latestLiveSession();

            if ($latest) {
                return redirect()->route('edit-session.show', ['s' => $latest->id], 303);
            }
        }

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

        return response()->json([
            'content_hash' => $session->content_hash,
            // The mod can toggle its AI backend mid-session (pushes refresh
            // the flag) — the page shows/hides the retranslate buttons live
            'ai_available' => $session->ai_available,
            // Game presence, so the page can show a live connection indicator
            // instead of letting the user edit into the void — saves are
            // accepted, but nothing applies them in-game.
            //
            // The mod's open stream is the authoritative signal (seconds, and
            // it cannot be faked away by a game that exits without warning);
            // the timestamp is the fallback for when Redis cannot answer. Both
            // are null-safe: an unknown state must never read as "gone".
            'game_connected' => SsePublisher::isGameStreamConnected($session->mod_key),
            'game_responding' => $session->isGameResponding(),
            'game_seen_seconds_ago' => $session->gameSeenSecondsAgo(),
            // Edits saved here that the game has not fetched yet. "Saved" is not
            // "applied in-game", and the difference is exactly what the user
            // stands to lose if they walk away from a game that never comes back.
            'pending_changes' => $session->pending_changes,
        ]);
    }

    /**
     * Ask the mod to re-translate one entry with ITS OWN AI backend.
     * The site holds no AI credential: the request travels over the
     * session's SSE channel and the translation runs on the player's
     * machine, coming back through the normal mod → session push.
     * Fire-and-forget: the mod ignores keys absent from its file.
     *
     * POST /edit-session-retranslate (AJAX)
     */
    public function retranslate(Request $request)
    {
        $session = $this->currentSession();

        if (!$session) {
            return response()->json(['error' => __('edit_session.error_expired')], 410);
        }

        if (!$session->ai_available) {
            return response()->json(['error' => 'No AI backend available.'], 422);
        }

        $request->validate([
            'key' => 'required|string|max:10000',
            // Browser-generated, stable across the retries of one request —
            // the mod deduplicates on it (SSE gaps lose events; the browser
            // re-emits every ~30s while the row is still pending)
            'id' => 'required|string|max:64',
        ]);

        $key = $request->input('key');
        // Metadata keys are never translatable content
        if (str_starts_with($key, '_')) {
            return response()->json(['error' => 'Invalid key.'], 422);
        }

        SsePublisher::editSessionRetranslate($session->mod_key, $key, $request->input('id'));

        return response()->json(['requested' => true]);
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
    /**
     * What the edited file carries besides its lines: fonts, image replacements, exclusions,
     * variables, game options.
     *
     * Read-only, and no side to pick: an edit session has a single source, so there is nothing
     * to arbitrate — only something to KNOW. Someone editing a file that swaps twenty images
     * should not have to open the mod to find out.
     *
     * Served apart from the content, which is streamed without ever being decoded so that a
     * large translation stays cheap to load.
     *
     * GET /edit-session-settings (AJAX)
     */
    public function settings(TranslationService $service)
    {
        $session = $this->currentSession();
        $path = $session?->getContentFilePath();

        if (!$path) {
            abort(410, 'Edit session expired. Please restart it from the mod.');
        }

        $json = json_decode($service->normalizeContent(file_get_contents($path)), true);

        return response()->json([
            'settings' => is_array($json) ? $service->extractComparableSettings($json) : [],
        ])->header('Cache-Control', 'no-store, private');
    }

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
            'selections' => 'sometimes|array|max:5000',
            'selections.*.key' => 'required|string',
            'selections.*.value' => 'present|string',
            'selections.*.tag' => 'required|in:H,A,V,M,S',
            'selections.*.source' => 'required|string', // 'manual' or 'local' (tag change)
            'deletions' => 'sometimes|array|max:5000',
            'deletions.*' => 'string',
        ]);

        if (empty($request->input('selections')) && empty($request->input('deletions'))) {
            return response()->json(['error' => 'No changes to apply.'], 422);
        }

        $rawContent = file_get_contents($path);
        $rawContent = $service->normalizeContent($rawContent);
        $content = json_decode($rawContent, true);
        if (!is_array($content)) {
            return response()->json(['error' => __('merge_preview.error_invalid_json')], 422);
        }

        $modifiedCount = 0;
        foreach ($request->input('selections', []) as $sel) {
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

            // rebuildEntry keeps the ordering index "i" of the existing entry
            $content[$key] = TranslationService::rebuildEntry($content[$key] ?? null, $value, $tag);
            $modifiedCount++;
        }

        // Deletions — same guard as selections: metadata keys are untouchable
        $deletedCount = 0;
        foreach ($request->input('deletions', []) as $delKey) {
            $delKey = $service->normalizeContent($delKey);
            if (!str_starts_with($delKey, '_') && array_key_exists($delKey, $content)) {
                unset($content[$delKey]);
                $deletedCount++;
            }
        }

        try {
            $contentHash = $session->writeContent($content);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => 'Content exceeds the size limit.'], 413);
        }
        // A save is browser presence, and browser presence only renews the
        // session while the game answers (see touchBrowserSeen)
        if ($session->touchBrowserSeen()) {
            SsePublisher::editSessionBrowserJoined($session->mod_key);
        }

        // Saved here is not applied in-game: count it as owed to the game until
        // the mod fetches the file. Guards the editor's honesty AND the early
        // collection of abandoned sessions.
        $session->addPendingChanges($modifiedCount + $deletedCount);

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
            'deleted' => $deletedCount,
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

        // Nothing matched: this page names a session that is gone, or it predates
        // the multi-session cookie and sends no id while the browser holds several
        // — in which case picking one would be a guess. Ending nothing while
        // announcing success is the worst of both: the session stays alive and the
        // user believes it is closed. Say so instead.
        if (!$session) {
            return back()->withErrors(['error' => __('edit_session.end_stale')]);
        }

        SsePublisher::editSessionEnded($session->mod_key);
        $session->deleteWithFile();

        // Drop THIS session only: ending one game's editor must not sign the
        // browser out of the other games it still has open
        $this->forgetSession($session);

        return redirect()->route('home')->with('success', __('edit_session.ended'));
    }

    /**
     * Bind the session to this browser, and slide the cookie forward on every
     * request that finds it: the cookie must never outlive nor die before the
     * server-side inactivity window it mirrors.
     *
     * The session joins a LIST rather than replacing it. One token per cookie
     * meant that opening a second game's editor silently rebound every open
     * tab to it: the older tab polled, saw another file's hash, loaded that
     * file, and its next save wrote its own keys into the other game's session.
     */
    private function rememberSession(EditSessionToken $session): void
    {
        $tokens = array_values(array_filter(
            $this->rememberedTokens(),
            fn(string $token) => $token !== $session->token
        ));
        array_unshift($tokens, $session->token);

        Cookie::queue(
            self::COOKIE_NAME,
            json_encode(array_slice($tokens, 0, self::MAX_REMEMBERED)),
            EditSessionToken::cookieLifetimeMinutes()
        );
    }

    /**
     * Remove one session from the cookie, keeping the others. A null session
     * (already gone server-side) clears nothing: the sweep below drops it on
     * the next request that finds it dead.
     */
    private function forgetSession(?EditSessionToken $session): void
    {
        $tokens = $this->rememberedTokens();

        if ($session) {
            $tokens = array_values(array_filter(
                $tokens,
                fn(string $token) => $token !== $session->token
            ));
        }

        if (!$tokens) {
            Cookie::queue(Cookie::forget(self::COOKIE_NAME));
            return;
        }

        Cookie::queue(
            self::COOKIE_NAME,
            json_encode($tokens),
            EditSessionToken::cookieLifetimeMinutes()
        );
    }

    /**
     * Session tokens this browser may act on. Also accepts the pre-list cookie
     * (a bare token), so upgrading the site never logs an open editor out.
     */
    private function rememberedTokens(): array
    {
        $raw = request()->cookie(self::COOKIE_NAME);
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        // Legacy format: the cookie held the token itself
        if (self::isValidTokenFormat($raw)) {
            return [$raw];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_filter(
            $decoded,
            fn($token) => is_string($token) && self::isValidTokenFormat($token)
        ));
    }

    /**
     * The session THIS page is about — never "the last one opened".
     *
     * The page states which session it means through `s` (its row id, not a
     * secret): authorization comes from the cookie, the id only picks one of
     * the sessions the cookie already allows. A request without `s` is served
     * only when there is exactly one session to mean — otherwise the answer is
     * "gone", because guessing is how the wrong file gets written.
     */
    private function currentSession(): ?EditSessionToken
    {
        $tokens = $this->rememberedTokens();
        if (!$tokens) {
            return null;
        }

        $requestedId = request()->input('s');
        $session = null;

        if ($requestedId !== null && $requestedId !== '' && ctype_digit((string) $requestedId)) {
            $session = EditSessionToken::query()
                ->whereIn('token', $tokens)
                ->where('id', (int) $requestedId)
                ->where('expires_at', '>', now())
                ->first();
        } elseif ($requestedId === null || $requestedId === '') {
            // No id: tabs opened before this shipped, and the plain /edit-session
            // URL. Unambiguous only while a single session is remembered.
            if (count($tokens) === 1) {
                $session = EditSessionToken::findForSession($tokens[0]);
            }
        }

        if ($session) {
            $this->rememberSession($session);
        }

        return $session;
    }

    /**
     * The most recently bound session still alive, in cookie order (newest
     * first). Used only to answer the bare page URL.
     */
    private function latestLiveSession(): ?EditSessionToken
    {
        foreach ($this->rememberedTokens() as $token) {
            $session = EditSessionToken::findForSession($token);
            if ($session) {
                return $session;
            }
        }

        return null;
    }

    /**
     * Mirrors EditSessionToken's own format check: tokens are Str::random(64).
     */
    private static function isValidTokenFormat(string $value): bool
    {
        return preg_match('/^[A-Za-z0-9]{64}$/', $value) === 1;
    }
}
