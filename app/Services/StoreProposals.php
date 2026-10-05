<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Game;
use App\Models\GameProposal;
use App\Models\User;
use App\Support\GameNaming;
use App\Support\StoreLinks;
use Illuminate\Validation\ValidationException;

/**
 * What the stores can tell us about a game card that it lacks — proposed, never written.
 *
 * 🔴 **The line between automatic and accepted** (decided 2026-09-22): a fact read FROM an id is
 * automatic — that is App\Services\AdultRating, which reads the store page of a game already
 * identified and cannot overrule an admin. A match guessed FROM a title is a proposal — that is
 * this class. A title can land on the wrong game, and a Steam id attached to a card decides which
 * card every later upload of that game is filed under.
 *
 * What it proposes, and only where the card has nothing:
 *
 * | field | from | when |
 * |---|---|---|
 * | `steam_id` | Steam's search, EXACT title only | the card has none |
 * | `igdb_id` | IGDB's search, EXACT title only | the card has none, and its Steam id did not name one |
 * | `image_url` | every picture the card's own ids give (App\Services\GameArt) | on "Check again" only — the variants |
 *
 * And what it WRITES, because it is read from an id the card already holds (user, 2026-10-06 —
 * analyse/images-des-jeux.md):
 *
 * | field | from | guard |
 * |---|---|---|
 * | `igdb_id` | the one IGDB game linked to the card's Steam id | exactly one game; no other card holds it |
 * | `image_url` | the best picture of GameArt | not after an admin chose one (`image_chosen_at`); only a better one |
 * | `banner_url` | the Steam header | — |
 *
 * ⚠ Never the display name, never `unity_name`, never an adult mark: those are human decisions or
 * facts no store holds. And never an id over a value that is already there — the same rule the
 * upload path follows. ⚠ No `rawg_id` is ever looked for: RAWG is searched by title only, its base
 * is edited by anyone, and on a card with a Steam id a RAWG pick already lands by that Steam id —
 * the id would be a link, at the price of a guess (user, 2026-10-06).
 *
 * ⚠ **A picture only ever comes from an id the card already HAS.** Proposing one alongside a
 * proposed Steam id would let an admin accept the picture and reject the id — a picture of the
 * wrong game. Accept the id, and the next check finds its pictures.
 */
class StoreProposals
{
    /**
     * How long one click may spend asking the stores. ⚠ A budget, not a wait: it bounds the work
     * of one request and the next click takes up the cards still due (checkDue). The stores answer
     * in well under a second each; the bound is there for the day one of them is slow, or many
     * cards arrive at once, so a click never runs into PHP's own limit.
     */
    public const BudgetSeconds = 15;

    public function __construct(private GameSearchService $stores, private AdultRating $rating, private GameArt $art)
    {
    }

    /**
     * Ask the stores about the cards DUE, within the budget.
     *
     * 🔴 **Due means there is a new question, not that time has passed** (user, 2026-10-05: "le
     * check store refait tous les jeux à chaque fois ? qu'est-ce que ça va donner quand on aura
     * 20000 jeux ?"). A card is due when it was never asked, or since it was renamed or one of its
     * ids or its cover changed (Game: `stores_checked_at` is cleared then). Every click used to
     * walk the whole catalogue oldest-first and ask again about every title no store knows — the
     * same question, the same empty answer, for ever. A store that adds a game later sends no
     * signal: "Check again" on the card's row is the way to ask about it then (checkOne).
     *
     * ⚠ Read in pages of ids, never the whole catalogue at once.
     *
     * @return array{checked: int, proposed: int, left: int, stopped: bool}
     */
    public function checkDue(): array
    {
        $started = microtime(true);
        $checked = 0;
        $proposed = 0;

        // A batch: it draws on the background share of the Steam ceiling (App\Support\SteamStore),
        // and Steam unavailable ends it — the card stays due, nothing is marked asked.
        $stopped = false;
        foreach (Game::whereNull('stores_checked_at')->lazyById(200) as $game) {
            // The budget, and the store's refusal: Steam's limit is per address and players
            // publishing come first (App\Support\SteamStore).
            if (microtime(true) - $started > self::BudgetSeconds || \App\Support\SteamStore::refusing()) {
                break;
            }

            try {
                $proposed += \App\Support\SteamStore::inBackground(fn () => $this->checkOne($game));
            } catch (\App\Exceptions\StoreUnavailable) {
                $stopped = true;
                break;
            }
            $checked++;
        }

        return [
            'checked' => $checked,
            'proposed' => $proposed,
            'left' => Game::whereNull('stores_checked_at')->count(),
            'stopped' => $stopped,
        ];
    }

    /**
     * Ask the stores about one card now, and mark it asked. Returns how many NEW proposals were
     * written. The way to ask again about a card that is not due — a store may know it by now.
     *
     * `$variants`: also propose every other picture the card's ids give, for an admin to choose
     * from — "Check again" on one card (user, 2026-10-06: "si je fais un check unitaire en admin
     * […] il me propose les variantes"). Never in the batch: a hundred cards would each carry three
     * proposals nobody asked for.
     *
     * ⚠ Throws StoreUnavailable when Steam could not be asked: the card is then NOT marked asked.
     */
    public function checkOne(Game $game, bool $variants = false): int
    {
        $proposed = $this->check($game, $variants);

        // ⚠ Quietly and without timestamps: asking a store about a card is not a change to the
        // card, and `updated_at` would reorder every listing sorted by freshness. Nothing here
        // touches the adult columns, so the derived answer cannot go stale.
        $game->stores_checked_at = now();
        $game->timestamps = false;
        $game->saveQuietly();

        return $proposed;
    }

    /**
     * Ask the stores about one card. Returns how many NEW proposals were written.
     */
    public function check(Game $game, bool $variants = false): int
    {
        // Proposals for a field the card has since filled — by an upload attaching an id, say —
        // are no longer proposals. Left behind, they would sit in "Pending" offering to overwrite.
        $this->forgetMootProposals($game);

        $new = 0;

        // Before the title searches: an IGDB id read from the Steam id is a fact, and a card that
        // gets one has no IGDB title question left.
        if (!$game->igdb_id && $game->steam_id) {
            $this->igdbIdFromSteamId($game);
        }

        if (!$game->steam_id) {
            // Every exact title, homonyms included: an admin decides which one is this card's
            // (GameNaming::exactTitleMatches) — "Love n Life: Happy Student" also returns its DLC.
            foreach (GameNaming::exactTitleMatches($this->stores->steamSearch($game->name), $game->name) as $hit) {
                $new += $this->propose($game, 'steam_id', $hit['id'], 'steam', $hit['name'], StoreLinks::steam($hit['id']));
            }
        }

        if (!$game->igdb_id) {
            $safe = GameSearchService::escapeIGDBQuery($game->name);

            // A title of symbols only escapes to nothing: there is nothing to ask IGDB then,
            // and an empty search would answer with whatever it likes.
            if (trim($safe) !== '') {
                // `url` too: an IGDB page is addressed by a slug, so the id alone gives the admin
                // nothing to open.
                $rows = $this->stores->igdb('games', 'search "' . $safe . '"; fields id,name,url; limit 10;');
                $hits = array_map(fn ($row) => [
                    'id' => (string) ($row['id'] ?? ''),
                    'name' => (string) ($row['name'] ?? ''),
                    'url' => $row['url'] ?? null,
                ], $rows);

                foreach (GameNaming::exactTitleMatches($hits, $game->name) as $hit) {
                    $new += $this->propose($game, 'igdb_id', $hit['id'], 'igdb', $hit['name'], StoreLinks::igdb($hit['url']));
                }
            }
        }

        $new += $this->pictures($game, $variants);

        return $new;
    }

    /**
     * The IGDB id of a card that has a Steam id, written when IGDB links that Steam id to exactly
     * ONE of its games and no other card holds it (user, 2026-10-06: "on ne veut pas d'erreurs").
     *
     * ⚠ The same reading the site already trusts to identify a game when Steam is down
     * (GameSearchService::getGameFromIgdbBySteamId): two IGDB games for one Steam id is a guess and
     * gives nothing; a value another card holds would make one game two cards', which is a merge.
     * Either way the title search below still runs and proposes, as before.
     */
    private function igdbIdFromSteamId(Game $game): void
    {
        $found = $this->stores->getGameFromIgdbBySteamId((string) $game->steam_id);
        $id = isset($found['id']) ? (string) $found['id'] : null;

        if ($id === null || !ctype_digit($id) || $this->holderOf('igdb_id', $id, $game->id)) {
            return;
        }

        $game->igdb_id = $id;
        $this->writeQuietly($game);

        AuditLog::log('game.igdb_id_from_steam_id', null, 'game', $game->id, [
            'steam_id' => $game->steam_id,
            'igdb_id' => $id,
        ]);
    }

    /**
     * The card's picture and banner from its own ids (App\Services\GameArt), and — on "Check
     * again" — every other picture they give, proposed. Returns how many NEW proposals were written.
     *
     * ⚠ Steam not answering: nothing is decided about pictures this time. A choice made without
     * Steam's answer would take a lower picture for the best one; the batch stops on it anyway
     * (checkDue), and "Check again" says Steam is not answering.
     */
    private function pictures(Game $game, bool $variants): int
    {
        if (!$game->steam_id && !$game->igdb_id) {
            return 0;
        }

        $candidates = $this->art->candidates($game->steam_id, $game->igdb_id);
        $best = GameArt::best($candidates);
        $before = ['image_url' => $game->image_url, 'banner_url' => $game->banner_url];

        // An admin's choice stands: the automatic pass never moves a picture somebody picked.
        if ($game->image_chosen_at === null && GameArt::shouldReplace($game->image_url, $best, $candidates)) {
            $game->image_url = $best['url'];
        }

        $banner = GameArt::banner($candidates);
        if ($banner !== null) {
            $game->banner_url = $banner;
        }

        if ($game->isDirty(['image_url', 'banner_url'])) {
            $this->writeQuietly($game);
            AuditLog::log('game.pictures_from_ids', null, 'game', $game->id, [
                'before' => $before,
                'after' => ['image_url' => $game->image_url, 'banner_url' => $game->banner_url],
            ]);
        }

        if (!$variants) {
            return 0;
        }

        $new = 0;
        foreach ($candidates as $candidate) {
            if ($candidate['url'] !== $game->image_url) {
                $new += $this->propose($game, 'image_url', $candidate['url'], $candidate['source'],
                    $candidate['shape'], StoreLinks::image($candidate['url']));
            }
        }

        return $new;
    }

    /**
     * Write what the stores answered from the card's own ids, without touching its dates: reading
     * a store is not a change somebody made, and `updated_at` orders every listing by freshness.
     * ⚠ Quietly also means the model's `saving` hooks do not run — nothing written here feeds them
     * (no title, no adult column); and the card is marked asked by the caller right after.
     */
    private function writeQuietly(Game $game): void
    {
        $game->timestamps = false;
        $game->saveQuietly();
        $game->timestamps = true;
    }

    /**
     * Accept a proposal: write it into the card.
     *
     * ⚠ Every condition is checked AGAIN here, not trusted from when the proposal was made: the
     * card may have been filled by an upload since, and another card may have taken the value.
     */
    public function apply(GameProposal $proposal, User $admin): void
    {
        $game = $proposal->game;

        if (!in_array($proposal->field, GameProposal::Fields, true)) {
            throw ValidationException::withMessages(['proposals' => "Unknown field {$proposal->field}."]);
        }

        if ($proposal->state !== GameProposal::Pending) {
            throw ValidationException::withMessages(['proposals' => "{$game->name}: this proposal was already decided."]);
        }

        if (!$this->fieldIsOpen($game, $proposal->field)) {
            throw ValidationException::withMessages(['proposals' => "{$game->name}: {$proposal->field} is already set — nothing is written over it."]);
        }

        if ($holder = $this->holderOf($proposal->field, $proposal->value, $game->id)) {
            throw ValidationException::withMessages(['proposals' => "{$game->name}: {$proposal->value} is already {$holder->name}'s — that would be a merge."]);
        }

        $before = $game->{$proposal->field};

        $game->{$proposal->field} = $proposal->value;

        // A picture an admin picked is theirs: the automatic pass leaves it from now on
        // (StoreProposals::pictures).
        if ($proposal->field === 'image_url') {
            $game->image_chosen_at = now();
        }

        $game->save();

        $proposal->state = GameProposal::Applied;
        $proposal->decided_by = $admin->id;
        $proposal->decided_at = now();
        $proposal->save();

        // The other candidates for the same field were alternatives to this one. Once one is
        // taken they are moot, not rejected — nobody said they were wrong.
        GameProposal::where('game_id', $game->id)
            ->where('field', $proposal->field)
            ->pending()
            ->delete();

        // A Steam id is what the adult classification is read from: a card judged on its name
        // alone was judged by IGDB, which misses most of what Steam states outright.
        if ($proposal->field === 'steam_id') {
            $this->rating->rate($game);
        }

        AuditLog::log('game.proposal_applied', $admin->id, 'game', $game->id, [
            'field' => $proposal->field,
            'before' => $before,
            'after' => $proposal->value,
            'source' => $proposal->source,
        ]);
    }

    /**
     * Refuse a proposal, for good: the same value is never proposed again for this card.
     */
    public function reject(GameProposal $proposal, User $admin): void
    {
        if ($proposal->state !== GameProposal::Pending) {
            return;
        }

        $proposal->state = GameProposal::Rejected;
        $proposal->decided_by = $admin->id;
        $proposal->decided_at = now();
        $proposal->save();

        AuditLog::log('game.proposal_rejected', $admin->id, 'game', $proposal->game_id, [
            'field' => $proposal->field,
            'value' => $proposal->value,
            'source' => $proposal->source,
        ]);
    }

    /**
     * Write a proposal unless this exact value was already put to an admin. Returns 1 when new.
     *
     * 🔴 **This is what keeps a decision from coming back.** A rejected value finds its row here
     * and stops; so does one already applied. A pending one only has its store name and conflict
     * refreshed — the answer is the same, the circumstances may not be.
     */
    private function propose(Game $game, string $field, string $value, string $source, ?string $detail, ?string $link): int
    {
        $holder = $this->holderOf($field, $value, $game->id);

        $existing = GameProposal::where('game_id', $game->id)
            ->where('field', $field)
            ->where('value', $value)
            ->first();

        if ($existing) {
            if ($existing->state === GameProposal::Pending) {
                $existing->detail = $detail;
                $existing->link = $link;
                $existing->conflict_game_id = $holder?->id;
                $existing->save();
            }

            return 0;
        }

        GameProposal::create([
            'game_id' => $game->id,
            'field' => $field,
            'value' => $value,
            'source' => $source,
            'detail' => $detail,
            'link' => $link,
            'conflict_game_id' => $holder?->id,
            'state' => GameProposal::Pending,
        ]);

        return 1;
    }

    /**
     * The card that already carries this value, if another one does.
     */
    private function holderOf(string $field, string $value, int $exceptId): ?Game
    {
        return match ($field) {
            // Through the alias table too: a demo's id belongs to the card of its full game.
            'steam_id' => Game::answeringToSteamId($value)->where('id', '!=', $exceptId)->first(),
            'igdb_id' => Game::where('igdb_id', $value)->where('id', '!=', $exceptId)->first(),
            default => null,
        };
    }

    /**
     * May this field still receive a value? An id never over one that is there. A picture always:
     * the variants are offered for an admin to change it (user, 2026-10-06), and every one of them
     * comes from the card's own ids.
     */
    private function fieldIsOpen(Game $game, string $field): bool
    {
        return $field === 'image_url' || !$game->{$field};
    }

    private function forgetMootProposals(Game $game): void
    {
        foreach (GameProposal::Fields as $field) {
            if (!$this->fieldIsOpen($game, $field)) {
                GameProposal::where('game_id', $game->id)->where('field', $field)->pending()->delete();
            }
        }

        // A picture variant the card now shows — taken since, by the automatic pass or an admin —
        // has nothing left to propose.
        if ($game->image_url) {
            GameProposal::where('game_id', $game->id)->where('field', 'image_url')
                ->where('value', $game->image_url)->pending()->delete();
        }
    }
}
