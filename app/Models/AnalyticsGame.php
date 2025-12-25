<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnalyticsGame extends Model
{
    protected $table = 'analytics_games';

    protected $fillable = [
        'date',
        'game_id',
        'page_views',
        'downloads',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }
}
