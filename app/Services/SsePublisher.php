<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Centralized Redis pub/sub publisher for SSE signaling.
 *
 * All methods are wrapped in try/catch so that Redis failure
 * never breaks core Laravel functionality (uploads, merges, auth).
 *
 * Uses the 'sse' Redis connection (no key prefix) to avoid
 * coupling with Laravel's cache prefix. The Node.js SSE server
 * subscribes to the same channels without any prefix.
 *
 * Channel naming convention:
 *   sse:device:{deviceCode}   — Device Flow auth events
 *   sse:translation:{id}      — Translation update events
 *   sse:uuid:{uuid}           — UUID lineage change events
 *   sse:merge:{token}         — Merge completion events
 *   sse:edit:{modKey}         — Live edit session events (saves + end)
 */
class SsePublisher
{
    private const REDIS_CONNECTION = 'sse';

    /**
     * TTL for the latest live-edit event (seconds).
     *
     * Replayed to the mod on every reconnect for the life of the session — it is the mod's way of
     * catching up on the save it missed — so it has to outlive a reconnect by a wide margin.
     */
    private const RESULT_TTL = 900; // 15 minutes

    /**
     * TTL for a result that is delivered ONCE: a device authorisation, a merge outcome (seconds).
     *
     * 🔴 The device result IS the access token. It was kept fifteen minutes for a client whose
     * stream was not yet open when the code was validated, and the relay served it to whoever
     * asked for as long as it lived — the device code travels in the URL path, so in every access
     * log in front of the relay. The relay now deletes it once delivered; this bounds how long it
     * waits for a client that never came. Two minutes covers a reconnect (`retry: 3000`) with room.
     */
    private const SINGLE_DELIVERY_TTL = 120;

    /**
     * Signal that a device code was authorized.
     * Called from DeviceFlowController::validateCode() after user enters code on /link.
     *
     * - Publishes 'authorized' event on sse:device:{deviceCode}
     * - Also stores result in a Redis key for late-connecting SSE clients
     *
     * @param string $deviceCode The device_code (not user_code)
     * @param array $tokenData ['access_token' => 'ugt_...', 'user' => ['id' => ..., 'name' => ...]]
     */
    public static function deviceAuthorized(string $deviceCode, array $tokenData): void
    {
        $channel = "sse:device:{$deviceCode}";
        $message = json_encode([
            'event' => 'authorized',
            'data' => $tokenData,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        self::safePublish($channel, $message);

        // Store result for late-connecting clients (edge case: user validates before SSE connects)
        self::safeSetex("sse:device:{$deviceCode}:result", self::SINGLE_DELIVERY_TTL, $message);
    }

    /**
     * The key that says "this site announces the codes it issues" — see expected().
     *
     * ⚠ Written on every announcement and never expiring, so a Redis that lost everything is
     * back in step at the first code issued after it, with no deployment in between.
     */
    public const EXPECTED_MARKER = 'sse:expected';

    /**
     * Announce a device code or a merge token to the relay BEFORE any client may stream on it.
     *
     * 🔴 **Why this needs no deployment order — and it must not.** The relay used to open a
     * subscription for any well-formed code, whether or not this site had ever issued it. The
     * remedy is a key per code the relay checks before subscribing. But a relay that demanded the
     * key while a site did not yet write it would refuse EVERY device flow, and the mod treats
     * that refusal as final. Site first, relay second, is not an order anybody can guarantee, and
     * nothing here may depend on one.
     *
     * So the key comes with a MARKER. The relay demands the per-code key only while the marker
     * exists; the marker only exists once this site has issued a code through this code. Relay
     * deployed first: no marker, nothing demanded. Site deployed first: the old relay ignores
     * both. Both in place, in either order: the guard is on, by itself.
     *
     * @param string $kind 'device' or 'merge' — the channel family, as the relay names it.
     * @param int $ttl Seconds the code itself lives; the announcement does not outlive it.
     */
    public static function expected(string $kind, string $code, int $ttl): void
    {
        self::safeSetex("sse:{$kind}:{$code}:pending", $ttl, '1');
        self::safeSet(self::EXPECTED_MARKER, '1');
    }

    /**
     * Signal that a device code expired.
     * Called when TTL expires or code is deleted.
     *
     * @param string $deviceCode The device_code
     */
    public static function deviceExpired(string $deviceCode): void
    {
        $channel = "sse:device:{$deviceCode}";
        $message = json_encode([
            'event' => 'expired',
            'data' => ['error' => 'Device code expired'],
        ]);

        self::safePublish($channel, $message);
    }

    /**
     * Signal that a translation was updated (content, hash, line count changed).
     * Called from TranslationController::store() after upload/update/fork.
     *
     * @param int $translationId The translation ID
     * @param array $data ['file_hash' => ..., 'line_count' => ..., 'vote_count' => ..., 'updated_at' => ...]
     */
    public static function translationUpdated(int $translationId, array $data): void
    {
        $channel = "sse:translation:{$translationId}";
        $message = json_encode([
            'event' => 'translation_updated',
            'data' => $data,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        self::safePublish($channel, $message);
    }

    /**
     * Signal that a UUID lineage changed (new translation created, fork, etc.).
     * Called when a new translation is created or uploaded for a UUID.
     *
     * @param string $uuid The file_uuid
     */
    public static function uuidChanged(string $uuid): void
    {
        $channel = "sse:uuid:{$uuid}";
        $message = json_encode([
            'event' => 'uuid_changed',
            'data' => ['uuid' => $uuid],
        ]);

        self::safePublish($channel, $message);
    }

    /**
     * Signal that a merge was completed in the browser.
     * Called from MergeController::apply() and TranslationController::applyMergePreview().
     *
     * @param string $token The merge preview token
     * @param array $data ['translation_id' => ..., 'file_hash' => ..., 'line_count' => ...]
     */
    public static function mergeCompleted(string $token, array $data): void
    {
        $channel = "sse:merge:{$token}";
        $message = json_encode([
            'event' => 'merge_completed',
            'data' => $data,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        self::safePublish($channel, $message);

        // Store result for late-connecting clients — delivered once, like the device result.
        self::safeSetex("sse:merge:{$token}:result", self::SINGLE_DELIVERY_TTL, $message);
    }

    /**
     * Signal that the browser saved during a live edit session.
     * Called from EditSessionController::save(). Unlike merges, one session
     * can emit many of these — the SSE stream stays open between saves.
     *
     * @param string $modKey The session's mod key (SSE channel identity)
     * @param array $data ['content_hash' => ..., 'line_count' => ..., 'saved_at' => ...]
     */
    public static function editSessionSaved(string $modKey, array $data): void
    {
        $channel = "sse:edit:{$modKey}";
        $message = json_encode([
            'event' => 'edit_saved',
            'data' => $data,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        self::safePublish($channel, $message);

        // Latest save replayed to (re)connecting clients: a save emitted
        // during a mod reconnection gap must not be lost. The mod dedupes
        // via content_hash, so replaying an already-applied save is a no-op.
        self::safeSetex("sse:edit:{$modKey}:result", self::RESULT_TTL, $message);
    }

    /**
     * Signal that a live edit session was ended from the browser.
     * Called from EditSessionController::end().
     *
     * @param string $modKey The session's mod key
     */
    public static function editSessionEnded(string $modKey): void
    {
        $channel = "sse:edit:{$modKey}";
        $message = json_encode([
            'event' => 'edit_session_ended',
            'data' => [],
        ]);

        self::safePublish($channel, $message);

        // Overwrite any stored save event: the session (and its content
        // endpoint) no longer exists, a replayed edit_saved would 404.
        self::safeSetex("sse:edit:{$modKey}:result", self::RESULT_TTL, $message);
    }

    /**
     * Signal browser presence changes during a live edit session.
     * left: pagehide beacon fired. joined: the page (re)signaled presence
     * after having been marked away. No :result storage — presence is also
     * carried by the mod's update-push responses, which covers missed events.
     *
     * @param string $modKey The session's mod key
     */
    public static function editSessionBrowserLeft(string $modKey): void
    {
        self::safePublish("sse:edit:{$modKey}", json_encode([
            'event' => 'browser_left',
            'data' => [],
        ]));
    }

    public static function editSessionBrowserJoined(string $modKey): void
    {
        self::safePublish("sse:edit:{$modKey}", json_encode([
            'event' => 'browser_joined',
            'data' => [],
        ]));
    }

    /**
     * Ask the mod to re-translate one entry with ITS OWN AI backend
     * during a live edit session (the site never holds any AI credential).
     * No :result storage (a replay on reconnection would ghost-retranslate);
     * reliability comes from the BROWSER re-emitting the request every ~30s
     * while pending, always with the same request id — the mod deduplicates
     * by id, so a request lost in an SSE reconnection gap is simply caught
     * by the next emission.
     *
     * @param string $modKey The session's mod key
     * @param string $key The translation key (source text) to re-translate
     * @param string $requestId Browser-generated id, stable across retries
     */
    public static function editSessionRetranslate(string $modKey, string $key, string $requestId): void
    {
        self::safePublish("sse:edit:{$modKey}", json_encode([
            'event' => 'edit_retranslate',
            'data' => ['key' => $key, 'id' => $requestId],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Where a session's holder says it is still there.
     *
     * ⚠ The key is named ":game" and stays named that, though either product may now write it:
     * the SSE server writes it from Node and this class reads it from PHP, and the two are NOT
     * deployed together (the Node server is restarted by hand). Renaming it would leave every
     * live session reading as absent for the length of that gap — a real "nobody is applying your
     * saves" shown to people mid-edit, for a cosmetic gain.
     */
    private static function presenceKey(string $modKey): string
    {
        return "sse:edit:{$modKey}:game";
    }

    /**
     * Is whoever holds this session still there?
     *
     * 🔴 **This measures PRESENCE, not a transport, and the difference is what it got wrong.** It
     * used to test only the key the SSE server writes while it holds a stream open — so a session
     * held by the Manager, which polls instead of streaming and never opens one, read as a plain
     * `false`. Not "unknown": false. The editor page therefore announced "Game disconnected", and
     * advised restarting the game, to somebody whose Manager was answering every three seconds on
     * a game that is closed by design.
     *
     * The mod's stream is still the best possible sign for the mod: it cannot promise to announce
     * its own exit — OnApplicationQuit does not fire in every Unity game — whereas a TCP
     * connection dies with the process however it dies, and a game merely frozen in the background
     * keeps it. A poller has no such connection to speak for it, so it says so itself, on the beat
     * it already runs (see markHolderPresent).
     *
     * Returns null when Redis cannot answer: an unknown state must never be shown as a
     * disconnection.
     */
    public static function isHolderPresent(string $modKey): ?bool
    {
        try {
            return Redis::connection(self::REDIS_CONNECTION)->exists(self::presenceKey($modKey)) > 0;
        } catch (\Exception $e) {
            Log::warning("[SsePublisher] Redis presence check failed: {$e->getMessage()}");

            return null;
        }
    }

    /**
     * A holder that follows its session by polling says it is still there.
     *
     * 🔴 **Only ever called for a caller that DECLARED it is following.** Asking what has happened
     * in a session is not being present: both products also read this route to inspect a session
     * the OTHER one opened, before offering to take it over. Marking presence on every read would
     * mean the mod, checking whether an abandoned Manager session is finally dead, revives the
     * presence of the very session it is asking about — and the page would show a Manager that is
     * gone as connected. That is why the claim is explicit and this method is not called from the
     * probe path.
     *
     * ⚠ Deliberately does NOT touch the session's expiry. Presence and lifetime are two questions,
     * and answering both here is what the state route refused: a window nobody has looked at since
     * yesterday would hold a session alive for ever. Presence lives in Redis and expires on its
     * own; the expiry is pushed back by keepalive, a separate call made by a caller that means it.
     *
     * @param int $ttl Seconds this counts for — derived from the caller's poll interval.
     */
    public static function markHolderPresent(string $modKey, int $ttl): void
    {
        self::safeSetex(self::presenceKey($modKey), $ttl, '1');
    }

    /**
     * Safely publish a message to a Redis channel.
     * Catches all exceptions so Redis failure never breaks core functionality.
     */
    private static function safePublish(string $channel, string $message): void
    {
        try {
            Redis::connection(self::REDIS_CONNECTION)->publish($channel, $message);
        } catch (\Exception $e) {
            Log::warning("[SsePublisher] Redis publish failed on {$channel}: {$e->getMessage()}");
        }
    }

    /** Safely store a key with no expiry in Redis. */
    private static function safeSet(string $key, string $value): void
    {
        try {
            Redis::connection(self::REDIS_CONNECTION)->set($key, $value);
        } catch (\Exception $e) {
            Log::warning("[SsePublisher] Redis set failed on {$key}: {$e->getMessage()}");
        }
    }

    /**
     * Safely store a key with expiry in Redis.
     * Used for edge-case late-connecting SSE clients.
     */
    private static function safeSetex(string $key, int $ttl, string $value): void
    {
        try {
            Redis::connection(self::REDIS_CONNECTION)->setex($key, $ttl, $value);
        } catch (\Exception $e) {
            Log::warning("[SsePublisher] Redis setex failed on {$key}: {$e->getMessage()}");
        }
    }
}
