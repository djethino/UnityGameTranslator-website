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
 *  - page load with the token: markConsumed(), inactivity window starts
 *  - any sign of life pushes the expiry back (sliding TTL): the mod's
 *    keepalive, a mod push, a browser save, the browser heartbeat
 *  - end (browser button, mod side) or expiration cleanup: row and file deleted
 *
 * The expiry is an INACTIVITY window, never a cap on how long a session may
 * live. A session in use never dies, whatever the editing rhythm: a player can
 * change a thousand lines before saving, or walk away for hours with the game
 * running. Only a session both sides have abandoned is collected — which is
 * what keeps MAX_ACTIVE_SESSIONS from filling up with ghosts.
 *
 * The two sides are not symmetric, though. The game renews the session on its
 * own; the browser only renews it while the game answers. A game can crash or
 * be killed without ever sending its DELETE, and a tab left open on such a
 * session would keep it — and its concurrency slot — for ever, while every
 * save it accepts goes nowhere.
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
     *
     * Configurable because the right value depends on the disk available to
     * the instance: the worst case is this count × MAX_CONTENT_BYTES.
     */
    public static function maxActiveSessions(): int
    {
        return (int) config('edit_session.max_active', 200);
    }

    /** Validity window to open the link from the mod. */
    private const INITIAL_TTL_MINUTES = 15;

    /**
     * INACTIVITY window, pushed back by any sign of life from either side.
     * Deliberately long: it is a garbage collector for sessions both the game
     * and the browser have abandoned, NOT a limit on how long someone may
     * work. A shorter window would kill sessions mid-edit — which is exactly
     * the bug this replaces (it used to be 120 min, tied to SESSION_LIFETIME).
     */
    private const INACTIVITY_TTL_MINUTES = 1440; // 24 h

    /**
     * How long the game may stay silent before the page says so.
     *
     * The mod pings keepalive every 10 minutes, so this tolerates three missed
     * pings: a session in normal use never trips it. Deliberately generous,
     * because a missed ping does NOT prove the game is gone — Unity freezes
     * Update() in games that run with runInBackground disabled, which is
     * precisely what happens while the player edits in the browser. The page
     * therefore states a fact ("no sign of the game since ...") and never
     * concludes on the player's behalf.
     */
    public const GAME_SILENT_AFTER_MINUTES = 30;

    protected $fillable = [
        'token',
        'mod_key',
        'game_name',
        'source_language',
        'target_language',
        'expires_at',
        'consumed_at',
        'content_hash',
        'ai_available',
        'ai_model',
        'browser_last_seen_at',
        'browser_left_at',
        'game_last_seen_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
        'ai_available' => 'boolean',
        'browser_last_seen_at' => 'datetime',
        'browser_left_at' => 'datetime',
        'game_last_seen_at' => 'datetime',
    ];

    /**
     * Create a new edit session and store its content file.
     * Throws RuntimeException if the file cannot be written (never fail silently).
     */
    public static function createSession(
        array $content,
        ?string $gameName,
        ?string $sourceLanguage,
        ?string $targetLanguage,
        bool $aiAvailable = false,
        ?string $aiModel = null
    ): self {
        self::cleanupExpired();

        if (self::where('expires_at', '>', now())->count() >= self::maxActiveSessions()) {
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
            'ai_available' => $aiAvailable,
            'ai_model' => $aiModel,
            // The mod is the caller: the game is present, by definition
            'game_last_seen_at' => now(),
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
            'expires_at' => self::inactivityDeadline(),
        ]);
    }

    /**
     * Game presence heartbeat: stamped by every mod-side call (keepalive,
     * content push, content download). Slides the expiry unconditionally —
     * a running game is reason enough to keep the session, whether or not
     * anyone has the page open.
     */
    public function touchGameSeen(): void
    {
        $this->update([
            'game_last_seen_at' => now(),
            'expires_at' => self::inactivityDeadline(),
        ]);
    }

    /** End of the inactivity window, counted from now. */
    public static function inactivityDeadline(): \Illuminate\Support\Carbon
    {
        return now()->addMinutes(self::INACTIVITY_TTL_MINUTES);
    }

    /**
     * Lifetime of the browser cookie holding the token. Same window as the
     * server-side expiry so both slide together: the page can never claim a
     * session the server has collected, nor lose one the server still holds.
     */
    public static function cookieLifetimeMinutes(): int
    {
        return self::INACTIVITY_TTL_MINUTES;
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
     *
     * Pushes the expiry back too — an open editor is a live session, and
     * without this the session died on the server's clock while the user was
     * editing — but ONLY while the game still answers. A tab left open on a
     * game that crashed would otherwise renew the session for ever, and it
     * holds one of the few concurrent slots the host allows. When the game has
     * gone silent the session simply runs out its window from the game's last
     * sign of life: a wide bound (24 h), not a guillotine, and the page warns
     * the whole time so ending it stays the user's call.
     */
    public function touchBrowserSeen(): bool
    {
        $wasAway = $this->browser_left_at !== null;

        $attributes = [
            'browser_last_seen_at' => now(),
            'browser_left_at' => null,
        ];
        if ($this->isGameResponding() !== false) {
            $attributes['expires_at'] = self::inactivityDeadline();
        }

        $this->update($attributes);

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
     * Seconds since the game last called, or null for a session created before
     * the column existed (never stamped yet).
     */
    public function gameSeenSecondsAgo(): ?int
    {
        return $this->game_last_seen_at
            ? (int) abs(now()->diffInSeconds($this->game_last_seen_at))
            : null;
    }

    /**
     * Whether the game is still calling in, or null when we have never heard
     * from it — never conclude on missing information: an unknown state must
     * behave exactly like a live one (no warning, session kept).
     */
    public function isGameResponding(): ?bool
    {
        $secondsAgo = $this->gameSeenSecondsAgo();

        return $secondsAgo === null
            ? null
            : $secondsAgo < self::GAME_SILENT_AFTER_MINUTES * 60;
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
