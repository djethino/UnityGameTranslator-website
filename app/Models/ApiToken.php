<?php

namespace App\Models;

use App\Support\ClientAgent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ApiToken extends Model
{
    protected $fillable = [
        'user_id',
        'token',
        'public_code',
        'name',
        'device_label',
        'client_kind',
        'client_version',
        'client_variant',
        'game_slot',
        'game_ref',
        'published_at_least_once',
        'last_used_at',
        'expires_at',
    ];

    protected $casts = [
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
        'published_at_least_once' => 'boolean',
        // Encrypted at rest, with a random IV, so two rows holding the same game do not look
        // alike — not even within one account. Whoever holds a database export and nothing else
        // cannot read it; whoever holds the server can, and must, since the screen shows the name.
        'game_ref' => 'encrypted',
    ];

    protected $hidden = [
        'token',
        'game_slot',
        'game_ref',
    ];

    /**
     * Generate a new API token (plain text, for returning to user)
     */
    public static function generateToken(): string
    {
        return 'ugt_' . Str::random(60); // Prefix for easy identification
    }

    /**
     * Hash a token for secure storage
     */
    public static function hashToken(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    /**
     * A handle for naming one line out loud — "I cut #A3F2E1".
     *
     * 🔴 Display only. No endpoint may ever accept it as a parameter: it is short, so accepting it
     * would hand out an enumeration surface, and revocation already has an owner-scoped id.
     */
    public static function generatePublicCode(): string
    {
        do {
            $code = strtoupper(Str::random(6));
        } while (self::where('public_code', $code)->exists());

        return $code;
    }

    /**
     * The value that says "this is the same game as that one", for one account only.
     *
     * Salted per user, so the same game held by two people produces two unrelated values: nobody
     * reading the table can group accounts by what they play. Derived from the application key,
     * which lives in the environment and not in the database — an export of the database alone
     * carries the values and not the means to read them.
     *
     * ⚠ Deterministic on purpose. That is what lets one game hold one access, and it is also why
     * this cannot double as the displayed name: a value that can be compared cannot be random.
     */
    public static function gameSlotFor(User $user, string $steamId): string
    {
        $userKey = hash_hmac('sha256', 'game-slot:' . $user->id, config('app.key'));

        return substr(hash_hmac('sha256', 'steam:' . $steamId, $userKey), 0, 32);
    }

    /**
     * Create a new API token for a user.
     * Returns the model with a 'plain_token' attribute containing the unhashed token.
     * The plain token is shown only once and cannot be retrieved later.
     * Token expires after 1 year by default.
     *
     * $client carries what the program declared when it asked for the link: device_label,
     * client_kind, client_version, client_variant, game_slot, game_ref. All optional — a program
     * that declares nothing still gets a working token, it just gets a line nobody can name.
     */
    public static function createForUser(User $user, ?string $name = null, array $client = []): self
    {
        $plainToken = self::generateToken();

        $apiToken = self::create([
            'user_id' => $user->id,
            'token' => self::hashToken($plainToken), // Store hash, not plain text
            'public_code' => self::generatePublicCode(),
            'name' => $name,
            'device_label' => $client['device_label'] ?? null,
            'client_kind' => $client['client_kind'] ?? null,
            'client_version' => $client['client_version'] ?? null,
            'client_variant' => $client['client_variant'] ?? null,
            'game_slot' => $client['game_slot'] ?? null,
            'game_ref' => $client['game_ref'] ?? null,
            'expires_at' => now()->addYear(),
        ]);

        // Attach plain token for one-time retrieval (not persisted)
        $apiToken->plain_token = $plainToken;

        return $apiToken;
    }

    /**
     * Find a token by its plain text value and mark it as used.
     * Hashes the input before searching. Excludes expired tokens.
     *
     * ⚠ $userAgent fills in which program holds this token for the rows issued before that was
     * recorded — the only field that can be recovered without anybody updating anything. It is
     * written once and never corrected afterwards: a token belongs to one install, and a value
     * that kept changing would describe the last caller rather than the holder.
     *
     * 🔴 $game does the same for the game, and it exists because the link was the ONLY moment we
     * listened — which is the one moment we had nothing more to learn. A mod that was linked before
     * it declared anything stayed nameless for ever while calling us several times an hour with the
     * game right in front of it. Reported from production on 2026-08-27: every line read "Mod", and
     * the screen could not tell "no game recorded" from "unknown".
     *
     * ⚠ **Filled once, never corrected**, and here that is not tidiness — `game_slot` is what the
     * one-access-per-game cap is applied to. Overwriting it would move an existing access under a
     * different game, so the next link would cut the wrong line.
     */
    public static function findAndMarkUsed(
        string $plainToken,
        ?string $userAgent = null,
        ?array $game = null
    ): ?self {
        $hashedToken = self::hashToken($plainToken);
        $apiToken = self::where('token', $hashedToken)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->first();

        if (!$apiToken) {
            return null;
        }

        $changes = ['last_used_at' => now()];

        if ($apiToken->client_kind === null && $userAgent !== null) {
            $client = ClientAgent::parse($userAgent);

            if ($client !== null) {
                $changes['client_kind'] = $client['kind'];
                $changes['client_version'] = $client['version'];
                $changes['client_variant'] = $client['variant'];
            }
        }

        // ⚠ The name may arrive without the id, and then it is a label and nothing else: the slot
        // stays null, so this access is never subject to the cap. Two games can carry the same
        // product name, and cutting on that would cut somebody's other game.
        if ($game !== null && $apiToken->game_slot === null && $apiToken->game_ref === null) {
            if ($game['game_id'] !== null) {
                $changes['game_slot'] = self::gameSlotFor($apiToken->user, $game['game_id']);
            }

            if ($game['game_name'] !== null) {
                $changes['game_ref'] = $game['game_name'];
            }
        }

        $apiToken->update($changes);

        return $apiToken;
    }

    /**
     * The tokens this account holds for one game, one program AND one named device — what the cap
     * is applied to.
     *
     * 🔴 The device belongs in the key, and leaving it out was a real defect. The cap exists to
     * kill the accesses an install abandons — a reinstall, a wiped config, a sign-out that never
     * reached the site — and every one of those happens on the same machine. Keyed on the game
     * alone it also cut ACROSS machines: linking a game on a Steam Deck signed the same game out
     * on the desktop, and back again on the next switch. That is a mainstream setup, and losing an
     * access on it is worse than the untidy line the cap was meant to remove.
     *
     * ⚠ Both parts are never null, for the same reason. A game with no Steam id has no slot and
     * matches nothing; a device with no name is not a device, it is an unanswered question, and
     * cutting on an absence is the product-name mistake in another shape.
     */
    public function scopeSameSlot($query, string $slot, ?string $clientKind, string $deviceLabel)
    {
        return $query
            ->where('game_slot', $slot)
            ->where('client_kind', $clientKind)
            ->where('device_label', $deviceLabel);
    }

    /**
     * Check if this token has expired.
     */
    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * Check if a plain token matches this token's hash
     */
    public function verifyToken(string $plainToken): bool
    {
        return hash_equals($this->token, self::hashToken($plainToken));
    }

    /**
     * The game's name, or null when there is none to show.
     *
     * ⚠ The one place in this class that catches. It is a real boundary — stored bytes against a
     * key that lives outside the database — and it has exactly one realistic cause: `APP_KEY` was
     * rotated, which makes every row written before it unreadable at once. That is not a fault to
     * survive silently, so it is reported; but it must not take the screen down either, because
     * the screen is where somebody cuts an access they do not recognise. The line falls back to
     * naming its program, and stays revocable.
     */
    public function gameName(): ?string
    {
        try {
            return $this->game_ref;
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * How recently this access last spoke, as a bucket — never a time.
     *
     * ⚠ The word for it is "exchange", not "use": `OptionalAuthenticateApi` refreshes `last_used_at`
     * on public routes too, so downloading a translation counts and the live relay triggers it. A
     * label saying "used" would read as "somebody acted under my account".
     *
     * ⚠ Never finer than these buckets, title attributes included. An exact hour would describe
     * when its owner is at their machine, on a page that whoever is already inside the account can
     * read.
     */
    public function lastExchangeBucket(): string
    {
        if ($this->last_used_at === null) {
            return 'never';
        }

        $days = $this->last_used_at->diffInDays(now());

        return match (true) {
            $days < 1 => 'today',
            $days < 7 => 'week',
            $days < 31 => 'month',
            default => 'idle',
        };
    }

    /**
     * Months since the last exchange — only meaningful alongside the 'idle' bucket.
     */
    public function idleMonths(): int
    {
        return $this->last_used_at === null ? 0 : (int) $this->last_used_at->diffInMonths(now());
    }

    /**
     * Has this token ever published under the account?
     *
     * A boolean, no date: at fifty lines "never published" everywhere is noise, while "published
     * under your name" on one is the difference between an unknown access that sleeps and one that
     * has already spoken for you. A date would let anybody cross it with the public catalogue and
     * attribute each release to a named machine.
     */
    public function hasPublished(): bool
    {
        return (bool) $this->published_at_least_once;
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
