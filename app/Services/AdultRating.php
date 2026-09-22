<?php

namespace App\Services;

use App\Models\Game;
use App\Support\GameNaming;

/**
 * Is this game for adults only — asked of the stores, never guessed from a title.
 *
 * 🔴 **The threshold is Steam's descriptor 3, and it was settled by measurement** (2026-09-22, see
 * TODO.md). Descriptor 1 (some nudity or sexual content) is what The Witcher 3 carries, 5 (general
 * mature content) is what GTA V carries, 2 is violence: filtering on any of those would hide
 * mainstream games and answer a question nobody asked. 3 — "Adult Only Sexual Content" — is the
 * only one Steam itself treats as opt-in, so marking on it reproduces the store's own line rather
 * than inventing one.
 *
 * ## Why the DLC are asked too
 *
 * ⚠ **An adult game can carry no descriptor at all.** The usual publisher move is to ship the
 * base game clean and the 18+ content as a separate free DLC — measured on app 3149980, whose
 * `content_descriptors` is empty while its DLC 3183690 carries `[1,3,4,5]`. The base app lists its
 * own DLC (`dlc: [...]` in the same response), so the fix is mechanical: read them, and raise the
 * verdict to the game.
 *
 * ## Why IGDB is only a fallback
 *
 * IGDB's theme 42 ("Erotic") produced **no false positive** on twelve mainstream references, but
 * its recall is poor — it missed four of the five games that prompted this work. And it describes
 * the WORK, not the build somebody plays: a game sold censored on Steam can carry 42 for its
 * uncensored release elsewhere. So it is asked only when Steam could not answer at all (no app id,
 * or an app the store no longer serves), never to second-guess an answer Steam gave.
 */
class AdultRating
{
    /** Steam's "Adult Only Sexual Content". The only descriptor that marks a game here. */
    public const SteamAdultOnly = 3;

    /** IGDB's "Erotic" theme. */
    public const IgdbErotic = 42;

    /**
     * How many DLC of one game are asked about. ⚠ A bound, not a timer: the store is rate-limited
     * (200 requests per 5 minutes) and a game can carry dozens of DLC, so an unbounded loop on one
     * upload would spend the whole budget. Ten covers every case measured; a game hiding its adult
     * content in its eleventh DLC is caught by a contributor's declaration instead.
     */
    private const DlcAsked = 10;

    public function __construct(private GameSearchService $games)
    {
    }

    /**
     * Ask the stores about this game and write down what they said.
     *
     * ⚠ **Reconciled, never accumulated**: a pass that finds nothing CLEARS a previous detection,
     * because a store page can change and the row must say what the source says today. It cannot
     * un-mark the game on its own — a contributor's declaration and an admin's word live in other
     * columns, and App\Models\Game's `saving` hook ORs the three together.
     */
    public function rate(Game $game, bool $quiet = false): bool
    {
        $verdict = $this->judge($game->steam_id, $game->igdb_id, $game->name);
        $changed = $game->adult_detected !== ($verdict !== null) || $game->adult_detected_source !== $verdict;

        $game->adult_detected = $verdict !== null;
        $game->adult_detected_source = $verdict;
        $game->adult_checked_at = now();

        if (!$quiet) {
            $game->save();

            return $changed;
        }

        // ⚠ A pass over the whole catalogue must not re-timestamp it: `updated_at` moves every
        // listing sorted by freshness and makes a catalogue look like it changed on the day of a
        // deploy. `saveQuietly()` silences the model events — including the hook that derives
        // `adult` — so the derivation is asked for by hand here. See Game::refreshAdult.
        $game->refreshAdult();
        $game->timestamps = false;
        $game->saveQuietly();

        return $changed;
    }

    /**
     * Which source says this game is for adults only — 'steam', 'steam_dlc', 'igdb' — or null when
     * none of them does. ⚠ Null means "nothing found", never "the stores say it is all-ages": the
     * DLC trick above is exactly a game the stores answer about while saying nothing.
     */
    public function judge(?string $steamId, ?int $igdbId, ?string $name): ?string
    {
        if ($steamId) {
            $app = $this->games->steamApp($steamId);

            if ($app !== null) {
                if ($this->carriesAdultOnly($app)) {
                    return 'steam';
                }

                if ($this->aDlcCarriesAdultOnly($app)) {
                    return 'steam_dlc';
                }

                // Steam answered. Its answer stands — see the class comment on IGDB.
                return null;
            }
        }

        return $this->igdbSaysErotic($igdbId, $name) ? 'igdb' : null;
    }

    /**
     * Does this app's own classification carry the adult-only descriptor?
     */
    private function carriesAdultOnly(array $app): bool
    {
        $ids = $app['content_descriptors']['ids'] ?? [];

        return is_array($ids) && in_array(self::SteamAdultOnly, array_map('intval', $ids), true);
    }

    /**
     * Does any of this app's own DLC carry it? See the class comment for why this exists.
     */
    private function aDlcCarriesAdultOnly(array $app): bool
    {
        $dlc = $app['dlc'] ?? [];

        if (!is_array($dlc) || empty($dlc)) {
            return false;
        }

        // ⚠ Unique before the bound: Steam repeats an id in that list (3149980 answers
        // [4932800, 4490200, 3183690, 4932800]), so without this the budget is spent twice on the
        // same app.
        $dlc = array_slice(array_values(array_unique(array_map('strval', $dlc))), 0, self::DlcAsked);

        foreach ($dlc as $id) {
            $app = $this->games->steamApp($id);

            if ($app !== null && $this->carriesAdultOnly($app)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Does IGDB place this game under its "Erotic" theme?
     *
     * ⚠ **By id when we have one, and by an EXACT name otherwise.** IGDB's `search` is loose and
     * happily answers with a neighbour — asked for "Love n Life Happy Student" it also returns its
     * three add-ons. Accepting a loose hit would mark a game on somebody else's classification,
     * which is the one failure this whole design is built to avoid.
     */
    private function igdbSaysErotic(?int $igdbId, ?string $name): bool
    {
        if ($igdbId) {
            $rows = $this->games->igdb('games', 'where id = ' . intval($igdbId) . '; fields name,themes;');

            return $this->hasEroticTheme($rows[0] ?? null);
        }

        if (!$name || mb_strlen($name) < 2) {
            return false;
        }

        $safe = GameSearchService::escapeIGDBQuery($name);

        if (trim($safe) === '') {
            return false;
        }

        $rows = $this->games->igdb('games', 'search "' . $safe . '"; fields name,themes; limit 5;');

        // Case, punctuation and spacing differ between our card and IGDB's ("Love N Life" against
        // "Love n Life") and neither makes it another game — the project's one normalizer says so.
        $wanted = GameNaming::flatten($name);

        foreach ($rows as $row) {
            if (GameNaming::flatten($row['name'] ?? '') === $wanted) {
                return $this->hasEroticTheme($row);
            }
        }

        return false;
    }

    private function hasEroticTheme(?array $row): bool
    {
        $themes = $row['themes'] ?? [];

        return is_array($themes) && in_array(self::IgdbErotic, array_map('intval', $themes), true);
    }
}
