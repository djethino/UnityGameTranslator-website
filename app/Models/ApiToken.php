<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ApiToken extends Model
{
    protected $fillable = [
        'user_id',
        'token',
        'name',
        'last_used_at',
        'expires_at',
    ];

    protected $casts = [
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    protected $hidden = [
        'token',
    ];

    /**
     * Generate a new API token (plain text, for returning to user)
     */
    public static function generateToken(): string
    {
        return 'ugt_' . Str::random(60); // Prefix for easy identification
    }

    /**
     * Hash a token for secure storage
     */
    public static function hashToken(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    /**
     * Create a new API token for a user.
     * Returns the model with a 'plain_token' attribute containing the unhashed token.
     * The plain token is shown only once and cannot be retrieved later.
     * Token expires after 1 year by default.
     */
    public static function createForUser(User $user, string $name = 'Unity Mod'): self
    {
        $plainToken = self::generateToken();

        $apiToken = self::create([
            'user_id' => $user->id,
            'token' => self::hashToken($plainToken), // Store hash, not plain text
            'name' => $name,
            'expires_at' => now()->addYear(),
        ]);

        // Attach plain token for one-time retrieval (not persisted)
        $apiToken->plain_token = $plainToken;

        return $apiToken;
    }

    /**
     * Find a token by its plain text value and mark it as used.
     * Hashes the input before searching. Excludes expired tokens.
     */
    public static function findAndMarkUsed(string $plainToken): ?self
    {
        $hashedToken = self::hashToken($plainToken);
        $apiToken = self::where('token', $hashedToken)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->first();

        if ($apiToken) {
            $apiToken->update(['last_used_at' => now()]);
        }

        return $apiToken;
    }

    /**
     * Check if this token has expired.
     */
    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Check if a plain token matches this token's hash
     */
    public function verifyToken(string $plainToken): bool
    {
        return hash_equals($this->token, self::hashToken($plainToken));
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
