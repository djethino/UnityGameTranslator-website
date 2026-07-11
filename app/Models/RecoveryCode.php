<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * One-time recovery codes for local (platform-less) accounts.
 * The only way back into an account that has no email — by design
 * (anonymity first). Stored hashed, single use.
 */
class RecoveryCode extends Model
{
    protected $fillable = ['user_id', 'code_hash', 'used_at'];

    protected function casts(): array
    {
        return ['used_at' => 'datetime'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Generate a fresh set of codes for a user (replaces any previous set).
     * Returns the PLAIN codes — the only time they are ever visible.
     */
    public static function generateFor(User $user, int $count = 8): array
    {
        self::where('user_id', $user->id)->delete();

        $plainCodes = [];
        for ($i = 0; $i < $count; $i++) {
            // Groups of 4, unambiguous alphabet (no 0/O/1/I/L)
            $alphabet = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
            $code = '';
            for ($j = 0; $j < 12; $j++) {
                if ($j > 0 && $j % 4 === 0) {
                    $code .= '-';
                }
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $plainCodes[] = $code;

            self::create([
                'user_id' => $user->id,
                'code_hash' => Hash::make($code),
            ]);
        }

        return $plainCodes;
    }

    /**
     * Consume a recovery code for a user. Returns true (and burns the code)
     * when it matches an unused one.
     */
    public static function consume(User $user, string $plainCode): bool
    {
        $codes = self::where('user_id', $user->id)->whereNull('used_at')->get();

        foreach ($codes as $code) {
            if (Hash::check(strtoupper(trim($plainCode)), $code->code_hash)) {
                $code->update(['used_at' => now()]);
                return true;
            }
        }

        return false;
    }
}
