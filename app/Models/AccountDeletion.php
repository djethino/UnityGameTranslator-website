<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The record that an account asked to be forgotten.
 *
 * An id and a date, so that a backup restored from before the request can be told which accounts
 * must stay erased. Read the migration for why it carries nothing else, and for the rule it depends
 * on — restore beside, never over.
 */
class AccountDeletion extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'deleted_at'];

    protected function casts(): array
    {
        return ['deleted_at' => 'datetime'];
    }

    /**
     * Note the request, once.
     *
     * ⚠ updateOrCreate rather than create: an account can only be deleted once, but a restore
     * followed by a second deletion of the same id must not fail on the unique index — the answer
     * to "was this erased?" is the same either way.
     */
    public static function note(int $userId): self
    {
        return self::updateOrCreate(['user_id' => $userId], ['deleted_at' => now()]);
    }
}
