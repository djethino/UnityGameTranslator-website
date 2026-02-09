<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ApiToken;
use App\Models\AuditLog;
use App\Models\DeviceCode;
use App\Models\MergePreviewToken;
use App\Models\Translation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SseController extends Controller
{
    private const HEARTBEAT_INTERVAL = 15;
    private const POLL_INTERVAL = 2;

    private const DEVICE_FLOW_MAX_DURATION = 900;   // 15 min
    private const SYNC_MAX_DURATION = 3600;          // 1 hour
    private const MERGE_MAX_DURATION = 900;           // 15 min

    /**
     * SSE stream for Device Flow authentication.
     * Replaces POST /auth/device/poll polling.
     *
     * GET /api/v1/auth/device/{device_code}/stream
     *
     * Events:
     *   authorized — user validated the code, token included
     *   expired    — device code expired or deleted
     */
    public function deviceFlowStream(string $deviceCode): StreamedResponse
    {
        $device = DeviceCode::findByDeviceCode($deviceCode);
        if (!$device) {
            return $this->sseError('Invalid or expired device code', 404);
        }

        // If already authorized (e.g., user was fast), return immediately
        if ($device->isAuthorized()) {
            return $this->sseImmediate($device, $deviceCode);
        }

        return $this->stream(self::DEVICE_FLOW_MAX_DURATION, function (callable $emit, callable $shouldStop) use ($deviceCode) {
            $lastVersion = (int) Cache::get("sse:device:{$deviceCode}:version", 0);
            $eventId = 0;

            while (!$shouldStop()) {
                $currentVersion = (int) Cache::get("sse:device:{$deviceCode}:version", 0);

                if ($currentVersion > $lastVersion) {
                    $lastVersion = $currentVersion;
                    $device = DeviceCode::findByDeviceCode($deviceCode);

                    if (!$device) {
                        $emit(++$eventId, 'expired', ['error' => 'Device code expired']);
                        return;
                    }

                    if ($device->isAuthorized()) {
                        $this->emitAuthorized($emit, ++$eventId, $device);
                        return;
                    }
                }

                // Check if device code expired naturally
                $device = DeviceCode::findByDeviceCode($deviceCode);
                if (!$device) {
                    $emit(++$eventId, 'expired', ['error' => 'Device code expired']);
                    return;
                }
            }
        });
    }

    /**
     * SSE stream for translation sync.
     * Replaces FetchServerState (check-uuid) + CheckForUpdates (check) at startup,
     * and provides real-time update notifications during the game session.
     *
     * GET /api/v1/sync/stream?uuid={uuid}&hash={hash}
     * Requires Bearer authentication.
     *
     * Events:
     *   state               — initial state (sent immediately on connect/reconnect)
     *   translation_updated — server translation was modified by someone
     */
    public function syncStream(Request $request): StreamedResponse
    {
        $request->validate([
            'uuid' => 'required|string|max:36',
            'hash' => 'nullable|string|max:100',
        ]);

        $uuid = $request->uuid;
        $userId = $request->user()->id;
        $clientHash = $request->query('hash');
        $lastEventId = $request->header('Last-Event-ID');

        return $this->stream(self::SYNC_MAX_DURATION, function (callable $emit, callable $shouldStop) use ($uuid, $userId, $clientHash, $lastEventId) {
            $eventId = $lastEventId ? (int) $lastEventId : 0;

            // Send initial state (combines check-uuid + check in one event)
            $state = $this->buildSyncState($uuid, $userId, $clientHash);
            $emit(++$eventId, 'state', $state);

            // Determine what to watch for live updates
            $translationId = $state['translation']['id'] ?? $state['main']['id'] ?? null;

            $lastTranslationVersion = $translationId
                ? (int) Cache::get("sse:translation:{$translationId}:version", 0)
                : 0;
            $lastUuidVersion = (int) Cache::get("sse:uuid:{$uuid}:version", 0);

            while (!$shouldStop()) {
                $changed = false;

                // Watch for changes on the specific translation
                if ($translationId) {
                    $currentVersion = (int) Cache::get("sse:translation:{$translationId}:version", 0);
                    if ($currentVersion > $lastTranslationVersion) {
                        $lastTranslationVersion = $currentVersion;
                        $changed = true;
                    }
                }

                // Watch for new translations with this UUID (e.g., upload from another device)
                $currentUuidVersion = (int) Cache::get("sse:uuid:{$uuid}:version", 0);
                if ($currentUuidVersion > $lastUuidVersion) {
                    $lastUuidVersion = $currentUuidVersion;
                    $changed = true;
                }

                if ($changed) {
                    // Re-fetch full state to detect new translations or ownership changes
                    $newState = $this->buildSyncState($uuid, $userId, $clientHash);
                    $newTranslationId = $newState['translation']['id'] ?? $newState['main']['id'] ?? null;

                    // If the tracked translation changed, update our watch target
                    if ($newTranslationId && $newTranslationId !== $translationId) {
                        $translationId = $newTranslationId;
                        $lastTranslationVersion = (int) Cache::get("sse:translation:{$translationId}:version", 0);
                        // Send full state since the tracked entity changed
                        $emit(++$eventId, 'state', $newState);
                    } else {
                        // Same translation, just updated — send lightweight event
                        $translation = $translationId ? Translation::find($translationId) : null;
                        if ($translation) {
                            $emit(++$eventId, 'translation_updated', [
                                'file_hash' => $translation->file_hash,
                                'line_count' => $translation->line_count,
                                'vote_count' => $translation->vote_count,
                                'updated_at' => $translation->updated_at->toIso8601String(),
                            ]);
                        }
                    }
                }
            }
        });
    }

    /**
     * SSE stream for merge preview completion.
     * Notifies the mod when the user completes a merge in the browser.
     *
     * GET /api/v1/merge-preview/{token}/stream
     * Token-based auth (no Bearer needed — the token IS the secret).
     *
     * Events:
     *   merge_completed — merge was applied in the browser
     */
    public function mergeStream(string $token): StreamedResponse
    {
        $mergeToken = MergePreviewToken::findValid($token);
        if (!$mergeToken) {
            return $this->sseError('Invalid or expired merge token', 404);
        }

        return $this->stream(self::MERGE_MAX_DURATION, function (callable $emit, callable $shouldStop) use ($token) {
            $eventId = 0;
            $cacheKey = "sse:merge:{$token}:completed";

            while (!$shouldStop()) {
                $completed = Cache::get($cacheKey);

                if ($completed) {
                    $emit(++$eventId, 'merge_completed', $completed);
                    Cache::forget($cacheKey);
                    return;
                }
            }
        });
    }

    /**
     * Build the combined sync state (replaces check-uuid + check in one payload).
     */
    private function buildSyncState(string $uuid, int $userId, ?string $clientHash): array
    {
        $state = [
            'exists' => false,
            'role' => 'none',
            'translation' => null,
            'main' => null,
            'branches_count' => 0,
            'has_update' => false,
        ];

        // Check if current user owns a translation with this UUID
        $ownTranslation = Translation::where('file_uuid', $uuid)
            ->where('user_id', $userId)
            ->first();

        if ($ownTranslation) {
            $role = $ownTranslation->visibility === 'public' ? 'main' : 'branch';
            $state['exists'] = true;
            $state['role'] = $role;
            $state['translation'] = [
                'id' => $ownTranslation->id,
                'source_language' => $ownTranslation->source_language,
                'target_language' => $ownTranslation->target_language,
                'type' => $ownTranslation->type,
                'notes' => $ownTranslation->notes,
                'line_count' => $ownTranslation->line_count,
                'file_hash' => $ownTranslation->file_hash,
                'vote_count' => $ownTranslation->vote_count,
                'updated_at' => $ownTranslation->updated_at->toIso8601String(),
            ];

            if ($role === 'main') {
                $state['branches_count'] = Translation::where('file_uuid', $uuid)
                    ->where('visibility', 'branch')
                    ->count();
            }

            if ($clientHash) {
                $state['has_update'] = $ownTranslation->file_hash !== $clientHash;
            }

            return $state;
        }

        // Check if Main exists with this UUID (user would become branch)
        $mainTranslation = Translation::where('file_uuid', $uuid)
            ->where('visibility', 'public')
            ->with('user:id,name')
            ->orderBy('created_at', 'asc')
            ->first();

        if ($mainTranslation) {
            $state['exists'] = true;
            $state['main'] = [
                'id' => $mainTranslation->id,
                'uploader' => $mainTranslation->user->name,
                'source_language' => $mainTranslation->source_language,
                'target_language' => $mainTranslation->target_language,
                'line_count' => $mainTranslation->line_count,
                'file_hash' => $mainTranslation->file_hash,
                'updated_at' => $mainTranslation->updated_at->toIso8601String(),
            ];

            if ($clientHash) {
                $state['has_update'] = $mainTranslation->file_hash !== $clientHash;
            }
        }

        return $state;
    }

    /**
     * Generic SSE streaming helper.
     * Handles heartbeats, connection detection, max duration, output buffering.
     */
    private function stream(int $maxDuration, callable $handler): StreamedResponse
    {
        return new StreamedResponse(function () use ($maxDuration, $handler) {
            // Disable all output buffering for real-time streaming
            set_time_limit(0);
            ini_set('output_buffering', 'off');
            ini_set('zlib.output_compression', 'off');
            while (ob_get_level()) {
                ob_end_clean();
            }

            $startTime = time();
            $lastHeartbeat = time();

            // Send retry directive
            echo "retry: 3000\n\n";
            flush();

            $emit = function (int $id, string $event, array $data) {
                echo "id: {$id}\n";
                echo "event: {$event}\n";
                echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
                flush();
            };

            $shouldStop = function () use ($startTime, $maxDuration, &$lastHeartbeat): bool {
                // Check if client disconnected
                if (connection_aborted()) {
                    return true;
                }

                // Check max duration
                if ((time() - $startTime) >= $maxDuration) {
                    return true;
                }

                // Send heartbeat if needed
                if ((time() - $lastHeartbeat) >= self::HEARTBEAT_INTERVAL) {
                    echo ": heartbeat\n\n";
                    flush();
                    $lastHeartbeat = time();

                    // Check connection again after flush
                    if (connection_aborted()) {
                        return true;
                    }
                }

                // Sleep between checks (server-side polling of cache)
                sleep(self::POLL_INTERVAL);

                return false;
            };

            $handler($emit, $shouldStop);

        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * Return an SSE error as a regular JSON response (non-streaming).
     */
    private function sseError(string $message, int $status): StreamedResponse
    {
        return new StreamedResponse(function () use ($message) {
            echo "event: error\n";
            echo 'data: ' . json_encode(['error' => $message]) . "\n\n";
            flush();
        }, $status, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
        ]);
    }

    /**
     * Handle the case where device code is already authorized when SSE connects.
     */
    private function sseImmediate(DeviceCode $device, string $deviceCode): StreamedResponse
    {
        return new StreamedResponse(function () use ($device, $deviceCode) {
            while (ob_get_level()) {
                ob_end_clean();
            }

            echo "retry: 3000\n\n";
            flush();

            $this->emitAuthorized(function (int $id, string $event, array $data) {
                echo "id: {$id}\n";
                echo "event: {$event}\n";
                echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
                flush();
            }, 1, $device);
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * Emit the authorized event and clean up the device code.
     */
    private function emitAuthorized(callable $emit, int $eventId, DeviceCode $device): void
    {
        $apiToken = ApiToken::createForUser($device->user);

        AuditLog::logTokenCreated($device->user->id, 'Unity Mod (Device Flow SSE)', request());

        $emit($eventId, 'authorized', [
            'access_token' => $apiToken->plain_token,
            'token_type' => 'Bearer',
            'user' => [
                'id' => $device->user->id,
                'name' => $device->user->name,
            ],
        ]);

        $device->delete();
    }
}
