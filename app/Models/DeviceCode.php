<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class DeviceCode extends Model
{
    protected $fillable = [
        'device_code',
        'user_code',
        'user_id',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    /**
     * Generate a new device code pair
     */
    public static function generate(): self
    {
        // Clean up expired codes first
        self::where('expires_at', '<', now())->delete();

        return self::create([
            'device_code' => Str::random(64),
            'user_code' => self::generateUserCode(),
            'expires_at' => now()->addMinutes(15),
        ]);
    }

    /**
     * Generate a human-readable user code (ABCD-1234 format)
     * 4 letters + 4 numbers = ~4.5 billion combinations (26^4 * 10^4)
     * Much more resistant to brute force than previous 3+3 format
     */
    private static function generateUserCode(): string
    {
        // Characters that are easy to read and type (no 0/O, 1/I/L confusion)
        $letters = 'ABCDEFGHJKMNPQRSTUVWXYZ'; // 23 letters (removed I, L, O)
        $numbers = '23456789'; // 8 numbers (removed 0, 1)

        do {
            // Generate 4 random letters
            $letterPart = '';
            for ($i = 0; $i < 4; $i++) {
                $letterPart .= $letters[random_int(0, strlen($letters) - 1)];
            }

            // Generate 4 random numbers
            $numberPart = '';
            for ($i = 0; $i < 4; $i++) {
                $numberPart .= $numbers[random_int(0, strlen($numbers) - 1)];
            }

            $code = "{$letterPart}-{$numberPart}";
        } while (self::where('user_code', $code)->exists());

        return $code;
    }

    /**
     * Check if this device code has been authorized
     */
    public function isAuthorized(): bool
    {
        return $this->user_id !== null;
    }

    /**
     * Check if this device code has expired
     */
    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * Authorize this device code with a user
     */
    public function authorize(User $user): void
    {
        $this->update(['user_id' => $user->id]);
    }

    /**
     * Find a device code by the polling device_code
     */
    public static function findByDeviceCode(string $deviceCode): ?self
    {
        return self::where('device_code', $deviceCode)
            ->where('expires_at', '>', now())
            ->first();
    }

    /**
     * Find a device code by the user-entered user_code
     */
    public static function findByUserCode(string $userCode): ?self
    {
        // Normalize: uppercase, remove spaces, add dash if missing
        $normalized = strtoupper(preg_replace('/\s+/', '', trim($userCode)));

        // Handle both old (ABC-123) and new (ABCD-1234) formats
        if (!str_contains($normalized, '-')) {
            // Try new format first (4+4)
            if (strlen($normalized) === 8) {
                $normalized = substr($normalized, 0, 4) . '-' . substr($normalized, 4);
            }
            // Fallback to old format (3+3) for backwards compatibility
            elseif (strlen($normalized) === 6) {
                $normalized = substr($normalized, 0, 3) . '-' . substr($normalized, 3);
            }
        }

        return self::where('user_code', $normalized)
            ->where('expires_at', '>', now())
            ->whereNull('user_id')
            ->first();
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
