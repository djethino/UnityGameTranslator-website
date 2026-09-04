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

        // A latin handle for a title written in another script — see App\Support\LatinSearch.
        // Filled by the model itself on save; listed here so a mass assignment can set it too.
        'latin_search',

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

        // Something to TYPE for a title written in another script — 龙胤立志传 cannot be reached
        // from a keyboard otherwise. Never shown, and never used to decide which game an upload
        // belongs to; see App\Support\LatinSearch for why both of those matter.
        //
        // ⚠ On `saving`, not `creating`: a game renamed afterwards — an admin tidying a title, an
        // IGDB match arriving late — would otherwise keep a handle for the name it no longer has.
        static::saving(function ($game) {
            if ($game->isDirty('name')) {
                $game->latin_search = \App\Support\LatinSearch::for($game->name);
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
