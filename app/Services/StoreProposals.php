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
 * | `igdb_id` | IGDB's search, EXACT title only | the card has none |
 * | `image_url` | the Steam page of the card's own id | no cover, or a RAWG in-game screenshot |
 *
 * ⚠ Never the display name, never `unity_name`, never an adult mark: those are human decisions or
 * facts no store holds. And never over a value that is already there — the same rule the upload
 * path follows.
 *
 * ⚠ **A cover is only proposed from an id the card already HAS.** Proposing one alongside a
 * proposed Steam id would let an admin accept the cover and reject the id — a cover taken from the
 * wrong game. Accept the id, and the next check proposes its cover.
 */
class StoreProposals
{
    /**
     * How long one click may spend asking the stores. ⚠ A budget, not a wait: it bounds the work
     * of one request and the next click takes up where this one stopped (oldest check first). The
     * stores answer in well under a second each, so a click covers most of the catalogue; the
     * bound is there for the day one of them is slow, so a click never runs into PHP's own limit.
     */
    public const BudgetSeconds = 15;

    public function __construct(private GameSearchService $stores, private AdultRating $rating)
    {
    }

    /**
     * Ask the stores about the cards due — never asked first, then the oldest — within the budget.
     *
     * @return array{checked: int, proposed: int, left: int}
     */
    public function checkDue(): array
    {
        $started = microtime(true);
        $checked = 0;
        $proposed = 0;

        $due = Game::query()
            ->orderByRaw('stores_checked_at IS NULL DESC')
            ->orderBy('stores_checked_at')
            ->orderBy('id')
            ->get();

        foreach ($due as $game) {
            if (microtime(true) - $started > self::BudgetSeconds) {
                break;
            }

            $proposed += $this->check($game);
            $checked++;

            // ⚠ Quietly and without timestamps: asking a store about a card is not a change to
            // the card, and `updated_at` would reorder every listing sorted by freshness. Nothing
            // here touches the adult columns, so the derived answer cannot go stale.
            $game->stores_checked_at = now();
            $game->timestamps = false;
            $game->saveQuietly();
        }

        return [
            'checked' => $checked,
            'proposed' => $proposed,
            'left' => $due->count() - $checked,
        ];
    }

    /**
     * Ask the stores about one card. Returns how many NEW proposals were written.
     */
    public function check(Game $game): int
    {
        // Proposals for a field the card has since filled — by an upload attaching an id, say —
        // are no longer proposals. Left behind, they would sit in "Pending" offering to overwrite.
        $this->forgetMootProposals($game);

        $new = 0;

        if (!$game->steam_id) {
            foreach ($this->exactMatches($this->stores->steamSearch($game->name), $game->name) as $hit) {
                $new += $this->propose($game, 'steam_id', $hit['id'], 'steam', $hit['name'], StoreLinks::steam($hit['id']));
            }
        }

        if (!$game->igdb_id) {
            $safe = GameSearchService::escapeIGDBQuery($game->name);

            // A title in another script escapes to nothing: there is nothing to ask IGDB then,
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

                foreach ($this->exactMatches($hits, $game->name) as $hit) {
                    $new += $this->propose($game, 'igdb_id', $hit['id'], 'igdb', $hit['name'], StoreLinks::igdb($hit['url']));
                }
            }
        }

        if ($game->steam_id && $this->coverMayBeReplaced($game->image_url)) {
            $header = $this->stores->steamApp($game->steam_id)['header_image'] ?? null;

            if ($header) {
                $new += $this->propose($game, 'image_url', $header, 'steam', null, StoreLinks::image($header));
            }
        }

        return $new;
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
     * Keep only the hits whose title IS this card's title — case, spacing and punctuation aside.
     *
     * ⚠ A store search answers with add-ons, sequels and neighbours ("Love n Life: Happy Student"
     * also returns its three DLC). Anything short of the same title is a guess about which game
     * somebody meant, and this class exists precisely to avoid writing one.
     */
    private function exactMatches(array $hits, string $title): array
    {
        $wanted = GameNaming::flatten($title);

        if ($wanted === '') {
            return [];
        }

        return collect($hits)
            ->filter(fn ($hit) => $hit['id'] !== '' && GameNaming::flatten($hit['name']) === $wanted)
            ->unique('id')
            ->values()
            ->all();
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
     * May this field still receive a value? Never over one that is there — except a cover that is
     * a RAWG in-game screenshot, which is not a cover at all.
     */
    private function fieldIsOpen(Game $game, string $field): bool
    {
        return $field === 'image_url'
            ? $this->coverMayBeReplaced($game->image_url)
            : !$game->{$field};
    }

    /**
     * No cover, or one RAWG took from inside the game. ⚠ A RAWG screenshot is in-game content, not
     * curated store art, so nothing guarantees what it shows — the one cover worth replacing.
     */
    private function coverMayBeReplaced(?string $url): bool
    {
        return !$url || str_contains($url, 'media.rawg.io/media/screenshots');
    }

    private function forgetMootProposals(Game $game): void
    {
        foreach (GameProposal::Fields as $field) {
            if (!$this->fieldIsOpen($game, $field)) {
                GameProposal::where('game_id', $game->id)->where('field', $field)->pending()->delete();
            }
        }
    }
}
