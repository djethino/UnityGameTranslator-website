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

    /**
     * The other store ids this same game answers to — see App\Models\GameIdentifier.
     */
    public function identifiers()
    {
        return $this->hasMany(GameIdentifier::class);
    }

    /**
     * Games reachable by these Steam app ids — the card's own id, or an id recorded for it.
     *
     * 🔴 **One scope, because there are six places that resolve by app id** (`translations` search,
     * the batch, the upload path, the two game listings and GameSearchService). Written out at each
     * of them, this rule would be six chances for the next one to forget the alias — which is
     * exactly what five copies of the LIKE escaping cost.
     *
     * ⚠ **`steam_id` stays first and stays untouched.** The alias only ever ADDS what used to
     * resolve to nothing: with an empty table this scope selects precisely what
     * `whereIn('steam_id', …)` selected before it, which is what makes it safe to put on paths
     * that already work.
     *
     * ⚠ A sub-select rather than `whereHas`: one plan, and the id list is already indexed by
     * `(source, value)`.
     */
    public function scopeAnsweringToSteamId($query, string|array $ids)
    {
        $ids = array_values(array_filter((array) $ids, fn ($id) => $id !== null && $id !== ''));

        if (empty($ids)) {
            // Asked about nothing, answer nothing — never "everything".
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function ($q) use ($ids) {
            $q->whereIn('steam_id', $ids)
                ->orWhereIn('id', GameIdentifier::query()
                    ->where('source', GameIdentifier::Steam)
                    ->whereIn('value', $ids)
                    ->select('game_id'));
        });
    }

    public function getRouteKeyName()
    {
        return 'slug';
    }
}
