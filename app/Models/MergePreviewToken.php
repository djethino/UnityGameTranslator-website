<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class MergePreviewToken extends Model
{
    protected $fillable = [
        'token',
        'translation_id',
        'user_id',
        'local_content',
        'expires_at',
    ];

    protected $casts = [
        'local_content' => 'array',
        'expires_at' => 'datetime',
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
     */
    public static function createForUser(int $userId, int $translationId, array $localContent): self
    {
        // Clean up old tokens for this user/translation combo
        self::where('user_id', $userId)
            ->where('translation_id', $translationId)
            ->delete();

        // Clean up expired tokens
        self::where('expires_at', '<', now())->delete();

        return self::create([
            'token' => Str::random(64),
            'user_id' => $userId,
            'translation_id' => $translationId,
            'local_content' => $localContent,
            'expires_at' => now()->addMinutes(15), // 15 min expiration
        ]);
    }

    /**
     * Find a valid (non-expired) token.
     */
    public static function findValid(string $token): ?self
    {
        return self::where('token', $token)
            ->where('expires_at', '>', now())
            ->first();
    }

    /**
     * Check if token is expired.
     */
    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
