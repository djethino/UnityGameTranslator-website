<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Game extends Model
{
    protected $fillable = [
        'name',

        // What a machine reads off the folder, kept beside what the game is displayed under. The
        // display name comes from IGDB or RAWG when they know the game, so it is often NOT the
        // string a mod or the Manager can see on disk — and that string is the only one every
        // client shares. See the migration for what its absence used to cost.
        'unity_name',
        'unity_company',

        'slug',
        'igdb_id',
        'rawg_id',
        'steam_id',
        'image_url',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($game) {
            if (empty($game->slug)) {
                $slug = Str::slug($game->name);
                // Str::slug returns empty for CJK/non-Latin names — fall back to steam_id or raw name
                if (empty($slug)) {
                    $slug = !empty($game->steam_id) ? 'game-' . $game->steam_id : Str::slug($game->name, '-', 'zh');
                }
                // Final fallback: use a unique ID-based slug
                if (empty($slug)) {
                    $slug = 'game-' . uniqid();
                }
                $game->slug = $slug;
            }
        });
    }

    public function translations()
    {
        return $this->hasMany(Translation::class);
    }

    public function getRouteKeyName()
    {
        return 'slug';
    }
}
