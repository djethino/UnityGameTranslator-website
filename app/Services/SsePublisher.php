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
