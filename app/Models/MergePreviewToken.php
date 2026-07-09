<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * One-time token for the mod → browser merge-preview flow.
 *
 * The local content is stored as a JSON file on the private disk
 * (merge-previews/{token}.json), never in the database or the session:
 * translation files can be tens of MB and shared-hosting MySQL silently
 * drops oversized column/session payloads.
 *
 * Lifecycle:
 *  - init (API): row + content file created, expires in 15 min
 *  - page load with ?token= : markConsumed() — the token can no longer
 *    authenticate (one-time login), but the row and file survive so the
 *    post-redirect request can stream the content and applyMergePreview
 *    can publish the SSE merge_completed event
 *  - save or expiration cleanup: row and file deleted together
 */
class MergePreviewToken extends Model
{
    /** Storage directory on the private ("local") disk. */
    public const CONTENT_DIR = 'merge-previews';

    /** Validity window to open the link from the mod. */
    private const INITIAL_TTL_MINUTES = 15;

    /**
     * Validity window of the browsing session once the token is consumed.
     * Matches SESSION_LIFETIME (120 min) so page reloads and the final
     * save keep working for as long as the web session itself.
     */
    private const CONSUMED_TTL_MINUTES = 120;

    protected $fillable = [
        'token',
        'translation_id',
        'user_id',
        'expires_at',
        'consumed_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
    ];

    public function translation(): BelongsTo
    {
        return $this->belongsTo(Translation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Create a new merge preview token for a user.
     * Writes the local content to the private disk; throws RuntimeException
     * if the file cannot be written (never fail silently).
     */
    public static function createForUser(int $userId, int $translationId, array $localContent): self
    {
        // Replace any previous token for this user/translation combo
        self::where('user_id', $userId)
            ->where('translation_id', $translationId)
            ->get()
            ->each(fn(self $token) => $token->deleteWithFile());

        self::cleanupExpired();

        $token = Str::random(64);

        $json = json_encode($localContent, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('Failed to encode merge preview content: ' . json_last_error_msg());
        }
        if (!Storage::disk('local')->put(self::contentFileName($token), $json)) {
            throw new \RuntimeException('Failed to write merge preview content file.');
        }

        return self::create([
            'token' => $token,
            'user_id' => $userId,
            'translation_id' => $translationId,
            'expires_at' => now()->addMinutes(self::INITIAL_TTL_MINUTES),
        ]);
    }

    /**
     * Find a token that can still authenticate (non-expired, never consumed).
     * Used by the ?token= URL from the mod.
     */
    public static function findValid(string $token): ?self
    {
        if (!self::isValidTokenFormat($token)) {
            return null;
        }

        return self::where('token', $token)
            ->where('expires_at', '>', now())
            ->whereNull('consumed_at')
            ->first();
    }

    /**
     * Find a consumed-or-not token for an ongoing browser session.
     * Used by the post-redirect page load and the data endpoint, where the
     * token comes from the server-side session (not from user input).
     */
    public static function findForSession(string $token, int $translationId): ?self
    {
        if (!self::isValidTokenFormat($token)) {
            return null;
        }

        return self::where('token', $token)
            ->where('translation_id', $translationId)
            ->where('expires_at', '>', now())
            ->first();
    }

    /**
     * Mark the token as consumed (one-time login) and extend its life to
     * cover the browsing session (reloads + final save + SSE publish).
     */
    public function markConsumed(): void
    {
        $this->update([
            'consumed_at' => now(),
            'expires_at' => now()->addMinutes(self::CONSUMED_TTL_MINUTES),
        ]);
    }

    /**
     * Absolute path of the content file, or null if it doesn't exist.
     */
    public function getContentFilePath(): ?string
    {
        $disk = Storage::disk('local');
        $file = self::contentFileName($this->token);

        return $disk->exists($file) ? $disk->path($file) : null;
    }

    /**
     * Delete the row and its content file together.
     */
    public function deleteWithFile(): void
    {
        Storage::disk('local')->delete(self::contentFileName($this->token));
        $this->delete();
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * Delete expired rows (with their files) and orphan content files
     * left behind by interrupted requests.
     */
    public static function cleanupExpired(): void
    {
        self::where('expires_at', '<', now())
            ->get()
            ->each(fn(self $token) => $token->deleteWithFile());

        // Orphan files: content written but row missing (crash between the
        // file write and the insert, or manual row deletion). The 3h grace
        // period is well past any token lifetime, so no live token can lose
        // its file to a race with this sweep.
        $disk = Storage::disk('local');
        foreach ($disk->files(self::CONTENT_DIR) as $file) {
            if ($disk->lastModified($file) > now()->subHours(3)->getTimestamp()) {
                continue;
            }
            $token = pathinfo($file, PATHINFO_FILENAME);
            if (!self::where('token', $token)->exists()) {
                $disk->delete($file);
            }
        }
    }

    /**
     * Tokens are Str::random(64) — reject anything else before it reaches
     * a query or a file path (defense in depth against path traversal).
     */
    private static function isValidTokenFormat(string $token): bool
    {
        return preg_match('/^[A-Za-z0-9]{64}$/', $token) === 1;
    }

    private static function contentFileName(string $token): string
    {
        return self::CONTENT_DIR . '/' . $token . '.json';
    }
}
