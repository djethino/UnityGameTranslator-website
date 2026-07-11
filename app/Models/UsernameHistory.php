<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Past display names. ADMIN-ONLY (moderation / anti-impersonation):
 * never exposed publicly — public anonymity is part of the product.
 */
class UsernameHistory extends Model
{
    protected $table = 'username_history';

    protected $fillable = ['user_id', 'old_name', 'changed_at'];

    protected function casts(): array
    {
        return ['changed_at' => 'datetime'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
