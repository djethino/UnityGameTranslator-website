<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Something a store proposes for a game card, waiting for an admin — see the migration
 * `let_the_stores_propose_what_a_game_card_lacks` for why a title match is never written directly
 * and why a rejection is kept.
 */
class GameProposal extends Model
{
    public const Pending = 'pending';
    public const Rejected = 'rejected';
    public const Applied = 'applied';

    /** The only columns a proposal may ever write into. */
    public const Fields = ['steam_id', 'igdb_id', 'image_url'];

    protected $fillable = [
        'game_id',
        'field',
        'value',
        'source',
        'detail',
        'conflict_game_id',
        'state',
    ];

    protected $casts = [
        'decided_at' => 'datetime',
    ];

    public function game()
    {
        return $this->belongsTo(Game::class);
    }

    /** The card that already carries this value, when there is one. */
    public function conflictGame()
    {
        return $this->belongsTo(Game::class, 'conflict_game_id');
    }

    public function scopePending($query)
    {
        return $query->where('state', self::Pending);
    }

    /**
     * Can an admin accept this? Not when another card holds the value: that is a merge.
     */
    public function isApplicable(): bool
    {
        return $this->state === self::Pending && $this->conflict_game_id === null;
    }
}
