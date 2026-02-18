<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'provider',
        'provider_id',
        'avatar',
        'locale',
    ];

    protected $guarded = [
        'is_admin',
        'banned_at',
        'ban_reason',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'banned_at' => 'datetime',
        ];
    }

    public function translations()
    {
        return $this->hasMany(Translation::class);
    }

    public function votes()
    {
        return $this->hasMany(Vote::class);
    }

    public function reports()
    {
        return $this->hasMany(Report::class, 'reporter_id');
    }

    public function isAdmin(): bool
    {
        return $this->is_admin;
    }

    public function isBanned(): bool
    {
        return $this->banned_at !== null;
    }

    public function ban(string $reason = null): void
    {
        $this->banned_at = now();
        $this->ban_reason = $reason;
        $this->save();
    }

    public function unban(): void
    {
        $this->banned_at = null;
        $this->ban_reason = null;
        $this->save();
    }
}
