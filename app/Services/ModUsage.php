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
 * 🔴 **Only what the calls already say.** From the mod: looking for its game's translations
 * (`look`), taking one (`take`), checking the one it holds (`play`). From the Manager, only what
 * concerns a game where the mod is installed: a translation it writes there (`take`), and a game
 * whose translation file or confirmed card it read in the mod's files (`held`). The rest of its
 * library leaves no trace — a game installed is not a game played. No call was added to either.
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
    /**
     * The day this started being written (deployed 2026-10-19). Before it, a copy's calls were never
     * looked at — not "called for nothing" (ModUsageReport::copies).
     */
    public const COUNTING_STARTED = '2026-10-19';

    public const LOOK = 'look';
    public const TAKE = 'take';
    public const PLAY = 'play';
    /**
     * The Manager reports a game where the mod has been used — its batch carries the game's
     * translation file or confirmed card, both read in the mod's own files (Api TranslationController
     * noteHeld). Not a session of the mod: it says the mod is installed and has run, not that the
     * game was launched that day.
     */
    public const HELD = 'held';
    /** held: the game's translation file is a lineage the site has. */
    public const ON_SITE = 'on_site';
    /** held: a translation file the site has never seen — made in that game, never published. */
    public const LOCAL_ONLY = 'local_only';
    /** held: a card confirmed, no translation file yet. */
    public const NO_FILE = 'no_file';

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
        self::noteMany($request, $act, [$what]);
    }

    /**
     * Keep several acts of one call — a Manager batch names many games — in ONE upsert, so a
     * hundred games never cost a hundred statements.
     *
     * @param list<array{game_id?: ?int, steam_id?: ?string, game_name?: ?string, language?: ?string, translation_id?: ?int, outcome?: string}> $whats
     */
    public static function noteMany(Request $request, string $act, array $whats): void
    {
        // 🔴 The mod for every act; the Manager for what touches a game where it installed the mod
        // only (user, 2026-10-19) — never its whole library, which is what its lookups are about.
        $client = ClientAgent::ours($request->userAgent());
        if ($client === null || !self::counts($client['kind'], $act)) {
            return;
        }

        try {
            $session = AnalyticsEvent::visitorFingerprints(
                $request->ip() ?? '0.0.0.0',
                (string) $request->userAgent()
            )['visitor_hash'];

            $rows = [];
            foreach ($whats as $what) {
                $row = [
                    'product' => $client['kind'],
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
                    continue;
                }

                $subject = hash('sha256', json_encode([
                    $row['translation_id'], $row['game_id'], $row['steam_id'],
                    $row['game_name'] === null ? null : mb_strtolower($row['game_name']), $row['language'],
                ]));

                // Keyed by subject: one batch naming the same game twice is one row, not a
                // duplicate key inside a single statement.
                $rows[$subject] = [
                    'date' => now()->toDateString(),
                    'session' => $session,
                    'act' => $act,
                    'subject' => $subject,
                    'signed_in' => $request->user() !== null,
                ] + $row;
            }

            if ($rows !== []) {
                DB::table('usage_seen')->upsert(array_values($rows), ['date', 'session', 'act', 'subject'], ['outcome', 'signed_in']);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Whether this program's act is counted: every act of the mod; of the Manager, a take and a
     * game it reports the mod used in.
     */
    private static function counts(string $product, string $act): bool
    {
        return $product === ClientAgent::MOD
            || ($product === ClientAgent::MANAGER && in_array($act, [self::TAKE, self::HELD], true));
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

    /** What a row of usage_daily or usage_monthly is about — everything but the counts and dates. */
    public const KEY_COLUMNS = ['product', 'act', 'game_id', 'steam_id', 'game_name', 'language', 'translation_id', 'outcome', 'signed_in'];

    /**
     * Fold every month older than the boundary of analytics_games (AnalyticsGame::foldedBefore) into
     * `usage_monthly`, and delete its days. Returns how many day rows were folded.
     *
     * 🔴 **One boundary for the whole admin**: the games of Analytics and the usage of the mod fold at
     * the same age, so a span reaching back reads "per month" from the same day on both screens.
     *
     * ⚠ **One transaction per month, merged with what the month already holds**, read and rewritten
     * whole: a month is never half in days and half in months — which is what lets the report add
     * the two tables — and a late day folded after its month joins it instead of being lost. Merged
     * in PHP because the key holds nullable columns, which SQL would match differently per engine.
     */
    public static function foldOldDays(): int
    {
        $before = \App\Models\AnalyticsGame::foldedBefore()->toDateString();
        $folded = 0;

        $months = DB::table('usage_daily')->where('date', '<', $before)->pluck('date')
            ->map(fn ($d) => substr((string) $d, 0, 7) . '-01')
            ->unique();

        foreach ($months as $month) {
            DB::transaction(function () use ($month, &$folded) {
                $end = \Carbon\Carbon::parse($month)->endOfMonth()->toDateString();
                $merged = [];
                $add = function (object $row, int $sessions, string $first, string $last) use (&$merged) {
                    $key = json_encode(array_map(fn ($c) => $row->{$c} === null ? null : (string) $row->{$c}, self::KEY_COLUMNS));
                    if (!isset($merged[$key])) {
                        $merged[$key] = array_combine(self::KEY_COLUMNS, array_map(fn ($c) => $row->{$c}, self::KEY_COLUMNS))
                            + ['sessions' => 0, 'first_day' => $first, 'last_day' => $last];
                    }
                    $merged[$key]['sessions'] += $sessions;
                    $merged[$key]['first_day'] = min($merged[$key]['first_day'], $first);
                    $merged[$key]['last_day'] = max($merged[$key]['last_day'], $last);
                };

                foreach (DB::table('usage_monthly')->where('month', $month)->get() as $row) {
                    $add($row, (int) $row->sessions, substr((string) $row->first_day, 0, 10), substr((string) $row->last_day, 0, 10));
                }
                $days = DB::table('usage_daily')->whereBetween('date', [$month, $end]);
                foreach ((clone $days)->get() as $row) {
                    $day = substr((string) $row->date, 0, 10);
                    $add($row, (int) $row->sessions, $day, $day);
                }

                DB::table('usage_monthly')->where('month', $month)->delete();
                foreach (array_chunk(array_values($merged), 500) as $chunk) {
                    DB::table('usage_monthly')->insert(array_map(fn ($r) => ['month' => $month] + $r, $chunk));
                }
                $folded += $days->delete();
            });
        }

        return $folded;
    }

    /**
     * Write one day of `usage_seen` into `usage_daily`, whole — the night's count, and today's,
     * counted again each time the screen is opened (ModUsageReport). Rewriting rather than adding
     * makes both idempotent.
     */
    public static function countDay(string $date): void
    {
        DB::table('usage_daily')->where('date', $date)->delete();

        $columns = ['product', 'act', 'game_id', 'steam_id', 'game_name', 'language', 'translation_id', 'outcome', 'signed_in'];

        $acts = DB::table('usage_seen')
            ->where('date', $date)
            ->select($columns)
            ->selectRaw('COUNT(*) as sessions')
            ->groupBy($columns)
            ->get();

        $rows = $acts->map(fn ($r) => ['date' => $date, 'sessions' => (int) $r->sessions]
            + array_intersect_key((array) $r, array_flip($columns)))->all();

        // What each session OF THE MOD did at most — a Manager's take is counted above as a take, but
        // a Manager is not a copy of the mod playing a game. Read session by session in PHP: "the
        // best of its acts" is an ordering of our own, which SQL would spell differently on MariaDB
        // and SQLite.
        $best = [];
        $signedIn = [];
        foreach (DB::table('usage_seen')->where('date', $date)->where('product', ClientAgent::MOD)
                     ->select('session', 'act', 'outcome', 'signed_in')->cursor() as $seen) {
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
                'date' => $date, 'product' => ClientAgent::MOD, 'act' => self::SESSION, 'game_id' => null, 'steam_id' => null,
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
