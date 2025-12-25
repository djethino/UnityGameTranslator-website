<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Game extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'igdb_id',
        'rawg_id',
        'image_url',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($game) {
            if (empty($game->slug)) {
                $game->slug = Str::slug($game->name);
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
