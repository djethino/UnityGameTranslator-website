<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Another id the same game answers to — a demo's app id today.
 *
 * 🔴 **Writing one is claiming an identity, so the rule lives here and nowhere else.** An alias
 * says "this id IS that card": get it wrong and somebody's upload is filed under a game they never
 * played. That is the same class of mistake `unity_name` had, and it was fixed the same way — one
 * rule, in one place, with a table of cases against it.
 *
 * ## What may be recorded
 *
 * | | |
 * |---|---|
 * | an id **no card carries** in `games.steam_id` | ✅ that is the whole point: it resolved to nothing |
 * | an id **already recorded** for the same card | ✅ nothing happens, saying it twice is not an error |
 * | an id a card carries as its own `steam_id` | ❌ never — the card is the authority on its own id |
 * | an id **another card** already claims as an alias | ❌ never — first claim wins, silently ignored |
 *
 * ⚠ The last two are refusals, not exceptions: nothing is thrown, nothing is logged as an error.
 * A client that publishes twice from a demo must not see a failure the second time.
 *
 * ⚠ **`games.steam_id` is not unique** (a plain index — two cards may already share one), so
 * "does a card carry this id" is a question with possibly several answers. Any of them refuses.
 */
class GameIdentifier extends Model
{
    public const Steam = 'steam';

    /** Steam's own `fullgame` said this app is a demo of another. */
    public const BecauseDemo = 'demo';

    protected $fillable = ['game_id', 'source', 'value', 'reason'];

    public function game()
    {
        return $this->belongsTo(Game::class);
    }

    /**
     * Attach an id to a card, if nothing else answers to it.
     *
     * Returns whether the card now answers to that id — true when it was written, true when it was
     * already there, false when something else holds it.
     */
    public static function remember(Game $game, string $source, string $value, ?string $reason = null): bool
    {
        $value = trim($value);

        // Nothing to resolve by, and a length the column could not hold anyway.
        if ($value === '' || mb_strlen($value) > 64) {
            return false;
        }

        $held = static::query()->where('source', $source)->where('value', $value)->first();

        if ($held) {
            // Already ours: idempotent. Somebody else's: first claim wins, and we do not move it.
            return $held->game_id === $game->id;
        }

        // A card's own id is the card's to state. This is also what stops a demo whose `fullgame`
        // points at a game we already know from being written as an alias of itself.
        if ($source === self::Steam && Game::query()->where('steam_id', $value)->exists()) {
            return false;
        }

        static::query()->create([
            'game_id' => $game->id,
            'source' => $source,
            'value' => $value,
            'reason' => $reason,
        ]);

        return true;
    }
}
