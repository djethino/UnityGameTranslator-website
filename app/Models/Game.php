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

        // The names the stores give the game besides its title — read from its own ids (see the
        // migration `keep_the_names_a_game_has_in_the_stores`). Set through setOtherNames().
        'other_names',

        'slug',
        'igdb_id',
        'rawg_id',
        'steam_id',
        'image_url',

        // The wide picture a link to the game's page is previewed with — App\Services\GameArt.
        // ⚠ Not `image_chosen_at`: only an admin's Apply on /admin/games writes it.
        'banner_url',

        // ⚠ No `adult*` column here, on purpose. They decide whether a game is shown at all, so
        // none of them may ever be set by a mass assignment from a request: they are written by
        // App\Services\AdultRating, by declareAdultBy() / withdrawAdultDeclaration() and by
        // /admin, each explicitly.
    ];

    protected $casts = [
        'adult' => 'boolean',
        'adult_detected' => 'boolean',
        'adult_override' => 'boolean',
        'adult_checked_at' => 'datetime',
        'adult_declared_at' => 'datetime',
        'stores_checked_at' => 'datetime',
        'image_chosen_at' => 'datetime',
    ];

    /**
     * What the stores proposed for this card — see App\Models\GameProposal.
     */
    public function proposals()
    {
        return $this->hasMany(GameProposal::class);
    }

    /**
     * The game's page on each store this card holds an id for, keyed by the store's name. Empty
     * when it holds none.
     *
     * ⚠ Every source the card holds an id from, RAWG included (user, 2026-10-02: what the card
     * knows about the game is shown on it) — RAWG was missing although cards made from it keep
     * `rawg_id`.
     */
    /**
     * This game as the API names the game a lineage is filed under (`LineageGame` in the spec):
     * the card, its title, and every id it answers to — what a client compares with the game its
     * player confirmed (common GameChoices). One shape for check-uuid, the public check and the
     * sync stream.
     */
    public function lineageBlock(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'steam_id' => $this->steam_id,
            'igdb_id' => $this->igdb_id !== null ? (int) $this->igdb_id : null,
            'rawg_id' => $this->rawg_id !== null ? (int) $this->rawg_id : null,
            // Additive: shown with the title, never compared (common GameChoices.WithNamesOf).
            'other_names' => $this->otherNames(),
        ];
    }

    public function storePages(): array
    {
        $pages = [];

        foreach (\App\Support\StoreLinks::Stores as $field => $store) {
            $pages[$store] = \App\Support\StoreLinks::forField($field, $this->{$field});
        }

        return array_filter($pages);
    }

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
                $game->slug = static::freeSlug($slug, $game->steam_id);
            }
        });

        // Something to TYPE for a title written in another script — 龙胤立志传 cannot be reached
        // from a keyboard otherwise. Never shown, and never used to decide which game an upload
        // belongs to; see App\Support\LatinSearch for why both of those matter.
        //
        // ⚠ On `saving`, not `creating`: a game renamed afterwards — an admin tidying a title, an
        // IGDB match arriving late — would otherwise keep a handle for the name it no longer has.
        static::saving(function ($game) {
            if ($game->isDirty(['name', 'other_names'])) {
                $game->refreshLatinSearch();
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

        // 🔴 **What makes asking the stores again worth it** (user, 2026-10-05: "le check store refait
        // tous les jeux à chaque fois ?"). The stores are asked about a card by its title and its
        // ids (App\Services\StoreProposals); the same card asked again gets the same answer. A new
        // title, an id added or removed, or a new cover is a new question — so it, and nothing
        // else, makes the card due again. The check itself writes quietly and never reaches here.
        static::saving(function ($game) {
            if ($game->exists && $game->isDirty(['name', 'steam_id', 'igdb_id', 'image_url'])) {
                $game->stores_checked_at = null;
            }
        });
    }

    /**
     * The cards whose translations were published from a machine that READ one of these product
     * names in the game's files (`translations.game_read`) — game ids keyed by the name, lowered.
     *
     * 🔴 **How a card answers to the names its own players' machines read** (analyse/
     * identite-des-jeux-parcours.md, T16). `unity_name` holds one name per card and refuses, on a
     * card with a Steam id, any name that does not look like its title — the guard against a
     * declared name taking a popular game's key. But "JHL" for a title that spells the words out is
     * a real product name, and the copies without a Steam id that read it found nothing.
     *
     * ⚠ **Read by name searches only, as a union** — never by GameResolver, never in place of the
     * other matches. Adding a card to an answer can only widen it, and the caller picks among the
     * games named (common/GameNames.Which); it can never decide where somebody's upload is filed.
     */
    public static function readAs(array $names): \Illuminate\Support\Collection
    {
        $names = array_values(array_filter(array_map(fn ($n) => trim((string) $n), $names), fn ($n) => $n !== ''));

        if ($names === []) {
            return collect();
        }

        return Translation::whereIn('game_read->product_name', $names)
            ->get(['game_id', 'game_read'])
            ->groupBy(fn (Translation $t) => mb_strtolower((string) ($t->game_read['product_name'] ?? '')))
            ->map(fn ($rows) => $rows->pluck('game_id')->unique()->values());
    }

    /**
     * The slug, or a free variant of it when another card already has it.
     *
     * 🔴 **Two games can share a title** — each with its own Steam id — and the slug is unique.
     * Derived from the title alone, the second one could not be created at all; and as long as the
     * upload attached a homonym instead of creating it, nobody saw that it could not. The Steam id
     * makes the most telling suffix (`lost-echo-500`); a counter covers a card without one.
     *
     * ⚠ The first card keeps the plain slug: it is the address already shared and indexed.
     */
    public static function freeSlug(string $slug, ?string $steamId = null): string
    {
        $taken = fn (string $candidate) => static::where('slug', $candidate)->exists();

        if (!$taken($slug)) {
            return $slug;
        }

        if ($steamId && ctype_digit((string) $steamId) && !$taken($slug . '-' . $steamId)) {
            return $slug . '-' . $steamId;
        }

        $n = 2;
        while ($taken($slug . '-' . $n)) {
            $n++;
        }

        return $slug . '-' . $n;
    }

    /**
     * The names the stores give this game that are not its title — 侠影录 on Steam for a card
     * titled "Legacy of Shadows" after IGDB, or the reverse. Never one that is only another way of
     * writing the title (case, punctuation, spacing: GameNaming::flatten).
     *
     * @return list<string>
     */
    public function otherNames(): array
    {
        return self::namesBesides((string) $this->name, preg_split('/\R/u', (string) $this->other_names) ?: []);
    }

    /**
     * Keep these names as the game's other names — the ones that are not its title, once each.
     *
     * @param list<?string> $names
     */
    public function setOtherNames(array $names): void
    {
        $this->other_names = implode("\n", self::namesBesides((string) $this->name, $names)) ?: null;
    }

    /**
     * The names that are not the title, nor another of them written differently, in order.
     *
     * ⚠ "&" reads "and" here (IGDB writes "Might & Magic" where Steam writes "Might and Magic"): a
     * subtitle that only swaps the two says nothing. Here only — identifying a game keeps
     * GameNaming::flatten as it is.
     */
    private static function namesBesides(string $title, array $names): array
    {
        $keyOf = fn (string $name) => \App\Support\GameNaming::flatten(str_replace('&', ' and ', $name));
        $seen = [$keyOf($title) => true];
        $kept = [];

        foreach ($names as $name) {
            $name = trim((string) $name);
            $flat = $keyOf($name);
            if ($name === '' || $flat === '' || isset($seen[$flat])) {
                continue;
            }
            $seen[$flat] = true;
            $kept[] = $name;
        }

        return $kept;
    }

    /**
     * The title as a search engine and a reader of the page meet it: "侠影录 (Legacy of Shadows)".
     */
    public function titleWithOtherNames(): string
    {
        $others = $this->otherNames();

        return $others === [] ? (string) $this->name : $this->name . ' (' . implode(' / ', $others) . ')';
    }

    /**
     * The latin handle of the title AND of the other names (App\Support\LatinSearch).
     *
     * ⚠ Public for the same reason as refreshAdult(): a quiet save (the stores check) does not run
     * the `saving` hook that calls it.
     */
    public function refreshLatinSearch(): void
    {
        $handles = array_filter(array_map(
            fn ($name) => \App\Support\LatinSearch::for($name),
            array_merge([$this->name], $this->otherNames())
        ));

        $this->latin_search = $handles === [] ? null : implode(' ', array_unique($handles));
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
     * Games whose title contains what a person typed — in its own script, or in latin letters.
     *
     * 🔴 **The one definition of "search a game by its title", for every search box.** It was
     * written out in each controller, and three of them forgot the second half: the admin screens
     * could not find 龙胤立志传 from "longyin" while the public catalogue could (reported
     * 2026-09-23). The latin handle is what lets a keyboard reach a title written in another script
     * — see App\Support\LatinSearch, which stores both "long yin li zhi zhuan" and "longyinlizhizhuan"
     * so either spelling works.
     *
     * ⚠ Takes the RAW term and escapes it here (App\Support\Like): a caller that escaped first
     * would escape twice, and a search for "a_b" would stop finding "a_b".
     *
     * ⚠ Wrapped in its own group, so a caller can OR other columns beside it (the admin searches
     * Unity names and Steam ids too) without the two halves leaking into its other conditions.
     */
    public function scopeTitleMatches($query, string $term)
    {
        $escaped = \App\Support\Like::escape($term);

        // ⚠ And by the names the stores give it (otherNames, 2026-10-06): a card titled after
        // one store was out of reach for whoever typed the other's name.
        return $query->where(fn ($q) => $q
            ->where('name', 'like', '%' . $escaped . '%')
            ->orWhere('other_names', 'like', '%' . $escaped . '%')
            ->orWhere('latin_search', 'like', '%' . mb_strtolower($escaped) . '%'));
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

    /**
     * The first publisher says this game is for adults only.
     *
     * 🔴 **Called by ONE path: the upload that creates the card** (Api\TranslationController::store,
     * `adult_declared`). A game's mark is a fact about the game and many people translate one game;
     * giving each of them the say is giving any of them the power to take it out of everyone's
     * listings. It used to be a button on the game's page open to every translator of it — the rule
     * agreed was the first publisher (analyse/adult-declaration-at-publish.md).
     *
     * ⚠ Traced: it takes a game out of the default listings for everybody, and whoever looks at a
     * surprising mark must see who put it there without re-deriving it.
     */
    public function declareAdultBy(int $userId): void
    {
        $this->adult_declared_by = $userId;
        $this->adult_declared_at = now();
        $this->save();

        AuditLog::log('game.adult_declared', $userId, 'game', $this->id, ['game' => $this->name]);
    }

    /**
     * Whether this person may take back the declaration on this game: only the one who made it.
     * Somebody who ticked the box to see what it does gets out of it alone — the stores' mark and an
     * admin's word are not theirs to undo, and never were.
     */
    public function adultDeclarationIsBy(?int $userId): bool
    {
        return $userId !== null && $this->adult_declared_at !== null
            && (int) $this->adult_declared_by === $userId;
    }

    /** Take the declaration back. The caller has checked adultDeclarationIsBy(). */
    public function withdrawAdultDeclaration(int $userId): void
    {
        $this->adult_declared_by = null;
        $this->adult_declared_at = null;
        $this->save();

        AuditLog::log('game.adult_declaration_withdrawn', $userId, 'game', $this->id, ['game' => $this->name]);
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
