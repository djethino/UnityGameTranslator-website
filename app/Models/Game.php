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

        // ⚠ No `adult*` column here, on purpose. They decide whether a game is shown at all, so
        // none of them may ever be set by a mass assignment from a request: they are written by
        // App\Services\AdultRating, by the declaration route and by /admin, each explicitly.
    ];

    protected $casts = [
        'adult' => 'boolean',
        'adult_detected' => 'boolean',
        'adult_override' => 'boolean',
        'adult_checked_at' => 'datetime',
        'adult_declared_at' => 'datetime',
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

        // Is this game for adults only — the answer every listing filters on, derived here so it
        // can never drift from the three columns that decide it. Same mechanism as latin_search
        // above, and the same reason: a value computed at read time cannot be indexed, and this
        // one is read by the catalogue, the front page and the API on every request.
        //
        // 🔴 **A monotone OR, with one exception: the admin.** Detection and a contributor's
        // declaration can only ever RAISE the flag; only `adult_override` can lower it. That is
        // what makes two contributors unable to contradict each other — and what lets a
        // declaration rescue a game detection missed, since "the store said nothing" is not "the
        // store said no" (a game whose adult content ships as a separate DLC says nothing).
        static::saving(fn ($game) => $game->refreshAdult());
    }

    /**
     * Recompute the derived answer from the three columns that decide it.
     *
     * ⚠ **Public because `saveQuietly()` does not fire the hook above.** A quiet save unsets the
     * event dispatcher, so a backfill that must not re-timestamp the catalogue — the trap this
     * project has paid for — would write the inputs and leave `adult` stale. Calling this first is
     * what keeps ONE definition of the rule instead of a second copy in a command.
     */
    public function refreshAdult(): void
    {
        $this->adult = $this->adult_override !== null
            ? (bool) $this->adult_override
            : ((bool) $this->adult_detected || $this->adult_declared_at !== null);
    }

    /**
     * Games nobody has to opt in to see. ⚠ Reads the derived column, so it says exactly what
     * `saving` decided — never re-derive the rule here, it would be a second place to get wrong.
     */
    public function scopeNotAdult($query)
    {
        return $query->where('adult', false);
    }

    /**
     * Who says this game is for adults only, or null when it is not — 'steam', 'steam_dlc',
     * 'igdb', 'contributor' or 'admin'.
     *
     * ⚠ **This is the CITATION, not the authority.** Authority is settled in `saving` above, where
     * the admin wins. What a reader needs on the page is the most checkable source that justifies
     * the mark: "according to Steam" can be verified in one click, "an admin decided" cannot. So
     * an admin override that merely agrees with the store still cites the store, and 'admin'
     * appears only when nothing else justifies the mark.
     */
    public function adultSource(): ?string
    {
        if (!$this->adult) {
            return null;
        }

        if ($this->adult_detected && $this->adult_detected_source) {
            return $this->adult_detected_source;
        }

        if ($this->adult_declared_at !== null) {
            return 'contributor';
        }

        return 'admin';
    }

    /**
     * The same answer as adultSource(), in the words a reader is shown — 'steam', 'igdb',
     * 'contributor' or 'admin'.
     *
     * ⚠ 'steam_dlc' folds into 'steam' here on purpose: the store said it either way, and "it was
     * the add-on that carried the descriptor" is a detail of HOW we asked, which belongs on the
     * admin screen and not on a game's page.
     */
    public function adultCitation(): ?string
    {
        return $this->adultSource() === 'steam_dlc' ? 'steam' : $this->adultSource();
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
