<?php

namespace App\Services;

use App\Models\AnalyticsEvent;
use App\Support\ClientAgent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * How the mod is used, kept from the calls it already makes (user, 2026-10-19). Read by
 * ModUsageReport for the admin "Mod usage" screen; analysis in analyse/statistiques-usages.md.
 *
 * 🔴 **Only what the calls already say, only from the mod.** Looking for its game's translations
 * (`look`), taking one (`take`), checking the one it holds (`play`). No call was added to the mod
 * for this, and the Manager is not counted: it asks about a whole library, and a game installed is
 * not a game played.
 *
 * ⚠ **A session is one copy on one day**: the site's daily fingerprint
 * (AnalyticsEvent::visitorFingerprints), the same one `client_usage_daily` counts copies with, so
 * the two figures add up. Its salt is destroyed two days later — a copy cannot be followed from one
 * day to the next — and the rows holding it are deleted the night they are counted (countPending).
 *
 * ⚠ **Never allowed to break a request**, and never silent either: a failure is reported, as in
 * TrackClientUsage.
 */
final class ModUsage
{
    public const LOOK = 'look';
    public const TAKE = 'take';
    public const PLAY = 'play';
    /** The act `usage_daily` gives to what each session did at most that day (countDay). */
    public const SESSION = 'session';

    /** look: a translation exists in the language asked. */
    public const FOUND = 'found';
    /** look: none in that language, some in others. */
    public const OTHER_LANGUAGES = 'other_languages';
    /** look: nothing published for the game at all. */
    public const NONE = 'none';
    /** play: the copy is the published file, byte for byte. */
    public const SAME = 'same';
    /** play: the copy differs — edited by its player, or older than the published one. */
    public const DIFFERS = 'differs';
    /** play: the player is its author. */
    public const OWN = 'own';
    /** session: plays a translation from the site. */
    public const PLAYS = 'plays';
    /** session: took one that day, not yet seen in use. */
    public const TOOK = 'took';

    /**
     * What a session did at most that day, best first — the order countDay decides in, and the
     * order the screen shows. ⚠ A look's own outcomes are reused for the three last: a session that
     * only looked is told by the best answer it got.
     */
    public const SESSION_OUTCOMES = [
        self::PLAYS => ['label' => 'Plays a translation from the site', 'rgb' => '34, 197, 94', 'class' => 'text-green-400'],
        self::TOOK => ['label' => 'Took a translation', 'rgb' => '59, 130, 246', 'class' => 'text-blue-400'],
        self::FOUND => ['label' => 'Found one, took none', 'rgb' => '234, 179, 8', 'class' => 'text-yellow-400'],
        self::OTHER_LANGUAGES => ['label' => 'Not in their language', 'rgb' => '249, 115, 22', 'class' => 'text-orange-400'],
        self::NONE => ['label' => 'Nothing for their game', 'rgb' => '239, 68, 68', 'class' => 'text-red-400'],
    ];

    /** A look's outcomes, best first. */
    private const LOOK_RANK = [self::FOUND, self::OTHER_LANGUAGES, self::NONE];

    /**
     * Keep one act of the calling copy.
     *
     * 🔴 **One statement per call, whatever the call**: an upsert on (day, session, act, subject).
     * A copy checking its translation every hour writes the same row again, and the last call of
     * the day says how the copy stands.
     *
     * @param array{game_id?: ?int, steam_id?: ?string, game_name?: ?string, language?: ?string, translation_id?: ?int, outcome?: string} $what
     */
    public static function note(Request $request, string $act, array $what): void
    {
        $client = ClientAgent::ours($request->userAgent());
        if ($client === null || $client['kind'] !== ClientAgent::MOD) {
            return;
        }

        try {
            $row = [
                'game_id' => $what['game_id'] ?? null,
                'steam_id' => self::steamId($what['steam_id'] ?? null),
                'game_name' => self::gameName($what['game_name'] ?? null),
                'language' => self::language($what['language'] ?? null),
                'translation_id' => $what['translation_id'] ?? null,
                'outcome' => $what['outcome'] ?? '',
            ];

            // ⚠ An act about nothing nameable is not kept: a look with no card, no well-formed
            // Steam id and no usable name would be counted under a game nobody can read.
            if ($row['game_id'] === null && $row['steam_id'] === null && $row['game_name'] === null
                && $row['translation_id'] === null) {
                return;
            }

            $subject = hash('sha256', json_encode([
                $row['translation_id'], $row['game_id'], $row['steam_id'],
                $row['game_name'] === null ? null : mb_strtolower($row['game_name']), $row['language'],
            ]));

            DB::table('usage_seen')->upsert(
                [[
                    'date' => now()->toDateString(),
                    'session' => AnalyticsEvent::visitorFingerprints(
                        $request->ip() ?? '0.0.0.0',
                        (string) $request->userAgent()
                    )['visitor_hash'],
                    'act' => $act,
                    'subject' => $subject,
                    'signed_in' => $request->user() !== null,
                ] + $row],
                ['date', 'session', 'act', 'subject'],
                ['outcome', 'signed_in']
            );
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * What a copy holding `$translation` is to it — its author's, or the published file, or not.
     *
     * ⚠ `differs` cannot say WHICH: the site keeps only the current hash, so a copy its player
     * translated further and a copy older than the last publication look the same. The screen says
     * both.
     */
    public static function playOutcome(bool $byItsAuthor, ?string $publishedHash, ?string $clientHash): string
    {
        if ($byItsAuthor) {
            return self::OWN;
        }

        if ($clientHash === null || $clientHash === '' || $publishedHash === null) {
            return '';
        }

        return $publishedHash === $clientHash ? self::SAME : self::DIFFERS;
    }

    /**
     * Count every day still held in `usage_seen` before today, then forget its sessions.
     *
     * 🔴 **Every pending day, not "yesterday"**: a night that did not run loses nothing, the next
     * one counts both. And a day already forgotten is never recounted — its rows are gone, so an
     * `analytics:aggregate --date=` on an old day cannot write zero over what it counted.
     *
     * @return list<string> the days counted
     */
    public static function countPending(): array
    {
        $days = DB::table('usage_seen')
            ->where('date', '<', now()->toDateString())
            ->distinct()
            ->orderBy('date')
            ->pluck('date')
            ->map(fn ($d) => substr((string) $d, 0, 10))
            ->all();

        foreach ($days as $day) {
            DB::transaction(function () use ($day) {
                self::countDay($day);
                DB::table('usage_seen')->where('date', $day)->delete();
            });
        }

        return $days;
    }

    /**
     * Write one day of `usage_seen` into `usage_daily`, whole — the night's count, and today's,
     * counted again each time the screen is opened (ModUsageReport). Rewriting rather than adding
     * makes both idempotent.
     */
    public static function countDay(string $date): void
    {
        DB::table('usage_daily')->where('date', $date)->delete();

        $columns = ['act', 'game_id', 'steam_id', 'game_name', 'language', 'translation_id', 'outcome', 'signed_in'];

        $acts = DB::table('usage_seen')
            ->where('date', $date)
            ->select($columns)
            ->selectRaw('COUNT(*) as sessions')
            ->groupBy($columns)
            ->get();

        $rows = $acts->map(fn ($r) => ['date' => $date, 'sessions' => (int) $r->sessions]
            + array_intersect_key((array) $r, array_flip($columns)))->all();

        // What each session did at most. Read session by session in PHP: "the best of its acts" is
        // an ordering of our own, which SQL would spell differently on MariaDB and SQLite.
        $best = [];
        $signedIn = [];
        foreach (DB::table('usage_seen')->where('date', $date)->select('session', 'act', 'outcome', 'signed_in')->cursor() as $seen) {
            $rank = match ($seen->act) {
                self::PLAY => 0,
                self::TAKE => 1,
                default => 2 + (array_search($seen->outcome, self::LOOK_RANK, true) ?: 0),
            };
            $best[$seen->session] = min($best[$seen->session] ?? PHP_INT_MAX, $rank);
            $signedIn[$seen->session] = ($signedIn[$seen->session] ?? false) || (bool) $seen->signed_in;
        }

        $outcomes = array_keys(self::SESSION_OUTCOMES);
        $tally = [];
        foreach ($best as $session => $rank) {
            $key = $outcomes[$rank] . '|' . ($signedIn[$session] ? 1 : 0);
            $tally[$key] = ($tally[$key] ?? 0) + 1;
        }
        foreach ($tally as $key => $sessions) {
            [$outcome, $signed] = explode('|', $key);
            $rows[] = [
                'date' => $date, 'act' => self::SESSION, 'game_id' => null, 'steam_id' => null,
                'game_name' => null, 'language' => '', 'translation_id' => null,
                'outcome' => $outcome, 'signed_in' => (bool) $signed, 'sessions' => $sessions,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('usage_daily')->insert($chunk);
        }
    }

    /**
     * A catalogue language name, or '' — never what the caller typed. The catalogue bounds what
     * can become a row, as KnownReleases bounds the versions of client_usage_daily.
     */
    private static function language(?string $language): string
    {
        $language = trim((string) $language);

        return $language !== '' && in_array($language, CatalogStore::languageNames(), true) ? $language : '';
    }

    /** A Steam app id is digits; anything else is not one. */
    private static function steamId(?string $steamId): ?string
    {
        $steamId = trim((string) $steamId);

        return preg_match('/^\d{1,32}$/', $steamId) === 1 ? $steamId : null;
    }

    /**
     * The name the mod read on disk, for a game the site does not know.
     *
     * ⚠ Longer than the column is dropped, not cut: a name cut in two is a game nobody will
     * recognise, and no game is named in 255 characters. Same bound as `translations/for-games`.
     */
    private static function gameName(?string $name): ?string
    {
        $name = trim((string) $name);

        return $name !== '' && mb_strlen($name) <= 255 ? $name : null;
    }
}
