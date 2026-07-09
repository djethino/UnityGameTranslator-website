<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Anonymous live-edit session for the mod → browser edit flow.
 *
 * The mod uploads its LOCAL translations file (no account, no public
 * Translation row) and opens the edit page in a browser. Every save in the
 * browser rewrites the session file and signals the mod over SSE, which
 * downloads it back and hot-reloads it in-game.
 *
 * Two credentials, one row:
 *  - token:   opens the edit page once (one-time login, lands in browser
 *             history so it must die on first use — same pattern as
 *             MergePreviewToken)
 *  - mod_key: never rendered in a browser; authenticates the mod's content
 *             download and SSE stream for the whole session lifetime
 *
 * Content storage follows MergePreviewToken: a JSON file on the private
 * disk (edit-sessions/{mod_key}.json), never in the database or the
 * session — translation files can be tens of MB.
 *
 * Lifecycle:
 *  - init (API, unauthenticated): row + content file created, 15 min to open
 *  - page load with the token: markConsumed(), session extended to 120 min
 *  - each save: file rewritten, expiry pushed back (sliding TTL)
 *  - end (browser button) or expiration cleanup: row and file deleted
 */
class EditSessionToken extends Model
{
    /** Storage directory on the private ("local") disk. */
    public const CONTENT_DIR = 'edit-sessions';

    /** Serialized JSON size cap — init is unauthenticated, never accept more. */
    public const MAX_CONTENT_BYTES = 20 * 1024 * 1024;

    /**
     * Hard cap on concurrently active sessions. Init is unauthenticated:
     * without this, 6 inits/min/IP × 15 min TTL × 20 MB is an unbounded
     * multi-IP disk-exhaustion vector on the shared private disk.
     */
    public const MAX_ACTIVE_SESSIONS = 200;

    /** Validity window to open the link from the mod. */
    private const INITIAL_TTL_MINUTES = 15;

    /**
     * Validity window of the browsing session, renewed on every save
     * (sliding TTL). Matches SESSION_LIFETIME (120 min).
     */
    private const SESSION_TTL_MINUTES = 120;

    protected $fillable = [
        'token',
        'mod_key',
        'game_name',
        'source_language',
        'target_language',
        'expires_at',
        'consumed_at',
        'content_hash',
        'browser_last_seen_at',
        'browser_left_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
        'browser_last_seen_at' => 'datetime',
        'browser_left_at' => 'datetime',
    ];

    /**
     * Create a new edit session and store its content file.
     * Throws RuntimeException if the file cannot be written (never fail silently).
     */
    public static function createSession(
        array $content,
        ?string $gameName,
        ?string $sourceLanguage,
        ?string $targetLanguage
    ): self {
        self::cleanupExpired();

        if (self::where('expires_at', '>', now())->count() >= self::MAX_ACTIVE_SESSIONS) {
            throw new \OverflowException('Too many active edit sessions.');
        }

        $token = Str::random(64);
        $modKey = Str::random(64);

        $json = json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('Failed to encode edit session content: ' . json_last_error_msg());
        }
        if (strlen($json) > self::MAX_CONTENT_BYTES) {
            throw new \InvalidArgumentException('Edit session content exceeds the size limit.');
        }
        if (!Storage::disk('local')->put(self::contentFileName($modKey), $json)) {
            throw new \RuntimeException('Failed to write edit session content file.');
        }

        return self::create([
            'token' => $token,
            'mod_key' => $modKey,
            'game_name' => $gameName,
            'source_language' => $sourceLanguage,
            'target_language' => $targetLanguage,
            'expires_at' => now()->addMinutes(self::INITIAL_TTL_MINUTES),
            'content_hash' => hash('sha256', $json),
        ]);
    }

    /**
     * Find a session whose browser token can still authenticate
     * (non-expired, never consumed). Used by the URL from the mod.
     */
    public static function findValidByToken(string $token): ?self
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
     * Find a live session by browser token, consumed or not. Used where the
     * token comes from the server-side session (not from user input).
     */
    public static function findForSession(string $token): ?self
    {
        if (!self::isValidTokenFormat($token)) {
            return null;
        }

        return self::where('token', $token)
            ->where('expires_at', '>', now())
            ->first();
    }

    /**
     * Find a live session by mod key (mod-side content download).
     */
    public static function findByModKey(string $modKey): ?self
    {
        if (!self::isValidTokenFormat($modKey)) {
            return null;
        }

        return self::where('mod_key', $modKey)
            ->where('expires_at', '>', now())
            ->first();
    }

    /**
     * Mark the browser token as consumed (one-time login) and extend the
     * session life to cover the browsing session.
     */
    public function markConsumed(): void
    {
        $this->update([
            'consumed_at' => now(),
            'expires_at' => now()->addMinutes(self::SESSION_TTL_MINUTES),
        ]);
    }

    /**
     * Sliding TTL: every save keeps the session alive for another window.
     */
    public function touchExpiry(): void
    {
        $this->update(['expires_at' => now()->addMinutes(self::SESSION_TTL_MINUTES)]);
    }

    /**
     * Absolute path of the content file, or null if it doesn't exist.
     */
    public function getContentFilePath(): ?string
    {
        $disk = Storage::disk('local');
        $file = self::contentFileName($this->mod_key);

        return $disk->exists($file) ? $disk->path($file) : null;
    }

    /**
     * Rewrite the content file (browser save). Returns the sha256 of the
     * new JSON so callers can signal it over SSE without re-reading.
     * Enforces the same size cap as init — save is reachable by any browser
     * holding the session and could otherwise grow the file without bound.
     */
    public function writeContent(array $content): string
    {
        $json = json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('Failed to encode edit session content: ' . json_last_error_msg());
        }
        if (strlen($json) > self::MAX_CONTENT_BYTES) {
            throw new \InvalidArgumentException('Edit session content exceeds the size limit.');
        }
        if (!Storage::disk('local')->put(self::contentFileName($this->mod_key), $json)) {
            throw new \RuntimeException('Failed to write edit session content file.');
        }

        $hash = hash('sha256', $json);
        $this->update(['content_hash' => $hash]);

        return $hash;
    }

    /**
     * Browser presence heartbeat: stamped by the page's state poll and data
     * load; also clears a pending "left" mark (page reload / bfcache return).
     * Returns true when the browser was marked away (caller may signal rejoin).
     */
    public function touchBrowserSeen(): bool
    {
        $wasAway = $this->browser_left_at !== null;
        $this->update([
            'browser_last_seen_at' => now(),
            'browser_left_at' => null,
        ]);

        return $wasAway;
    }

    /**
     * pagehide beacon: the browser is (probably) gone. Never destroys the
     * session — pagehide also fires on refresh/navigation; the mod concludes
     * after its own grace period.
     */
    public function markBrowserLeft(): void
    {
        $this->update(['browser_left_at' => now()]);
    }

    /**
     * Seconds since the browser last signaled presence, or null if it never
     * opened the page.
     */
    public function browserSeenSecondsAgo(): ?int
    {
        return $this->browser_last_seen_at
            ? (int) abs(now()->diffInSeconds($this->browser_last_seen_at))
            : null;
    }

    /**
     * Delete the row and its content file together.
     */
    public function deleteWithFile(): void
    {
        Storage::disk('local')->delete(self::contentFileName($this->mod_key));
        $this->delete();
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * Delete expired rows (with their files) and orphan content files.
     * Same sweep as MergePreviewToken::cleanupExpired.
     */
    public static function cleanupExpired(): void
    {
        self::where('expires_at', '<', now())
            ->get()
            ->each(fn(self $session) => $session->deleteWithFile());

        $disk = Storage::disk('local');
        foreach ($disk->files(self::CONTENT_DIR) as $file) {
            if ($disk->lastModified($file) > now()->subHours(3)->getTimestamp()) {
                continue;
            }
            $modKey = pathinfo($file, PATHINFO_FILENAME);
            if (!self::where('mod_key', $modKey)->exists()) {
                $disk->delete($file);
            }
        }
    }

    /**
     * Tokens and mod keys are Str::random(64) — reject anything else before
     * it reaches a query or a file path (defense in depth).
     */
    private static function isValidTokenFormat(string $value): bool
    {
        return preg_match('/^[A-Za-z0-9]{64}$/', $value) === 1;
    }

    private static function contentFileName(string $modKey): string
    {
        return self::CONTENT_DIR . '/' . $modKey . '.json';
    }
}
