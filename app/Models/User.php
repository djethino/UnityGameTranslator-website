<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'provider',
        'provider_id',
        'avatar',
        'locale',
        'game_language',
    ];

    protected $guarded = [
        'is_admin',
        'banned_at',
        'ban_reason',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'banned_at' => 'datetime',
            'name_changed_at' => 'datetime',
            'username_prompt_seen_at' => 'datetime',
        ];
    }

    public function translations()
    {
        return $this->hasMany(Translation::class);
    }

    public function votes()
    {
        return $this->hasMany(Vote::class);
    }

    public function reports()
    {
        return $this->hasMany(Report::class, 'reporter_id');
    }

    public function apiTokens()
    {
        return $this->hasMany(ApiToken::class);
    }

    // The language somebody wants their GAMES in — the `game_language` column.
    //
    // 🔴 **Not the interface language, and the distinction is the point.** `locale` is one of the
    // twenty this site is translated into; a game translation can be any of the catalogue's ninety.
    // Someone reading an English interface and wanting French subtitles is ordinary, and a Tamil
    // player has no interface language to be inferred from at all.
    //
    // ⚠ **Resolving it is NOT a method on this model**, although the column lives here: an account
    // is only one of the places the answer can come from, and the visitor with none deserves the
    // same answer. App\Services\GameLanguage holds the order of preference and is the only writer.
    // What stays below is the guess, which needs no account at all.

    /**
     * What to assume when nobody has chosen — for a visitor as much as for an account.
     *
     * ⚠ The BROWSER first, then the interface. The browser names a language out of ninety; the
     * interface can only name one of twenty, so asking it first throws away the better answer.
     * Somebody whose browser says Tamil is told about Tamil translations, which no interface
     * language could ever have produced.
     *
     * Static because a visitor has no account and deserves the same answer.
     */
    public static function detectedGameLanguage(): ?string
    {
        $supported = array_keys(config('locales.supported', []));

        foreach (request()?->getLanguages() ?? [] as $announced) {
            // Not a language we host translations in: nothing to say about it.
            if (\App\Services\CatalogStore::nameOfCode($announced) === null) {
                continue;
            }

            // 🔴 The interface can express this one, so the interface decides. Somebody browsing
            // /en/ has SAID English, and a browser header is a guess — overruling a stated choice
            // with a guess is the failure the tests caught.
            if (\App\Services\CatalogStore::canonicalTag($announced) !== null
                && self::interfaceSpeaks($announced, $supported)) {
                break;
            }

            // The gap this exists for: a browser asking for Tamil, Catalan or Basque, which no
            // interface language could ever have expressed. Twenty against ninety.
            return \App\Services\CatalogStore::nameOfCode($announced);
        }

        return \App\Services\CatalogStore::nameOfCode(app()->getLocale());
    }

    /**
     * Is this announced locale one the SITE ITSELF is translated into?
     *
     * ⚠ Compared after shortening, so a browser asking for fr-CA is recognised as French. The
     * interface list is spelt in its own codes (pt-BR, zh), which is why this cannot be a plain
     * in_array on the raw header value.
     */
    private static function interfaceSpeaks(string $announced, array $supported): bool
    {
        $wanted = strtolower(str_replace('_', '-', trim($announced)));

        while ($wanted !== '') {
            foreach ($supported as $code) {
                if (strtolower($code) === $wanted) {
                    return true;
                }
            }

            $cut = strrpos($wanted, '-');
            if ($cut === false) {
                return false;
            }

            $wanted = substr($wanted, 0, $cut);
        }

        return false;
    }

    public function isAdmin(): bool
    {
        return (bool) $this->is_admin;
    }

    /**
     * Local (platform-less) account: logs in with username+password,
     * may have no email at all.
     */
    public function isLocalAccount(): bool
    {
        return $this->provider === 'local';
    }

    public function isBanned(): bool
    {
        return $this->banned_at !== null;
    }

    public function ban(?string $reason = null): void
    {
        $this->banned_at = now();
        $this->ban_reason = $reason;
        $this->save();

        // Cut the mod off straight away. A banned account keeps its website session (it must be
        // able to read why it was banned) but loses every API token: the plugin is a granted
        // access, not a place to display a notice. Re-linking is refused while the ban stands.
        $this->apiTokens()->delete();
    }

    public function unban(): void
    {
        $this->banned_at = null;
        $this->ban_reason = null;
        $this->save();
    }

    /**
     * Is this display name already somebody else's?
     *
     * 🔴 **Nothing checked this until 2026-08-26, and `name` is what the site shows.** `username` is
     * unique but is displayed nowhere and is null on every account created through a provider, so
     * the field everyone reads had no uniqueness at all: any account could take the exact name of
     * another in three clicks, and the whole anti-impersonation set — the 30-day delay, the ASCII
     * charset against homoglyphs — guarded only the subtle version of the attack.
     *
     * ⚠ Compared in lower case, like local sign-in already does with `username`. Two names differing
     * only in capitals are the same name to a reader, which is the only judge that matters here.
     *
     * ⚠ `$except` is the account asking. Without it nobody could re-save their own profile, nor fix
     * the capitalisation of the name they already hold.
     */
    public static function displayNameTaken(string $name, ?int $except = null): bool
    {
        $query = self::whereRaw('LOWER(name) = ?', [mb_strtolower($name)]);

        if ($except !== null) $query->where('id', '!=', $except);

        return $query->exists();
    }

    /**
     * Free variants of a name that is taken, in the shape everybody already knows.
     *
     * A refusal with nothing to do next is where people give up. Every service that enforces a
     * unique handle offers the way out in the same breath — it is why so many addresses end in a
     * number nobody chose on purpose.
     *
     * ⚠ Numbers appended rather than inserted, and the source name left untouched: it is the form
     * readers recognise as "the same person, second account", and it keeps the result inside the
     * charset the rename already enforces.
     */
    public static function suggestDisplayNames(string $name, int $count = 3): array
    {
        $suggestions = [];

        // Bounded rather than while(true): on a name whose first hundreds are all taken, giving
        // three suggestions is not worth an unbounded scan of the table.
        for ($n = 2; $n <= 200 && count($suggestions) < $count; $n++)
        {
            $candidate = $name . $n;

            // The rename allows 50 characters; a suggestion nobody could save is not a suggestion.
            if (mb_strlen($candidate) > 50) break;

            if (!self::displayNameTaken($candidate)) $suggestions[] = $candidate;
        }

        return $suggestions;
    }
}
