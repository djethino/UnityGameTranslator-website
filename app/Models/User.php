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
        'game_language',
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
            'name_changed_at' => 'datetime',
            'username_prompt_seen_at' => 'datetime',
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

    public function apiTokens()
    {
        return $this->hasMany(ApiToken::class);
    }

    /**
     * The language this person wants their GAMES in — the catalogue's name for it.
     *
     * 🔴 **Not the interface language, and the distinction is the point.** `locale` is one of the
     * twenty this site is translated into; a game translation can be any of the catalogue's ninety.
     * Someone reading an English interface and wanting French subtitles is ordinary, and a Tamil
     * player has no interface language to be inferred from at all.
     *
     * ⚠ Falls back to the interface language when nothing was chosen, which is exactly what the
     * site did before the column existed — so nobody's ordering changes until they say otherwise.
     * Null out means "no idea", and the caller must not invent one: ranking by a language nobody
     * asked for is what this separation exists to stop.
     */
    public function gameLanguage(): ?string
    {
        if ($this->game_language) {
            return \App\Services\CatalogStore::nameOfCode($this->game_language) ?? null;
        }

        return \App\Services\CatalogStore::nameOfCode($this->locale ?? app()->getLocale());
    }

    public function isAdmin(): bool
    {
        return (bool) $this->is_admin;
    }

    /**
     * Local (platform-less) account: logs in with username+password,
     * may have no email at all.
     */
    public function isLocalAccount(): bool
    {
        return $this->provider === 'local';
    }

    public function isBanned(): bool
    {
        return $this->banned_at !== null;
    }

    public function ban(?string $reason = null): void
    {
        $this->banned_at = now();
        $this->ban_reason = $reason;
        $this->save();

        // Cut the mod off straight away. A banned account keeps its website session (it must be
        // able to read why it was banned) but loses every API token: the plugin is a granted
        // access, not a place to display a notice. Re-linking is refused while the ban stands.
        $this->apiTokens()->delete();
    }

    public function unban(): void
    {
        $this->banned_at = null;
        $this->ban_reason = null;
        $this->save();
    }
}
