<?php

namespace App\Services;

use App\Exceptions\WrongGame;
use App\Models\Game;
use App\Models\GameIdentifier;
use App\Support\GameNaming;

/**
 * The game card a NEW translation is filed under — found, or created. The one place a card is born
 * from a publication.
 *
 * 🔴 **One creator for both doors** (analyse/identite-des-jeux-parcours.md, T5). The upload API and
 * the site's upload form each had their own: the API kept the Steam id of the stores' answer and
 * never its IGDB or RAWG id, the form the opposite, and the form went through no resolution at all
 * — so a card's sources, and whether the mod could ever find it, depended on which door it came in
 * by. Both now hand what they were told to this class.
 *
 * Reads with App\Services\GameResolver (the same resolution `GET games/adult` answers with), and
 * WRITES on the way: the ids an answer carries, the Steam id a copy brings, the name a machine read.
 *
 * 🔴 **What the caller reads off the disk is KEPT** (`unity_name`, `unity_company`). It was used to
 * look the game up and then thrown away: when the game is new, IGDB or RAWG names it, and the
 * string every client can actually see — `Application.productName`, the first lines of
 * `<Game>_Data/app.info` — was recorded nowhere.
 *
 * ⚠ Filled in on games that already exist too, and only when empty: every upload carries the name,
 * so the catalogue completes itself as people publish. Nothing overwrites a value already there —
 * two machines disagreeing about a game's productName is a thing to notice, not to settle silently
 * by last-writer-wins.
 */
class GameFiling
{
    public function __construct(
        private GameResolver $resolver,
        private GameSearchService $stores,
        private AdultRating $rating,
    ) {
    }

    /**
     * What a client said it read in the game's own files, with the empty fields dropped — or null
     * when it said nothing of the kind (every client released before 2026-10-04). ⚠ An empty
     * object is not null: the client said it read nothing.
     *
     * @return array{product_name?: string, company_name?: string, steam_id?: string, steam_id_from?: string, engine?: string}|null
     */
    public static function readFrom(mixed $input): ?array
    {
        if (!is_array($input)) {
            return null;
        }

        return array_filter([
            'product_name' => $input['product_name'] ?? null,
            'company_name' => $input['company_name'] ?? null,
            'steam_id' => isset($input['steam_id']) ? (string) $input['steam_id'] : null,
            'steam_id_from' => $input['steam_id_from'] ?? null,
            'engine' => $input['engine'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');
    }

    /**
     * The publish-list answer the person took, or null.
     *
     * @return array{source: string, id: string}|null
     */
    public static function pickFrom(mixed $input): ?array
    {
        return is_array($input) && isset($input['source'], $input['id']) && $input['id'] !== ''
            ? ['source' => (string) $input['source'], 'id' => (string) $input['id']]
            : null;
    }

    /**
     * The card for a new translation, or null when nothing identifies the game: no card answers and
     * no store describes it.
     *
     * - by what the person CHOSE (`$pick`): that card, or the store's own description of that id —
     *   never a new search;
     * - otherwise by Steam id — the card's own, or one recorded as also being this game (a demo's);
     * - 🔴 by the DISPLAY name before the declared one, and that order is a guard: `unity_name` is a
     *   string a caller states about itself, so resolving on it first let an account send any name
     *   and be sent to the game holding it (see rememberUnityNames, the other half);
     * - by the name the machine reads, which is what other machines will search with;
     * - 🔴 by what the stores answer — one game is one card wherever the copy came from. ⚠ Searched
     *   on what the resolution ANSWERED, not on what the caller sent.
     *
     * @param array{product_name?: string, company_name?: string, steam_id?: string, engine?: string}|null $read
     * @param array{source: string, id: string}|null $pick
     *
     * @throws WrongGame when a fact read on disk contradicts the game found — before anything is written
     */
    public function cardFor(?string $steamId, ?string $gameName, ?string $company, ?array $pick, ?array $read): ?Game
    {
        // 🔴 **The key other machines resolve with comes from what a machine READ, never from what
        // a person CHOSE** (T16). A client that says what it read sends the chosen title as the
        // game name — recording that as `unity_name` filled the key with shop titles, and the name
        // the game states on disk was never kept. A client that says nothing of the kind keeps the
        // old reading of the name it sends.
        $declaredName = $read !== null ? ($read['product_name'] ?? null) : $gameName;
        $company = $read !== null ? ($read['company_name'] ?? null) : $company;

        // A Steam id read in the game's own files is a fact about this installation: the card is
        // given it when it has none, exactly like the one the old clients send.
        $steamId ??= $read['steam_id'] ?? null;

        $found = $this->resolver->resolve($steamId, $gameName, $pick);
        $external = $found['external'];

        $this->refuseWrongGame($found['game'], $external, $read);

        if ($found['via'] === 'steam') {
            $this->rememberUnityNames($found['game'], $declaredName, $company);

            return $found['game'];
        }

        if ($found['via'] === 'name' || $found['via'] === 'unity' || ($found['via'] === 'pick' && !$external)) {
            $this->attachSteamId($found['game'], $steamId);
            $this->rememberUnityNames($found['game'], $declaredName, $company);

            return $found['game'];
        }

        // 🔴 **A game nothing identifies is refused, never created** (user, 2026-10-01 and again
        // 2026-10-04: "un jeu non identifiable ne partagera jamais rien avec personne… on n'est pas
        // un stockage poubelle… sinon je fais des projets Unity sur mon disque et j'envoie plein de
        // fausses traductions"). A new card is made only from what a store DESCRIBES: an id a store
        // answers for, or a title the stores know as exactly one game. A name alone, a Steam id
        // Steam does not know, a picked id its store does not describe — all of them can be typed
        // into any Unity project on anybody's disk, and what is filed under them reaches nobody.
        //
        // ⚠ This includes a store that is down when asked: the publication is refused with the way
        // out (search again) rather than filed under a card nothing vouches for. It replaces the
        // 2026-10-04 morning choice of making a card from the picked id when its store was silent.
        // The caller says it (`game_not_found`).
        if (!$external) {
            if ($pick || $steamId) {
                \Illuminate\Support\Facades\Log::info('Publication refused: no store describes the game named', ['pick' => $pick, 'steam_id' => $steamId]);
            }

            return null;
        }

        $title = $external['name'] ?? $gameName;
        $resolvedSteamId = $external['steam_id'] ?? $steamId;
        $known = $found['game'];

        if ($known) {
            // The copy in hand may know something the card does not: an id it was created
            // without, and the product name a machine reads.
            $fill = $this->storeIdsFor($known, $external);

            if ($resolvedSteamId && !$known->steam_id) {
                $fill['steam_id'] = $resolvedSteamId;
            }

            if ($fill !== []) {
                $known->update($fill);

                // The card can now be asked about at the store, and it could not before: a
                // game rated on its name alone was judged by IGDB, which misses most of what
                // Steam states outright. See App\Services\AdultRating.
                $this->rating->rate($known);
            }

            $this->rememberUnityNames($known, $declaredName, $company);
            $this->rememberDemoId($known, $external);

            return $known;
        }

        // Created under the title the world knows it by — and carrying the name the machine
        // that published it reads, which is what makes it findable from another machine.
        //
        // ⚠ **The same rule as an update.** When the title comes from IGDB rather than from the
        // caller, the declared name is a separate claim about the game — so it is held to the
        // same test. Without it the FIRST publisher of a game chose its key freely while every
        // later one was refused, and a key chosen badly cannot be written again ("never
        // overwrite"), so the real product name was locked out for good.
        $created = Game::create([
            'name' => $title,
            'unity_name' => GameNaming::isFormOfTitle($declaredName, $title) ? $declaredName : null,
            'unity_company' => $company,
            'steam_id' => $resolvedSteamId,
            'image_url' => $external['image_url'] ?? null,
        ] + $this->storeIdsFor(null, $external));

        $this->rememberDemoId($created, $external);

        // 🔴 **Rated before it can ever be listed.** A card is created by the upload that
        // publishes the first translation of a game, so this is the only moment between the
        // game not existing and it appearing in the catalogue. A nightly pass would leave a
        // window of up to a day where a game marked for adults only is shown to everyone.
        $this->rating->rate($created);

        return $created;
    }

    /**
     * Refuse an upload into an EXISTING lineage — an update, a branch, a fork — when the Steam id the
     * machine read in the game's files is not the game that lineage is filed under.
     *
     * 🔴 **The last step of the chain the 2026-09-04 decision guarded against** (analyse/
     * identite-des-jeux.md § « Voie écartée », analyse/identite-des-jeux-parcours.md « Tranché »):
     * a file taken for the wrong game, by any path, republished, became a branch in that other
     * game's lineage — in everybody's database. Only a new translation was checked (cardFor); a
     * lineage that exists took its game as given, whatever the installation said.
     *
     * ⚠ Sure only: a Steam id read on disk (never a title), against a card that has one. A card
     * without one, or a client that says nothing it read (released before 2026-10-04), is let
     * through as before. A demo of the lineage's game is that game: an id no card answers to yet is
     * asked of the store once, and remembered when it is a demo of this one.
     *
     * @param string $wayOut The sentence that says who can put it right — the caller knows the role
     *
     * @throws WrongGame before anything is written
     */
    public function refuseIntoLineage(Game $game, ?array $read, string $wayOut): void
    {
        $readSteam = $read['steam_id'] ?? null;

        if (!$readSteam || !$game->steam_id) {
            return;
        }

        if (Game::answeringToSteamId($readSteam)->whereKey($game->id)->exists()) {
            return;
        }

        $store = $this->stores->getGameFromSteam($readSteam);
        if (($store['demo_steam_id'] ?? null) === $readSteam && (string) ($store['steam_id'] ?? '') === (string) $game->steam_id) {
            $this->rememberDemoId($game, $store);

            return;
        }

        throw new WrongGame("Wrong game: the installed game is Steam app {$readSteam}, this translation "
            . "is filed under {$game->name} (Steam app {$game->steam_id}). {$wayOut}");
    }

    /**
     * Refuse when what the machine read in the game's files CONTRADICTS the game found — and only
     * then.
     *
     * 🔴 **Refused when sure, never on a resemblance** (decided 2026-10-02: "si on peut être sûr on
     * refuse, sinon on avertit"). Sure means two facts disagree:
     *
     * - a Steam id read on disk (`steam_appid.txt`, the library manifest) that the game found does
     *   not answer to — its own id, or one recorded as also being it (a demo's);
     * - an engine the game runs on, when the store names the game's engines and that one is not
     *   among them.
     *
     * ⚠ Titles that do not look alike prove nothing — a product name made of initials for a shop
     * title that spells the words out is ordinary — so they are left to the client to WARN about,
     * before sending. A store that names no engine proves nothing either.
     */
    private function refuseWrongGame(?Game $card, ?array $external, ?array $read): void
    {
        if (!$read) {
            return;
        }

        $readSteam = $read['steam_id'] ?? null;
        $foundSteam = $card?->steam_id ?? ($external['steam_id'] ?? null);

        if ($readSteam && $foundSteam && (string) $foundSteam !== $readSteam) {
            $sameGame = $card
                ? Game::answeringToSteamId($readSteam)->whereKey($card->id)->exists()
                : ($external['demo_steam_id'] ?? null) === $readSteam;

            if (!$sameGame) {
                throw new WrongGame("Wrong game: the installed game is Steam app {$readSteam}, the picked one is "
                    . "Steam app {$foundSteam}. Pick the game again.");
            }
        }

        $engine = $read['engine'] ?? null;
        $engines = $external['engines'] ?? [];

        if ($engine && $engines !== [] && !in_array(mb_strtolower($engine), array_map('mb_strtolower', $engines), true)) {
            throw new WrongGame('Wrong game: the picked one is made with ' . implode(', ', $engines)
                . ", the installed game with {$engine}. Pick the game again.");
        }
    }

    /**
     * The IGDB or RAWG id the stores' answer carries, as the column to fill — only where the card
     * has none and no other card holds it.
     *
     * 🔴 **The answer the card was made from is kept, not thrown away** (T5/T12). ⚠ Never over a
     * value already there, and never one another card answers to: that would make one game two
     * cards' — the same rule as StoreProposals::apply.
     *
     * @return array<string, int|string> Zero or one `column => id`.
     */
    private function storeIdsFor(?Game $card, array $external): array
    {
        $field = match ($external['source'] ?? null) {
            'igdb' => 'igdb_id',
            'rawg' => 'rawg_id',
            default => null,
        };
        $id = $external['id'] ?? null;

        if ($field === null || $id === null || ($card && $card->{$field})) {
            return [];
        }

        $heldElsewhere = Game::where($field, $id)
            ->when($card, fn ($q) => $q->where('id', '!=', $card->id))
            ->exists();

        return $heldElsewhere ? [] : [$field => $id];
    }

    /**
     * A card found by name, with no Steam id yet, receives the one the copy carries — the FULL
     * game's when that copy is a demo, the demo's own then becoming an alias.
     *
     * ⚠ **One store call, and only here**: the condition is a card with no id at all, so it can
     * happen once per card and never again. If Steam does not answer, the id is written as sent
     * rather than the upload being refused for a detail. Filling a blank only: an id already
     * recorded is never moved.
     */
    private function attachSteamId(Game $game, ?string $steamId): void
    {
        if (!$steamId || $game->steam_id) {
            return;
        }

        $store = $this->stores->getGameFromSteam($steamId);
        $demoId = $store['demo_steam_id'] ?? null;

        if ($demoId && !empty($store['steam_id'])) {
            $game->update(['steam_id' => $store['steam_id']]);
            GameIdentifier::remember($game, GameIdentifier::Steam, $demoId, GameIdentifier::BecauseDemo);

            return;
        }

        $game->update(['steam_id' => $steamId]);
    }

    /**
     * Records the demo's own app id on the game it is a demo of — so the store is asked once, not
     * once per player. ⚠ The write refuses on its own if that id belongs elsewhere
     * (App\Models\GameIdentifier).
     */
    private function rememberDemoId(Game $game, array $external): void
    {
        $demoId = $external['demo_steam_id'] ?? null;

        if ($demoId) {
            GameIdentifier::remember($game, GameIdentifier::Steam, $demoId, GameIdentifier::BecauseDemo);
        }
    }

    /**
     * Writes what a machine reported about a game, without ever overwriting what is there.
     *
     * ⚠ Only fills blanks. A game published from two installs can report two different product
     * names — a repack, a demo, a regional build — and letting the last upload win would move the
     * key other machines resolve with, silently.
     */
    private function rememberUnityNames(Game $game, ?string $gameName, ?string $company): void
    {
        // 🔴 **On a game with a Steam id, only a FORM OF ITS TITLE.** `unity_name` is consulted for
        // games resolved without a Steam id; accepting any declared name on a game that has one let
        // an account publish with the Steam id of a popular game and any product name it liked, and
        // that name became the key every other machine resolves with — and "never overwrite" made
        // it permanent. Refusing outright would cost the case the column exists for: a copy of that
        // same game WITHOUT a Steam id. "LONESTAR" against "Lonestar: The Game" passes; "Cattails"
        // against "Cat" does not, and neither does anything unrelated. A name read that does not
        // pass still finds the card through its translations (Game::readAs).
        if ($game->steam_id && !GameNaming::isFormOfTitle($gameName, $game->name)) {
            return;
        }

        $fill = [];

        // ⚠ **Refused when the name already belongs to another game**, under either column. A
        // declared string may not be made to collide with a name somebody else's game answers to.
        $taken = $gameName !== null && Game::where('id', '!=', $game->id)
            ->where(fn ($q) => $q->where('unity_name', $gameName)
                                 ->orWhereRaw('LOWER(name) = ?', [strtolower($gameName)]))
            ->exists();

        if ($gameName && !$game->unity_name && !$taken) {
            $fill['unity_name'] = $gameName;
        }

        if ($company && !$game->unity_company) {
            $fill['unity_company'] = $company;
        }

        if ($fill !== []) {
            $game->update($fill);
        }
    }
}
