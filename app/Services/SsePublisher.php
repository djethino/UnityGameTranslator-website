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
     * TTL for edge-case result storage (seconds).
     * Covers the case where user validates the device code / merge
     * before the mod's SSE stream connects.
     */
    private const RESULT_TTL = 900; // 15 minutes

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
        self::safeSetex("sse:device:{$deviceCode}:result", self::RESULT_TTL, $message);
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

        // Store result for late-connecting clients
        self::safeSetex("sse:merge:{$token}:result", self::RESULT_TTL, $message);
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
     * Fire-and-forget by design: no :result storage — a replayed request
     * on reconnection would trigger ghost retranslations, and the browser
     * button simply stays available if the request is lost.
     *
     * @param string $modKey The session's mod key
     * @param string $key The translation key (source text) to re-translate
     */
    public static function editSessionRetranslate(string $modKey, string $key): void
    {
        self::safePublish("sse:edit:{$modKey}", json_encode([
            'event' => 'edit_retranslate',
            'data' => ['key' => $key],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
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
